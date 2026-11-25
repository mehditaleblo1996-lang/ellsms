<?php
require_once __DIR__ . '/../app/bootstrap.php';
// «خدمات پیامک» — the public page of the SMS services (docs/sms-services.md). Fixed HTML/SVG, no table
// behind it. Every diagram and the simulator are illustrative: nothing on this page sends a message or
// calls an API. A reseller's own host has no platform marketing site: it opens on the login.
if (reseller_brand_for_host() !== null) redirect('/login');

$pageTitle    = 'خدمات پیامک سازمانی';
$lpNavCurrent = 'sms';
$lpBodyClass  = 'ss-body';
$extraStyles  = ['/assets/css/sms-services.css'];
$metaDescription = 'زیرساخت یکپارچه ارسال، مدیریت و رهگیری پیامک‌های سازمانی، خدماتی و تبلیغاتی داتیس رایان: کد تأیید و احراز هویت، پیامک خدماتی، پیامک تبلیغاتی، پیامک سیستمی خودکار و گزارش تحویل هر پیام — از پنل تحت وب یا وب‌سرویس.';

// The consultation CTAs open the existing «تماس با ما» form with its subject filled in.
$ssConsult = '/contact?subject=' . rawurlencode('مشاوره راهکار پیامکی سازمانی');
$fa = static fn (int $n): string => to_persian_digits(str_pad((string) $n, 2, '0', STR_PAD_LEFT));

/* -------------------------------------------------------------------------------------------------
 * Hero diagram: the life of one message. Six stations on a closed loop, drawn right-to-left (RTL):
 * the top row carries the request in, the bottom row carries it out, and the delivery report climbs
 * back up to the business application. [x, y, title, technical label, explanation]
 * ----------------------------------------------------------------------------------------------- */
$ssLife = [
    [470, 60,  'نرم‌افزار کسب‌وکار', 'APP · API',      'سایت، اپلیکیشن یا نرم‌افزار سازمانی شما، درخواست ارسال را از پنل یا وب‌سرویس می‌فرستد.'],
    [245, 60,  'درگاه داتیس رایان', 'GATEWAY',        'درخواست احراز و اعتبارسنجی می‌شود و برای هر پیام یک شناسه‌ی رهگیری ثبت می‌شود.'],
    [20,  60,  'پردازش پیام',       'QUEUE · PARTS',  'شماره‌ها و متن بررسی، تعداد بخش‌های پیام محاسبه و پیام در صف ارسال قرار می‌گیرد.'],
    [20,  300, 'ارسال به اپراتور',  'ROUTE · SMSC',   'پیام به مسیر اپراتور گیرنده سپرده می‌شود.'],
    [245, 300, 'گیرنده',            'HANDSET',        'پیام روی گوشی مخاطب می‌نشیند.'],
    [470, 300, 'گزارش تحویل',       'DLR',            'اپراتور وضعیت تحویل را برمی‌گرداند و وضعیت پیام در گزارش‌ها به‌روز می‌شود.'],
];

/* -------------------------------------------------------------------------------------------------
 * Service explorer. Each service: [slug, title, lead, flow-kind, four flow stations
 * [title, technical label], example message [sender, text], use cases, where it lives in the panel].
 * The flow kind picks the diagram's extra geometry (ssFlowSvg below).
 * ----------------------------------------------------------------------------------------------- */
$ssServices = [
    ['otp', 'احراز هویت و کد تأیید',
        'کد یکبارمصرف ورود، ثبت‌نام و تأیید تراکنش؛ پیامی که کاربر چند ثانیه منتظرش می‌ماند و نباید دیر برسد.',
        'loop',
        [['درخواست ورود', 'LOGIN'], ['تولید کد یکبارمصرف', 'OTP'], ['درگاه پیامک', 'GATEWAY'], ['تأیید کاربر', 'VERIFY']],
        ['سامانه‌ی نمونه', "کد تأیید ورود شما: ۸۴۲۹۱۶\nاین کد را در اختیار دیگران قرار ندهید."],
        ['ورود دومرحله‌ای به حساب کاربری', 'تأیید شماره‌ی همراه هنگام ثبت‌نام', 'تأیید تراکنش و تغییر اطلاعات حساب', 'بازیابی رمز عبور'],
        'قالب‌های کد تأیید و ارسال از وب‌سرویس'],
    ['service', 'پیامک خدماتی',
        'اطلاع‌رسانی رویدادهای حساب و سفارش مشتری؛ هر پیام با نام، کد و مبلغ همان مشتری ساخته می‌شود.',
        'merge',
        [['رویداد سفارش', 'EVENT'], ['قالب پویا', 'TEMPLATE'], ['درگاه پیامک', 'GATEWAY'], ['مشتری', 'CUSTOMER']],
        ['فروشگاه نمونه', "سارا عزیز، سفارش ۴۸۲۱ شما ثبت شد.\nکد رهگیری مرسوله: ۷۳۵۰۲۹۱۸"],
        ['تأیید ثبت و ارسال سفارش', 'اطلاع‌رسانی وضعیت پرونده یا درخواست', 'یادآوری سررسید قسط و قبض', 'یادآوری نوبت و جلسه'],
        'پیامک هوشمند با قالب پویا، ارسال نظیر به نظیر'],
    ['marketing', 'پیامک تبلیغاتی',
        'کمپین به فهرست مخاطبان، گروه‌ها یا یک منطقه‌ی جغرافیایی؛ زمان‌بندی‌شده و با امکان لغو عضویت برای گیرنده.',
        'fanout',
        [['فهرست مخاطبان', 'AUDIENCE'], ['زمان‌بندی کمپین', 'SCHEDULE'], ['درگاه پیامک', 'GATEWAY'], ['گیرندگان', 'RECIPIENTS']],
        ['فروشگاه نمونه', "جشنواره‌ی پاییزه از فردا آغاز می‌شود؛\nتا ۲۰٪ تخفیف روی همه‌ی کالاها.\nلغو۱۱"],
        ['معرفی محصول و جشنواره‌ی فروش', 'دعوت به رویداد و نمایشگاه', 'باشگاه مشتریان و پیام مناسبتی', 'ارسال منطقه‌ای برای فروشگاه‌های محلی'],
        'ارسال گروهی و منطقه‌ای، زمان‌بندی، لغو۱۱'],
    ['system', 'پیامک سیستمی و خودکار',
        'پیامی که هیچ انسانی آن را نمی‌نویسد: سامانه‌ها خودشان وضعیت را پایش می‌کنند و به افراد مسئول خبر می‌دهند.',
        'cycle',
        [['پایش سامانه', 'MONITOR'], ['قاعده‌ی هشدار', 'RULE'], ['درگاه پیامک', 'GATEWAY'], ['تیم مسئول', 'ON-CALL']],
        ['پایش سرور', "هشدار: فضای دیسک سرور DB-02 به ۹۲٪ رسید.\nزمان: ۰۳:۱۴"],
        ['هشدار خرابی و قطعی سامانه‌ها', 'گزارش‌های دوره‌ای و زمان‌بندی‌شده', 'پیام تولد و مناسبت‌های تکرارشونده', 'پاسخ خودکار به پیامک‌های دریافتی'],
        'ارسال دوره‌ای، وب‌سرویس و منشی پیامک خودکار'],
    ['report', 'گزارش‌گیری و رهگیری',
        'سرنوشت هر پیام تا رسیدن به گوشی گیرنده روشن است؛ وضعیت تحویل در پنل دیده می‌شود و به سامانه‌ی شما برمی‌گردد.',
        'return',
        [['ارسال پیام', 'SUBMIT'], ['اپراتور', 'SMSC'], ['گزارش تحویل', 'DLR'], ['پنل و وب‌هوک', 'WEBHOOK']],
        ['وضعیت پیام', "⁦۰۹۱۲***۴۴۷۱⁩ — تحویل‌شده\n⁦۰۹۳۵***۱۱۸۷⁩ — در انتظار گزارش\n⁦۰۹۱۹***۰۰۳۲⁩ — ارسال‌شده"],
        ['پیگیری تحویل هر پیام و هر بخش آن', 'وب‌هوک وضعیت به سامانه‌ی شما', 'گزارش وضعیت و خروجی CSV', 'گزارش روزانه و مقایسه‌ی ارسال‌ها'],
        'گزارش پیام‌ها، گزارش وضعیت، وب‌هوک'],
];

/** One service's flow diagram: four stations right-to-left plus the geometry of its kind. */
$ssFlowSvg = static function (string $slug, string $kind, array $nodes): string {
    $cx = [565, 405, 245, 85];          // station centres, right to left
    $w = 128; $h = 52; $y = 110;        // station size and the main line
    $m = 'ssArrow-' . $slug;
    $o = '<svg class="ss-flow-svg" viewBox="0 0 650 230" role="presentation" aria-hidden="true" focusable="false">'
       . '<defs><marker id="' . $m . '" viewBox="0 0 8 8" refX="7" refY="4" markerWidth="8" markerHeight="8" orient="auto-start-reverse"><path d="M0 0 8 4 0 8" fill="none" stroke="currentColor" stroke-width="1.4"/></marker></defs>'
       . '<g class="ss-flow-grid"><path d="M0 30H650M0 190H650"/></g>';
    $lines = '';
    $last = $kind === 'fanout' ? 2 : 3; // the fan-out replaces the last station with its fan
    for ($i = 0; $i < $last; $i++) {
        $lines .= '<path class="ss-flow-line" pathLength="1" d="M' . ($cx[$i] - $w / 2) . ' ' . $y . ' H' . ($cx[$i + 1] + $w / 2 + 2) . '" marker-end="url(#' . $m . ')"/>';
    }
    $extra = ''; $packet = 'M' . $cx[0] . ' ' . $y . ' H' . $cx[3];
    switch ($kind) {
        case 'loop': // the code comes back: the user types it and the application verifies it
            $extra = '<path class="ss-flow-line is-return" pathLength="1" d="M' . $cx[3] . ' ' . ($y - $h / 2) . ' V48 H' . $cx[0] . ' V' . ($y - $h / 2 - 2) . '" marker-end="url(#' . $m . ')"/>'
                   . '<text class="ss-flow-note" x="325" y="40">کاربر کد را وارد می‌کند و برنامه آن را تطبیق می‌دهد</text>';
            $packet = 'M' . $cx[0] . ' ' . $y . ' H' . $cx[3] . ' V48 H' . $cx[0] . ' V' . $y;
            break;
        case 'merge': // customer fields flow into the template from below
            foreach (['{نام}', '{کد}', '{مبلغ}'] as $k => $f) {
                $fx = $cx[1] - 40 + $k * 40;
                $extra .= '<path class="ss-flow-line is-thin" pathLength="1" d="M' . $fx . ' 178 V' . ($y + $h / 2 + 2) . '" marker-end="url(#' . $m . ')"/>'
                        . '<text class="ss-flow-field" x="' . $fx . '" y="198">' . $f . '</text>';
            }
            $extra .= '<text class="ss-flow-note" x="' . ($cx[1] + 58) . '" y="168" text-anchor="end">ردیف‌های فایل مشتریان</text>';
            break;
        case 'fanout': // one campaign, many recipients
            $ys = [46, 78, 110, 142, 174];
            $packet = '';
            foreach ($ys as $k => $fy) {
                $d = 'M' . ($cx[2] - $w / 2) . ' ' . $y . ' C' . ($cx[2] - $w / 2 - 50) . ' ' . $y . ' ' . 110 . ' ' . $fy . ' 60 ' . $fy;
                $extra .= '<path class="ss-flow-line is-thin" pathLength="1" d="' . $d . '"/>'
                        . '<rect class="ss-flow-end" x="40" y="' . ($fy - 7) . '" width="14" height="14"/>';
                $packet .= '<path class="ss-flow-track" data-track d="M' . $cx[0] . ' ' . $y . ' H' . ($cx[2] - $w / 2) . substr($d, strpos($d, ' C')) . '"/>';
            }
            $extra .= '<text class="ss-flow-note" x="47" y="212">گیرندگان</text>';
            break;
        case 'cycle': // the monitor wakes up on its own schedule
            $extra = '<path class="ss-flow-line is-thin" pathLength="1" d="M' . ($cx[0] + 18) . ' 52 A22 22 0 1 0 ' . ($cx[0] - 18) . ' 52" marker-end="url(#' . $m . ')"/>'
                   . '<text class="ss-flow-note" x="' . ($cx[0] - 36) . '" y="44" text-anchor="start">پایش دوره‌ای</text>'
                   . '<path class="ss-flow-line is-return" pathLength="1" d="M' . $cx[3] . ' ' . ($y + $h / 2) . ' V176 H' . $cx[0] . ' V' . ($y + $h / 2 + 2) . '" marker-end="url(#' . $m . ')"/>'
                   . '<text class="ss-flow-note" x="325" y="198">تأیید دریافت هشدار، چرخه را می‌بندد</text>';
            $packet = 'M' . $cx[0] . ' ' . $y . ' H' . $cx[3] . ' V176 H' . $cx[0] . ' V' . $y;
            break;
        case 'return': // forward on the top lane, the delivery status comes back on the bottom lane
            $extra = '<path class="ss-flow-line is-return" pathLength="1" d="M' . $cx[1] . ' ' . ($y + $h / 2) . ' V172 H' . ($cx[2] + 2) . ' V' . ($y + $h / 2 + 2) . '" marker-end="url(#' . $m . ')"/>'
                   . '<text class="ss-flow-note" x="325" y="194">وضعیت تحویل از اپراتور</text>';
            foreach (['در صف', 'ارسال‌شده', 'تحویل‌شده'] as $k => $st) {
                $extra .= '<g class="ss-flow-state" style="--k:' . $k . '"><rect x="' . (470 - $k * 110) . '" y="14" width="96" height="22"/><text x="' . (518 - $k * 110) . '" y="29">' . $st . '</text></g>';
            }
            $packet = 'M' . $cx[0] . ' ' . $y . ' H' . $cx[1] . ' V172 H' . $cx[2] . ' V' . $y . ' H' . $cx[3];
            break;
    }
    if ($kind !== 'fanout') $packet = '<path class="ss-flow-track" data-track d="' . $packet . '"/>';
    $o .= $packet . $lines . $extra . '<g class="ss-packets" data-packets></g>';
    foreach ($nodes as $i => [$title, $tech]) {
        if ($kind === 'fanout' && $i === 3) break;
        $x = $cx[$i] - $w / 2;
        $o .= '<g class="ss-flow-node' . ($i === 2 ? ' is-gateway' : '') . '" data-node="' . $i . '">'
            . '<rect x="' . $x . '" y="' . ($y - $h / 2) . '" width="' . $w . '" height="' . $h . '"/>'
            . '<text class="ss-flow-tech" x="' . ($x + $w - 8) . '" y="' . ($y - $h / 2 + 15) . '">' . e($tech) . '</text>'
            . '<text class="ss-flow-title" x="' . $cx[$i] . '" y="' . ($y + 14) . '">' . e($title) . '</text>'
            . '</g>';
    }
    return $o . '</svg>';
};

/* Simulator scenarios: [key, label, sender, text]. The texts are samples, never sent. */
$ssScenarios = [
    ['otp',      'کد تأیید ورود',     'سامانه‌ی نمونه',   'کد تأیید ورود شما: ۸۴۲۹۱۶'],
    ['order',    'تأیید سفارش',       'فروشگاه نمونه',    'سارا عزیز، سفارش ۴۸۲۱ شما ثبت شد و فردا ارسال می‌شود.'],
    ['visit',    'یادآوری نوبت',      'کلینیک نمونه',     'یادآوری نوبت: فردا ساعت ۱۰:۳۰، دکتر محمدی. برای لغو، عدد ۱ را ارسال کنید.'],
    ['txn',      'اطلاع تراکنش',      'بانک نمونه',       'برداشت ۱٬۲۵۰٬۰۰۰ ریال از حساب ⁦۰۱۲***۴۷۱۱⁩ — مانده: ۸٬۴۱۰٬۵۰۰ ریال'],
    ['campaign', 'کمپین تبلیغاتی',    'فروشگاه نمونه',    'جشنواره‌ی پاییزه از فردا آغاز می‌شود؛ تا ۲۰٪ تخفیف روی همه‌ی کالاها. لغو۱۱'],
];
$ssSimStages = [
    ['دریافت درخواست', 'درخواست از وب‌سرویس پذیرفته و شناسه‌ی رهگیری ثبت شد.'],
    ['پردازش پیام',    'شماره و متن بررسی شد؛ پیام در صف ارسال قرار گرفت.'],
    ['ارسال به اپراتور', 'پیام به مسیر اپراتور گیرنده سپرده شد.'],
    ['تحویل پیام',     'پیام روی گوشی گیرنده نشست.'],
    ['گزارش وضعیت',    'گزارش تحویل رسید؛ وضعیت پیام در گزارش‌ها «تحویل‌شده» شد.'],
];

/* Scroll story: [title, text, technical notes]. Layer n of the sticky diagram belongs to stage n. */
$ssStory = [
    ['اتصال کسب‌وکار', 'سایت، اپلیکیشن یا نرم‌افزار سازمانی از راه وب‌سرویس REST و SDK به درگاه وصل می‌شود؛ تیم‌هایی که نرم‌افزار ندارند، همین کار را از پنل تحت وب انجام می‌دهند.', ['REST API · کلید دسترسی با محدوده', 'SDK و ارسال با URL', 'پنل تحت وب']],
    ['دریافت و پردازش درخواست', 'هر درخواست پیش از ارسال بررسی می‌شود: اعتبار شماره‌ها، فهرست سیاه و لغو عضویت، واژه‌های غیرمجاز و تعداد بخش‌های پیام. سپس پیام در صف ارسال می‌نشیند.', ['اعتبارسنجی شماره و متن', 'شمارش بخش‌ها', 'صف ارسال']],
    ['مسیریابی و ارسال پیام', 'پیام بر اساس اپراتور گیرنده به مسیر مناسب سپرده می‌شود و درگاه، پاسخ اپراتور را برای همان پیام ثبت می‌کند.', ['تشخیص اپراتور گیرنده', 'درگاه‌های ارسال', 'ثبت پاسخ اپراتور']],
    ['رهگیری وضعیت تحویل', 'اپراتور گزارش تحویل را برمی‌گرداند. وضعیت هر پیام از «در صف» تا «تحویل‌شده» یا «تحویل‌نشده» در پنل دیده می‌شود.', ['گزارش تحویل (DLR)', 'وضعیت هر بخش پیام', 'به‌روزرسانی خودکار']],
    ['گزارش‌گیری و تحلیل', 'وضعیت‌ها با وب‌هوک به سامانه‌ی شما برمی‌گردند و در گزارش‌های پنل، خروجی CSV و گزارش روزانه کنار هم قرار می‌گیرند.', ['وب‌هوک وضعیت', 'گزارش وضعیت و خروجی CSV', 'گزارش روزانه']],
];

/* Industries: [title, problem, solution, sample line, glyph]. Illustrative scenarios, not clients. */
$ssIndustries = [
    ['بانک و خدمات مالی', 'مشتری باید از هر برداشت و هر ورود به حسابش همان لحظه باخبر شود؛ تأخیر یعنی تماس با مرکز پاسخ‌گویی.', 'پیامک تراکنش و کد تأیید یکبارمصرف از وب‌سرویس، با رهگیری تحویل هر پیام.', 'برداشت ۱٬۲۵۰٬۰۰۰ ریال — مانده: ۸٬۴۱۰٬۵۰۰', 'bank'],
    ['فروشگاه اینترنتی', 'پرسش تکراری «سفارشم کجاست؟» بار پشتیبانی را بالا می‌برد.', 'اطلاع‌رسانی خودکار هر مرحله‌ی سفارش با قالب پویا: نام، شماره‌ی سفارش و کد رهگیری مرسوله.', 'سفارش ۴۸۲۱ تحویل پست شد.', 'cart'],
    ['سلامت و درمان', 'نوبتی که بیمار فراموش می‌کند، زمان پزشک را هدر می‌دهد.', 'یادآوری زمان‌بندی‌شده‌ی نوبت پیش از مراجعه، با امکان لغو از راه پاسخ پیامکی.', 'نوبت شما: فردا ساعت ۱۰:۳۰', 'health'],
    ['سازمان‌های بزرگ', 'خبر یک رخداد عملیاتی باید بدون واسطه به همه‌ی افراد مسئول برسد.', 'ارسال گروهی به فهرست‌های سازمانی و پیامک سیستمی خودکار از سامانه‌های پایش.', 'هشدار: قطعی سرویس در شعبه‌ی مرکزی', 'org'],
    ['پلتفرم‌های دیجیتال', 'ثبت‌نام و ورود کاربران به کد تأییدی وابسته است که سریع و قابل‌اتکا برسد.', 'اتصال از REST API و SDK، قالب‌های کد تأیید و وب‌هوک وضعیت تحویل.', 'کد ورود شما: ۵۱۷۳۰۲', 'app'],
];
$ssGlyph = static function (string $k): string {
    $g = [
        'bank'   => '<path d="M8 30 40 12l32 18Z"/><path d="M14 34v26M30 34v26M50 34v26M66 34v26M6 64h68M4 70h72"/><path class="ss-g-hot" d="M36 44h8v10h-8z"/>',
        'cart'   => '<path d="M6 14h10l8 36h42l6-26H22"/><circle cx="30" cy="60" r="5"/><circle cx="60" cy="60" r="5"/><path class="ss-g-hot" d="M34 30h28M37 40h22"/>',
        'health' => '<rect x="10" y="12" width="60" height="56"/><path d="M10 26h60M24 6v12M56 6v12"/><path class="ss-g-hot" d="M36 34h8v8h8v8h-8v8h-8v-8h-8v-8h8z"/>',
        'org'    => '<rect x="30" y="6" width="20" height="14"/><rect x="6" y="56" width="20" height="14"/><rect x="30" y="56" width="20" height="14"/><rect x="54" y="56" width="20" height="14"/><path d="M40 20v18M16 56V38h48v18M40 38v18"/><circle class="ss-g-hot" cx="40" cy="38" r="3"/>',
        'app'    => '<rect x="20" y="4" width="40" height="72"/><path d="M34 68h12"/><path class="ss-g-hot" d="M28 26h24v14H28zM32 33h2m5 0h2m5 0h2"/><path d="M28 50h24"/>',
    ];
    return '<svg class="ss-glyph" viewBox="0 0 80 80" aria-hidden="true" focusable="false">' . $g[$k] . '</svg>';
};
$ssArrow = '<svg class="ss-arrow" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M19 12H5"/><path d="m11 18-6-6 6-6"/></svg>';

require __DIR__ . '/../app/views/public_header.php';
?>
<div class="ss" data-ss>

  <!-- ================================================================ HERO: command center -->
  <section class="ss-hero" aria-labelledby="ssHeroTitle">
    <div class="ss-wrap ss-hero-grid">
      <div class="ss-hero-copy">
        <p class="ss-eyebrow"><span>خدمات پیامک</span><span class="ss-eyebrow-rule" aria-hidden="true"></span><span class="ss-mono">SMS INFRASTRUCTURE</span></p>
        <h1 id="ssHeroTitle" class="ss-display"><span class="ss-mask"><span>هر پیام،</span></span> <span class="ss-mask"><span>یک ارتباط <em>مؤثر.</em></span></span></h1>
        <p class="ss-hero-lead">زیرساخت یکپارچه ارسال، مدیریت و رهگیری پیامک‌های سازمانی، خدماتی و تبلیغاتی.</p>
        <div class="ss-cta-row">
          <a class="btn btn-primary ss-btn" href="<?= e($ssConsult) ?>">دریافت مشاوره تخصصی</a>
          <a class="ss-textlink" href="#explorer">کشف قابلیت‌ها <?= $ssArrow ?></a>
        </div>
        <dl class="ss-spec">
          <div><dt>اتصال</dt><dd>پنل تحت وب، REST API، SDK</dd></div>
          <div><dt>انواع پیام</dt><dd>کد تأیید، خدماتی، تبلیغاتی، سیستمی</dd></div>
          <div><dt>رهگیری</dt><dd>گزارش تحویل هر پیام و وب‌هوک وضعیت</dd></div>
        </dl>
      </div>

      <figure class="ss-life" data-ss-life>
        <div class="ss-life-frame">
          <span class="ss-corner is-a" aria-hidden="true"></span><span class="ss-corner is-b" aria-hidden="true"></span>
          <span class="ss-corner is-c" aria-hidden="true"></span><span class="ss-corner is-d" aria-hidden="true"></span>
          <svg class="ss-life-svg" viewBox="0 0 640 420" aria-hidden="true" focusable="false">
            <defs>
              <pattern id="ssGrid" width="20" height="20" patternUnits="userSpaceOnUse"><path d="M20 0H0V20" fill="none"/></pattern>
              <marker id="ssLifeArrow" viewBox="0 0 8 8" refX="7" refY="4" markerWidth="8" markerHeight="8" orient="auto-start-reverse"><path d="M0 0 8 4 0 8" fill="none" stroke="currentColor" stroke-width="1.4"/></marker>
            </defs>
            <rect class="ss-life-grid" width="640" height="420" fill="url(#ssGrid)"/>
            <!-- dimension marks -->
            <g class="ss-life-dim">
              <path d="M20 30H620M20 26v8M620 26v8M320 26v8"/>
              <text x="320" y="20">چرخه‌ی عمر یک پیامک — نمودار مفهومی</text>
              <path d="M628 60V364M624 60h8M624 364h8"/>
            </g>
            <!-- the path a packet runs: through every station centre, closed -->
            <path class="ss-life-track" data-track d="M545 92 H95 V332 H545 V92"/>
            <!-- visible wiring between stations -->
            <g class="ss-life-wires">
              <path pathLength="1" style="--d:0" d="M470 92H397" marker-end="url(#ssLifeArrow)"/>
              <path pathLength="1" style="--d:1" d="M245 92H172" marker-end="url(#ssLifeArrow)"/>
              <path pathLength="1" style="--d:2" d="M95 124V298" marker-end="url(#ssLifeArrow)"/>
              <path pathLength="1" style="--d:3" d="M170 332H243" marker-end="url(#ssLifeArrow)"/>
              <path pathLength="1" style="--d:4" d="M395 332H468" marker-end="url(#ssLifeArrow)"/>
              <path pathLength="1" style="--d:5" class="is-return" d="M545 300V126" marker-end="url(#ssLifeArrow)"/>
            </g>
            <g class="ss-life-notes">
              <text x="108" y="214" text-anchor="end">مسیریابی</text>
              <text x="532" y="214" text-anchor="start">بازگشت وضعیت</text>
            </g>
            <g class="ss-packets" data-packets></g>
            <?php foreach ($ssLife as $i => [$x, $y, $title, $tech]): ?>
            <g class="ss-life-node<?= $i === 1 ? ' is-gateway' : '' ?>" data-node="<?= $i ?>">
              <rect x="<?= $x ?>" y="<?= $y ?>" width="150" height="64"/>
              <text class="ss-life-idx" x="<?= $x + 142 ?>" y="<?= $y + 17 ?>"><?= $fa($i + 1) ?></text>
              <text class="ss-life-tech" x="<?= $x + 8 ?>" y="<?= $y + 17 ?>"><?= e($tech) ?></text>
              <text class="ss-life-title" x="<?= $x + 75 ?>" y="<?= $y + 46 ?>"><?= e($title) ?></text>
            </g>
            <?php endforeach; ?>
            <!-- the centre readout: which station the lead packet is at -->
            <g class="ss-life-readout">
              <path d="M200 176H440M200 256H440"/>
              <text class="ss-life-readout-k" x="320" y="198">ایستگاه فعلی</text>
              <text class="ss-life-readout-v" x="320" y="232" data-readout><?= e($ssLife[0][2]) ?></text>
            </g>
          </svg>
        </div>
        <figcaption>
          <ol class="ss-life-legend">
            <?php foreach ($ssLife as $i => [, , $title, , $desc]): ?>
            <li data-legend="<?= $i ?>"><span class="ss-num"><?= $fa($i + 1) ?></span><b><?= e($title) ?></b><span><?= e($desc) ?></span></li>
            <?php endforeach; ?>
          </ol>
          <p class="ss-disclaimer">نمودار مفهومی است و ارسال واقعی یا آمار زنده‌ای را نشان نمی‌دهد.</p>
        </figcaption>
      </figure>
    </div>
  </section>

  <!-- ================================================================ 01 — service explorer -->
  <section id="explorer" class="ss-section ss-explorer" aria-labelledby="ssExplorerTitle">
    <div class="ss-wrap">
      <header class="ss-head">
        <span class="ss-head-num" aria-hidden="true">۰۱</span>
        <div>
          <p class="ss-eyebrow"><span>قابلیت‌ها</span><span class="ss-eyebrow-rule" aria-hidden="true"></span></p>
          <h2 id="ssExplorerTitle">پنج نوع پیام، پنج مسیر متفاوت</h2>
          <p class="ss-head-lead">هر نوع پیامک مسئله‌ی خودش را دارد: یکی باید در چند ثانیه برسد، یکی برای هزاران نفر شخصی شود و دیگری بی‌دخالت انسان ارسال شود. یکی را انتخاب کنید تا مسیرش را ببینید.</p>
        </div>
      </header>

      <div class="ss-x" data-ss-explorer>
        <div class="ss-x-nav" role="tablist" aria-orientation="vertical" aria-label="خدمات پیامک">
          <?php foreach ($ssServices as $i => [$slug, $title]): ?>
          <button type="button" role="tab" class="ss-x-tab" id="ssTab-<?= $slug ?>" aria-controls="ssPanel-<?= $slug ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"<?= $i === 0 ? '' : ' tabindex="-1"' ?>>
            <span class="ss-x-tab-num"><?= $fa($i + 1) ?></span>
            <span class="ss-x-tab-title"><?= e($title) ?></span>
            <span class="ss-x-tab-bar" aria-hidden="true"></span>
          </button>
          <?php endforeach; ?>
        </div>

        <div class="ss-x-stage">
          <?php foreach ($ssServices as $i => [$slug, $title, $lead, $kind, $nodes, [$sender, $msg], $uses, $panel]): ?>
          <div class="ss-x-panel<?= $i === 0 ? ' is-active' : '' ?>" role="tabpanel" id="ssPanel-<?= $slug ?>" aria-labelledby="ssTab-<?= $slug ?>" tabindex="0"<?= $i === 0 ? '' : ' hidden' ?> data-kind="<?= $kind ?>">
            <div class="ss-x-top">
              <h3><span class="ss-num"><?= $fa($i + 1) ?></span> <?= e($title) ?></h3>
              <p><?= e($lead) ?></p>
            </div>
            <div class="ss-x-flow">
              <?= $ssFlowSvg($slug, $kind, $nodes) ?>
              <ol class="ss-x-flow-list">
                <?php foreach ($nodes as [$t]): ?><li><?= e($t) ?></li><?php endforeach; ?>
              </ol>
            </div>
            <div class="ss-x-bottom">
              <div class="ss-specimen">
                <p class="ss-specimen-k">نمونه‌ی پیام <span>(نمایشی)</span></p>
                <div class="ss-specimen-msg">
                  <p class="ss-specimen-from"><?= e($sender) ?></p>
                  <p class="ss-specimen-text"><?= nl2br(e($msg)) ?></p>
                </div>
              </div>
              <div class="ss-uses">
                <p class="ss-uses-k">کاربردها</p>
                <ul>
                  <?php foreach ($uses as $u): ?><li><?= e($u) ?></li><?php endforeach; ?>
                </ul>
                <p class="ss-uses-panel"><span>در پنل داتیس:</span> <?= e($panel) ?></p>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </section>

  <!-- ================================================================ 02 — simulator -->
  <section id="simulator" class="ss-section ss-sim" aria-labelledby="ssSimTitle">
    <div class="ss-wrap">
      <header class="ss-head">
        <span class="ss-head-num" aria-hidden="true">۰۲</span>
        <div>
          <p class="ss-eyebrow"><span>شبیه‌ساز</span><span class="ss-eyebrow-rule" aria-hidden="true"></span></p>
          <h2 id="ssSimTitle">یک پیام را تا مقصد دنبال کنید</h2>
          <p class="ss-head-lead">یک سناریو انتخاب کنید و مسیر پیام را مرحله به مرحله ببینید. این یک شبیه‌سازی نمایشی است؛ هیچ پیامکی ارسال نمی‌شود و به هیچ وب‌سرویسی وصل نیست.</p>
        </div>
      </header>

      <div class="ss-sim-grid" data-ss-sim>
        <fieldset class="ss-sim-pick">
          <legend>سناریو</legend>
          <?php foreach ($ssScenarios as $i => [$key, $label, $sender, $text]): ?>
          <label class="ss-sim-opt">
            <input type="radio" name="ssScenario" value="<?= $key ?>" data-sender="<?= e($sender) ?>" data-text="<?= e($text) ?>"<?= $i === 0 ? ' checked' : '' ?>>
            <span class="ss-sim-opt-num"><?= $fa($i + 1) ?></span>
            <span class="ss-sim-opt-label"><?= e($label) ?></span>
          </label>
          <?php endforeach; ?>
          <button type="button" class="ss-sim-replay" data-ss-replay>
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 12a8 8 0 1 0 2.4-5.7"/><path d="M4 4v4.5h4.5"/></svg>
            اجرای دوباره
          </button>
        </fieldset>

        <div class="ss-console">
          <div class="ss-console-bar">
            <span class="ss-mono">SIMULATION</span>
            <span class="ss-console-flag">نمایشی — بدون ارسال واقعی</span>
          </div>
          <div class="ss-console-msg">
            <p class="ss-console-k">پیام</p>
            <p class="ss-console-from" data-sim-sender><?= e($ssScenarios[0][2]) ?></p>
            <p class="ss-console-text" data-sim-text><?= e($ssScenarios[0][3]) ?></p>
            <p class="ss-console-meta"><span data-sim-chars></span><span data-sim-parts></span></p>
          </div>
          <div class="ss-pipe" data-sim-pipe>
            <span class="ss-pipe-line" aria-hidden="true"><i data-sim-fill></i></span>
            <span class="ss-pipe-packet" data-sim-packet aria-hidden="true"></span>
            <ol class="ss-pipe-stages">
              <?php foreach ($ssSimStages as $i => [$name, $log]): ?>
              <li class="ss-pipe-stage" data-sim-stage data-log="<?= e($log) ?>">
                <span class="ss-pipe-dot" aria-hidden="true"></span>
                <span class="ss-pipe-num"><?= $fa($i + 1) ?></span>
                <span class="ss-pipe-name"><?= e($name) ?></span>
              </li>
              <?php endforeach; ?>
            </ol>
          </div>
          <div class="ss-console-log">
            <p class="ss-console-k">گزارش مراحل</p>
            <ol data-sim-log aria-live="polite"></ol>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ================================================================ 03 — scroll story -->
  <section id="how" class="ss-section ss-story" aria-labelledby="ssStoryTitle">
    <div class="ss-wrap">
      <header class="ss-head">
        <span class="ss-head-num" aria-hidden="true">۰۳</span>
        <div>
          <p class="ss-eyebrow"><span>سازوکار</span><span class="ss-eyebrow-rule" aria-hidden="true"></span></p>
          <h2 id="ssStoryTitle">زیرساخت، لایه به لایه</h2>
          <p class="ss-head-lead">از لحظه‌ای که سامانه‌ی شما درخواست ارسال می‌دهد تا وقتی گزارش تحویل به آن برمی‌گردد، پنج لایه کار می‌کنند.</p>
        </div>
      </header>

      <div class="ss-story-grid" data-ss-story data-stage="1">
        <div class="ss-story-steps">
          <?php foreach ($ssStory as $i => [$title, $text, $notes]): ?>
          <article class="ss-step" data-step="<?= $i + 1 ?>">
            <p class="ss-step-k"><span class="ss-mono">STAGE</span> <span class="ss-num"><?= $fa($i + 1) ?></span></p>
            <h3><?= e($title) ?></h3>
            <p><?= e($text) ?></p>
            <ul><?php foreach ($notes as $n): ?><li><?= e($n) ?></li><?php endforeach; ?></ul>
          </article>
          <?php endforeach; ?>
        </div>

        <div class="ss-story-figure">
          <div class="ss-story-sticky">
            <svg class="ss-story-svg" viewBox="0 0 520 540" aria-hidden="true" focusable="false">
              <defs>
                <marker id="ssStoryArrow" viewBox="0 0 8 8" refX="7" refY="4" markerWidth="8" markerHeight="8" orient="auto-start-reverse"><path d="M0 0 8 4 0 8" fill="none" stroke="currentColor" stroke-width="1.4"/></marker>
              </defs>
              <rect class="ss-life-grid" width="520" height="540" fill="url(#ssGrid)"/>
              <!-- layer 1: business systems -->
              <g class="ss-layer" data-layer="1">
                <?php foreach ([['وب‌سایت', 380], ['اپلیکیشن', 210], ['سامانه‌ی داخلی', 40]] as [$t, $x]): ?>
                <g class="ss-s-node"><rect x="<?= $x ?>" y="24" width="100" height="44"/><text x="<?= $x + 50 ?>" y="51"><?= e($t) ?></text></g>
                <?php endforeach; ?>
                <path class="ss-s-wire" pathLength="1" d="M430 68V100H290V126" marker-end="url(#ssStoryArrow)"/>
                <path class="ss-s-wire" pathLength="1" d="M260 68V126" marker-end="url(#ssStoryArrow)"/>
                <path class="ss-s-wire" pathLength="1" d="M90 68V100H230V126" marker-end="url(#ssStoryArrow)"/>
                <text class="ss-s-note" x="340" y="94">REST API · پنل</text>
                <path class="ss-s-track" data-track d="M260 46V140"/>
              </g>
              <!-- layer 2: gateway and processing -->
              <g class="ss-layer" data-layer="2">
                <g class="ss-s-node is-gateway"><rect x="150" y="128" width="220" height="104"/>
                  <text class="ss-s-tech" x="362" y="146">GATEWAY</text>
                  <text class="ss-s-title" x="260" y="168">درگاه داتیس رایان</text>
                </g>
                <g class="ss-s-checks">
                  <?php foreach (['اعتبارسنجی', 'شمارش بخش', 'صف ارسال'] as $k => $t): ?>
                  <g style="--k:<?= $k ?>"><rect x="<?= 296 - $k * 70 ?>" y="186" width="62" height="30"/><text x="<?= 327 - $k * 70 ?>" y="206"><?= e($t) ?></text></g>
                  <?php endforeach; ?>
                </g>
                <path class="ss-s-track" data-track d="M330 201H190"/>
              </g>
              <!-- layer 3: routing to operators -->
              <g class="ss-layer" data-layer="3">
                <?php foreach ([['همراه اول', 380, 'M300 232V262H430V300'], ['ایرانسل', 210, 'M260 232V300'], ['رایتل', 40, 'M220 232V262H90V300']] as [$t, $x, $d]): ?>
                <path class="ss-s-wire" pathLength="1" d="<?= $d ?>" marker-end="url(#ssStoryArrow)"/>
                <g class="ss-s-node"><rect x="<?= $x ?>" y="302" width="100" height="44"/><text class="ss-s-tech" x="<?= $x + 92 ?>" y="316">SMSC</text><text x="<?= $x + 50 ?>" y="336"><?= e($t) ?></text></g>
                <path class="ss-s-track" data-track d="<?= $d ?>V324"/>
                <?php endforeach; ?>
                <?php foreach ([430, 260, 90] as $x): ?>
                <path class="ss-s-wire is-thin" pathLength="1" d="M<?= $x ?> 346V396" marker-end="url(#ssStoryArrow)"/>
                <rect class="ss-s-phone" x="<?= $x - 12 ?>" y="398" width="24" height="36"/>
                <?php endforeach; ?>
                <text class="ss-s-note" x="260" y="456">گیرندگان</text>
              </g>
              <!-- layer 4: delivery reports come back -->
              <g class="ss-layer" data-layer="4">
                <path class="ss-s-wire is-return" pathLength="1" d="M478 416H500V180H372" marker-end="url(#ssStoryArrow)"/>
                <path class="ss-s-wire is-return" pathLength="1" d="M42 416H20V180H148" marker-end="url(#ssStoryArrow)"/>
                <text class="ss-s-note is-v" x="510" y="300">DLR</text>
                <text class="ss-s-note is-v" x="10" y="300">DLR</text>
                <path class="ss-s-track" data-track d="M442 416H500V180H372"/>
                <path class="ss-s-track" data-track d="M78 416H20V180H148"/>
              </g>
              <!-- layer 5: reports and webhook back to the business -->
              <g class="ss-layer" data-layer="5">
                <g class="ss-s-node is-report"><rect x="150" y="470" width="220" height="56"/>
                  <text class="ss-s-tech" x="362" y="486">REPORTS</text>
                  <text x="260" y="508">گزارش‌ها · CSV · گزارش روزانه</text>
                </g>
                <path class="ss-s-wire is-return" pathLength="1" d="M150 498H6V8H90V22" marker-end="url(#ssStoryArrow)"/>
                <text class="ss-s-note" x="12" y="462" text-anchor="end">وب‌هوک</text>
                <path class="ss-s-track" data-track d="M150 498H6V8H90V22"/>
              </g>
              <g class="ss-packets" data-packets></g>
            </svg>
            <p class="ss-story-readout" aria-hidden="true"><span class="ss-mono">STAGE</span> <b data-story-num>۰۱</b> <span data-story-name><?= e($ssStory[0][0]) ?></span></p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ================================================================ 04 — industries -->
  <section id="industries" class="ss-section ss-ind" aria-labelledby="ssIndTitle">
    <div class="ss-wrap">
      <header class="ss-head ss-head-split">
        <span class="ss-head-num" aria-hidden="true">۰۴</span>
        <div>
          <p class="ss-eyebrow"><span>کاربردها</span><span class="ss-eyebrow-rule" aria-hidden="true"></span></p>
          <h2 id="ssIndTitle">یک مسئله، یک پیام</h2>
          <p class="ss-head-lead">سناریوهای نمونه از صنایع مختلف؛ هر کدام یک مسئله‌ی مشخص کسب‌وکار و پیامی که آن را حل می‌کند.</p>
        </div>
        <div class="ss-ind-ctrl" aria-hidden="false">
          <button type="button" class="ss-ind-btn" data-ind-prev aria-label="سناریوی قبلی"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 12h14"/><path d="m13 6 6 6-6 6"/></svg></button>
          <button type="button" class="ss-ind-btn" data-ind-next aria-label="سناریوی بعدی"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M19 12H5"/><path d="m11 18-6-6 6-6"/></svg></button>
        </div>
      </header>
    </div>
    <div class="ss-ind-rail" data-ind-rail tabindex="0" role="region" aria-label="سناریوهای صنایع؛ با کلیدهای جهت‌نما یا کشیدن، جابه‌جا شوید">
      <ol class="ss-ind-list">
        <?php foreach ($ssIndustries as $i => [$title, $problem, $solution, $sample, $glyph]): ?>
        <li class="ss-ind-item">
          <div class="ss-ind-top">
            <span class="ss-ind-num"><?= $fa($i + 1) ?></span>
            <?= $ssGlyph($glyph) ?>
          </div>
          <h3><?= e($title) ?></h3>
          <dl>
            <dt>مسئله</dt><dd><?= e($problem) ?></dd>
            <dt>راهکار پیامکی</dt><dd><?= e($solution) ?></dd>
          </dl>
          <p class="ss-ind-sample"><span class="ss-mono">SMS</span> <?= e($sample) ?></p>
        </li>
        <?php endforeach; ?>
      </ol>
    </div>
    <div class="ss-wrap"><p class="ss-disclaimer">سناریوها نمونه‌اند و به مشتری یا سازمان مشخصی اشاره ندارند.</p></div>
  </section>

  <!-- ================================================================ closing CTA -->
  <section class="ss-final" aria-labelledby="ssFinalTitle">
    <div class="ss-wrap ss-final-grid">
      <svg class="ss-final-line" viewBox="0 0 1200 120" preserveAspectRatio="none" aria-hidden="true" focusable="false">
        <path pathLength="1" d="M1200 60H760V20H420V100H0"/>
      </svg>
      <h2 id="ssFinalTitle">زیرساخت ارتباطی کسب‌وکار شما، از همین‌جا شروع می‌شود.</h2>
      <div class="ss-final-side">
        <p>کارشناسان داتیس نوع پیام‌ها، حجم ارسال و روش اتصال سامانه‌ی شما را بررسی می‌کنند و راهکار مناسب را پیشنهاد می‌دهند.</p>
        <div class="ss-cta-row">
          <a class="btn btn-primary ss-btn" href="<?= e($ssConsult) ?>">طراحی راهکار پیامکی سازمان شما</a>
          <a class="ss-textlink is-light" href="<?= e($primaryHref) ?>"><?= e($primaryLabel) ?> <?= $ssArrow ?></a>
        </div>
      </div>
    </div>
  </section>
</div>
<script src="<?= e(asset_url('/assets/js/sms-services.js')) ?>" defer></script>
<?php require __DIR__ . '/../app/views/public_footer.php'; ?>
