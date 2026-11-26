package ir.ellsms.smppbridge;

import org.junit.jupiter.api.AfterEach;
import org.junit.jupiter.api.Test;

import java.io.DataInputStream;
import java.io.DataOutputStream;
import java.net.ServerSocket;
import java.net.Socket;
import java.nio.charset.StandardCharsets;
import java.time.Instant;
import java.util.ArrayList;
import java.util.List;
import java.util.Map;

import static org.junit.jupiter.api.Assertions.*;

/** «اتصال آزمایشی» diagnoses why a bind failed (BindTester), against small local servers. No database needed. */
class BindTesterTest {
    private final List<AutoCloseable> closers = new ArrayList<>();

    @AfterEach
    void close() throws Exception {
        for (AutoCloseable c : closers) c.close();
    }

    private static BindTester fast() {
        BindTester t = new BindTester();
        t.tcpTimeoutMs = 1000;
        t.bindTimeoutMs = 1500;
        t.probeTimeoutMs = 1000;
        t.altBindTimeoutMs = 1500;
        return t;
    }

    private static GatewayConfig cfg(int port, String bindMode, String password) {
        return new GatewayConfig(9, "t", 1, true, "127.0.0.1", port, false, "ellsms", password, "", "3.4", bindMode,
            1, 50, 10, 30, 5, 5000, 5, 0, 1, 1, "auto", "udh", 1, 0, "international", true, null, 0, 0, 0, 0);
    }

    private static SessionWorker.Snapshot bound() {
        return new SessionWorker.Snapshot(9, "trx-1", "trx", "BOUND", Instant.now(), null, null, 0, 0, 0, 0, 0, 0, 0, 0, 1);
    }

    /** Accepts connections and never sends a byte — an SMSC that ignores this client. */
    private int silentServer() throws Exception {
        ServerSocket ss = new ServerSocket(0);
        closers.add(ss);
        Thread.ofVirtual().start(() -> {
            while (!ss.isClosed()) {
                try {
                    Socket s = ss.accept();
                    closers.add(s);
                    Thread.ofVirtual().start(() -> { try { s.getInputStream().transferTo(java.io.OutputStream.nullOutputStream()); } catch (Exception ignored) { } });
                } catch (Exception e) {
                    return;
                }
            }
        });
        return ss.getLocalPort();
    }

    /** A minimal SMSC that answers enquire_link and bind_transmitter but ignores bind_transceiver. */
    private int noTransceiverServer() throws Exception {
        ServerSocket ss = new ServerSocket(0);
        closers.add(ss);
        Thread.ofVirtual().start(() -> {
            while (!ss.isClosed()) {
                try {
                    Socket s = ss.accept();
                    closers.add(s);
                    Thread.ofVirtual().start(() -> serveRaw(s));
                } catch (Exception e) {
                    return;
                }
            }
        });
        return ss.getLocalPort();
    }

    private static void serveRaw(Socket s) {
        try (s; DataInputStream in = new DataInputStream(s.getInputStream()); DataOutputStream out = new DataOutputStream(s.getOutputStream())) {
            while (true) {
                int len = in.readInt();
                int cmd = in.readInt();
                in.readInt(); // status
                int seq = in.readInt();
                in.skipNBytes(len - 16);
                byte[] body = new byte[0];
                if (cmd == 0x15 || cmd == 0x06) {
                    // enquire_link / unbind
                } else if (cmd == 0x02) {
                    body = "RAWSMSC\0".getBytes(StandardCharsets.US_ASCII);
                } else {
                    continue; // bind_transceiver and anything else: no answer
                }
                out.writeInt(16 + body.length);
                out.writeInt(cmd | 0x80000000);
                out.writeInt(0);
                out.writeInt(seq);
                out.write(body);
                out.flush();
                if (cmd == 0x06) return;
            }
        } catch (Exception ignored) {
            // connection closed
        }
    }

    /** Holds the first connection silently and hangs up on every later one — a one-connection SMSC. */
    private int oneConnectionServer() throws Exception {
        ServerSocket ss = new ServerSocket(0);
        closers.add(ss);
        Thread.ofVirtual().start(() -> {
            boolean first = true;
            while (!ss.isClosed()) {
                try {
                    Socket s = ss.accept();
                    if (first) {
                        first = false;
                        closers.add(s);
                        Thread.ofVirtual().start(() -> { try { s.getInputStream().transferTo(java.io.OutputStream.nullOutputStream()); } catch (Exception ignored) { } });
                    } else {
                        s.close();
                    }
                } catch (Exception e) {
                    return;
                }
            }
        });
        return ss.getLocalPort();
    }

    private static int freePort() throws Exception {
        try (ServerSocket s = new ServerSocket(0)) {
            return s.getLocalPort();
        }
    }

    @Test
    void aClosedPortIsReportedAsRefused() throws Exception {
        Map<String, Object> r = fast().run(cfg(freePort(), "trx", "secret12"), List.of());
        assertEquals(false, r.get("ok"));
        assertEquals("tcp_refused", r.get("diagnosis"));
    }

    @Test
    void aSilentPortIsDiagnosedAfterTheProbes() throws Exception {
        Map<String, Object> r = fast().run(cfg(silentServer(), "trx", "secret12"), List.of());
        assertEquals(false, r.get("ok"));
        assertEquals(true, ((Map<?, ?>) r.get("tcp")).get("ok"), "the TCP connection itself works");
        assertEquals("none", ((Map<?, ?>) r.get("enquire_link_probe")).get("answer"));
        assertEquals(false, r.get("tls_handshake"));
        assertEquals("silent", r.get("diagnosis"));
    }

    @Test
    void anSmscThatHangsUpOnFurtherConnectionsIsNamed() throws Exception {
        Map<String, Object> r = fast().run(cfg(oneConnectionServer(), "trx", "secret12"), List.of());
        assertEquals("closed", ((Map<?, ?>) r.get("enquire_link_probe")).get("answer"));
        assertNotNull(r.get("alt_bind_tx"), "a transmitter bind is tried too");
        assertEquals("second_connection_closed", r.get("diagnosis"));
    }

    @Test
    void anUnansweredTestWhileTheGatewayIsBoundIsTheSessionLimit() throws Exception {
        Map<String, Object> r = fast().run(cfg(silentServer(), "trx", "secret12"), List.of(bound()));
        assertEquals("session_limit", r.get("diagnosis"));
        assertEquals(1L, r.get("gateway_sessions_bound"));
    }

    @Test
    void anSmscWithoutTransceiverSupportIsToldToUseTxRx() throws Exception {
        Map<String, Object> r = fast().run(cfg(noTransceiverServer(), "trx", "secret12"), List.of());
        assertEquals("smpp", ((Map<?, ?>) r.get("enquire_link_probe")).get("answer"), "the port speaks SMPP");
        assertEquals(true, ((Map<?, ?>) r.get("alt_bind_tx")).get("ok"));
        assertEquals("use_tx_rx", r.get("diagnosis"));
    }

    @Test
    void theSimulatorBindsAndAWrongPasswordIsNamed() throws Exception {
        int port = freePort();
        Simulator sim = new Simulator(port, "secret12");
        closers.add(sim::close);
        Map<String, Object> ok = fast().run(cfg(port, "trx", "secret12"), List.of());
        assertEquals(true, ok.get("ok"), String.valueOf(ok.get("error")));
        assertEquals("ok", ok.get("diagnosis"));
        assertEquals("SIMSMSC", ok.get("smsc_system_id"));

        Map<String, Object> bad = fast().run(cfg(port, "trx", "wrong"), List.of());
        assertEquals(false, bad.get("ok"));
        assertEquals("invalid_password", bad.get("diagnosis"));
        assertEquals("0x0000000E", bad.get("command_status"));
    }

    @Test
    void responseBytesAreClassified() {
        assertEquals("http", BindTester.classify("HTTP/1.1 400".getBytes(StandardCharsets.US_ASCII), 12));
        assertEquals("tls", BindTester.classify(new byte[]{0x15, 0x03, 0x03, 0, 2}, 5));
        assertEquals("smpp", BindTester.classify(new byte[]{0, 0, 0, 16, (byte) 0x80, 0, 0, 0x15, 0, 0, 0, 0, 0, 0, 0, 1}, 16));
        assertEquals("unknown", BindTester.classify(new byte[]{1, 2, 3, 4, 5, 6, 7, 8}, 8));
    }
}
