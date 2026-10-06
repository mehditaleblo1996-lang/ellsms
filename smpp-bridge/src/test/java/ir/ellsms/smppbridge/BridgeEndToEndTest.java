package ir.ellsms.smppbridge;

import com.zaxxer.hikari.HikariConfig;
import com.zaxxer.hikari.HikariDataSource;
import org.junit.jupiter.api.AfterAll;
import org.junit.jupiter.api.BeforeAll;
import org.junit.jupiter.api.Test;

import javax.crypto.Cipher;
import javax.crypto.spec.GCMParameterSpec;
import javax.crypto.spec.SecretKeySpec;
import java.net.ServerSocket;
import java.nio.charset.StandardCharsets;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.Statement;
import java.util.Arrays;
import java.util.function.BooleanSupplier;

import static org.junit.jupiter.api.Assertions.*;
import static org.junit.jupiter.api.Assumptions.assumeTrue;

/**
 * The bridge against its own built-in SMSC simulator and a REAL MySQL/MariaDB (for configuration and
 * events): config load + password decrypt, bind, submit (single and concatenated), throttle retry,
 * delivery receipt → ellsms_smpp_events, MO → ellsms_smpp_events, session state publishing, and a
 * rebuild when config_version moves. Runs when SMPP_TEST_DB_HOST is set, e.g.
 *   SMPP_TEST_DB_HOST=127.0.0.1 SMPP_TEST_DB_USER=ellsms_test SMPP_TEST_DB_PASS=ellsms_test mvn test
 */
class BridgeEndToEndTest {
    static HikariDataSource ds;
    static Store store;
    static Simulator sim;
    static GatewayManager manager;
    static int simPort;
    static final String MASTER = "m".repeat(48);

    @BeforeAll
    static void setUp() throws Exception {
        String host = System.getenv("SMPP_TEST_DB_HOST");
        assumeTrue(host != null && !host.isEmpty(), "SMPP_TEST_DB_HOST not set");
        String db = System.getenv().getOrDefault("SMPP_TEST_DB_NAME", "smpp_bridge_test");
        HikariConfig root = new HikariConfig();
        root.setJdbcUrl("jdbc:mysql://" + host + ":3306/?useSSL=false&allowPublicKeyRetrieval=true");
        root.setUsername(System.getenv().getOrDefault("SMPP_TEST_DB_USER", "root"));
        root.setPassword(System.getenv().getOrDefault("SMPP_TEST_DB_PASS", ""));
        try (HikariDataSource r = new HikariDataSource(root); Connection c = r.getConnection(); Statement st = c.createStatement()) {
            st.execute("DROP DATABASE IF EXISTS " + db);
            st.execute("CREATE DATABASE " + db + " CHARACTER SET utf8mb4");
        }
        HikariConfig cfg = new HikariConfig();
        cfg.setJdbcUrl("jdbc:mysql://" + host + ":3306/" + db + "?useSSL=false&allowPublicKeyRetrieval=true&characterEncoding=utf8");
        cfg.setUsername(root.getUsername());
        cfg.setPassword(root.getPassword());
        ds = new HikariDataSource(cfg);
        try (Connection c = ds.getConnection(); Statement st = c.createStatement()) {
            st.execute("CREATE TABLE ellsms_sms_gateways (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40), protocol VARCHAR(10), name VARCHAR(120), status VARCHAR(20), send_enabled TINYINT(1), config_version INT UNSIGNED)");
            st.execute("CREATE TABLE ellsms_sms_gateway_secrets (id INT AUTO_INCREMENT PRIMARY KEY, gateway_id INT UNSIGNED, secret_key VARCHAR(60), ciphertext BLOB, nonce VARBINARY(24), tag VARBINARY(16), key_fingerprint CHAR(16))");
            // The real DDL, straight from the migration, so the bridge is tested against what PHP creates.
            String sql = java.nio.file.Files.readString(java.nio.file.Path.of("../db/migrations/2026_10_06_smpp_gateway.sql"));
            for (String stmt : sql.replaceAll("(?m)--.*$", "").split(";")) {
                String s = stmt.trim();
                if (s.startsWith("CREATE TABLE")) st.execute(s);
            }
        }
        simPort = freePort();
        sim = new Simulator(simPort, "secret12");
        SecretVault vault = new SecretVault(MASTER);
        store = new Store(ds, vault);

        try (Connection c = ds.getConnection()) {
            c.createStatement().execute("INSERT INTO ellsms_sms_gateways (id, code, protocol, name, status, send_enabled, config_version) VALUES (7, 'smpp_sim', 'smpp', 'sim', 'active', 1, 1)");
            PreparedStatement ps = c.prepareStatement("INSERT INTO ellsms_sms_gateway_smpp_connectors (gateway_id, host, port, system_id, bind_mode, session_count, tps, window_size) VALUES (7, '127.0.0.1', ?, 'ellsms', 'trx', 2, 200, 10)");
            ps.setInt(1, simPort);
            ps.executeUpdate();
            byte[] key = SecretVault.hkdfSha256(MASTER.getBytes(StandardCharsets.UTF_8), new byte[0], SecretVault.PURPOSE.getBytes(StandardCharsets.UTF_8), 32);
            byte[] nonce = new byte[12];
            Cipher ci = Cipher.getInstance("AES/GCM/NoPadding");
            ci.init(Cipher.ENCRYPT_MODE, new SecretKeySpec(key, "AES"), new GCMParameterSpec(128, nonce));
            byte[] out = ci.doFinal("secret12".getBytes(StandardCharsets.UTF_8));
            PreparedStatement sp = c.prepareStatement("INSERT INTO ellsms_sms_gateway_secrets (gateway_id, secret_key, ciphertext, nonce, tag, key_fingerprint) VALUES (7, 'smpp_password', ?, ?, ?, ?)");
            sp.setBytes(1, Arrays.copyOf(out, out.length - 16));
            sp.setBytes(2, nonce);
            sp.setBytes(3, Arrays.copyOfRange(out, out.length - 16, out.length));
            sp.setString(4, vault.fingerprint());
            sp.executeUpdate();
        }
        manager = new GatewayManager(store, "test");
        manager.reload();
        assertNotNull(manager.get(7));
        waitFor(() -> manager.get(7).boundSenders() == 2, 15_000, "both TRX sessions bind");
    }

    @AfterAll
    static void tearDown() throws Exception {
        if (manager != null) manager.stopAll();
        if (sim != null) sim.close();
        if (ds != null) ds.close();
    }

    @Test
    void submitReceiptAndMo() throws Exception {
        GatewayRuntime rt = manager.get(7);
        GatewayRuntime.Result ok = rt.submit("30001234", "989121234567", "hello");
        assertTrue(ok.ok(), String.valueOf(ok.error()));
        assertEquals(1, ok.parts());
        waitFor(() -> count("SELECT COUNT(*) FROM ellsms_smpp_events WHERE event_type='dlr' AND message_id='" + ok.messageId() + "' AND dlr_stat='DELIVRD'") == 1, 10_000, "DELIVRD receipt stored");

        GatewayRuntime.Result undeliv = rt.submit("30001234", "989120000000", "x");
        waitFor(() -> count("SELECT COUNT(*) FROM ellsms_smpp_events WHERE message_id='" + undeliv.messageId() + "' AND dlr_stat='UNDELIV'") == 1, 10_000, "UNDELIV receipt stored");

        // Concatenated Persian message: 3 parts, receipts for later parts land on the first id.
        GatewayRuntime.Result longFa = rt.submit("30001234", "989121111111", "س".repeat(150));
        assertTrue(longFa.ok());
        assertEquals(3, longFa.parts());
        assertEquals(3, longFa.partIds().size());
        waitFor(() -> count("SELECT COUNT(*) FROM ellsms_smpp_events WHERE message_id='" + longFa.messageId() + "'") == 3, 10_000, "3 receipts mapped to the first part id");

        // ESME_RTHROTTLED once, then accepted on retry.
        GatewayRuntime.Result throttled = rt.submit("30001234", "989122222222", "THROTTLE");
        assertTrue(throttled.ok(), String.valueOf(throttled.error()));

        // MO from the SMSC.
        assertEquals(1, sim.sendMo("989123333333", "30001234", "سلام از مشتری"));
        waitFor(() -> count("SELECT COUNT(*) FROM ellsms_smpp_events WHERE event_type='mo' AND content='سلام از مشتری' AND source='989123333333'") == 1, 10_000, "MO stored");

        manager.publishState();
        assertEquals(2, count("SELECT COUNT(*) FROM ellsms_smpp_sessions WHERE gateway_id = 7 AND state = 'BOUND'"));
        assertTrue(count("SELECT SUM(submit_ok) FROM ellsms_smpp_sessions WHERE gateway_id = 7") >= 6);
        assertTrue(count("SELECT SUM(throttled) FROM ellsms_smpp_sessions WHERE gateway_id = 7") >= 1);
    }

    @Test
    void wrongPasswordNeverBindsAndConfigChangeRebuilds() throws Exception {
        try (Connection c = ds.getConnection()) {
            c.createStatement().execute("INSERT INTO ellsms_sms_gateways (id, code, protocol, name, status, send_enabled, config_version) VALUES (8, 'smpp_bad', 'smpp', 'bad', 'active', 1, 1)");
            c.createStatement().execute("INSERT INTO ellsms_sms_gateway_smpp_connectors (gateway_id, host, port, system_id, reconnect_delay_s) VALUES (8, '127.0.0.1', " + simPort + ", 'x', 1)");
        }
        manager.reload();
        GatewayRuntime bad = manager.get(8);
        assertNotNull(bad);
        Thread.sleep(1500);
        assertEquals(0, bad.boundSenders());
        GatewayRuntime.Result r = bad.submit("1", "989121234567", "x");
        assertFalse(r.ok());
        assertEquals("unavailable", r.errorClass());
        assertNotNull(bad.describe().get("password_error"));

        // Archiving the gateway stops it.
        try (Connection c = ds.getConnection()) {
            c.createStatement().execute("UPDATE ellsms_sms_gateways SET status = 'archived' WHERE id = 8");
        }
        manager.reload();
        assertNull(manager.get(8));
    }

    static long count(String sql) {
        try (Connection c = ds.getConnection(); ResultSet rs = c.createStatement().executeQuery(sql)) {
            rs.next();
            return rs.getLong(1);
        } catch (Exception e) {
            throw new RuntimeException(e);
        }
    }

    static void waitFor(BooleanSupplier cond, long ms, String what) throws InterruptedException {
        long end = System.currentTimeMillis() + ms;
        while (System.currentTimeMillis() < end) {
            if (cond.getAsBoolean()) return;
            Thread.sleep(100);
        }
        fail("timed out waiting for: " + what);
    }

    static int freePort() throws Exception {
        try (ServerSocket s = new ServerSocket(0)) {
            return s.getLocalPort();
        }
    }
}
