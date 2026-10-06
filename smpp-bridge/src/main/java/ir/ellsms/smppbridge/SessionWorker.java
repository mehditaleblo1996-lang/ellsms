package ir.ellsms.smppbridge;

import org.jsmpp.bean.BindType;
import org.jsmpp.bean.InterfaceVersion;
import org.jsmpp.bean.NumberingPlanIndicator;
import org.jsmpp.bean.TypeOfNumber;
import org.jsmpp.extra.SessionState;
import org.jsmpp.session.BindParameter;
import org.jsmpp.session.MessageReceiverListener;
import org.jsmpp.session.SMPPSession;
import org.jsmpp.session.connection.ConnectionFactory;
import org.jsmpp.session.connection.socket.NoTrustSSLSocketConnectionFactory;
import org.jsmpp.session.connection.socket.SocketConnectionFactory;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import java.time.Instant;
import java.util.concurrent.Semaphore;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicInteger;
import java.util.concurrent.atomic.AtomicLong;

/**
 * One SMPP session (TRX, TX or RX) of one gateway, kept bound by its own thread: connect, bind,
 * watch the state, and on any loss reconnect with a backoff (reconnect_delay_s doubling up to 60 s).
 * enquire_link keep-alives are sent by jSMPP itself at enquire_link_s.
 *
 * The window (outstanding submit_sm) is a semaphore taken by {@link #acquireWindow}; counters feed
 * the panel's monitoring card through {@link #snapshot()}.
 */
public final class SessionWorker implements Runnable {
    private static final Logger LOG = LoggerFactory.getLogger(SessionWorker.class);

    public record Snapshot(int gatewayId, String key, String bindType, String state, Instant boundSince,
                           String lastError, Instant lastErrorAt, long submitted, long submitOk, long submitFailed,
                           long throttled, long dlrReceived, long moReceived, int inFlight, int reconnects, int configVersion) {}

    private final GatewayConfig cfg;
    private final String key;
    private final BindType bindType;
    private final MessageReceiverListener receiver;
    private final Semaphore window;
    private volatile boolean running = true;
    private volatile SMPPSession session;
    private volatile String state = "CONNECTING";
    private volatile Instant boundSince;
    private volatile String lastError;
    private volatile Instant lastErrorAt;
    private Thread thread;

    final AtomicLong submitted = new AtomicLong();
    final AtomicLong submitOk = new AtomicLong();
    final AtomicLong submitFailed = new AtomicLong();
    final AtomicLong throttled = new AtomicLong();
    final AtomicLong dlrReceived = new AtomicLong();
    final AtomicLong moReceived = new AtomicLong();
    private final AtomicInteger reconnects = new AtomicInteger();

    public SessionWorker(GatewayConfig cfg, String key, BindType bindType, MessageReceiverListener receiver) {
        this.cfg = cfg;
        this.key = key;
        this.bindType = bindType;
        this.receiver = receiver;
        this.window = new Semaphore(cfg.windowSize(), true);
    }

    public void start() {
        thread = Thread.ofPlatform().name("smpp-" + cfg.code() + "-" + key).daemon().start(this);
    }

    public String key() { return key; }
    public BindType bindType() { return bindType; }
    public boolean canSubmit() { return bindType == BindType.BIND_TX || bindType == BindType.BIND_TRX; }

    public boolean isBound() {
        SMPPSession s = session;
        return s != null && s.getSessionState().isBound();
    }

    public SMPPSession session() { return session; }

    public boolean acquireWindow(long timeoutMs) throws InterruptedException {
        return window.tryAcquire(timeoutMs, TimeUnit.MILLISECONDS);
    }

    public void releaseWindow() { window.release(); }

    void recordError(String error) {
        lastError = error;
        lastErrorAt = Instant.now();
    }

    @Override
    public void run() {
        int delay = cfg.reconnectDelayS();
        while (running) {
            try {
                if (!isBound()) {
                    state = "CONNECTING";
                    connect();
                    state = "BOUND";
                    boundSince = Instant.now();
                    delay = cfg.reconnectDelayS();
                    LOG.info("gateway {} session {} bound to {}:{}", cfg.code(), key, cfg.host(), cfg.port());
                }
                Thread.sleep(1000);
            } catch (InterruptedException e) {
                Thread.currentThread().interrupt();
                break;
            } catch (Exception e) {
                state = "DISCONNECTED";
                boundSince = null;
                recordError("bind failed: " + e.getMessage());
                LOG.warn("gateway {} session {} bind failed: {}", cfg.code(), key, e.getMessage());
                closeQuietly();
                try {
                    Thread.sleep(delay * 1000L);
                } catch (InterruptedException ie) {
                    Thread.currentThread().interrupt();
                    break;
                }
                delay = Math.min(60, delay * 2);
                reconnects.incrementAndGet();
            }
        }
        closeQuietly();
        state = "STOPPED";
    }

    private void connect() throws Exception {
        if (cfg.passwordError() != null) {
            throw new IllegalStateException(cfg.passwordError());
        }
        closeQuietly();
        ConnectionFactory factory = cfg.useTls() ? new NoTrustSSLSocketConnectionFactory() : SocketConnectionFactory.getInstance();
        SMPPSession s = new SMPPSession(factory);
        s.setEnquireLinkTimer(cfg.enquireLinkS() * 1000);
        s.setTransactionTimer(cfg.submitTimeoutMs());
        s.setPduProcessorDegree(Math.max(3, Math.min(20, cfg.windowSize())));
        if (receiver != null && bindType != BindType.BIND_TX) {
            s.setMessageReceiverListener(receiver);
        }
        s.addSessionStateListener((newState, oldState, source) -> {
            if (newState == SessionState.CLOSED && running) {
                state = "DISCONNECTED";
                boundSince = null;
                recordError("session closed by peer or network");
            }
        });
        InterfaceVersion iv = "5.0".equals(cfg.interfaceVersion()) ? InterfaceVersion.IF_50 : InterfaceVersion.IF_34;
        BindParameter bp = new BindParameter(bindType, cfg.systemId(), cfg.password(), cfg.systemType(),
            TypeOfNumber.UNKNOWN, NumberingPlanIndicator.UNKNOWN, null, iv);
        s.connectAndBind(cfg.host(), cfg.port(), bp, 15_000);
        session = s;
    }

    public void stop() {
        running = false;
        if (thread != null) thread.interrupt();
        closeQuietly();
    }

    /** Drops the current session; the worker thread reconnects right away. */
    public void reconnect() {
        closeQuietly();
    }

    private void closeQuietly() {
        SMPPSession s = session;
        session = null;
        if (s != null) {
            try {
                if (s.getSessionState().isBound()) s.unbindAndClose();
                else s.close();
            } catch (Exception ignored) {
                // closing a broken session must never take the worker down
            }
        }
    }

    public Snapshot snapshot() {
        String bt = switch (bindType) { case BIND_TX -> "tx"; case BIND_RX -> "rx"; default -> "trx"; };
        return new Snapshot(cfg.gatewayId(), key, bt, isBound() ? "BOUND" : state, boundSince, lastError, lastErrorAt,
            submitted.get(), submitOk.get(), submitFailed.get(), throttled.get(), dlrReceived.get(), moReceived.get(),
            cfg.windowSize() - window.availablePermits(), reconnects.get(), cfg.configVersion());
    }
}
