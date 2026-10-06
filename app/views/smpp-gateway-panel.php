<?php
/**
 * #46 — SMPP gateway: settings form + live monitoring, included by public/sms-gateways.php on the
 * connector tab of a gateway whose protocol is SMPP.
 *
 * expects: $gatewayId, $gateway, $smppRow, $smppSessions, $smppEvents, $smppLive, $smppPasswordSet
 */
$fa = static fn(int|string|null $n): string => to_persian_digits(number_format((int)$n));
$liveGateway = null;
foreach ((array)($smppLive['gateways'] ?? []) as $g) {
    if ((int)($g['gateway_id'] ?? 0) === (int)$gatewayId) $liveGateway = $g;
}
$sel = static fn(string $field, string $value): string => (string)($smppRow[$field] ?? '') === $value ? ' selected' : '';
?>
<div class="card">
  <h2>وضعیت اتصال SMPP</h2>
  <?php if ($smppLive === null): ?>
    <p class="error">سرویس <span class="ltr">smpp-bridge</span> در دسترس نیست (<span class="ltr"><?= e(smpp_bridge_url()) ?></span>). کانتینر را اجرا کنید و <span class="ltr">SMPP_BRIDGE_TOKEN</span> را در هر دو طرف یکسان تنظیم کنید. آمار زیر آخرین وضعیت ثبت‌شده است.</p>
  <?php elseif ($liveGateway === null): ?>
    <p class="error">سرویس SMPP این درگاه را اجرا نکرده است. درگاه باید فعال باشد و آدرس سرور داشته باشد.<?= !empty($smppLive['config_error']) ? ' خطای بارگذاری: <span class="ltr">' . e((string)$smppLive['config_error']) . '</span>' : '' ?></p>
  <?php elseif (!empty($liveGateway['password_error'])): ?>
    <p class="error">رمز SMPP قابل استفاده نیست: <span class="ltr"><?= e((string)$liveGateway['password_error']) ?></span></p>
  <?php else: ?>
    <p><strong><?= (int)$liveGateway['bound_senders'] > 0 ? '✅ متصل' : '⛔ هیچ نشست ارسالی وصل نیست' ?></strong>
      — <?= $fa($liveGateway['bound_senders']) ?> نشست ارسال آماده؛ نسخه‌ی پیکربندی در حال اجرا <span class="ltr">v<?= (int)$liveGateway['config_version'] ?></span><?= (int)$liveGateway['config_version'] !== (int)$gateway['config_version'] ? ' (در حال اعمال نسخه‌ی جدید…)' : '' ?></p>
  <?php endif; ?>

  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>نشست</th><th>وضعیت</th><th>متصل از</th><th>ارسال موفق / ناموفق</th><th>محدودشده (throttle)</th><th>رسید تحویل</th><th>پیام دریافتی</th><th>در جریان</th><th>اتصال مجدد</th><th>آخرین خطا</th></tr></thead>
    <tbody>
    <?php foreach ($smppSessions as $s): $stale = (int)$s['age_s'] > 30; ?>
      <tr>
        <td class="ltr"><?= e((string)$s['session_key']) ?></td>
        <td><?php if ($stale): ?><span class="badge badge-unknown">نامعلوم (به‌روز نشده)</span>
            <?php elseif ($s['state'] === 'BOUND'): ?><span class="badge badge-active">متصل</span>
            <?php else: ?><span class="badge badge-failed"><?= e((string)$s['state']) ?></span><?php endif; ?></td>
        <td class="num"><?= $s['bound_since'] ? jdate((string)$s['bound_since']) : '—' ?></td>
        <td class="num"><?= $fa($s['submit_ok']) ?> / <?= $fa($s['submit_failed']) ?></td>
        <td class="num"><?= $fa($s['throttled']) ?></td>
        <td class="num"><?= $fa($s['dlr_received']) ?></td>
        <td class="num"><?= $fa($s['mo_received']) ?></td>
        <td class="num"><?= $fa($s['in_flight']) ?></td>
        <td class="num"><?= $fa($s['reconnects']) ?></td>
        <td class="ltr muted"><?= e(mb_strimwidth((string)($s['last_error'] ?? ''), 0, 90, '…')) ?><?= $s['last_error_at'] ? ' — ' . jdate((string)$s['last_error_at']) : '' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($smppSessions === []): ?><tr><td colspan="10" class="muted">هنوز نشستی گزارش نشده است.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <p class="muted">شمارنده‌ها از آخرین راه‌اندازی هر نشست است. ۲۴ ساعت اخیر:
    رسید تحویل <?= $fa($smppEvents['dlr'] ?? 0) ?> (تحویل‌شده <?= $fa($smppEvents['dlr_delivered'] ?? 0) ?>، ناموفق <?= $fa($smppEvents['dlr_failed'] ?? 0) ?>، بدون پیام متناظر <?= $fa($smppEvents['dlr_unmatched'] ?? 0) ?>)
    · پیام دریافتی <?= $fa($smppEvents['mo'] ?? 0) ?> · در صف پردازش <?= $fa($smppEvents['pending'] ?? 0) ?></p>
  <div class="toolbar">
    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="smpp_test"><input type="hidden" name="gateway_id" value="<?= (int)$gatewayId ?>"><input type="hidden" name="tab" value="connector">
      <button class="btn">اتصال آزمایشی (bind/unbind)</button></form>
    <form method="post" style="display:inline" onsubmit="return confirm('همه‌ی نشست‌های این درگاه قطع و دوباره وصل شوند؟')"><?= csrf_field() ?><input type="hidden" name="do" value="smpp_reconnect"><input type="hidden" name="gateway_id" value="<?= (int)$gatewayId ?>"><input type="hidden" name="tab" value="connector">
      <button class="btn">اتصال مجدد نشست‌ها</button></form>
    <a class="btn" href="/sms-gateways.php?gateway=<?= (int)$gatewayId ?>&tab=connector">به‌روزرسانی</a>
  </div>
  <p class="muted">اتصال آزمایشی یک نشست جداگانه باز می‌کند؛ اگر اپراتور تعداد نشست را محدود کرده، ممکن است وقتی نشست‌های اصلی وصل‌اند رد شود.</p>
</div>

<div class="card">
  <h2>تنظیمات SMPP</h2>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="do" value="smpp_save">
    <input type="hidden" name="gateway_id" value="<?= (int)$gatewayId ?>"><input type="hidden" name="tab" value="connector">
    <div class="form-row">
      <label>آدرس SMSC (host/IP) <input type="text" name="host" class="ltr" required value="<?= e((string)$smppRow['host']) ?>"></label>
      <label>پورت <input type="number" name="port" class="ltr" min="1" max="65535" value="<?= (int)$smppRow['port'] ?>"></label>
      <label>system_id <input type="text" name="system_id" class="ltr" maxlength="15" required value="<?= e((string)$smppRow['system_id']) ?>"></label>
      <label>رمز (password) <input type="password" name="password" class="ltr" maxlength="8" autocomplete="new-password" placeholder="<?= $smppPasswordSet ? 'ذخیره شده — برای تغییر وارد کنید' : 'وارد نشده' ?>">
        <span class="hint">حداکثر ۸ نویسه؛ رمزنگاری‌شده ذخیره می‌شود.</span></label>
      <label>system_type <input type="text" name="system_type" class="ltr" maxlength="12" value="<?= e((string)$smppRow['system_type']) ?>"></label>
      <label>نسخه‌ی پروتکل
        <select name="interface_version"><option value="3.4"<?= $sel('interface_version', '3.4') ?>>3.4</option><option value="5.0"<?= $sel('interface_version', '5.0') ?>>5.0</option></select></label>
      <label>نوع اتصال (bind)
        <select name="bind_mode">
          <option value="trx"<?= $sel('bind_mode', 'trx') ?>>Transceiver (ارسال و دریافت در یک نشست)</option>
          <option value="tx_rx"<?= $sel('bind_mode', 'tx_rx') ?>>Transmitter + Receiver جدا</option>
          <option value="tx"<?= $sel('bind_mode', 'tx') ?>>فقط Transmitter (بدون رسید و پیام دریافتی)</option>
        </select></label>
      <label>تعداد نشست موازی <input type="number" name="session_count" class="ltr" min="1" max="10" value="<?= (int)$smppRow['session_count'] ?>">
        <span class="hint">در حالت TX+RX از هر نوع همین تعداد.</span></label>
      <label>سقف سرعت (TPS) <input type="number" name="tps" class="ltr" min="1" max="5000" value="<?= (int)$smppRow['tps'] ?>">
        <span class="hint">برای کل درگاه، طبق قرارداد اپراتور.</span></label>
      <label>window (درخواست هم‌زمان هر نشست) <input type="number" name="window_size" class="ltr" min="1" max="500" value="<?= (int)$smppRow['window_size'] ?>"></label>
      <label>enquire_link (ثانیه) <input type="number" name="enquire_link_s" class="ltr" min="5" max="600" value="<?= (int)$smppRow['enquire_link_s'] ?>"></label>
      <label>تأخیر اتصال مجدد (ثانیه) <input type="number" name="reconnect_delay_s" class="ltr" min="1" max="300" value="<?= (int)$smppRow['reconnect_delay_s'] ?>"></label>
      <label>مهلت پاسخ submit (میلی‌ثانیه) <input type="number" name="submit_timeout_ms" class="ltr" min="1000" max="120000" value="<?= (int)$smppRow['submit_timeout_ms'] ?>"></label>
      <label>TON/NPI فرستنده
        <span class="toolbar"><input type="number" name="source_ton" class="ltr" min="0" max="6" value="<?= (int)$smppRow['source_ton'] ?>" style="width:5em"><input type="number" name="source_npi" class="ltr" min="0" max="18" value="<?= (int)$smppRow['source_npi'] ?>" style="width:5em"></span>
        <span class="hint">شماره‌ی خدماتی معمولاً ۵/۰ یا ۰/۰؛ طبق مستند اپراتور.</span></label>
      <label>TON/NPI گیرنده
        <span class="toolbar"><input type="number" name="dest_ton" class="ltr" min="0" max="6" value="<?= (int)$smppRow['dest_ton'] ?>" style="width:5em"><input type="number" name="dest_npi" class="ltr" min="0" max="18" value="<?= (int)$smppRow['dest_npi'] ?>" style="width:5em"></span></label>
      <label>قالب شماره گیرنده
        <select name="destination_format">
          <option value="international"<?= $sel('destination_format', 'international') ?>>بین‌المللی (98912…)</option>
          <option value="national"<?= $sel('destination_format', 'national') ?>>داخلی (0912…)</option>
          <option value="as_is"<?= $sel('destination_format', 'as_is') ?>>بدون تغییر</option>
        </select></label>
      <label>کدگذاری متن
        <select name="data_coding">
          <option value="auto"<?= $sel('data_coding', 'auto') ?>>خودکار (GSM یا UCS-2)</option>
          <option value="ucs2"<?= $sel('data_coding', 'ucs2') ?>>همیشه UCS-2</option>
          <option value="gsm7"<?= $sel('data_coding', 'gsm7') ?>>همیشه GSM 7-bit</option>
          <option value="latin1"<?= $sel('data_coding', 'latin1') ?>>Latin-1</option>
        </select></label>
      <label>پیام چندبخشی
        <select name="long_message">
          <option value="udh"<?= $sel('long_message', 'udh') ?>>UDH (رایج‌ترین)</option>
          <option value="sar"<?= $sel('long_message', 'sar') ?>>SAR (TLV)</option>
          <option value="payload"<?= $sel('long_message', 'payload') ?>>message_payload (یک‌جا)</option>
        </select></label>
      <label>اعتبار پیام (دقیقه، ۰ = پیش‌فرض SMSC) <input type="number" name="validity_minutes" class="ltr" min="0" max="10080" value="<?= (int)$smppRow['validity_minutes'] ?>"></label>
      <label>قالب شناسه در رسید تحویل
        <select name="dlr_id_format">
          <option value="auto"<?= $sel('dlr_id_format', 'auto') ?>>خودکار (همه‌ی حالت‌ها)</option>
          <option value="as_is"<?= $sel('dlr_id_format', 'as_is') ?>>بدون تبدیل</option>
          <option value="hex_to_dec"<?= $sel('dlr_id_format', 'hex_to_dec') ?>>رسید هگز، ارسال دهدهی</option>
          <option value="dec_to_hex"<?= $sel('dlr_id_format', 'dec_to_hex') ?>>رسید دهدهی، ارسال هگز</option>
        </select></label>
    </div>
    <div class="toolbar">
      <label><input type="checkbox" name="use_tls" value="1"<?= (int)$smppRow['use_tls'] ? ' checked' : '' ?>> اتصال TLS</label>
      <label><input type="checkbox" name="registered_delivery" value="1"<?= (int)$smppRow['registered_delivery'] ? ' checked' : '' ?>> درخواست رسید تحویل</label>
      <label><input type="checkbox" name="receive_enabled" value="1"<?= (int)$smppRow['receive_enabled'] ? ' checked' : '' ?>> ذخیره‌ی پیام‌های دریافتی (MO)</label>
    </div>
    <button class="btn btn-primary">ذخیره‌ی تنظیمات SMPP</button>
    <p class="muted">بعد از ذخیره، سرویس SMPP حداکثر ظرف چند ثانیه نشست‌ها را با تنظیمات جدید دوباره وصل می‌کند. اپراتورهایی که این درگاه پوشش می‌دهد را در برگه‌ی «اپراتورها» انتخاب کنید و درگاه را به مسیر یا شماره‌ها اختصاص دهید.</p>
  </form>
</div>
