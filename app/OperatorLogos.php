<?php
/**
 * ELLSMS — Iranian operator logos (public/assets/img/operators/), wherever the panel names an
 * operator: tariff pages, reports, analytics, message details.
 *
 * Operators are admin-managed data (ellsms_sms_operators), so a logo is found from the stable code
 * ('mci', 'mtn', 'rightel') or, where only a display name is at hand, from the Persian name. An
 * operator without a known logo simply renders its name, as before.
 */

declare(strict_types=1);

/** Persian name of each operator that has a logo. */
const OPERATOR_LOGO_NAMES = ['mci' => 'همراه اول', 'mtn' => 'ایرانسل', 'rightel' => 'رایتل'];

/** Display name of the test/mock operator (code 'mock', seeded as «Mock Operator»). */
const OPERATOR_OTHER_NAME = 'سایر اپراتورها';

/** Report order of the major operators, by code: همراه اول، ایرانسل، رایتل. */
const OPERATOR_DISPLAY_ORDER = ['mci', 'mtn', 'rightel'];

/** Whether a code or name is the mock operator — shown everywhere as «سایر اپراتورها». */
function operator_is_mock(?string $codeOrName): bool {
    $value = mb_strtolower(trim((string)$codeOrName));
    return $value === 'mock' || $value === 'mock operator' || $value === OPERATOR_OTHER_NAME;
}

/** The name to show for an operator: the mock operator becomes «سایر اپراتورها», anything else as given. */
function operator_display_name(string $name): string {
    return operator_is_mock($name) ? OPERATOR_OTHER_NAME : $name;
}

/**
 * Sort rank for showing an operator in a report: همراه اول 0، ایرانسل 1، رایتل 2، any other operator
 * 50, «سایر اپراتورها» 100, «سایر / نامشخص» 200.
 */
function operator_display_rank(?string $code, ?string $name = null): int {
    if (operator_is_mock($code) || operator_is_mock($name)) return 100;
    if (trim((string)($name ?? $code)) === 'سایر / نامشخص') return 200;
    $key = in_array((string)$code, OPERATOR_DISPLAY_ORDER, true) ? (string)$code : (operator_logo_key($code) ?? operator_logo_key($name));
    $i = $key === null ? false : array_search($key, OPERATOR_DISPLAY_ORDER, true);
    return $i === false ? 50 : (int)$i;
}

/** Logo key for an operator code or display name: 'mci' | 'mtn' | 'rightel' | null. */
function operator_logo_key(?string $codeOrName): ?string {
    $value = mb_strtolower(trim((string)$codeOrName));
    if ($value === '') {
        return null;
    }
    $aliases = [
        'mci' => 'mci', 'hamrah-e-aval' => 'mci', 'hamrahaval' => 'mci', 'hamrah aval' => 'mci',
        'mtn' => 'mtn', 'irancell' => 'mtn', 'mtn irancell' => 'mtn',
        'rightel' => 'rightel', 'righttel' => 'rightel',
    ];
    if (isset($aliases[$value])) {
        return $aliases[$value];
    }
    $value = str_replace(['ي', 'ك', "\u{200c}", ' '], ['ی', 'ک', '', ''], $value);
    return match (true) {
        str_contains($value, 'همراهاول') => 'mci',
        str_contains($value, 'ایرانسل')  => 'mtn',
        str_contains($value, 'رایتل')    => 'rightel',
        default                          => null,
    };
}

/**
 * <img> for an operator, or '' when it has no logo. $size is the rendered height in CSS pixels;
 * the icon is the brand mark alone (for table rows), $full the whole logo with its wordmark.
 */
function operator_logo(?string $codeOrName, int $size = 20, bool $full = false): string {
    $key = operator_logo_key($codeOrName);
    if ($key === null) {
        return '';
    }
    $alt = OPERATOR_LOGO_NAMES[$key];
    $file = $full ? "{$key}.png" : "{$key}-icon.png";
    return '<img class="op-logo" src="/assets/img/operators/' . $file . '" alt="' . e($alt) . '" height="' . $size
        . '" style="height:' . $size . 'px" loading="lazy" decoding="async">';
}

/**
 * Logo + name, for a table cell or label. $name is printed as given (escaped); without one, a known
 * operator gets its Persian name and anything else its code. With no logo this is just the escaped
 * text, so callers can use it for every operator unconditionally.
 */
function operator_label(?string $codeOrName, ?string $name = null, int $size = 20): string {
    if (operator_is_mock($codeOrName) || ($name !== null && operator_is_mock($name))) {
        return e(OPERATOR_OTHER_NAME);
    }
    $key = operator_logo_key($codeOrName);
    $text = e((string)($name ?? ($key !== null ? OPERATOR_LOGO_NAMES[$key] : $codeOrName) ?? ''));
    $logo = operator_logo($codeOrName, $size) ?: ($name !== null ? operator_logo($name, $size) : '');
    return $logo === '' ? $text : '<span class="op-label">' . $logo . '<span>' . $text . '</span></span>';
}
