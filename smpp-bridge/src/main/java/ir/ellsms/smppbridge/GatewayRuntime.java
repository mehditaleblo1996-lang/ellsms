package ir.ellsms.smppbridge;

import org.jsmpp.bean.AlertNotification;
import org.jsmpp.bean.BindType;
import org.jsmpp.bean.DataSm;
import org.jsmpp.bean.DeliverSm;
import org.jsmpp.bean.ESMClass;
import org.jsmpp.bean.NumberingPlanIndicator;
import org.jsmpp.bean.OptionalParameter;
import org.jsmpp.bean.RawDataCoding;
import org.jsmpp.bean.RegisteredDelivery;
import org.jsmpp.bean.TypeOfNumber;
import org.jsmpp.extra.NegativeResponseException;
import org.jsmpp.extra.ProcessRequestException;
import org.jsmpp.extra.ResponseTimeoutException;
import org.jsmpp.session.DataSmResult;
import org.jsmpp.session.MessageReceiverListener;
import org.jsmpp.session.SMPPSession;
import org.jsmpp.session.Session;
import org.jsmpp.session.SubmitSmResult;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import java.nio.charset.StandardCharsets;
import java.time.Instant;
import java.time.LocalDateTime;
import java.time.ZoneId;
import java.time.format.DateTimeFormatter;
import java.util.ArrayList;
import java.util.Arrays;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.TreeMap;
import java.util.concurrent.ConcurrentHashMap;
import java.util.concurrent.ThreadLocalRandom;
import java.util.concurrent.atomic.AtomicInteger;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/**
 * All sessions of one SMPP gateway, the submit path, and the deliver_sm handler.
 *
 * Submit: encode → split → for each part take a rate token and a window slot on a bound TX-capable
 * session (round robin) → submit_sm. ESME_RTHROTTLED / ESME_RMSGQFUL pause the whole gateway and
 * retry the part (up to 3 times); any other negative response fails that recipient with its
 * command_status. A recipient whose FIRST part was accepted is reported as sent (later parts are
 * retried, never the whole message, so the handset is never sent the beginning twice).
 *
 * Deliver: a receipt is stored as a 'dlr' event, a user message as an 'mo' event (concatenated parts
 * are reassembled first). The event is written BEFORE deliver_sm_resp is returned; if the database is
 * down the bridge answers ESME_RSYSERR and the SMSC delivers it again later — nothing is lost.
 */
public final class GatewayRuntime implements MessageReceiverListener {
    private static final Logger LOG = LoggerFactory.getLogger(GatewayRuntime.class);
    static final int ESME_RTHROTTLED = 0x58;
    static final int ESME_RMSGQFUL = 0x14;
    static final int ESME_RSYSERR = 0x08;
    private static final Pattern RECEIPT_ID = Pattern.compile("(?i)\\bid:\\s*([^\\s]+)");
    private static final Pattern RECEIPT_STAT = Pattern.compile("(?i)\\bstat:\\s*([A-Z_]+)");
    private static final Pattern RECEIPT_ERR = Pattern.compile("(?i)\\berr:\\s*([0-9A-Za-z]+)");
    private static final Pattern RECEIPT_DONE = Pattern.compile("(?i)\\bdone\\s*date:\\s*(\\d{10,12})");

    private final GatewayConfig cfg;
    private final Store store;
    private final List<SessionWorker> sessions = new ArrayList<>();
    private final RateLimiter limiter;
    private final AtomicInteger roundRobin = new AtomicInteger();
    private final AtomicInteger concatRef = new AtomicInteger(ThreadLocalRandom.current().nextInt(256));
    /** Later-part message ids → first-part id, so a receipt for any part lands on the message. */
    private final Map<String, PartRef> partIds = new ConcurrentHashMap<>();
    /** Inbound concatenation buffers. */
    private final Map<String, Pending> pending = new ConcurrentHashMap<>();

    private record PartRef(String firstId, long at) {}

    private static final class Pending {
        final int total;
        final long startedAt = System.currentTimeMillis();
        final TreeMap<Integer, String> parts = new TreeMap<>();
        final String source;
        final String destination;
        Pending(int total, String source, String destination) {
            this.total = total;
            this.source = source;
            this.destination = destination;
        }
    }

    public GatewayRuntime(GatewayConfig cfg, Store store) {
        this.cfg = cfg;
        this.store = store;
        this.limiter = new RateLimiter(cfg.tps());
        int n = cfg.sessionCount();
        switch (cfg.bindMode()) {
            case "tx_rx" -> {
                for (int i = 1; i <= n; i++) sessions.add(new SessionWorker(cfg, "tx-" + i, BindType.BIND_TX, null));
                for (int i = 1; i <= n; i++) sessions.add(new SessionWorker(cfg, "rx-" + i, BindType.BIND_RX, this));
            }
            case "tx" -> {
                for (int i = 1; i <= n; i++) sessions.add(new SessionWorker(cfg, "tx-" + i, BindType.BIND_TX, null));
            }
            default -> {
                for (int i = 1; i <= n; i++) sessions.add(new SessionWorker(cfg, "trx-" + i, BindType.BIND_TRX, this));
            }
        }
    }

    public GatewayConfig config() { return cfg; }

    public void start() { sessions.forEach(SessionWorker::start); }

    public void stop() { sessions.forEach(SessionWorker::stop); }

    public void reconnect() { sessions.forEach(SessionWorker::reconnect); }

    public List<SessionWorker.Snapshot> snapshots() {
        return sessions.stream().map(SessionWorker::snapshot).toList();
    }

    public int boundSenders() {
        return (int) sessions.stream().filter(s -> s.canSubmit() && s.isBound()).count();
    }

    /* ======================= submit ======================= */

    public record Result(String recipient, boolean ok, String messageId, List<String> partIds, int parts,
                         String error, String errorClass, Integer commandStatus) {}

    public Result submit(String sender, String recipient, String text) {
        if (!cfg.sendEnabled()) return fail(recipient, 0, "sending is disabled for this gateway", "unavailable", null);
        Codec.Encoded enc = Codec.encode(text, cfg.dataCoding());
        boolean payload = "payload".equals(cfg.longMessage());
        List<byte[]> chunks = payload ? List.of(enc.bytes()) : Codec.split(enc);
        int total = chunks.size();
        int ref = concatRef.incrementAndGet() & 0xFFFF;
        String destination = formatDestination(recipient);
        List<String> ids = new ArrayList<>();
        for (int i = 0; i < total; i++) {
            byte[] shortMessage;
            byte esm = 0;
            List<OptionalParameter> tlvs = new ArrayList<>();
            byte[] chunk = chunks.get(i);
            if (payload) {
                shortMessage = new byte[0];
                tlvs.add(new OptionalParameter.OctetString(OptionalParameter.Tag.MESSAGE_PAYLOAD.code(), chunk));
            } else if (total > 1 && "sar".equals(cfg.longMessage())) {
                shortMessage = chunk;
                tlvs.add(new OptionalParameter.Short(OptionalParameter.Tag.SAR_MSG_REF_NUM, (short) ref));
                tlvs.add(new OptionalParameter.Byte(OptionalParameter.Tag.SAR_TOTAL_SEGMENTS, (byte) total));
                tlvs.add(new OptionalParameter.Byte(OptionalParameter.Tag.SAR_SEGMENT_SEQNUM, (byte) (i + 1)));
            } else if (total > 1) {
                shortMessage = Codec.withUdh(chunk, ref & 0xFF, total, i + 1);
                esm = 0x40;
            } else {
                shortMessage = chunk;
            }
            Attempt a = submitPart(sender, destination, shortMessage, enc.dataCoding(), esm, tlvs.toArray(new OptionalParameter[0]));
            if (!a.ok()) {
                if (ids.isEmpty()) return fail(recipient, total, a.error(), a.errorClass(), a.commandStatus());
                // The beginning is already on its way to the handset: report sent, keep going with the rest.
                LOG.warn("gateway {} part {}/{} to {} failed after first part was accepted: {}", cfg.code(), i + 1, total, recipient, a.error());
                continue;
            }
            ids.add(a.messageId());
        }
        String first = ids.get(0);
        for (int i = 1; i < ids.size(); i++) partIds.put(ids.get(i), new PartRef(first, System.currentTimeMillis()));
        return new Result(recipient, true, first, ids, total, ids.size() < total ? "partially submitted" : null, null, null);
    }

    private record Attempt(boolean ok, String messageId, String error, String errorClass, Integer commandStatus) {}

    private Attempt submitPart(String sender, String destination, byte[] shortMessage, byte dataCoding, byte esm, OptionalParameter[] tlvs) {
        long timeout = cfg.submitTimeoutMs() + 5_000L;
        for (int attempt = 1; attempt <= 4; attempt++) {
            SessionWorker w = pickSender();
            if (w == null) return new Attempt(false, null, "no bound SMPP session", "unavailable", null);
            try {
                if (!limiter.acquire(timeout)) return new Attempt(false, null, "rate limit wait timed out", "unavailable", null);
                if (!w.acquireWindow(timeout)) return new Attempt(false, null, "window full (no submit_sm_resp in time)", "timeout", null);
            } catch (InterruptedException e) {
                Thread.currentThread().interrupt();
                return new Attempt(false, null, "interrupted", "unavailable", null);
            }
            SMPPSession s = w.session();
            try {
                if (s == null) continue;
                w.submitted.incrementAndGet();
                SubmitSmResult r = s.submitShortMessage(null,
                    TypeOfNumber.valueOf((byte) cfg.sourceTon()), NumberingPlanIndicator.valueOf((byte) cfg.sourceNpi()), sender,
                    TypeOfNumber.valueOf((byte) cfg.destTon()), NumberingPlanIndicator.valueOf((byte) cfg.destNpi()), destination,
                    new ESMClass(esm), (byte) 0, (byte) 1, null, validity(),
                    new RegisteredDelivery((byte) cfg.registeredDelivery()), (byte) 0, new RawDataCoding(dataCoding), (byte) 0,
                    shortMessage, tlvs);
                w.submitOk.incrementAndGet();
                return new Attempt(true, r.getMessageId(), null, null, null);
            } catch (NegativeResponseException e) {
                int status = e.getCommandStatus();
                if (status == ESME_RTHROTTLED || status == ESME_RMSGQFUL) {
                    w.throttled.incrementAndGet();
                    limiter.pause(500L * attempt);
                    if (attempt < 4) continue;
                    w.submitFailed.incrementAndGet();
                    return new Attempt(false, null, "SMSC throttled (0x" + Integer.toHexString(status) + ")", "unavailable", status);
                }
                w.submitFailed.incrementAndGet();
                w.recordError("submit_sm rejected 0x" + Integer.toHexString(status));
                return new Attempt(false, null, "SMSC rejected the message (command_status 0x" + Integer.toHexString(status) + ")", classify(status), status);
            } catch (ResponseTimeoutException e) {
                w.submitFailed.incrementAndGet();
                w.recordError("submit_sm timeout");
                return new Attempt(false, null, "no submit_sm_resp within " + cfg.submitTimeoutMs() + " ms", "timeout", null);
            } catch (Exception e) {
                // I/O or state error: the session is broken; let the worker reconnect and try another one.
                w.submitFailed.incrementAndGet();
                w.recordError("submit_sm failed: " + e.getMessage());
                w.reconnect();
                if (attempt < 4) continue;
                return new Attempt(false, null, "SMPP session error: " + e.getMessage(), "unavailable", null);
            } finally {
                w.releaseWindow();
            }
        }
        return new Attempt(false, null, "no bound SMPP session", "unavailable", null);
    }

    /** Destination/source problems are the message's fault; everything else may succeed on retry. */
    static String classify(int status) {
        return switch (status) {
            case 0x0A, 0x0B, 0x01, 0x02, 0x48, 0x49, 0x50, 0x51, 0x61, 0x62, 0x63, 0x66, 0xC0, 0xC1, 0xC2, 0xC3, 0xC4 -> "rejected";
            case ESME_RSYSERR, 0x45, 0x58, 0x14 -> "unavailable";
            default -> "rejected";
        };
    }

    private SessionWorker pickSender() {
        List<SessionWorker> bound = sessions.stream().filter(s -> s.canSubmit() && s.isBound()).toList();
        if (bound.isEmpty()) return null;
        return bound.get(Math.floorMod(roundRobin.getAndIncrement(), bound.size()));
    }

    private String validity() {
        if (cfg.validityMinutes() <= 0) return null;
        // Relative time format: YYMMDDhhmmss000R
        long m = cfg.validityMinutes();
        long days = m / 1440, hours = (m % 1440) / 60, mins = m % 60;
        return String.format("0000%02d%02d%02d00000R", Math.min(days, 99), hours, mins);
    }

    String formatDestination(String recipient) {
        String digits = recipient.replaceAll("\\D", "");
        return switch (cfg.destinationFormat()) {
            case "national" -> digits.startsWith("98") ? "0" + digits.substring(2) : digits;
            case "as_is" -> recipient;
            default -> digits;
        };
    }

    private static Result fail(String recipient, int parts, String error, String errorClass, Integer status) {
        return new Result(recipient, false, null, List.of(), parts, error, errorClass, status);
    }

    /* ======================= deliver_sm ======================= */

    @Override
    public void onAcceptDeliverSm(DeliverSm sm) throws ProcessRequestException {
        try {
            if (sm.isSmscDeliveryReceipt() || sm.getOptionalParameter(OptionalParameter.Tag.RECEIPTED_MESSAGE_ID) != null) {
                handleReceipt(sm);
            } else if (cfg.receiveEnabled()) {
                handleMo(sm);
            }
        } catch (ProcessRequestException e) {
            throw e;
        } catch (Exception e) {
            LOG.error("gateway {} could not store deliver_sm: {}", cfg.code(), e.getMessage());
            // Not acknowledged: the SMSC keeps it and delivers it again.
            throw new ProcessRequestException("could not store: " + e.getMessage(), ESME_RSYSERR, e);
        }
    }

    record Receipt(String id, String stat, String err, Instant doneAt) {}

    /** Text receipt ("id:… stat:… err:… done date:…") with the TLVs taking precedence when present. */
    static Receipt parseReceipt(byte[] shortMessage, String tlvId, Integer tlvState) {
        String text = shortMessage == null ? "" : new String(shortMessage, StandardCharsets.ISO_8859_1);
        String id = tlvId;
        if (id == null || id.isBlank()) {
            Matcher m = RECEIPT_ID.matcher(text);
            id = m.find() ? m.group(1) : null;
        }
        String stat = null;
        if (tlvState != null) {
            stat = switch (tlvState) {
                case 1 -> "ENROUTE"; case 2 -> "DELIVRD"; case 3 -> "EXPIRED"; case 4 -> "DELETED";
                case 5 -> "UNDELIV"; case 6 -> "ACCEPTD"; case 7 -> "UNKNOWN"; case 8 -> "REJECTD";
                default -> null;
            };
        }
        if (stat == null) {
            Matcher m = RECEIPT_STAT.matcher(text);
            stat = m.find() ? m.group(1).toUpperCase() : "UNKNOWN";
        }
        Matcher e = RECEIPT_ERR.matcher(text);
        String err = e.find() ? e.group(1) : null;
        Instant done = null;
        Matcher d = RECEIPT_DONE.matcher(text);
        if (d.find()) {
            String v = d.group(1);
            try {
                DateTimeFormatter f = DateTimeFormatter.ofPattern(v.length() == 12 ? "yyMMddHHmmss" : "yyMMddHHmm");
                done = LocalDateTime.parse(v, f).atZone(ZoneId.systemDefault()).toInstant();
            } catch (Exception ignored) {
                // a malformed date never fails the receipt
            }
        }
        return new Receipt(id == null ? null : id.replace("\u0000", "").trim(), stat, err, done);
    }

    private void handleReceipt(DeliverSm sm) throws Exception {
        OptionalParameter idTlv = sm.getOptionalParameter(OptionalParameter.Tag.RECEIPTED_MESSAGE_ID);
        OptionalParameter stTlv = sm.getOptionalParameter(OptionalParameter.Tag.MESSAGE_STATE);
        String tlvId = idTlv instanceof OptionalParameter.OctetString os ? os.getValueAsString() : null;
        Integer tlvState = stTlv instanceof OptionalParameter.Byte b ? (int) b.getValue() : null;
        Receipt r = parseReceipt(sm.getShortMessage(), tlvId, tlvState);
        if (r.id() == null || r.id().isEmpty()) {
            LOG.warn("gateway {} receipt without a message id ignored", cfg.code());
            return;
        }
        PartRef first = partIds.get(r.id());
        store.insertDlr(cfg.gatewayId(), first != null ? first.firstId() : r.id(), r.stat(), r.err(), r.doneAt());
        sessionFor(sm).ifPresent(w -> w.dlrReceived.incrementAndGet());
    }

    private void handleMo(DeliverSm sm) throws Exception {
        byte[] msg = sm.getShortMessage();
        OptionalParameter payload = sm.getOptionalParameter(OptionalParameter.Tag.MESSAGE_PAYLOAD);
        if ((msg == null || msg.length == 0) && payload instanceof OptionalParameter.OctetString os) msg = os.getValue();
        String source = sm.getSourceAddr();
        String destination = sm.getDestAddress();
        int ref = -1, total = 1, seq = 1;
        byte[] body = msg == null ? new byte[0] : msg;
        if ((sm.getEsmClass() & 0x40) != 0) {
            Codec.Concat c = Codec.parseUdh(body);
            if (c != null) {
                body = Arrays.copyOfRange(body, Math.min(c.headerLength(), body.length), body.length);
                ref = c.ref(); total = c.total(); seq = c.seq();
            }
        } else if (sm.getOptionalParameter(OptionalParameter.Tag.SAR_MSG_REF_NUM) instanceof OptionalParameter.Short r
            && sm.getOptionalParameter(OptionalParameter.Tag.SAR_TOTAL_SEGMENTS) instanceof OptionalParameter.Byte t
            && sm.getOptionalParameter(OptionalParameter.Tag.SAR_SEGMENT_SEQNUM) instanceof OptionalParameter.Byte s) {
            ref = r.getValue() & 0xFFFF; total = t.getValue() & 0xFF; seq = s.getValue() & 0xFF;
        }
        String text = Codec.decode(body, sm.getDataCoding());
        sessionFor(sm).ifPresent(w -> w.moReceived.incrementAndGet());
        if (ref < 0 || total <= 1) {
            store.insertMo(cfg.gatewayId(), source, destination, text);
            return;
        }
        final int parts = Math.max(1, Math.min(255, total));
        String key = source + "|" + destination + "|" + ref + "|" + parts;
        Pending p = pending.computeIfAbsent(key, k -> new Pending(parts, source, destination));
        String complete = null;
        synchronized (p) {
            p.parts.put(seq, text);
            if (p.parts.size() >= p.total) complete = String.join("", p.parts.values());
        }
        if (complete != null) {
            store.insertMo(cfg.gatewayId(), source, destination, complete);
            pending.remove(key);
        }
    }

    /** Flushes concatenated MOs whose missing parts never came, and forgets old part-id mappings. */
    public void housekeeping() {
        long now = System.currentTimeMillis();
        for (Map.Entry<String, Pending> e : pending.entrySet()) {
            Pending p = e.getValue();
            if (now - p.startedAt > 180_000) {
                String text;
                synchronized (p) {
                    text = String.join("", p.parts.values());
                }
                try {
                    store.insertMo(cfg.gatewayId(), p.source, p.destination, text);
                    pending.remove(e.getKey());
                } catch (Exception ex) {
                    LOG.warn("gateway {} could not store incomplete MO: {}", cfg.code(), ex.getMessage());
                }
            }
        }
        long cutoff = now - 72L * 3600 * 1000;
        partIds.entrySet().removeIf(e -> e.getValue().at() < cutoff);
        if (partIds.size() > 2_000_000) partIds.clear();
    }

    private java.util.Optional<SessionWorker> sessionFor(DeliverSm sm) {
        return sessions.stream().filter(s -> !s.canSubmit() || s.bindType() == BindType.BIND_TRX).findFirst();
    }

    @Override
    public void onAcceptAlertNotification(AlertNotification alertNotification) {
        // not used
    }

    @Override
    public DataSmResult onAcceptDataSm(DataSm dataSm, Session source) throws ProcessRequestException {
        throw new ProcessRequestException("data_sm not supported", 0x03);
    }

    public Map<String, Object> describe() {
        Map<String, Object> m = new LinkedHashMap<>();
        m.put("gateway_id", cfg.gatewayId());
        m.put("code", cfg.code());
        m.put("config_version", cfg.configVersion());
        m.put("bind_mode", cfg.bindMode());
        m.put("bound_senders", boundSenders());
        m.put("password_error", cfg.passwordError());
        m.put("sessions", snapshots());
        return m;
    }
}
