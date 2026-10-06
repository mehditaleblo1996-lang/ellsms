package ir.ellsms.smppbridge;

import com.zaxxer.hikari.HikariConfig;
import com.zaxxer.hikari.HikariDataSource;

import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.sql.Timestamp;
import java.time.Instant;
import java.util.ArrayList;
import java.util.List;

/**
 * Everything the bridge reads from / writes to the shared ELLSMS database:
 *   - gateway configuration (read, every few seconds),
 *   - delivery receipts and received messages (ellsms_smpp_events — written BEFORE the deliver_sm is
 *     acknowledged, so the SMSC redelivers anything the bridge failed to store),
 *   - live session state for the panel (ellsms_smpp_sessions).
 */
public final class Store implements AutoCloseable {
    private final HikariDataSource ds;
    private final SecretVault vault;

    public Store(String host, int port, String db, String user, String pass, SecretVault vault) {
        HikariConfig cfg = new HikariConfig();
        cfg.setJdbcUrl("jdbc:mysql://" + host + ":" + port + "/" + db
            + "?useUnicode=true&characterEncoding=utf8&connectionTimeZone=SERVER&useSSL=false&allowPublicKeyRetrieval=true");
        cfg.setUsername(user);
        cfg.setPassword(pass);
        cfg.setMaximumPoolSize(8);
        cfg.setMinimumIdle(1);
        cfg.setConnectionTimeout(10_000);
        cfg.setInitializationFailTimeout(-1); // start even if the DB is not up yet; queries retry
        cfg.setPoolName("smpp-bridge");
        this.ds = new HikariDataSource(cfg);
        this.vault = vault;
    }

    /** Store over an existing pool (tests). */
    Store(HikariDataSource ds, SecretVault vault) {
        this.ds = ds;
        this.vault = vault;
    }

    public List<GatewayConfig> loadGateways() throws SQLException {
        String sql = """
            SELECT g.id, g.code, g.config_version, g.send_enabled, c.*,
                   s.ciphertext, s.nonce, s.tag, s.key_fingerprint
              FROM ellsms_sms_gateways g
              JOIN ellsms_sms_gateway_smpp_connectors c ON c.gateway_id = g.id
              LEFT JOIN ellsms_sms_gateway_secrets s ON s.gateway_id = g.id AND s.secret_key = 'smpp_password'
             WHERE g.protocol = 'smpp' AND g.status = 'active'
             ORDER BY g.id""";
        List<GatewayConfig> out = new ArrayList<>();
        try (Connection c = ds.getConnection(); PreparedStatement st = c.prepareStatement(sql); ResultSet rs = st.executeQuery()) {
            while (rs.next()) {
                String password = "";
                String passwordError = null;
                byte[] ct = rs.getBytes("ciphertext");
                if (ct == null) {
                    passwordError = "no smpp_password secret stored";
                } else {
                    try {
                        password = vault.decrypt(ct, rs.getBytes("nonce"), rs.getBytes("tag"), rs.getString("key_fingerprint"));
                    } catch (RuntimeException e) {
                        passwordError = e.getMessage();
                    }
                }
                out.add(new GatewayConfig(
                    rs.getInt("id"), rs.getString("code"), rs.getInt("config_version"), rs.getBoolean("send_enabled"),
                    rs.getString("host"), rs.getInt("port"), rs.getBoolean("use_tls"), rs.getString("system_id"), password,
                    rs.getString("system_type"), rs.getString("interface_version"), rs.getString("bind_mode"),
                    Math.max(1, rs.getInt("session_count")), Math.max(1, rs.getInt("tps")), Math.max(1, rs.getInt("window_size")),
                    Math.max(5, rs.getInt("enquire_link_s")), Math.max(1, rs.getInt("reconnect_delay_s")), Math.max(1000, rs.getInt("submit_timeout_ms")),
                    rs.getInt("source_ton"), rs.getInt("source_npi"), rs.getInt("dest_ton"), rs.getInt("dest_npi"),
                    rs.getString("data_coding"), rs.getString("long_message"), rs.getInt("registered_delivery"), rs.getInt("validity_minutes"),
                    rs.getString("destination_format"), rs.getBoolean("receive_enabled"), passwordError));
            }
        }
        return out;
    }

    public void insertDlr(int gatewayId, String messageId, String stat, String err, Instant doneAt) throws SQLException {
        try (Connection c = ds.getConnection(); PreparedStatement st = c.prepareStatement(
            "INSERT INTO ellsms_smpp_events (gateway_id, event_type, message_id, dlr_stat, dlr_err, done_at, received_at) VALUES (?, 'dlr', ?, ?, ?, ?, NOW())")) {
            st.setInt(1, gatewayId);
            st.setString(2, trim(messageId, 190));
            st.setString(3, trim(stat, 20));
            st.setString(4, trim(err, 10));
            st.setTimestamp(5, doneAt == null ? null : Timestamp.from(doneAt));
            st.executeUpdate();
        }
    }

    public void insertMo(int gatewayId, String source, String destination, String content) throws SQLException {
        try (Connection c = ds.getConnection(); PreparedStatement st = c.prepareStatement(
            "INSERT INTO ellsms_smpp_events (gateway_id, event_type, source, destination, content, received_at) VALUES (?, 'mo', ?, ?, ?, NOW())")) {
            st.setInt(1, gatewayId);
            st.setString(2, trim(source, 30));
            st.setString(3, trim(destination, 30));
            st.setString(4, content);
            st.executeUpdate();
        }
    }

    public void upsertSessions(List<SessionWorker.Snapshot> snapshots, String bridgeId) throws SQLException {
        if (snapshots.isEmpty()) return;
        String sql = """
            INSERT INTO ellsms_smpp_sessions
              (gateway_id, session_key, bind_type, state, bound_since, last_error, last_error_at, submitted, submit_ok,
               submit_failed, throttled, dlr_received, mo_received, in_flight, reconnects, config_version, bridge_id, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE bind_type = VALUES(bind_type), state = VALUES(state), bound_since = VALUES(bound_since),
              last_error = VALUES(last_error), last_error_at = VALUES(last_error_at), submitted = VALUES(submitted),
              submit_ok = VALUES(submit_ok), submit_failed = VALUES(submit_failed), throttled = VALUES(throttled),
              dlr_received = VALUES(dlr_received), mo_received = VALUES(mo_received), in_flight = VALUES(in_flight),
              reconnects = VALUES(reconnects), config_version = VALUES(config_version), bridge_id = VALUES(bridge_id), updated_at = NOW()""";
        try (Connection c = ds.getConnection(); PreparedStatement st = c.prepareStatement(sql)) {
            for (SessionWorker.Snapshot s : snapshots) {
                st.setInt(1, s.gatewayId());
                st.setString(2, s.key());
                st.setString(3, s.bindType());
                st.setString(4, s.state());
                st.setTimestamp(5, s.boundSince() == null ? null : Timestamp.from(s.boundSince()));
                st.setString(6, trim(s.lastError(), 500));
                st.setTimestamp(7, s.lastErrorAt() == null ? null : Timestamp.from(s.lastErrorAt()));
                st.setLong(8, s.submitted());
                st.setLong(9, s.submitOk());
                st.setLong(10, s.submitFailed());
                st.setLong(11, s.throttled());
                st.setLong(12, s.dlrReceived());
                st.setLong(13, s.moReceived());
                st.setInt(14, s.inFlight());
                st.setInt(15, s.reconnects());
                st.setInt(16, s.configVersion());
                st.setString(17, bridgeId);
                st.addBatch();
            }
            st.executeBatch();
        }
    }

    /** Session rows of gateways/sessions that no longer exist in this bridge. */
    public void deleteSessionsExcept(List<SessionWorker.Snapshot> live) throws SQLException {
        try (Connection c = ds.getConnection()) {
            if (live.isEmpty()) {
                try (PreparedStatement st = c.prepareStatement("DELETE FROM ellsms_smpp_sessions")) { st.executeUpdate(); }
                return;
            }
            StringBuilder sb = new StringBuilder("DELETE FROM ellsms_smpp_sessions WHERE (gateway_id, session_key) NOT IN (");
            for (int i = 0; i < live.size(); i++) sb.append(i == 0 ? "(?,?)" : ",(?,?)");
            sb.append(')');
            try (PreparedStatement st = c.prepareStatement(sb.toString())) {
                int p = 1;
                for (SessionWorker.Snapshot s : live) {
                    st.setInt(p++, s.gatewayId());
                    st.setString(p++, s.key());
                }
                st.executeUpdate();
            }
        }
    }

    private static String trim(String s, int max) {
        if (s == null) return null;
        return s.length() <= max ? s : s.substring(0, max);
    }

    @Override
    public void close() {
        ds.close();
    }
}
