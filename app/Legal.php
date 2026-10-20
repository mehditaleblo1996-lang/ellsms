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

const LEGAL_TERMS_VERSION = '1405-07-22';
const LEGAL_TERMS_UPDATED_FA = '۲۲ مهر ۱۴۰۵';

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
    return $name !== '' ? $name : 'داتیس رایان';
}

/** Renders the «مشخصات ارائه‌دهنده» block shared by the legal pages. */
function legal_identity_block(): string {
    $rows = legal_identity_rows();
    $html = '<dl class="lp-legal-identity">';
    if (!$rows) {
        $html .= '<dt>ارائه‌دهنده</dt><dd>داتیس رایان — برای ارتباط از صفحه‌ی <a href="/contact">تماس با ما</a> استفاده کنید.</dd>';
    }
    foreach ($rows as [$label, $value, $ltr]) {
        $html .= '<dt>' . e($label) . '</dt><dd' . ($ltr ? ' class="ltr"' : '') . '>' . nl2br(e($value)) . '</dd>';
    }
    return $html . '</dl>';
}

/**
 * The trust badges shown in both footers. With an eNamad id and code entered in settings, the eNamad
 * badge is the official one: an image served by trustseal.enamad.ir inside a link to its public
 * verification page. Without them it stays the plain image that was there before — which proves
 * nothing to a visitor, so the real id/code should be entered (see the settings page).
 *
 * @param int $height badge height in CSS pixels
 */
function legal_trust_badges_html(int $height): string {
    $enamadId = trim((string) setting('enamad_id', ''));
    $enamadCode = trim((string) setting('enamad_code', ''));
    $style = 'display:block;width:auto;height:' . $height . 'px;max-width:150px;object-fit:contain';

    if ($enamadId !== '' && $enamadCode !== '') {
        $query = 'id=' . rawurlencode($enamadId) . '&Code=' . rawurlencode($enamadCode);
        $enamad = '<a href="' . e('https://trustseal.enamad.ir/?' . $query) . '" target="_blank" rel="noopener" referrerpolicy="origin"'
                . ' aria-label="نماد اعتماد الکترونیکی (اینماد) — استعلام در سایت enamad.ir، در پنجره‌ی جدید">'
                . '<img src="' . e('https://trustseal.enamad.ir/logo.aspx?' . $query) . '" alt="نماد اعتماد الکترونیکی (اینماد)"'
                . ' referrerpolicy="origin" loading="lazy" decoding="async" style="' . $style . '"></a>';
    } else {
        $enamad = '<img src="/assets/img/footer-image-2.png" alt="نماد اعتماد الکترونیکی (اینماد)" loading="lazy" decoding="async" style="' . $style . '">';
    }
    $samandehi = '<img src="/assets/img/footer-image-1.png" alt="نشان ملی ثبت رسانه‌های دیجیتال (ساماندهی)" loading="lazy" decoding="async" style="' . $style . '">';

    return $samandehi . $enamad;
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
