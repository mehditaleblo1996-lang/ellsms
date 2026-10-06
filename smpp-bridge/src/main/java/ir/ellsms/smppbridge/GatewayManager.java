package ir.ellsms.smppbridge;

import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import java.util.ArrayList;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.concurrent.ConcurrentHashMap;

/**
 * Keeps the running gateways in line with the database: every {@code reload} it re-reads the SMPP
 * gateways, starts new ones, rebuilds those whose configuration signature changed (a panel edit bumps
 * config_version), and stops removed/archived ones. Also publishes session state for the panel.
 */
public final class GatewayManager {
    private static final Logger LOG = LoggerFactory.getLogger(GatewayManager.class);
    private final Store store;
    private final String bridgeId;
    private final Map<Integer, GatewayRuntime> runtimes = new ConcurrentHashMap<>();
    private final Map<Integer, String> signatures = new HashMap<>();
    private volatile String lastLoadError;

    public GatewayManager(Store store, String bridgeId) {
        this.store = store;
        this.bridgeId = bridgeId;
    }

    public synchronized void reload() {
        List<GatewayConfig> configs;
        try {
            configs = store.loadGateways();
            lastLoadError = null;
        } catch (Exception e) {
            lastLoadError = e.getMessage();
            LOG.warn("could not load gateway configuration: {}", e.getMessage());
            return;
        }
        Map<Integer, GatewayConfig> wanted = new HashMap<>();
        for (GatewayConfig c : configs) wanted.put(c.gatewayId(), c);

        for (Integer id : new ArrayList<>(runtimes.keySet())) {
            GatewayConfig c = wanted.get(id);
            if (c == null || !c.signature().equals(signatures.get(id))) {
                GatewayRuntime old = runtimes.remove(id);
                signatures.remove(id);
                if (old != null) {
                    LOG.info("gateway {} {} — stopping its sessions", old.config().code(), c == null ? "removed/archived" : "reconfigured");
                    old.stop();
                }
            }
        }
        for (GatewayConfig c : configs) {
            if (!runtimes.containsKey(c.gatewayId())) {
                GatewayRuntime rt = new GatewayRuntime(c, store);
                runtimes.put(c.gatewayId(), rt);
                signatures.put(c.gatewayId(), c.signature());
                LOG.info("gateway {} (#{}) v{} starting: {} x{} to {}:{}", c.code(), c.gatewayId(), c.configVersion(), c.bindMode(), c.sessionCount(), c.host(), c.port());
                rt.start();
            } else if (runtimes.get(c.gatewayId()).config().sendEnabled() != c.sendEnabled()
                || runtimes.get(c.gatewayId()).config().tps() != c.tps()) {
                // Non-session settings: rebuild without waiting for a signature change.
                runtimes.remove(c.gatewayId()).stop();
                GatewayRuntime rt = new GatewayRuntime(c, store);
                runtimes.put(c.gatewayId(), rt);
                signatures.put(c.gatewayId(), c.signature());
                rt.start();
            }
        }
    }

    public GatewayRuntime get(int gatewayId) {
        return runtimes.get(gatewayId);
    }

    public List<GatewayRuntime> all() {
        return new ArrayList<>(runtimes.values());
    }

    public String lastLoadError() {
        return lastLoadError;
    }

    public void publishState() {
        List<SessionWorker.Snapshot> snaps = new ArrayList<>();
        for (GatewayRuntime rt : runtimes.values()) snaps.addAll(rt.snapshots());
        try {
            store.upsertSessions(snaps, bridgeId);
            store.deleteSessionsExcept(snaps);
        } catch (Exception e) {
            LOG.warn("could not publish session state: {}", e.getMessage());
        }
    }

    public void housekeeping() {
        runtimes.values().forEach(GatewayRuntime::housekeeping);
    }

    public void stopAll() {
        runtimes.values().forEach(GatewayRuntime::stop);
        runtimes.clear();
    }
}
