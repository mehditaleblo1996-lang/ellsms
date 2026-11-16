<?php
/**
 * ELLSMS — public legal pages (terms, privacy, refund) and the provider identity they show.
 *
 * The provider's legal identity is entered by an admin under «تنظیمات ← تماس با ما» and shown on
 * /terms, /privacy, /refund and /contact. Nothing is invented here: a field left empty is simply
 * not shown.
 *
 * LEGAL_TERMS_VERSION is recorded (in the audit log) whenever someone accepts the terms at sign-up.
 * Bump it whenever the text of terms.php, privacy.php or refund.php changes in substance.
 */

require_once __DIR__ . '/bootstrap.php';

const LEGAL_TERMS_VERSION = '1405-08-05';
const LEGAL_TERMS_UPDATED_FA = '۵ آبان ۱۴۰۵';

/** Days the provider takes to pay out an approved refund; admin-editable, default 10 working days. */
function legal_refund_processing_days(): int {
    $days = (int) from_persian_digits((string) setting('legal_refund_processing_days', '10'));
    return $days > 0 ? $days : 10;
}

/**
 * The provider identity rows that are filled in, as [label, value, ltr] — in display order.
 * @return list<array{0:string,1:string,2:bool}>
 */
function legal_identity_rows(): array {
    $rows = [
        ['نام ارائه‌دهنده', trim((string) setting('legal_company_name', '')), false],
        ['شماره ثبت', trim((string) setting('legal_registration_no', '')), true],
        ['شناسه ملی', trim((string) setting('legal_national_id', '')), true],
        ['نشانی', trim((string) setting('contact_address', '')), false],
        ['تلفن', trim((string) setting('contact_phone', '')), true],
        ['ایمیل', trim((string) setting('contact_email', '')), true],
    ];
    return array_values(array_filter($rows, static fn(array $r): bool => $r[1] !== ''));
}

/** The provider name for running text: the registered name if entered, else the brand. */
function legal_provider_name(): string {
    $name = trim((string) setting('legal_company_name', ''));
    return $name !== '' ? $name : 'داتیس رایان گستر ایرانیان';
}

/** Renders the «مشخصات ارائه‌دهنده» block shared by the legal pages. */
function legal_identity_block(): string {
    $rows = legal_identity_rows();
    $html = '<dl class="lp-legal-identity">';
    if (!$rows) {
        $html .= '<dt>ارائه‌دهنده</dt><dd>داتیس رایان گستر ایرانیان — برای ارتباط از صفحه‌ی <a href="/contact">تماس با ما</a> استفاده کنید.</dd>';
    }
    foreach ($rows as [$label, $value, $ltr]) {
        $html .= '<dt>' . e($label) . '</dt><dd' . ($ltr ? ' class="ltr"' : '') . '>' . nl2br(e($value)) . '</dd>';
    }
    return $html . '</dl>';
}

/** The site's eNamad trust seal (from the code enamad.ir issued for it). */
const LEGAL_ENAMAD_ID = '8308816';
const LEGAL_ENAMAD_CODE = '2UqX8SOQMWSOgUruuThqNA8IY4shqPhu';

/**
 * The trust badges shown in both footers (ساماندهی، اینماد، اتحادیه کسب و کارهای مجازی). The eNamad
 * badge is the official seal issued by enamad.ir for this site: an image served by
 * trustseal.enamad.ir inside a link to its public verification page (both with
 * referrerpolicy="origin", which enamad.ir needs to match the seal to the domain, and no rel
 * attribute: enamad.ir says rel="noopener noreferrer" on the link stops the seal from showing). The image host is
 * allowed in the Content-Security-Policy's img-src (send_security_headers() in app/bootstrap.php).
 *
 * @param int $height badge height in CSS pixels
 */
function legal_trust_badges_html(int $height): string {
    $style = 'display:block;width:auto;height:' . $height . 'px;max-width:150px;object-fit:contain';

    $innerStyle = 'display:block;width:auto;height:' . max(1, $height - 12) . 'px;max-width:138px;object-fit:contain';
    $query = 'id=' . LEGAL_ENAMAD_ID . '&Code=' . LEGAL_ENAMAD_CODE;
    // Kept as close as possible to the snippet enamad.ir issues: no rel attribute (enamad.ir warns that
    // rel="noopener noreferrer" stops the seal from showing) and referrerpolicy="origin" on both tags.
    // The seal image has a transparent background with dark-blue art and grey stars, which vanish on
    // the dark landing footer, so it sits on a white card (like the footer logo).
    $enamad = '<a referrerpolicy="origin" target="_blank" href="' . e('https://trustseal.enamad.ir/?' . $query) . '"'
            . ' style="display:block;line-height:0;background:#fff;border-radius:12px;padding:6px">'
            . '<img referrerpolicy="origin" src="' . e('https://trustseal.enamad.ir/logo.aspx?' . $query) . '"'
            . ' alt="نماد اعتماد الکترونیکی (اینماد)" style="cursor:pointer;' . $innerStyle . '" code="' . LEGAL_ENAMAD_CODE . '"></a>';
    $samandehi = '<img src="/assets/img/footer-image-1.png" alt="نشان ملی ثبت رسانه‌های دیجیتال (ساماندهی)" loading="lazy" decoding="async" style="' . $style . '">';

    return $samandehi . $enamad . legal_ecunion_badge_html($height);
}

/**
 * The «عضو اتحادیه کشوری کسب و کارهای مجازی» (ecunion.ir) membership badge. Drawn inline rather than
 * as an <img> so its caption uses the site font and the footer's text colour (readable on both the
 * dark landing footer and the light panel footer). Clicking it opens ecunion.ir's verification page
 * for datisrayan.com in a popup — or, without JavaScript, in a new tab.
 */
function legal_ecunion_badge_html(int $height): string {
    $url = 'https://ecunion.ir/verify/datisrayan.com?token=66339366af96fdccce72';
    $width = (int) round($height * 240 / 360);
    $popup = "window.open(this.href,'ecunion','toolbar=no,location=no,status=no,menubar=no,scrollbars=1,resizable=0,width=580,height=600,top=30');return false;";

    return '<a class="ecunion-badge" href="' . e($url) . '" target="_blank" rel="noopener" onclick="' . e($popup) . '"'
         . ' aria-label="عضو اتحادیه کشوری کسب و کارهای مجازی — استعلام در سایت ecunion.ir" style="display:block;line-height:0;color:inherit">'
         . '<svg viewBox="0 0 240 360" width="' . $width . '" height="' . $height . '" role="img" aria-hidden="true" focusable="false" style="display:block">'
         . '<path d="m120 243l94-54 0-109-94 54 0 109z" fill="#808285"/>'
         . '<path d="m120 254l-103-60 0-119 103-60 103 60 0 119-103 60z" fill="none" stroke="#00aeef" stroke-width="5" stroke-linejoin="round"/>'
         . '<path d="m214 80l-94-54-94 54 94 54 94-54z" fill="#00aeef"/>'
         . '<path d="m26 80l0 109 94 54 0-109-94-54z" fill="#58595b"/>'
         . '<path d="m120 157l47-27 0-23-47-27-47 27 0 54 47 27 47-27" fill="none" stroke="#fff" stroke-width="15" stroke-linecap="round" stroke-linejoin="round"/>'
         . '<g fill="currentColor" font-family="Vazirmatn, \'B Yekan\', Tahoma, sans-serif" font-weight="700" font-size="25" text-anchor="middle" direction="rtl">'
         . '<text x="120" y="300">عضو اتحادیه کشوری</text>'
         . '<text x="120" y="343">کسب و کارهای مجازی</text>'
         . '</g></svg></a>';
}

/** Links to the legal pages, for both footers. */
function legal_footer_links_html(): string {
    return '<nav class="legal-links" aria-label="اطلاعات حقوقی">'
         . '<a href="/terms">قوانین و مقررات</a>'
         . '<a href="/privacy">حریم خصوصی</a>'
         . '<a href="/refund">انصراف و بازگشت وجه</a>'
         . '<a href="/contact">تماس با ما</a>'
         . '</nav>';
}

/**
 * The non-empty lines of a multi-line contact setting — the settings form takes one address or one
 * phone number per line.
 * @return list<string>
 */
function contact_lines(string $key): array {
    $lines = preg_split('/\R/u', trim((string) setting($key, ''))) ?: [];
    return array_values(array_filter(array_map('trim', $lines), static fn(string $l): bool => $l !== ''));
}

/**
 * A tel: link for a phone number as the admin typed it: Persian digits folded, separators dropped and
 * a leading 0 (021…, 0912…) turned into +98. '' when the text holds no digits.
 */
function contact_tel_href(string $phone): string {
    $digits = preg_replace('/\D+/', '', from_persian_digits($phone)) ?? '';
    if ($digits === '') {
        return '';
    }
    if (str_starts_with($digits, '0098')) {
        $digits = substr($digits, 4);
    } elseif (str_starts_with($digits, '98') && strlen($digits) > 10) {
        $digits = substr($digits, 2);
    } elseif (str_starts_with($digits, '0')) {
        $digits = substr($digits, 1);
    } else {
        return 'tel:' . $digits; // a short code or a local number: dial it exactly as written
    }
    return 'tel:+98' . $digits;
}

/** The exact office pin set under «تنظیمات ← تماس با ما», as [lat, lng], or null when not set. */
function contact_map_pin(): ?array {
    $lat = trim(from_persian_digits((string) setting('contact_map_lat', '')));
    $lng = trim(from_persian_digits((string) setting('contact_map_lng', '')));
    if (!is_numeric($lat) || !is_numeric($lng)) {
        return null;
    }
    $lat = (float) $lat;
    $lng = (float) $lng;
    return ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) ? [$lat, $lng] : null;
}

/**
 * Where the contact-page map points: "lat,lng" when a pin is set, otherwise the first address line
 * (map services geocode it themselves). '' when neither is entered.
 */
function contact_map_query(): string {
    $pin = contact_map_pin();
    if ($pin !== null) {
        return $pin[0] . ',' . $pin[1];
    }
    return from_persian_digits(contact_lines('contact_address')[0] ?? '');
}

/**
 * "Open in …" links for the office location, as [label, url]. Neshan and Balad only take coordinates,
 * so they appear once a pin is set.
 * @return list<array{0:string,1:string}>
 */
function contact_map_links(): array {
    $query = contact_map_query();
    if ($query === '') {
        return [];
    }
    $links = [
        ['Google Maps', 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($query)],
    ];
    $pin = contact_map_pin();
    if ($pin !== null) {
        [$lat, $lng] = $pin;
        $links[] = ['نشان', 'https://neshan.org/maps/@' . $lat . ',' . $lng . ',17z,0p'];
        $links[] = ['بلد', 'https://balad.ir/location?latitude=' . $lat . '&longitude=' . $lng . '&zoom=17'];
        $links[] = ['Waze', 'https://waze.com/ul?ll=' . rawurlencode($lat . ',' . $lng) . '&navigate=yes'];
    } else {
        $links[] = ['Waze', 'https://waze.com/ul?q=' . rawurlencode($query) . '&navigate=yes'];
    }
    return $links;
}

/**
 * The OpenStreetMap embed shown on the contact page with the office pin; '' while no pin is set.
 * OpenStreetMap is used because Google's embedded maps are unreliable from Iran.
 */
function contact_map_osm_embed_url(): string {
    $pin = contact_map_pin();
    if ($pin === null) {
        return '';
    }
    [$lat, $lng] = $pin;
    $bbox = implode(',', [$lng - 0.0045, $lat - 0.0024, $lng + 0.0045, $lat + 0.0024]);
    return 'https://www.openstreetmap.org/export/embed.html?bbox=' . rawurlencode($bbox)
         . '&layer=mapnik&marker=' . rawurlencode($lat . ',' . $lng);
}

/** The full-screen OpenStreetMap page for the office pin; '' while no pin is set. */
function contact_map_osm_url(): string {
    $pin = contact_map_pin();
    return $pin === null ? '' : 'https://www.openstreetmap.org/?mlat=' . $pin[0] . '&mlon=' . $pin[1] . '#map=17/' . $pin[0] . '/' . $pin[1];
}

/** Without a pin: the Google Maps embed the contact page loads once the visitor asks for it; '' without a query. */
function contact_map_embed_url(): string {
    $query = contact_map_query();
    return $query === '' ? '' : 'https://maps.google.com/maps?q=' . rawurlencode($query) . '&hl=fa&z=17&output=embed';
}
