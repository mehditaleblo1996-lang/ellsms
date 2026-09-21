<?php
/**
 * ELLSMS — shared price-quote/invoice document model.
 *
 * One JSON document (ellsms_settings.price_quote_document) backs both the
 * admin's editable web page (public/price-quote.php) and the Telegram bot
 * command that regenerates the same document as a PDF on request
 * (public/telegram-webhook.php). Keeping the schema/defaults/HTML render in
 * one place means a field added in one surface can't drift from the other.
 */

const PRICE_QUOTE_SETTING_KEY = 'price_quote_document';

function price_quote_default(): array {
    [$jy] = gregorian_to_jalali((int)date('Y'), (int)date('n'), (int)date('j'));
    return [
        'invoice_no'     => 'ELL-' . $jy . '-001',
        'date'           => jdate(date('Y-m-d H:i:s'), false),
        'validity_days'  => '7',
        'price_unit'     => 'تومان',
        'title'          => 'فاکتور / لیست قیمت خدمات پیامک',
        'subtitle'       => 'ELLSMS Smart SMS Panel',
        'rows' => [
            ['title' => 'پیامک حقوقی',              'desc' => 'مناسب فاکتور رسمی / شرکتی',            'unit' => 'هر پیامک', 'price' => '115'],
            ['title' => 'پیامک غیررسمی',             'desc' => 'مناسب فاکتور شخصی / غیررسمی',          'unit' => 'هر پیامک', 'price' => '110'],
            ['title' => 'پیامک بالک منطقه‌ای',        'desc' => 'ارسال هدفمند بر اساس مناطق دارای دیتا (برای مناطقی که دیتا نداریم)', 'unit' => 'هر پیامک', 'price' => '145'],
            ['title' => 'LBA',                        'desc' => 'ارسال مبتنی بر موقعیت مکانی',          'unit' => 'هر پیامک', 'price' => '400'],
        ],
        'notes' => [
            'تمام قیمت‌ها به ازای هر پیامک محاسبه می‌شوند.',
            'تعرفه‌ها ممکن است بر اساس حجم ارسال با شرایط همکاری تغییر کنند.',
            'برای دریافت مشاوره و خرید پنل، با واحد فروش ELLSMS تماس بگیرید.',
        ],
        'features' => ['پایداری و امنیت', 'پشتیبانی حرفه‌ای', 'ارسال سریع', 'پوشش سراسری'],
        'phone1'   => '۰۹۱۲۳۳۴۸۴۱۷',
        'phone2'   => '۰۹۱۹۷۶۸۴۰۶۳',
        'website'  => 'www.ellsms.ir',
        'email'    => 'sales@ellsms.ir',
        'footer_tagline' => 'با ELLSMS بیشتر دیده شوید ...',
    ];
}

function price_quote_load(): array {
    $raw = setting(PRICE_QUOTE_SETTING_KEY);
    if (!$raw) return price_quote_default();
    $data = json_decode($raw, true);
    if (!is_array($data)) return price_quote_default();
    return array_replace(price_quote_default(), $data);
}

function price_quote_save(array $data): void {
    set_setting(PRICE_QUOTE_SETTING_KEY, json_encode($data, JSON_UNESCAPED_UNICODE));
}

/**
 * Renders the document as a standalone HTML string (no editable form
 * controls) suitable for wkhtmltopdf, built from the exact reference design
 * the user supplied (a browser-accurate index.html + extracted logo/art
 * assets — see docs/telegram-invoice-bot.md). Reused as-is where safe;
 * adapted where wkhtmltopdf's bundled WebKit (an old, patched Qt WebKit —
 * see the Dockerfile comment on why it is used at all) can't be trusted:
 *
 * - The source's `.sheet:before/:after` decorative blobs, the logo, and the
 *   marketing slogan/ribbon are 100% static brand chrome (none of it is a
 *   $doc field), so they are pre-rendered ONCE into
 *   public/assets/img/price-quote-band.png with a real browser engine
 *   (regenerate the same way if the design changes) and placed as a plain
 *   background-image. An image is something any renderer can just show —
 *   no CSS gradient/shape support required, unlike the source's own
 *   linear-gradient() blobs.
 * - The source's `display:grid` intro layout is rebuilt with flexbox:
 *   grid is a newer spec than this engine targets, flexbox already proven
 *   to render correctly here (see docs/telegram-invoice-bot.md).
 * - The source's per-row/per-benefit Unicode icon glyphs (▤ ϟ ◉ …) are
 *   dropped in favor of plain dots/borders: this engine's font fallback
 *   for arbitrary symbol glyphs is unverified, while text and solid colors
 *   are proven. The one static icon that matters most for the reference
 *   look (the intro's small "document" badge) is pre-rendered the same way
 *   as the band, as public/assets/img/price-quote-doc-icon.png.
 * - The source's thead `linear-gradient()` becomes a solid background-color
 *   — baking it as an image too would risk misaligning its column widths
 *   against the live tbody's, since they're no longer the same <table>.
 *
 * Fonts are referenced as local file:// paths (TTF, not the browser's
 * woff2 copies) since wkhtmltopdf needs --enable-local-file-access to read
 * them — see price_quote_render_pdf().
 */
function price_quote_render_html(array $doc): string {
    $fontPath = 'file://' . APP_ROOT . '/public/assets/fonts';
    $imgPath = 'file://' . APP_ROOT . '/public/assets/img';

    $rowsHtml = '';
    foreach ($doc['rows'] as $i => $row) {
        $rowsHtml .= '<tr>'
            . '<td class="rowno">' . e(to_persian_digits((string)($i + 1))) . '</td>'
            . '<td class="service">' . e($row['title']) . '</td>'
            . '<td class="unit">' . e($row['unit']) . '</td>'
            . '<td class="price">' . e(to_persian_digits((string)$row['price'])) . ' ' . e($doc['price_unit']) . '</td>'
            . '<td class="desc">' . e($row['desc']) . '</td>'
            . '</tr>';
    }
    $notesHtml = '';
    foreach ($doc['notes'] as $note) {
        $notesHtml .= '<li>' . e($note) . '</li>';
    }
    $featuresHtml = '';
    foreach ($doc['features'] as $f) {
        $featuresHtml .= '<div class="benefit">' . e($f) . '</div>';
    }

    return '<!doctype html><html dir="rtl" lang="fa"><head><meta charset="utf-8"><style>
        @font-face { font-family: Vazirmatn; src: url("' . $fontPath . '/Vazirmatn-Regular.ttf"); font-weight: 400; }
        @font-face { font-family: Vazirmatn; src: url("' . $fontPath . '/Vazirmatn-Medium.ttf"); font-weight: 500; }
        @font-face { font-family: Vazirmatn; src: url("' . $fontPath . '/Vazirmatn-Bold.ttf"); font-weight: 700; }
        @font-face { font-family: Vazirmatn; src: url("' . $fontPath . '/Vazirmatn-ExtraBold.ttf"); font-weight: 900; }
        :root{--ink:#0c1542;--blue:#076df2;--line:#dce6f9;--pale:#f4f7ff;--muted:#8a9bc4}
        * { box-sizing: border-box; }
        body { margin:0; background:#fff; color:var(--ink); font-family:Vazirmatn,Tahoma,sans-serif; }
        .band { background-color:#4b3fd6; background-image:url("' . $imgPath . '/price-quote-band.png"); background-size:100% 100%; background-repeat:no-repeat; height:158px; }
        .intro { display:flex; direction:ltr; border:2px solid #e3ebfb; border-radius:16px; margin:16px 26px 0; overflow:hidden; }
        .meta { flex:0 0 34%; direction:rtl; background:var(--pale); padding:12px 16px; font-size:12px; }
        .meta div { display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px solid #e2e9f7; }
        .meta div:last-child { border:0; }
        .meta b { font-weight:700; color:#4a5170; }
        .headline { flex:1 1 auto; direction:rtl; text-align:center; border-left:1px solid #cbd9f2; padding:14px 10px; }
        .headline h3 { font-size:20px; margin:0 0 6px; font-weight:900; color:#0a0f31; }
        .headline h3 span { color:var(--blue); }
        .headline p { font-size:11px; letter-spacing:3px; margin:0; color:var(--muted); }
        .document { flex:0 0 22%; direction:rtl; text-align:center; padding:12px 8px; }
        .document img { width:36px; height:36px; }
        .document h4 { font-size:11.5px; line-height:1.6; margin:6px 0 3px; }
        .document p { color:var(--muted); font-size:9px; line-height:1.6; margin:0; }
        .prices { margin:14px 26px 0; border:1px solid var(--line); border-radius:16px; overflow:hidden; }
        table { width:100%; border-collapse:collapse; table-layout:fixed; }
        thead { background-color:#0874ef; color:#fff; }
        th { height:34px; font-size:12px; font-weight:800; border-inline-start:1px solid rgba(255,255,255,.36); }
        th:nth-child(1){width:8%} th:nth-child(2){width:27%} th:nth-child(3){width:14%} th:nth-child(4){width:18%} th:nth-child(5){width:33%}
        td { height:46px; border-inline-start:1px solid var(--line); border-top:1px solid var(--line); text-align:center; font-size:11px; padding:4px 6px; }
        tbody tr:nth-child(even) { background:#fafbff; }
        .rowno { font-size:13px; font-weight:800; color:var(--muted); }
        .service { font-size:12px; font-weight:700; }
        .price { color:#043cf3; font-size:13px; font-weight:900; }
        .desc { color:#6b7290; font-size:10px; line-height:1.5; }
        .notes { margin:14px 26px 0; display:flex; direction:ltr; background-color:#f1f6ff; background:linear-gradient(105deg,#f1f6ff,#fff); border-radius:16px; overflow:hidden; }
        .note-art { flex:0 0 34%; background-image:url("' . $imgPath . '/price-quote-message-art.png"); background-size:cover; background-position:center; }
        .note-copy { flex:1 1 auto; direction:rtl; padding:12px 16px; }
        .note-title { color:#073af0; font-size:13px; font-weight:900; margin-bottom:6px; }
        .note-copy ul { list-style:none; padding:0; margin:0; }
        .note-copy li { font-size:10.5px; line-height:1.9; position:relative; padding-inline-start:12px; }
        .note-copy li:before { content:"●"; position:absolute; inset-inline-start:0; color:#3055f7; font-size:7px; top:5px; }
        .benefits { display:flex; margin:14px 26px 0; }
        .benefit { flex:1 1 25%; text-align:center; font-size:10px; font-weight:700; color:#14329a; background:#edf3ff; border-radius:8px; padding:8px 4px; margin-inline-end:6px; }
        .benefit:last-child { margin-inline-end:0; }
        .contact { display:flex; direction:ltr; margin:18px 26px 0; padding-top:14px; border-top:1px solid var(--line); }
        .contact > div { flex:1 1 25%; padding:0 10px; border-right:1px solid #cbd8f3; font-size:10.5px; direction:rtl; }
        .contact > div:first-child { border:0; }
        .mini-logo { direction:ltr; text-align:left; }
        .mini-logo strong { font-size:18px; letter-spacing:1px; color:#1b47ef; }
        .mini-logo strong span { color:#0e1535; }
        .mini-logo small { display:block; letter-spacing:2px; color:var(--muted); margin-top:2px; }
        .contact b { font-size:11px; }
        .contact p { margin:2px 0; font-size:10px; color:#5a6690; }
        .footline { margin-top:16px; padding:10px 26px; background:#f5f8ff; color:#9aabd0; display:flex; justify-content:space-between; font-size:9.5px; }
    </style></head><body>
        <div class="band"></div>
        <div class="intro">
            <div class="meta">
                <div><b>شماره فاکتور:</b><span>' . e($doc['invoice_no']) . '</span></div>
                <div><b>تاریخ:</b><span>' . e($doc['date']) . '</span></div>
                <div><b>اعتبار قیمت:</b><span>' . e(to_persian_digits((string)$doc['validity_days'])) . ' روز</span></div>
                <div><b>واحد قیمت:</b><span>' . e($doc['price_unit']) . '</span></div>
            </div>
            <div class="headline"><h3>' . e($doc['title']) . '</h3><p>' . e($doc['subtitle']) . '</p></div>
            <div class="document"><img src="' . $imgPath . '/price-quote-doc-icon.png" alt=""><h4>پیش فاکتور<br>خدمات پیامک</h4><p>راهنمای مطمئن<br>برای ارتباط با مشتریان</p></div>
        </div>
        <div class="prices">
            <table>
                <thead><tr><th>ردیف</th><th>شرح خدمت</th><th>واحد</th><th>قیمت</th><th>توضیحات</th></tr></thead>
                <tbody>' . $rowsHtml . '</tbody>
            </table>
        </div>
        <div class="notes">
            <div class="note-art"></div>
            <div class="note-copy">
                <div class="note-title">نکات مهم</div>
                <ul>' . $notesHtml . '</ul>
            </div>
        </div>
        <div class="benefits">' . $featuresHtml . '</div>
        <div class="contact">
            <div class="mini-logo"><strong>ELL<span>SMS</span></strong><small>Smart SMS Panel</small></div>
            <div><b>واحد فروش</b><p>' . e($doc['phone1']) . '</p><p>' . e($doc['phone2']) . '</p></div>
            <div><b>' . e($doc['website']) . '</b><p>وب‌سایت رسمی</p></div>
            <div><b>' . e($doc['email']) . '</b><p>ایمیل ارتباطی</p></div>
        </div>
        <div class="footline"><span>ELLSMS | Smart SMS Panel | ' . e($doc['footer_tagline']) . '</span><span>ارتباط امروز، فرصت فردا ...</span></div>
    </body></html>';
}

/**
 * Renders HTML to a PDF via the local wkhtmltopdf binary (installed in the
 * Docker image — see docker/Dockerfile). No PHP PDF library is used: this
 * project ships zero production Composer dependencies by design (only
 * PHPUnit is a require-dev), so shelling out to one local, version-pinned
 * binary with a fully-controlled argv (proc_open + bypass_shell, the same
 * pattern app/TotpMfa.php uses for qrencode) is the fit here, not adding a
 * package tree that never gets installed in production anyway.
 *
 * Returns the raw PDF bytes, or null if the binary is missing or the render
 * fails — callers must treat null as "PDF export unavailable on this
 * server" and say so, never as an empty-but-valid file.
 */
function price_quote_render_pdf(string $html): ?string {
    if (!function_exists('proc_open')) return null;

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $pipes = [];
    $process = @proc_open(
        ['wkhtmltopdf', '--quiet', '--enable-local-file-access', '--page-size', 'A4', '--encoding', 'utf-8', '-', '-'],
        $descriptors,
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) return null;

    fwrite($pipes[0], $html);
    fclose($pipes[0]);
    $pdf = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    if ($exit !== 0 || !is_string($pdf) || substr($pdf, 0, 4) !== '%PDF') {
        Logger::warning('price_quote.pdf_render_failed', ['exit' => $exit, 'stderr_present' => trim((string)$stderr) !== '']);
        return null;
    }
    return $pdf;
}

/**
 * Parses a Telegram /invoice command into an override of $baseDoc (normally
 * price_quote_load() — the admin's saved template). Only what the sender
 * actually wrote changes; everything else (branding, contact footer, and
 * rows/notes if none were given) stays as configured in the admin panel.
 * Recognized lines (any order, Persian labels, "label: value"):
 *   شماره / تاریخ / اعتبار / واحد / عنوان / زیرعنوان / پاورقی / تلفن۱ / تلفن۲ / وبسایت / ایمیل
 *   ردیف: عنوان | توضیح | واحد | قیمت   (repeatable — replaces all rows)
 *   نکته: متن                           (repeatable — replaces all notes)
 */
function price_quote_parse_telegram_message(string $text, array $baseDoc): array {
    $fieldMap = [
        'شماره'      => 'invoice_no',
        'شماره فاکتور' => 'invoice_no',
        'تاریخ'      => 'date',
        'اعتبار'     => 'validity_days',
        'واحد'       => 'price_unit',
        'عنوان'      => 'title',
        'زیرعنوان'   => 'subtitle',
        'پاورقی'     => 'footer_tagline',
        'تلفن1'      => 'phone1',
        'تلفن۱'      => 'phone1',
        'تلفن'       => 'phone1',
        'تلفن2'      => 'phone2',
        'تلفن۲'      => 'phone2',
        'وبسایت'     => 'website',
        'سایت'       => 'website',
        'ایمیل'      => 'email',
    ];

    $doc = $baseDoc;
    $rows = [];
    $notes = [];
    $sawRows = false;
    $sawNotes = false;

    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '/invoice')) continue;
        $colon = mb_strpos($line, ':');
        if ($colon === false) $colon = mb_strpos($line, '：'); // fullwidth colon, common on mobile keyboards
        if ($colon === false) continue;

        $label = from_persian_digits(trim(mb_substr($line, 0, $colon)));
        $value = trim(mb_substr($line, $colon + 1));

        if ($label === 'ردیف') {
            $parts = array_map('trim', explode('|', $value));
            $title = $parts[0] ?? '';
            if ($title === '') continue;
            $rows[] = [
                'title' => $title,
                'desc'  => $parts[1] ?? '',
                'unit'  => $parts[2] ?? 'هر پیامک',
                'price' => from_persian_digits($parts[3] ?? ''),
            ];
            $sawRows = true;
            continue;
        }
        if ($label === 'نکته') {
            if ($value !== '') $notes[] = $value;
            $sawNotes = true;
            continue;
        }
        if (isset($fieldMap[$label])) {
            $doc[$fieldMap[$label]] = $label === 'اعتبار' ? from_persian_digits($value) : $value;
        }
    }

    if ($sawRows) $doc['rows'] = $rows;
    if ($sawNotes) $doc['notes'] = $notes;
    return $doc;
}
