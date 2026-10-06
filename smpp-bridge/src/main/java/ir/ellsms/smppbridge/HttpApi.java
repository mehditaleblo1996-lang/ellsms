package ir.ellsms.smppbridge;

import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;
import com.sun.net.httpserver.HttpExchange;
import com.sun.net.httpserver.HttpServer;
import org.jsmpp.bean.BindType;
import org.jsmpp.bean.InterfaceVersion;
import org.jsmpp.bean.NumberingPlanIndicator;
import org.jsmpp.bean.TypeOfNumber;
import org.jsmpp.session.BindParameter;
import org.jsmpp.session.SMPPSession;
import org.jsmpp.session.connection.socket.NoTrustSSLSocketConnectionFactory;
import org.jsmpp.session.connection.socket.SocketConnectionFactory;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import java.io.IOException;
import java.io.InputStream;
import java.net.InetSocketAddress;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.concurrent.CompletableFuture;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/**
 * Internal HTTP API used ONLY by ELLSMS's PHP side on the private Docker network. Every endpoint but
 * /health requires "Authorization: Bearer $SMPP_BRIDGE_TOKEN" (constant-time compare).
 *
 *   GET  /health                          liveness
 *   GET  /v1/status                       every gateway and session
 *   POST /v1/submit                       {gateway_id, sender, recipients[], message, messages{recipient: text}}
 *   POST /v1/reload                       re-read configuration now
 *   POST /v1/gateways/{id}/reconnect      drop and rebind every session of a gateway
 *   POST /v1/gateways/{id}/test           one throw-away bind+unbind with the stored configuration
 *   POST /v1/simulator/mo                 (simulator only) {from, to, text}
 */
public final class HttpApi {
    private static final Logger LOG = LoggerFactory.getLogger(HttpApi.class);
    private static final ObjectMapper JSON = new ObjectMapper()
        .registerModule(new com.fasterxml.jackson.datatype.jsr310.JavaTimeModule())
        .disable(com.fasterxml.jackson.databind.SerializationFeature.WRITE_DATES_AS_TIMESTAMPS);
    private static final Pattern GATEWAY_ACTION = Pattern.compile("^/v1/gateways/(\\d+)/(reconnect|test)$");
    private static final int MAX_BODY = 4 * 1024 * 1024;
    private static final int MAX_RECIPIENTS = 1000;

    private final GatewayManager manager;
    private final Store store;
    private final byte[] token;
    private final Simulator simulator;
    private final ExecutorService submitPool = Executors.newVirtualThreadPerTaskExecutor();
    private HttpServer server;

    public HttpApi(GatewayManager manager, Store store, String token, Simulator simulator) {
        this.manager = manager;
        this.store = store;
        this.token = token == null ? new byte[0] : token.getBytes(StandardCharsets.UTF_8);
        this.simulator = simulator;
    }

    public void start(int port) throws IOException {
        server = HttpServer.create(new InetSocketAddress(port), 128);
        server.setExecutor(Executors.newVirtualThreadPerTaskExecutor());
        server.createContext("/", this::handle);
        server.start();
        LOG.info("HTTP API listening on :{}", port);
    }

    public void stop() {
        if (server != null) server.stop(1);
    }

    private void handle(HttpExchange ex) throws IOException {
        try {
            String path = ex.getRequestURI().getPath();
            String method = ex.getRequestMethod();
            if (path.equals("/health")) {
                send(ex, 200, Map.of("ok", true, "gateways", manager.all().size()));
                return;
            }
            if (!authorized(ex)) {
                send(ex, 401, Map.of("error", "unauthorized"));
                return;
            }
            if (path.equals("/v1/status") && method.equals("GET")) {
                List<Object> gws = new ArrayList<>();
                for (GatewayRuntime rt : manager.all()) gws.add(rt.describe());
                Map<String, Object> out = new LinkedHashMap<>();
                out.put("gateways", gws);
                out.put("config_error", manager.lastLoadError());
                send(ex, 200, out);
                return;
            }
            if (!method.equals("POST")) {
                send(ex, 405, Map.of("error", "method_not_allowed"));
                return;
            }
            JsonNode body = readBody(ex);
            if (path.equals("/v1/submit")) {
                submit(ex, body);
                return;
            }
            if (path.equals("/v1/reload")) {
                manager.reload();
                send(ex, 200, Map.of("ok", true, "gateways", manager.all().size()));
                return;
            }
            if (path.equals("/v1/simulator/mo") && simulator != null) {
                int n = simulator.sendMo(body.path("from").asText(), body.path("to").asText(), body.path("text").asText());
                send(ex, 200, Map.of("ok", n > 0, "sessions", n));
                return;
            }
            Matcher m = GATEWAY_ACTION.matcher(path);
            if (m.matches()) {
                int id = Integer.parseInt(m.group(1));
                if (m.group(2).equals("reconnect")) {
                    GatewayRuntime rt = manager.get(id);
                    if (rt == null) { send(ex, 404, Map.of("error", "gateway_not_running")); return; }
                    rt.reconnect();
                    send(ex, 200, Map.of("ok", true));
                } else {
                    send(ex, 200, testBind(id));
                }
                return;
            }
            send(ex, 404, Map.of("error", "not_found"));
        } catch (IllegalArgumentException e) {
            send(ex, 400, Map.of("error", "bad_request", "detail", String.valueOf(e.getMessage())));
        } catch (Exception e) {
            LOG.error("request failed", e);
            send(ex, 500, Map.of("error", "internal", "detail", String.valueOf(e.getMessage())));
        }
    }

    private void submit(HttpExchange ex, JsonNode body) throws Exception {
        int gatewayId = body.path("gateway_id").asInt(0);
        String sender = body.path("sender").asText("");
        String message = body.path("message").asText("");
        JsonNode recipients = body.path("recipients");
        JsonNode perRecipient = body.path("messages");
        if (gatewayId <= 0 || !recipients.isArray() || recipients.isEmpty() || recipients.size() > MAX_RECIPIENTS || sender.isEmpty()) {
            throw new IllegalArgumentException("gateway_id, sender and 1.." + MAX_RECIPIENTS + " recipients are required");
        }
        GatewayRuntime rt = manager.get(gatewayId);
        if (rt == null) {
            manager.reload(); // the gateway may have been created a moment ago
            rt = manager.get(gatewayId);
        }
        if (rt == null) {
            send(ex, 503, Map.of("error", "gateway_not_configured", "detail", "gateway is not an active SMPP gateway in this bridge"));
            return;
        }
        final GatewayRuntime runtime = rt;
        List<CompletableFuture<GatewayRuntime.Result>> futures = new ArrayList<>();
        for (JsonNode r : recipients) {
            String to = r.asText();
            String text = perRecipient.isObject() && perRecipient.has(to) ? perRecipient.get(to).asText() : message;
            futures.add(CompletableFuture.supplyAsync(() -> runtime.submit(sender, to, text), submitPool));
        }
        List<Map<String, Object>> results = new ArrayList<>();
        for (CompletableFuture<GatewayRuntime.Result> f : futures) {
            GatewayRuntime.Result r = f.get();
            Map<String, Object> m = new LinkedHashMap<>();
            m.put("recipient", r.recipient());
            m.put("ok", r.ok());
            m.put("message_id", r.messageId());
            m.put("part_ids", r.partIds());
            m.put("parts", r.parts());
            m.put("error", r.error());
            m.put("error_class", r.errorClass());
            m.put("command_status", r.commandStatus());
            results.add(m);
        }
        send(ex, 200, Map.of("ok", true, "bound_senders", runtime.boundSenders(), "results", results));
    }

    /** A separate, short-lived session: proves host, port, credentials and bind type without touching live sessions. */
    private Map<String, Object> testBind(int gatewayId) throws Exception {
        GatewayConfig cfg = store.loadGateways().stream().filter(c -> c.gatewayId() == gatewayId).findFirst().orElse(null);
        Map<String, Object> out = new LinkedHashMap<>();
        if (cfg == null) {
            out.put("ok", false);
            out.put("error", "gateway is not an active SMPP gateway (or its SMPP settings are missing)");
            return out;
        }
        if (cfg.passwordError() != null) {
            out.put("ok", false);
            out.put("error", cfg.passwordError());
            return out;
        }
        BindType type = switch (cfg.bindMode()) { case "tx", "tx_rx" -> BindType.BIND_TX; default -> BindType.BIND_TRX; };
        long started = System.currentTimeMillis();
        SMPPSession s = new SMPPSession(cfg.useTls() ? new NoTrustSSLSocketConnectionFactory() : SocketConnectionFactory.getInstance());
        try {
            String systemId = s.connectAndBind(cfg.host(), cfg.port(), new BindParameter(type, cfg.systemId(), cfg.password(), cfg.systemType(),
                TypeOfNumber.UNKNOWN, NumberingPlanIndicator.UNKNOWN, null, "5.0".equals(cfg.interfaceVersion()) ? InterfaceVersion.IF_50 : InterfaceVersion.IF_34), 15_000);
            out.put("ok", true);
            out.put("smsc_system_id", systemId);
        } catch (Exception e) {
            out.put("ok", false);
            out.put("error", e.getMessage());
        } finally {
            try { s.unbindAndClose(); } catch (Exception ignored) { /* best effort */ }
        }
        out.put("elapsed_ms", System.currentTimeMillis() - started);
        out.put("bind_type", type.name());
        return out;
    }

    private boolean authorized(HttpExchange ex) {
        if (token.length == 0) return false; // no token configured: refuse everything but /health
        String h = ex.getRequestHeaders().getFirst("Authorization");
        if (h == null || !h.startsWith("Bearer ")) return false;
        return MessageDigest.isEqual(token, h.substring(7).trim().getBytes(StandardCharsets.UTF_8));
    }

    private static JsonNode readBody(HttpExchange ex) throws IOException {
        try (InputStream in = ex.getRequestBody()) {
            byte[] raw = in.readNBytes(MAX_BODY + 1);
            if (raw.length > MAX_BODY) throw new IllegalArgumentException("body too large");
            if (raw.length == 0) return JSON.createObjectNode();
            return JSON.readTree(raw);
        }
    }

    private static void send(HttpExchange ex, int status, Object body) throws IOException {
        byte[] out = JSON.writeValueAsBytes(body);
        ex.getResponseHeaders().set("Content-Type", "application/json; charset=utf-8");
        ex.sendResponseHeaders(status, out.length);
        ex.getResponseBody().write(out);
        ex.close();
    }
}
