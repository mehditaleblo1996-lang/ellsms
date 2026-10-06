package ir.ellsms.smppbridge;

import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import java.net.InetAddress;
import java.util.UUID;
import java.util.concurrent.Executors;
import java.util.concurrent.ScheduledExecutorService;
import java.util.concurrent.TimeUnit;

/**
 * ELLSMS SMPP bridge (#46, docs/smpp-gateway.md).
 *
 * Environment:
 *   BACKEND_DB_HOST/PORT/NAME/USER/PASS   the shared ELLSMS database (configuration, events, session state)
 *   SMS_GATEWAY_MASTER_KEY                to decrypt the stored SMPP passwords (same key as PHP)
 *   SMPP_BRIDGE_TOKEN                     bearer token PHP must present (required)
 *   SMPP_BRIDGE_PORT                      HTTP port (default 8090)
 *   SMPP_BRIDGE_RELOAD_SECONDS            how often configuration is re-read (default 10)
 *   SMPP_SIMULATOR_PORT                   start the built-in test SMSC on this port (dev/test only)
 */
public final class Main {
    private static final Logger LOG = LoggerFactory.getLogger(Main.class);

    public static void main(String[] args) throws Exception {
        String token = env("SMPP_BRIDGE_TOKEN", "");
        if (token.length() < 16) {
            LOG.error("SMPP_BRIDGE_TOKEN must be set (>= 16 chars); the API refuses every request without it");
        }
        SecretVault vault = new SecretVault(env("SMS_GATEWAY_MASTER_KEY", ""));
        if (!vault.configured()) LOG.error("SMS_GATEWAY_MASTER_KEY is not set: SMPP passwords cannot be decrypted, no gateway will bind");

        Store store = new Store(env("BACKEND_DB_HOST", "127.0.0.1"), Integer.parseInt(env("BACKEND_DB_PORT", "3306")),
            env("BACKEND_DB_NAME", "ellsms"), env("BACKEND_DB_USER", "root"), env("BACKEND_DB_PASS", ""), vault);
        String bridgeId = InetAddress.getLocalHost().getHostName() + ":" + UUID.randomUUID().toString().substring(0, 8);
        GatewayManager manager = new GatewayManager(store, bridgeId);

        Simulator simulator = null;
        String simPort = env("SMPP_SIMULATOR_PORT", "");
        if (!simPort.isEmpty()) simulator = new Simulator(Integer.parseInt(simPort), env("SMPP_SIMULATOR_PASSWORD", ""));

        HttpApi api = new HttpApi(manager, store, token, simulator);
        api.start(Integer.parseInt(env("SMPP_BRIDGE_PORT", "8090")));

        int reload = Math.max(2, Integer.parseInt(env("SMPP_BRIDGE_RELOAD_SECONDS", "10")));
        ScheduledExecutorService timers = Executors.newScheduledThreadPool(2);
        timers.scheduleWithFixedDelay(guard(manager::reload), 0, reload, TimeUnit.SECONDS);
        timers.scheduleWithFixedDelay(guard(manager::publishState), 3, 5, TimeUnit.SECONDS);
        timers.scheduleWithFixedDelay(guard(manager::housekeeping), 30, 30, TimeUnit.SECONDS);

        Simulator sim = simulator;
        Runtime.getRuntime().addShutdownHook(new Thread(() -> {
            LOG.info("shutting down: unbinding every session");
            timers.shutdownNow();
            api.stop();
            manager.stopAll();
            manager.publishState();
            if (sim != null) try { sim.close(); } catch (Exception ignored) { /* exiting */ }
            store.close();
        }));
        LOG.info("smpp-bridge {} started", bridgeId);
        Thread.currentThread().join();
    }

    private static Runnable guard(Runnable r) {
        return () -> {
            try {
                r.run();
            } catch (Throwable t) {
                LOG.error("background task failed", t);
            }
        };
    }

    static String env(String name, String def) {
        String v = System.getenv(name);
        return v == null || v.isEmpty() ? def : v;
    }
}
