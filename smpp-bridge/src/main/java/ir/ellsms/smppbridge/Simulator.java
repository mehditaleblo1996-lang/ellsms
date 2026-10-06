package ir.ellsms.smppbridge;

import org.jsmpp.bean.BroadcastSm;
import org.jsmpp.bean.CancelBroadcastSm;
import org.jsmpp.bean.CancelSm;
import org.jsmpp.bean.DataSm;
import org.jsmpp.bean.ESMClass;
import org.jsmpp.bean.GeneralDataCoding;
import org.jsmpp.bean.NumberingPlanIndicator;
import org.jsmpp.bean.QueryBroadcastSm;
import org.jsmpp.bean.QuerySm;
import org.jsmpp.bean.RawDataCoding;
import org.jsmpp.bean.RegisteredDelivery;
import org.jsmpp.bean.ReplaceSm;
import org.jsmpp.bean.SubmitMulti;
import org.jsmpp.bean.SubmitSm;
import org.jsmpp.bean.TypeOfNumber;
import org.jsmpp.bean.BindType;
import org.jsmpp.extra.ProcessRequestException;
import org.jsmpp.session.BindRequest;
import org.jsmpp.session.BroadcastSmResult;
import org.jsmpp.session.DataSmResult;
import org.jsmpp.session.QueryBroadcastSmResult;
import org.jsmpp.session.QuerySmResult;
import org.jsmpp.session.SMPPServerSession;
import org.jsmpp.session.SMPPServerSessionListener;
import org.jsmpp.session.ServerMessageReceiverListener;
import org.jsmpp.session.Session;
import org.jsmpp.session.SubmitMultiResult;
import org.jsmpp.session.SubmitSmResult;
import org.jsmpp.util.MessageId;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import java.nio.charset.StandardCharsets;
import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.List;
import java.util.concurrent.CopyOnWriteArrayList;
import java.util.concurrent.Executors;
import java.util.concurrent.ScheduledExecutorService;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicLong;

/**
 * A tiny SMSC for development and tests (SMPP_SIMULATOR_PORT). Accepts any bind (or only
 * SMPP_SIMULATOR_PASSWORD when set), answers submit_sm with a decimal id, and a second later sends a
 * delivery receipt to a bound TRX/RX session: DELIVRD, or UNDELIV when the destination ends in "0000".
 * A message whose text is "THROTTLE" is refused once with ESME_RTHROTTLED, to exercise the retry path.
 * MOs can be injected through POST /v1/simulator/mo. NEVER enable it in production.
 */
public final class Simulator implements ServerMessageReceiverListener {
    private static final Logger LOG = LoggerFactory.getLogger(Simulator.class);
    private final SMPPServerSessionListener listener;
    private final String password;
    private final List<SMPPServerSession> receivers = new CopyOnWriteArrayList<>();
    private final AtomicLong ids = new AtomicLong(100_000);
    private final ScheduledExecutorService scheduler = Executors.newScheduledThreadPool(2);
    private volatile boolean throttledOnce = false;
    public final AtomicLong submits = new AtomicLong();

    public Simulator(int port, String password) throws Exception {
        this.listener = new SMPPServerSessionListener(port);
        this.password = password;
        this.listener.setMessageReceiverListener(this);
        Thread.ofPlatform().name("smsc-simulator").daemon().start(this::acceptLoop);
        LOG.warn("SMSC SIMULATOR listening on :{} — development/testing only", port);
    }

    private void acceptLoop() {
        while (true) {
            try {
                SMPPServerSession session = listener.accept();
                Thread.ofVirtual().start(() -> {
                    try {
                        BindRequest req = session.waitForBind(10_000);
                        if (password != null && !password.isEmpty() && !password.equals(req.getPassword())) {
                            req.reject(0x0E); // ESME_RINVPASWD
                            return;
                        }
                        req.accept("SIMSMSC");
                        if (req.getBindType() != BindType.BIND_TX) receivers.add(session);
                    } catch (Exception e) {
                        LOG.debug("simulator bind failed: {}", e.getMessage());
                    }
                });
            } catch (Exception e) {
                return;
            }
        }
    }

    @Override
    public SubmitSmResult onAcceptSubmitSm(SubmitSm submitSm, SMPPServerSession source) throws ProcessRequestException {
        String text = new String(submitSm.getShortMessage(), StandardCharsets.ISO_8859_1);
        if (text.equals("THROTTLE") && !throttledOnce) {
            throttledOnce = true;
            throw new ProcessRequestException("throttled", 0x58);
        }
        submits.incrementAndGet();
        String id = String.valueOf(ids.incrementAndGet());
        if ((submitSm.getRegisteredDelivery() & 0x03) != 0) {
            String dest = submitSm.getDestAddress();
            String stat = dest.endsWith("0000") ? "UNDELIV" : "DELIVRD";
            scheduler.schedule(() -> sendReceipt(source, id, dest, submitSm.getSourceAddr(), stat), 1, TimeUnit.SECONDS);
        }
        try {
            return new SubmitSmResult(new MessageId(id), null);
        } catch (Exception e) {
            throw new ProcessRequestException(e.getMessage(), 0x08);
        }
    }

    private void sendReceipt(SMPPServerSession submitter, String id, String from, String to, String stat) {
        String now = new SimpleDateFormat("yyMMddHHmm").format(new Date());
        String receipt = "id:" + id + " sub:001 dlvrd:" + (stat.equals("DELIVRD") ? "001" : "000") + " submit date:" + now
            + " done date:" + now + " stat:" + stat + " err:000 text:";
        SMPPServerSession target = submitter.getSessionState().isReceivable() ? submitter
            : receivers.stream().filter(s -> s.getSessionState().isReceivable()).findFirst().orElse(null);
        if (target == null) return;
        try {
            target.deliverShortMessage(null, TypeOfNumber.INTERNATIONAL, NumberingPlanIndicator.ISDN, from,
                TypeOfNumber.UNKNOWN, NumberingPlanIndicator.UNKNOWN, to, new ESMClass(0x04), (byte) 0, (byte) 0,
                new RegisteredDelivery(0), GeneralDataCoding.DEFAULT, receipt.getBytes(StandardCharsets.ISO_8859_1));
        } catch (Exception e) {
            LOG.warn("simulator receipt failed: {}", e.getMessage());
        }
    }

    /** Sends a (UCS-2) MO to every bound receiving session; returns how many accepted it. */
    public int sendMo(String from, String to, String text) {
        int n = 0;
        for (SMPPServerSession s : receivers) {
            if (!s.getSessionState().isReceivable()) continue;
            try {
                s.deliverShortMessage(null, TypeOfNumber.INTERNATIONAL, NumberingPlanIndicator.ISDN, from,
                    TypeOfNumber.UNKNOWN, NumberingPlanIndicator.UNKNOWN, to, new ESMClass(0), (byte) 0, (byte) 0,
                    new RegisteredDelivery(0), new RawDataCoding((byte) 0x08), text.getBytes(StandardCharsets.UTF_16BE));
                n++;
                break;
            } catch (Exception e) {
                LOG.warn("simulator MO failed: {}", e.getMessage());
            }
        }
        return n;
    }

    public void close() throws Exception {
        scheduler.shutdownNow();
        listener.close();
    }

    @Override public SubmitMultiResult onAcceptSubmitMulti(SubmitMulti s, SMPPServerSession src) throws ProcessRequestException { throw new ProcessRequestException("unsupported", 0x03); }
    @Override public QuerySmResult onAcceptQuerySm(QuerySm q, SMPPServerSession src) throws ProcessRequestException { throw new ProcessRequestException("unsupported", 0x03); }
    @Override public void onAcceptReplaceSm(ReplaceSm r, SMPPServerSession src) throws ProcessRequestException { throw new ProcessRequestException("unsupported", 0x03); }
    @Override public void onAcceptCancelSm(CancelSm c, SMPPServerSession src) throws ProcessRequestException { throw new ProcessRequestException("unsupported", 0x03); }
    @Override public BroadcastSmResult onAcceptBroadcastSm(BroadcastSm b, SMPPServerSession src) throws ProcessRequestException { throw new ProcessRequestException("unsupported", 0x03); }
    @Override public void onAcceptCancelBroadcastSm(CancelBroadcastSm c, SMPPServerSession src) throws ProcessRequestException { throw new ProcessRequestException("unsupported", 0x03); }
    @Override public QueryBroadcastSmResult onAcceptQueryBroadcastSm(QueryBroadcastSm q, SMPPServerSession src) throws ProcessRequestException { throw new ProcessRequestException("unsupported", 0x03); }
    @Override public DataSmResult onAcceptDataSm(DataSm d, Session src) throws ProcessRequestException { throw new ProcessRequestException("unsupported", 0x03); }
}
