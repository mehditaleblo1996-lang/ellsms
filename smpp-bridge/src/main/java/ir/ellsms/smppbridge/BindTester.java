package ir.ellsms.smppbridge;

import org.jsmpp.bean.BindType;
import org.jsmpp.bean.InterfaceVersion;
import org.jsmpp.bean.NumberingPlanIndicator;
import org.jsmpp.bean.TypeOfNumber;
import org.jsmpp.extra.NegativeResponseException;
import org.jsmpp.extra.ResponseTimeoutException;
import org.jsmpp.session.BindParameter;
import org.jsmpp.session.SMPPSession;
import org.jsmpp.session.connection.socket.NoTrustSSLSocketConnectionFactory;
import org.jsmpp.session.connection.socket.SocketConnectionFactory;

import javax.net.ssl.SSLContext;
import javax.net.ssl.SSLSocket;
import javax.net.ssl.TrustManager;
import javax.net.ssl.X509TrustManager;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.InetSocketAddress;
import java.net.Socket;
import java.net.SocketTimeoutException;
import java.nio.charset.StandardCharsets;
import java.security.cert.X509Certificate;
import java.util.HexFormat;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/**
 * «اتصال آزمایشی»: one throw-away bind with the stored configuration and, when it fails, a diagnosis of
 * WHY, so the admin is told what to change instead of only "no bind response".
 *
 * Steps (each bounded):
 *   1. TCP connect — proves the network path (refused = port closed; timeout = no route / firewall).
 *   2. bind as configured.
 *   3. Only if the bind got no answer:
 *      - the gateway's own sessions: if one is BOUND, the credentials work and the SMSC just does not
 *        answer an extra session (its session limit);
 *      - a raw enquire_link on a fresh connection: does anything SMPP answer on this port at all?
 *        (silent = IP not allowed / wrong port / a proxy or VPN not connected to the SMSC);
 *      - a TLS handshake, when TLS is off and the port stayed silent;
 *      - bind_transmitter instead of bind_transceiver, when the port speaks SMPP (an SMSC that does not
 *        support transceiver binds).
 * The result carries a machine-readable {@code diagnosis} the panel turns into Persian advice.
 */
final class BindTester {
    int tcpTimeoutMs = 5_000;
    int bindTimeoutMs = 15_000;
    int probeTimeoutMs = 5_000;
    int altBindTimeoutMs = 10_000;

    /** One bind attempt's outcome. */
    record Attempt(boolean ok, String smscSystemId, String error, boolean timedOut, Integer commandStatus, long elapsedMs) {
        Map<String, Object> toMap() {
            Map<String, Object> m = new LinkedHashMap<>();
            m.put("ok", ok);
            m.put("error", error);
            m.put("timed_out", timedOut);
            m.put("command_status", commandStatus == null ? null : String.format("0x%08X", commandStatus));
            m.put("elapsed_ms", elapsedMs);
            return m;
        }
    }

    Map<String, Object> run(GatewayConfig cfg, List<SessionWorker.Snapshot> liveSessions) {
        Map<String, Object> out = new LinkedHashMap<>();
        BindType type = switch (cfg.bindMode()) { case "tx", "tx_rx" -> BindType.BIND_TX; default -> BindType.BIND_TRX; };
        InterfaceVersion iv = "5.0".equals(cfg.interfaceVersion()) ? InterfaceVersion.IF_50 : InterfaceVersion.IF_34;
        out.put("host", cfg.host());
        out.put("port", cfg.port());
        out.put("bind_type", type.name());
        out.put("use_tls", cfg.useTls());
        long bound = liveSessions.stream().filter(s -> "BOUND".equals(s.state())).count();
        out.put("gateway_sessions", liveSessions.size());
        out.put("gateway_sessions_bound", bound);

        Map<String, Object> tcp = tcpConnect(cfg.host(), cfg.port());
        out.put("tcp", tcp);
        if (!Boolean.TRUE.equals(tcp.get("ok"))) {
            out.put("ok", false);
            out.put("error", tcp.get("error"));
            out.put("diagnosis", Boolean.TRUE.equals(tcp.get("refused")) ? "tcp_refused" : "tcp_failed");
            return out;
        }

        Attempt a = bind(cfg, type, iv, cfg.useTls(), bindTimeoutMs);
        out.put("ok", a.ok());
        out.put("elapsed_ms", a.elapsedMs());
        if (a.ok()) {
            out.put("smsc_system_id", a.smscSystemId());
            out.put("diagnosis", "ok");
            return out;
        }
        out.put("error", a.error());
        out.put("command_status", a.commandStatus() == null ? null : String.format("0x%08X", a.commandStatus()));
        if (a.commandStatus() != null) {
            out.put("diagnosis", switch (a.commandStatus()) {
                case 0x0E -> "invalid_password";
                case 0x0F -> "invalid_system_id";
                case 0x05 -> "already_bound";
                case 0x0D -> "bind_failed";
                default -> "bind_rejected";
            });
            return out;
        }
        if (!a.timedOut()) {
            out.put("diagnosis", "closed_by_peer");
            return out;
        }

        // No answer to the bind: find out why.
        if (bound > 0) {
            out.put("diagnosis", "session_limit");
            return out;
        }
        Map<String, Object> probe = enquireLinkProbe(cfg.host(), cfg.port(), cfg.useTls());
        out.put("enquire_link_probe", probe);
        String answer = String.valueOf(probe.get("answer"));
        if (answer.equals("http") || answer.equals("tls")) {
            out.put("diagnosis", "not_smpp_" + answer);
            return out;
        }
        if (answer.equals("smpp")) {
            if (type == BindType.BIND_TRX) {
                Attempt tx = bind(cfg, BindType.BIND_TX, iv, cfg.useTls(), altBindTimeoutMs);
                out.put("alt_bind_tx", tx.toMap());
                if (tx.ok()) {
                    out.put("diagnosis", "use_tx_rx");
                    return out;
                }
                if (tx.commandStatus() != null) {
                    out.put("diagnosis", tx.commandStatus() == 0x0E ? "invalid_password" : tx.commandStatus() == 0x0F ? "invalid_system_id" : "bind_rejected");
                    out.put("command_status", String.format("0x%08X", tx.commandStatus()));
                    return out;
                }
            }
            out.put("diagnosis", "smsc_ignores_bind");
            return out;
        }
        if (!cfg.useTls()) {
            boolean tls = tlsHandshake(cfg.host(), cfg.port());
            out.put("tls_handshake", tls);
            if (tls) {
                out.put("diagnosis", "enable_tls");
                return out;
            }
        }
        out.put("diagnosis", answer.equals("closed") ? "closed_by_peer" : "silent");
        return out;
    }

    Attempt bind(GatewayConfig cfg, BindType type, InterfaceVersion iv, boolean tls, int timeoutMs) {
        long started = System.currentTimeMillis();
        SMPPSession s = new SMPPSession(tls ? new NoTrustSSLSocketConnectionFactory() : SocketConnectionFactory.getInstance());
        try {
            String systemId = s.connectAndBind(cfg.host(), cfg.port(), new BindParameter(type, cfg.systemId(), cfg.password(), cfg.systemType(),
                TypeOfNumber.UNKNOWN, NumberingPlanIndicator.UNKNOWN, null, iv), timeoutMs);
            return new Attempt(true, systemId, null, false, null, System.currentTimeMillis() - started);
        } catch (Exception e) {
            Integer status = null;
            boolean timedOut = false;
            for (Throwable t = e; t != null; t = t.getCause()) {
                if (t instanceof NegativeResponseException n) status = n.getCommandStatus();
                if (t instanceof ResponseTimeoutException || t instanceof SocketTimeoutException) timedOut = true;
            }
            return new Attempt(false, null, e.getMessage(), timedOut && status == null, status, System.currentTimeMillis() - started);
        } finally {
            try { s.unbindAndClose(); } catch (Exception ignored) { /* best effort */ }
        }
    }

    Map<String, Object> tcpConnect(String host, int port) {
        Map<String, Object> m = new LinkedHashMap<>();
        long started = System.currentTimeMillis();
        try (Socket s = new Socket()) {
            s.connect(new InetSocketAddress(host, port), tcpTimeoutMs);
            m.put("ok", true);
            m.put("local_address", s.getLocalAddress().getHostAddress());
        } catch (Exception e) {
            m.put("ok", false);
            m.put("error", e.getClass().getSimpleName() + ": " + e.getMessage());
            m.put("refused", e instanceof java.net.ConnectException && String.valueOf(e.getMessage()).toLowerCase().contains("refused"));
        }
        m.put("elapsed_ms", System.currentTimeMillis() - started);
        return m;
    }

    /**
     * Sends a bare enquire_link on a new connection and reads what comes back. Any SMPP server answers
     * it (enquire_link_resp or generic_nack) even before a bind, so silence means nothing SMPP is
     * listening to this client.
     */
    Map<String, Object> enquireLinkProbe(String host, int port, boolean tls) {
        Map<String, Object> m = new LinkedHashMap<>();
        byte[] enquireLink = {0, 0, 0, 16, 0, 0, 0, 0x15, 0, 0, 0, 0, 0, 0, 0, 1};
        try (Socket s = tls ? trustAllContext().getSocketFactory().createSocket() : new Socket()) {
            s.connect(new InetSocketAddress(host, port), tcpTimeoutMs);
            s.setSoTimeout(probeTimeoutMs);
            if (s instanceof SSLSocket ssl) ssl.startHandshake();
            OutputStream o = s.getOutputStream();
            o.write(enquireLink);
            o.flush();
            InputStream in = s.getInputStream();
            byte[] buf = new byte[64];
            int n;
            try {
                n = in.read(buf);
            } catch (SocketTimeoutException e) {
                m.put("answer", "none");
                return m;
            }
            if (n <= 0) {
                m.put("answer", "closed");
                return m;
            }
            m.put("first_bytes", HexFormat.of().formatHex(buf, 0, n));
            m.put("answer", classify(buf, n));
        } catch (Exception e) {
            m.put("answer", "error");
            m.put("error", e.getClass().getSimpleName() + ": " + e.getMessage());
        }
        return m;
    }

    static String classify(byte[] b, int n) {
        if (n >= 5 && new String(b, 0, Math.min(n, 5), StandardCharsets.ISO_8859_1).startsWith("HTTP")) return "http";
        if (n >= 2 && (b[0] == 0x15 || b[0] == 0x16) && b[1] == 0x03) return "tls";
        if (n >= 8 && (b[4] & 0x80) != 0) return "smpp"; // a response command_id (high bit set)
        return "unknown";
    }

    boolean tlsHandshake(String host, int port) {
        try (SSLSocket s = (SSLSocket) trustAllContext().getSocketFactory().createSocket()) {
            s.connect(new InetSocketAddress(host, port), tcpTimeoutMs);
            s.setSoTimeout(probeTimeoutMs);
            s.startHandshake();
            return true;
        } catch (Exception e) {
            return false;
        }
    }

    private static SSLContext trustAllContext() throws Exception {
        SSLContext ctx = SSLContext.getInstance("TLS");
        ctx.init(null, new TrustManager[]{new X509TrustManager() {
            public void checkClientTrusted(X509Certificate[] c, String a) { }
            public void checkServerTrusted(X509Certificate[] c, String a) { }
            public X509Certificate[] getAcceptedIssuers() { return new X509Certificate[0]; }
        }}, null);
        return ctx;
    }
}
