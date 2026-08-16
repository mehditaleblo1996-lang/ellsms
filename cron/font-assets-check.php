<?php
declare(strict_types=1);
/**
 * ELLSMS — font asset verification.
 *
 * WHY THIS EXISTS. A missing font is invisible in code review: every stylesheet parses, every page
 * returns HTTP 200, and the panel merely falls back to Tahoma. The supplied integration pack is
 * explicit that this is "a release-blocking defect, not a cosmetic warning", so the condition needs
 * something that actually fails rather than a comment nobody reads.
 *
 * Checks that every file referenced by an @font-face in public/assets/css/fonts.css exists on disk,
 * is non-empty, and is a real WOFF2 (magic bytes 'wOF2'), and that no stylesheet has re-introduced
 * an external font URL.
 *
 * Exit 0 = every declared font is present and self-hosted.
 * Exit 1 = at least one is missing, corrupt, or fetched off-host.
 *
 *   php cron/font-assets-check.php
 */
$root       = dirname(__DIR__);
$publicDir  = $root . '/public';
$fontsCss   = $publicDir . '/assets/css/fonts.css';
$styleCss   = $publicDir . '/assets/css/style.css';

$errors   = [];
$warnings = [];

if (!is_file($fontsCss)) {
    fwrite(STDERR, "FAIL: {$fontsCss} does not exist.\n");
    exit(1);
}

$css = (string)file_get_contents($fontsCss);

// Strip comments first: the explanatory prose in fonts.css mentions font URLs, and matching those
// would produce phantom requirements.
$code = (string)preg_replace('#/\*.*?\*/#s', '', $css);

preg_match_all('#url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)#', $code, $m);
$referenced = array_values(array_unique($m[1] ?? []));

if ($referenced === []) {
    $errors[] = 'fonts.css declares no font files at all.';
}

echo "ELLSMS font assets\n";
echo "  stylesheet: public/assets/css/fonts.css\n";
echo "  declared font files: " . count($referenced) . "\n\n";

foreach ($referenced as $url) {
    if (preg_match('#^(https?:)?//#i', $url)) {
        $errors[] = "EXTERNAL font URL declared ({$url}). Fonts must be self-hosted.";
        continue;
    }
    $path = $publicDir . '/' . ltrim($url, '/');
    $name = basename($path);

    if (!is_file($path)) {
        $errors[] = "MISSING: {$url} (expected at public" . substr($path, strlen($publicDir)) . ')';
        printf("  [MISSING] %-32s —\n", $name);
        continue;
    }
    $size = (int)filesize($path);
    if ($size === 0) {
        $errors[] = "EMPTY: {$url}";
        printf("  [EMPTY]   %-32s 0 bytes\n", $name);
        continue;
    }
    // WOFF2 files begin with the signature 'wOF2'. A well-meaning copy of a .ttf renamed to .woff2
    // would otherwise pass an existence check and then fail to load in every browser.
    $magic = (string)file_get_contents($path, false, null, 0, 4);
    $isWoff2 = $magic === 'wOF2';
    $expectWoff2 = str_ends_with(strtolower($path), '.woff2');
    if ($expectWoff2 && !$isWoff2) {
        $errors[] = "NOT A WOFF2: {$url} (signature '" . bin2hex($magic) . "', expected 'wOF2')";
        printf("  [BAD]     %-32s %d bytes — not WOFF2\n", $name, $size);
        continue;
    }
    printf("  [ok]      %-32s %s bytes\n", $name, number_format($size));
}

// A CDN import anywhere else would defeat the whole point, so check the main stylesheet too.
foreach ([$styleCss => 'style.css', $fontsCss => 'fonts.css'] as $file => $label) {
    if (!is_file($file)) continue;
    $body = (string)preg_replace('#/\*.*?\*/#s', '', (string)file_get_contents($file));
    if (preg_match('#(fonts\.googleapis\.com|fonts\.gstatic\.com|cdnjs|jsdelivr|unpkg)#i', $body, $hit)) {
        $errors[] = "{$label} references an external font/CDN host ({$hit[1]}).";
    }
}

echo "\n";
if ($errors !== []) {
    echo "FAIL — font integration is incomplete:\n";
    foreach ($errors as $e) echo "  - {$e}\n";
    echo "\n  The UI will fall back to Tahoma/Arial and remain usable, but the supplied\n";
    echo "  Vazirmatn weights are NOT being served. Place the approved .woff2 files in\n";
    echo "  public/assets/fonts/ using the exact names above, then re-run this check.\n";
    exit(1);
}

foreach ($warnings as $w) echo "  warning: {$w}\n";
echo "PASS — all declared fonts are present, valid WOFF2, and self-hosted.\n";
exit(0);
