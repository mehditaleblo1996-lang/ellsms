package ir.ellsms.smppbridge;

import javax.crypto.Cipher;
import javax.crypto.Mac;
import javax.crypto.spec.GCMParameterSpec;
import javax.crypto.spec.SecretKeySpec;
import java.nio.charset.StandardCharsets;
import java.security.GeneralSecurityException;
import java.security.MessageDigest;
import java.util.HexFormat;

/**
 * Reads secrets written by PHP's gateway_secret_put() (app/Sms/GatewaySecrets.php):
 * key = HKDF-SHA256(SMS_GATEWAY_MASTER_KEY, length 32, info "ellsms.sms_gateway.secret.v1", empty salt),
 * cipher = AES-256-GCM with a 12-byte nonce and a separate 16-byte tag. The fingerprint check mirrors
 * gateway_secret_key_fingerprint() so a secret stored under a different master key is reported, not
 * mis-decrypted.
 */
public final class SecretVault {
    public static final String PURPOSE = "ellsms.sms_gateway.secret.v1";
    private final byte[] key;

    public SecretVault(String masterKey) {
        if (masterKey == null || masterKey.length() < 32) {
            this.key = null;
        } else {
            this.key = hkdfSha256(masterKey.getBytes(StandardCharsets.UTF_8), new byte[0], PURPOSE.getBytes(StandardCharsets.UTF_8), 32);
        }
    }

    public boolean configured() {
        return key != null;
    }

    /** PHP: substr(hash('sha256', $key . '|fingerprint'), 0, 16). */
    public String fingerprint() {
        if (key == null) return "";
        try {
            MessageDigest sha = MessageDigest.getInstance("SHA-256");
            sha.update(key);
            sha.update("|fingerprint".getBytes(StandardCharsets.UTF_8));
            return HexFormat.of().formatHex(sha.digest()).substring(0, 16);
        } catch (GeneralSecurityException e) {
            throw new IllegalStateException(e);
        }
    }

    public String decrypt(byte[] ciphertext, byte[] nonce, byte[] tag, String keyFingerprint) {
        if (key == null) throw new IllegalStateException("SMS_GATEWAY_MASTER_KEY is not set (>= 32 chars)");
        if (keyFingerprint != null && !keyFingerprint.isEmpty() && !keyFingerprint.equals(fingerprint())) {
            throw new IllegalStateException("secret was encrypted with a different SMS_GATEWAY_MASTER_KEY");
        }
        try {
            Cipher cipher = Cipher.getInstance("AES/GCM/NoPadding");
            cipher.init(Cipher.DECRYPT_MODE, new SecretKeySpec(key, "AES"), new GCMParameterSpec(tag.length * 8, nonce));
            byte[] input = new byte[ciphertext.length + tag.length];
            System.arraycopy(ciphertext, 0, input, 0, ciphertext.length);
            System.arraycopy(tag, 0, input, ciphertext.length, tag.length);
            return new String(cipher.doFinal(input), StandardCharsets.UTF_8);
        } catch (GeneralSecurityException e) {
            throw new IllegalStateException("secret could not be decrypted", e);
        }
    }

    /** RFC 5869, as PHP's hash_hkdf() with an empty salt (= HashLen zero bytes). */
    static byte[] hkdfSha256(byte[] ikm, byte[] salt, byte[] info, int length) {
        try {
            Mac mac = Mac.getInstance("HmacSHA256");
            mac.init(new SecretKeySpec(salt.length == 0 ? new byte[32] : salt, "HmacSHA256"));
            byte[] prk = mac.doFinal(ikm);
            mac.init(new SecretKeySpec(prk, "HmacSHA256"));
            byte[] out = new byte[length];
            byte[] t = new byte[0];
            int pos = 0;
            for (int i = 1; pos < length; i++) {
                mac.update(t);
                mac.update(info);
                mac.update((byte) i);
                t = mac.doFinal();
                int n = Math.min(t.length, length - pos);
                System.arraycopy(t, 0, out, pos, n);
                pos += n;
            }
            return out;
        } catch (GeneralSecurityException e) {
            throw new IllegalStateException(e);
        }
    }
}
