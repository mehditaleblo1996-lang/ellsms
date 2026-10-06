package ir.ellsms.smppbridge;

import java.io.ByteArrayOutputStream;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.HexFormat;
import java.util.List;
import java.util.Map;

/**
 * Text ↔ SMPP short_message bytes.
 *
 * Outbound: picks the coding (GSM 03.38 default alphabet when every character fits, otherwise UCS-2;
 * or the coding the gateway forces), then splits into parts on CHARACTER boundaries — never through
 * a GSM escape sequence or a UTF-16 surrogate pair, which is what corrupts the last character of a
 * part with naive byte splitting. GSM text is sent unpacked (one septet per octet), which is what
 * SMPP SMSCs expect for data_coding 0.
 *
 * Inbound: decodes by data_coding and strips/parses a concatenation UDH.
 */
public final class Codec {
    public static final byte DC_GSM = 0x00;
    public static final byte DC_LATIN1 = 0x03;
    public static final byte DC_UCS2 = 0x08;

    // GSM 03.38 default alphabet, index = septet value.
    private static final String GSM_BASIC =
        "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞ\u001BÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?" +
        "¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
    private static final Map<Character, Integer> GSM_EXT = Map.of(
        '\f', 0x0A, '^', 0x14, '{', 0x28, '}', 0x29, '\\', 0x2F, '[', 0x3C, '~', 0x3D, ']', 0x3E, '|', 0x40, '€', 0x65);
    private static final Map<Character, Integer> GSM_INDEX = new HashMap<>();
    private static final Map<Integer, Character> GSM_EXT_REVERSE = new HashMap<>();

    static {
        for (int i = 0; i < GSM_BASIC.length(); i++) {
            if (i != 0x1B) GSM_INDEX.put(GSM_BASIC.charAt(i), i);
        }
        GSM_EXT.forEach((c, v) -> GSM_EXT_REVERSE.put(v, c));
    }

    /** One encoded message: its data_coding and the bytes of each character, kept separate for splitting. */
    public record Encoded(byte dataCoding, List<byte[]> chars) {
        public int length() {
            int n = 0;
            for (byte[] c : chars) n += c.length;
            return n;
        }

        public byte[] bytes() {
            ByteArrayOutputStream out = new ByteArrayOutputStream();
            for (byte[] c : chars) out.writeBytes(c);
            return out.toByteArray();
        }
    }

    public static boolean isGsm(String text) {
        for (int i = 0; i < text.length(); i++) {
            char c = text.charAt(i);
            if (!GSM_INDEX.containsKey(c) && !GSM_EXT.containsKey(c)) return false;
        }
        return true;
    }

    /** @param mode auto | gsm7 | ucs2 | latin1 */
    public static Encoded encode(String text, String mode) {
        String m = mode == null ? "auto" : mode;
        if (m.equals("auto")) m = isGsm(text) ? "gsm7" : "ucs2";
        List<byte[]> chars = new ArrayList<>();
        switch (m) {
            case "gsm7" -> {
                for (int i = 0; i < text.length(); i++) {
                    char c = text.charAt(i);
                    Integer basic = GSM_INDEX.get(c);
                    if (basic != null) chars.add(new byte[]{basic.byteValue()});
                    else if (GSM_EXT.containsKey(c)) chars.add(new byte[]{0x1B, GSM_EXT.get(c).byteValue()});
                    else chars.add(new byte[]{0x3F}); // '?' — only reachable when gsm7 is forced
                }
                return new Encoded(DC_GSM, chars);
            }
            case "latin1" -> {
                for (int i = 0; i < text.length(); i++) {
                    char c = text.charAt(i);
                    chars.add(new byte[]{(byte) (c <= 0xFF ? c : '?')});
                }
                return new Encoded(DC_LATIN1, chars);
            }
            default -> {
                int i = 0;
                while (i < text.length()) {
                    int cp = text.codePointAt(i);
                    int len = Character.charCount(cp);
                    chars.add(text.substring(i, i + len).getBytes(StandardCharsets.UTF_16BE));
                    i += len;
                }
                return new Encoded(DC_UCS2, chars);
            }
        }
    }

    /** Max bytes in a single (non-concatenated) message, and per part when concatenated with a 6-byte UDH. */
    public static int singleLimit(byte dc) {
        return dc == DC_GSM ? 160 : 140;
    }

    public static int partLimit(byte dc) {
        return dc == DC_GSM ? 153 : 134;
    }

    /** Splits on character boundaries. One element when it fits a single message. */
    public static List<byte[]> split(Encoded enc) {
        List<byte[]> parts = new ArrayList<>();
        if (enc.length() <= singleLimit(enc.dataCoding())) {
            parts.add(enc.bytes());
            return parts;
        }
        int limit = partLimit(enc.dataCoding());
        ByteArrayOutputStream cur = new ByteArrayOutputStream();
        for (byte[] c : enc.chars()) {
            if (cur.size() + c.length > limit) {
                parts.add(cur.toByteArray());
                cur.reset();
            }
            cur.writeBytes(c);
        }
        if (cur.size() > 0) parts.add(cur.toByteArray());
        return parts;
    }

    /** 8-bit-reference concatenation UDH: 05 00 03 ref total seq. */
    public static byte[] withUdh(byte[] part, int ref, int total, int seq) {
        byte[] out = new byte[part.length + 6];
        out[0] = 0x05; out[1] = 0x00; out[2] = 0x03;
        out[3] = (byte) ref; out[4] = (byte) total; out[5] = (byte) seq;
        System.arraycopy(part, 0, out, 6, part.length);
        return out;
    }

    /* ---------------- inbound ---------------- */

    public static String decode(byte[] bytes, byte dataCoding) {
        if (bytes == null) return "";
        int dc = dataCoding & 0xFF;
        // Coding group 00xx (general) keeps the alphabet in bits 2-3; 0xF0 group keeps it in bit 2.
        int alphabet = (dc & 0xC0) == 0 ? (dc & 0x0C) : ((dc & 0xF0) == 0xF0 ? (dc & 0x04) : -1);
        if (dc == DC_UCS2 || alphabet == 0x08) return new String(bytes, StandardCharsets.UTF_16BE);
        if (dc == DC_LATIN1) return new String(bytes, StandardCharsets.ISO_8859_1);
        if (dc == 0x02 || dc == 0x04 || alphabet == 0x04) return HexFormat.of().withUpperCase().formatHex(bytes);
        return decodeGsm(bytes);
    }

    public static String decodeGsm(byte[] bytes) {
        StringBuilder sb = new StringBuilder();
        for (int i = 0; i < bytes.length; i++) {
            int b = bytes[i] & 0x7F;
            if (b == 0x1B && i + 1 < bytes.length) {
                Character ext = GSM_EXT_REVERSE.get(bytes[i + 1] & 0x7F);
                sb.append(ext != null ? ext : ' ');
                i++;
            } else {
                sb.append(GSM_BASIC.charAt(b));
            }
        }
        return sb.toString();
    }

    /** A parsed concatenation header (8- or 16-bit reference), or null when the UDH carries none. */
    public record Concat(int ref, int total, int seq, int headerLength) {}

    public static Concat parseUdh(byte[] msg) {
        if (msg == null || msg.length < 1) return null;
        int udhl = msg[0] & 0xFF;
        if (udhl + 1 > msg.length) return null;
        int i = 1;
        while (i + 1 < 1 + udhl) {
            int iei = msg[i] & 0xFF;
            int len = msg[i + 1] & 0xFF;
            if (i + 2 + len > msg.length) return null;
            if (iei == 0x00 && len == 3) {
                return new Concat(msg[i + 2] & 0xFF, msg[i + 3] & 0xFF, msg[i + 4] & 0xFF, udhl + 1);
            }
            if (iei == 0x08 && len == 4) {
                return new Concat(((msg[i + 2] & 0xFF) << 8) | (msg[i + 3] & 0xFF), msg[i + 4] & 0xFF, msg[i + 5] & 0xFF, udhl + 1);
            }
            i += 2 + len;
        }
        return new Concat(-1, 1, 1, udhl + 1); // a UDH without concatenation (ports, etc.)
    }
}
