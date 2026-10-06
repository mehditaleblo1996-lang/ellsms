package ir.ellsms.smppbridge;

/**
 * Token bucket: {@code tps} tokens per second, burst of one second's worth. One per gateway, shared by
 * all its sessions, because the operator's TPS limit is per account, not per connection.
 * {@link #pause} empties the bucket for a while — used when the SMSC answers ESME_RTHROTTLED.
 */
public final class RateLimiter {
    private final double ratePerNano;
    private final double capacity;
    private double tokens;
    private long last = System.nanoTime();
    private long pausedUntil = 0;

    public RateLimiter(int tps) {
        this.capacity = Math.max(1, tps);
        this.ratePerNano = Math.max(1, tps) / 1_000_000_000.0;
        this.tokens = capacity;
    }

    /** Blocks until a token is available or the deadline passes; true when a token was taken. */
    public boolean acquire(long timeoutMs) throws InterruptedException {
        long deadline = System.nanoTime() + timeoutMs * 1_000_000L;
        while (true) {
            long waitNanos;
            synchronized (this) {
                long now = System.nanoTime();
                if (now >= pausedUntil) {
                    tokens = Math.min(capacity, tokens + (now - last) * ratePerNano);
                    last = now;
                    if (tokens >= 1) {
                        tokens -= 1;
                        return true;
                    }
                    waitNanos = (long) ((1 - tokens) / ratePerNano);
                } else {
                    last = now;
                    waitNanos = pausedUntil - now;
                }
                if (now + waitNanos > deadline) return false;
            }
            Thread.sleep(Math.max(1, waitNanos / 1_000_000L), (int) (waitNanos % 1_000_000L));
        }
    }

    public synchronized void pause(long millis) {
        pausedUntil = Math.max(pausedUntil, System.nanoTime() + millis * 1_000_000L);
        tokens = 0;
    }
}
