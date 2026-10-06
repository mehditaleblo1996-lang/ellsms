package ir.ellsms.smppbridge;

import org.junit.jupiter.api.Test;

import java.nio.charset.StandardCharsets;
import java.util.HexFormat;
import java.util.List;

import static org.junit.jupiter.api.Assertions.*;

class CodecAndVaultTest {

    @Test
    void vaultDecryptsWhatPhpEncrypted() {
        // Produced by PHP: openssl_encrypt('p@ss-رمز', 'aes-256-gcm', hash_hkdf('sha256', str_repeat('k',40), 32,
        // 'ellsms.sms_gateway.secret.v1'), OPENSSL_RAW_DATA, hex2bin('000102030405060708090a0b'), $tag)
        SecretVault v = new SecretVault("k".repeat(40));
        HexFormat h = HexFormat.of();
        assertEquals("e790c97c2eaf3930", v.fingerprint());
        assertEquals("p@ss-رمز", v.decrypt(h.parseHex("2a108d2bc278a71dd28b04"), h.parseHex("000102030405060708090a0b"),
            h.parseHex("995f466beed0d3675c2f3621eb51a33f"), "e790c97c2eaf3930"));
        assertThrows(IllegalStateException.class, () -> v.decrypt(h.parseHex("2a108d2bc278a71dd28b04"),
            h.parseHex("000102030405060708090a0b"), h.parseHex("995f466beed0d3675c2f3621eb51a33f"), "0000000000000000"));
        assertFalse(new SecretVault("short").configured());
    }

    @Test
    void gsmAlphabetIsComplete() {
        assertTrue(Codec.isGsm("Hello @£$ {[]} €~^|\\ 0123"));
        assertFalse(Codec.isGsm("سلام"));
        // Every basic septet decodes back to itself.
        Codec.Encoded e = Codec.encode("@£$¥èéùìòÇ", "gsm7");
        assertEquals("@£$¥èéùìòÇ", Codec.decodeGsm(e.bytes()));
        assertEquals("a{b€", Codec.decodeGsm(Codec.encode("a{b€", "gsm7").bytes()));
    }

    @Test
    void autoPicksGsmOrUcs2AndSplitsOnCharacterBoundaries() {
        assertEquals(Codec.DC_GSM, Codec.encode("hello", "auto").dataCoding());
        assertEquals(Codec.DC_UCS2, Codec.encode("سلام", "auto").dataCoding());

        assertEquals(1, Codec.split(Codec.encode("a".repeat(160), "auto")).size());
        List<byte[]> gsm = Codec.split(Codec.encode("a".repeat(161), "auto"));
        assertEquals(2, gsm.size());
        assertEquals(153, gsm.get(0).length);

        assertEquals(1, Codec.split(Codec.encode("س".repeat(70), "auto")).size());
        List<byte[]> fa = Codec.split(Codec.encode("س".repeat(71), "auto"));
        assertEquals(2, fa.size());
        assertEquals(134, fa.get(0).length);

        // An escape sequence is never cut: 152 'a' + '€' (2 septets) cannot fit 153, so '€' moves to part 2.
        List<byte[]> esc = Codec.split(Codec.encode("a".repeat(152) + "€" + "a".repeat(10), "gsm7"));
        assertEquals(152, esc.get(0).length);
        assertEquals(0x1B, esc.get(1)[0]);

        // A surrogate pair (emoji) is never cut.
        String emoji = "س".repeat(66) + "😀" + "س".repeat(10);
        List<byte[]> parts = Codec.split(Codec.encode(emoji, "auto"));
        assertEquals(132, parts.get(0).length);
        String rejoined = new String(parts.get(0), StandardCharsets.UTF_16BE) + new String(parts.get(1), StandardCharsets.UTF_16BE);
        assertEquals(emoji, rejoined);
    }

    @Test
    void udhRoundTrip() {
        byte[] p = Codec.withUdh(new byte[]{1, 2, 3}, 0xAB, 3, 2);
        Codec.Concat c = Codec.parseUdh(p);
        assertEquals(0xAB, c.ref());
        assertEquals(3, c.total());
        assertEquals(2, c.seq());
        assertEquals(6, c.headerLength());
        byte[] udh16 = {0x06, 0x08, 0x04, 0x12, 0x34, 0x02, 0x01, 'x'};
        Codec.Concat c16 = Codec.parseUdh(udh16);
        assertEquals(0x1234, c16.ref());
        assertEquals(2, c16.total());
    }

    @Test
    void decodeByDataCoding() {
        assertEquals("سلام", Codec.decode("سلام".getBytes(StandardCharsets.UTF_16BE), (byte) 0x08));
        assertEquals("café", Codec.decode("café".getBytes(StandardCharsets.ISO_8859_1), (byte) 0x03));
        assertEquals("hi@", Codec.decode(new byte[]{'h', 'i', 0x00}, (byte) 0x00));
        assertEquals("0102", Codec.decode(new byte[]{1, 2}, (byte) 0x04));
        assertEquals("سلام", Codec.decode("سلام".getBytes(StandardCharsets.UTF_16BE), (byte) 0x18)); // class bits set
    }

    @Test
    void receiptParsing() {
        GatewayRuntime.Receipt r = GatewayRuntime.parseReceipt(
            "id:0A1B2C sub:001 dlvrd:001 submit date:2610061200 done date:2610061201 stat:DELIVRD err:000 text:hi".getBytes(StandardCharsets.ISO_8859_1), null, null);
        assertEquals("0A1B2C", r.id());
        assertEquals("DELIVRD", r.stat());
        assertEquals("000", r.err());
        assertNotNull(r.doneAt());

        GatewayRuntime.Receipt tlv = GatewayRuntime.parseReceipt("stat:DELIVRD".getBytes(), "77\u0000", 5);
        assertEquals("77", tlv.id());
        assertEquals("UNDELIV", tlv.stat(), "message_state TLV wins over the text");

        assertEquals("UNKNOWN", GatewayRuntime.parseReceipt("id:5".getBytes(), null, null).stat());
    }

    @Test
    void commandStatusClassification() {
        assertEquals("rejected", GatewayRuntime.classify(0x0B)); // invalid destination
        assertEquals("unavailable", GatewayRuntime.classify(0x58)); // throttled
        assertEquals("unavailable", GatewayRuntime.classify(0x08)); // system error
    }

    @Test
    void rateLimiterHonoursTps() throws Exception {
        RateLimiter l = new RateLimiter(20);
        long start = System.nanoTime();
        for (int i = 0; i < 40; i++) assertTrue(l.acquire(5_000));
        long ms = (System.nanoTime() - start) / 1_000_000;
        assertTrue(ms >= 900, "40 tokens at 20 TPS with a burst of 20 take about a second, took " + ms);
        l.pause(300);
        assertFalse(l.acquire(100));
    }
}
