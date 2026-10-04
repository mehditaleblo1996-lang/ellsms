<?php
/**
 * Downloadable integrations: the WooCommerce plugin and the PHP / JavaScript / Python SDKs, built as zip
 * files straight from the repository (integrations/, sdk/) so a download always matches the running code.
 * The WooCommerce zip carries its own copy of the PHP SDK (lib/ellsms-php) — WordPress plugins cannot
 * rely on Composer. Built zips are cached under the system temp dir, keyed by a hash of their inputs.
 *
 * Also: a small, escape-first Markdown renderer for integrations/GUIDE.fa.md on the /integrations page.
 */

declare(strict_types=1);

const INTEGRATION_GUIDE_FILE = 'integrations/GUIDE.fa.md';
// The customer-facing API guide PDF, served by public/api-guide.php to any signed-in user. It lives here,
// outside public/, because Apache would hand anything under public/ to anyone — logged in or not.
const INTEGRATION_API_GUIDE_PDF = 'integrations/ELLSMS-API-Guide.pdf';

/** name => [zip root folder, label, list of [source (relative to the repo, file or dir), target inside root]] */
function integration_packages(): array {
    return [
        'woocommerce' => ['ellsms-woocommerce', 'افزونه‌ی ووکامرس', [
            ['integrations/woocommerce/ellsms-woocommerce', ''],
            ['sdk/php/src', 'lib/ellsms-php'],
            [INTEGRATION_GUIDE_FILE, 'GUIDE.fa.md'],
        ]],
        'sdk-php' => ['ellsms-php', 'SDK زبان PHP', [
            ['sdk/php/src', 'src'], ['sdk/php/composer.json', 'composer.json'], ['sdk/php/README.md', 'README.md'],
        ]],
        'sdk-js' => ['ellsms-js', 'SDK جاوااسکریپت / Node.js', [
            ['sdk/js/index.js', 'index.js'], ['sdk/js/index.mjs', 'index.mjs'], ['sdk/js/index.d.ts', 'index.d.ts'],
            ['sdk/js/package.json', 'package.json'], ['sdk/js/README.md', 'README.md'],
        ]],
        'sdk-python' => ['ellsms-python', 'SDK پایتون', [
            ['sdk/python/ellsms/__init__.py', 'ellsms/__init__.py'], ['sdk/python/pyproject.toml', 'pyproject.toml'],
            ['sdk/python/README.md', 'README.md'],
        ]],
    ];
}

function integration_repo_root(): string {
    return dirname(__DIR__);
}

/** @return array<string, string> target path inside the zip => absolute source path */
function integration_package_files(string $name): array {
    $package = integration_packages()[$name] ?? null;
    if ($package === null) {
        throw new InvalidArgumentException('Unknown integration package.');
    }
    [$rootFolder, , $sources] = $package;
    $repo = integration_repo_root();
    $files = [];
    foreach ($sources as [$source, $target]) {
        $abs = $repo . '/' . $source;
        if (is_file($abs)) {
            $files[ltrim($rootFolder . '/' . $target, '/')] = $abs;
            continue;
        }
        if (!is_dir($abs)) {
            throw new RuntimeException("Missing integration source: {$source}");
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile()) continue;
            $relative = substr($file->getPathname(), strlen($abs) + 1);
            $files[rtrim($rootFolder . '/' . ltrim($target . '/' . $relative, '/'), '/')] = $file->getPathname();
        }
    }
    ksort($files);
    return $files;
}

/** Builds (or reuses) the zip for a package and returns its path. */
function integration_package_zip(string $name): string {
    $files = integration_package_files($name);
    $hash = hash_init('sha256');
    foreach ($files as $target => $source) {
        hash_update($hash, $target . "\0" . hash_file('sha256', $source) . "\0");
    }
    $dir = sys_get_temp_dir() . '/ellsms-integrations';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $path = $dir . '/' . $name . '-' . substr(hash_final($hash), 0, 16) . '.zip';
    if (is_file($path)) {
        return $path;
    }
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create the package zip.');
    }
    foreach ($files as $target => $source) {
        $zip->addFile($source, $target);
    }
    $zip->close();
    rename($tmp, $path); // atomic: a concurrent download never sees a half-written zip
    return $path;
}

/**
 * Minimal Markdown → HTML for the integration guide: headings, paragraphs, bullet/numbered lists, fenced
 * code, tables, inline code, bold and links. Everything is escaped FIRST; only this renderer's own tags
 * reach the page, and links are limited to http(s), relative paths and anchors.
 */
function integration_markdown(string $markdown): string {
    $lines = preg_split('/\R/u', $markdown) ?: [];
    $html = '';
    $inList = null;
    $inCode = false;
    $code = '';
    $paragraph = [];
    $table = [];
    $flushParagraph = static function () use (&$paragraph, &$html): void {
        if ($paragraph !== []) {
            $html .= '<p>' . integration_markdown_inline(implode(' ', $paragraph)) . "</p>\n";
            $paragraph = [];
        }
    };
    $closeList = static function () use (&$inList, &$html): void {
        if ($inList !== null) { $html .= "</{$inList}>\n"; $inList = null; }
    };
    $flushTable = static function () use (&$table, &$html): void {
        if ($table === []) return;
        $html .= "<div class=\"table-wrap\"><table>\n";
        foreach ($table as $i => $cells) {
            if ($i === 1 && preg_match('/^[\s:|-]+$/u', implode('', $cells))) continue; // the |---| separator row
            $tag = $i === 0 ? 'th' : 'td';
            $html .= '<tr>' . implode('', array_map(static fn($c) => "<{$tag}>" . integration_markdown_inline(trim($c)) . "</{$tag}>", $cells)) . "</tr>\n";
        }
        $html .= "</table></div>\n";
        $table = [];
    };
    foreach ($lines as $line) {
        if (preg_match('/^```/u', $line)) {
            if ($inCode) {
                $html .= '<pre class="ltr"><code>' . htmlspecialchars(rtrim($code, "\n"), ENT_QUOTES, 'UTF-8') . "</code></pre>\n";
                $inCode = false;
                $code = '';
            } else {
                $flushParagraph(); $closeList(); $flushTable();
                $inCode = true;
            }
            continue;
        }
        if ($inCode) { $code .= $line . "\n"; continue; }
        if (preg_match('/^\s*\|(.*)\|\s*$/u', $line, $m)) {
            $flushParagraph(); $closeList();
            $table[] = explode('|', $m[1]);
            continue;
        }
        $flushTable();
        if (trim($line) === '') { $flushParagraph(); $closeList(); continue; }
        if (preg_match('/^(#{1,4})\s+(.*)$/u', $line, $m)) {
            $flushParagraph(); $closeList();
            $level = strlen($m[1]) + 1; // # → h2: the page already has its own h1
            $id = 'g-' . substr(md5($m[2]), 0, 8);
            $html .= "<h{$level} id=\"{$id}\">" . integration_markdown_inline($m[2]) . "</h{$level}>\n";
            continue;
        }
        if (preg_match('/^\s*(?:[-*]|(\d+)\.)\s+(.*)$/u', $line, $m)) {
            $flushParagraph();
            $type = $m[1] !== '' ? 'ol' : 'ul';
            if ($inList !== $type) {
                $closeList();
                // A numbered list interrupted by a sub-list continues from the source's own number.
                $html .= $type === 'ol' && (int)$m[1] > 1 ? '<ol start="' . (int)$m[1] . "\">\n" : "<{$type}>\n";
                $inList = $type;
            }
            $html .= '<li>' . integration_markdown_inline($m[2]) . "</li>\n";
            continue;
        }
        if (preg_match('/^>\s?(.*)$/u', $line, $m)) {
            $flushParagraph(); $closeList();
            $html .= '<blockquote>' . integration_markdown_inline($m[1]) . "</blockquote>\n";
            continue;
        }
        $closeList();
        $paragraph[] = trim($line);
    }
    if ($inCode) $html .= '<pre class="ltr"><code>' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . "</code></pre>\n";
    $flushParagraph(); $closeList(); $flushTable();
    return $html;
}

function integration_markdown_inline(string $text): string {
    $codes = [];
    // Inline code first, kept out of every later substitution.
    $text = preg_replace_callback('/`([^`]+)`/u', static function ($m) use (&$codes) {
        $codes[] = '<code class="ltr">' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</code>';
        return "\x01" . (count($codes) - 1) . "\x01";
    }, $text) ?? $text;
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $text) ?? $text;
    $text = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/u', static function ($m) {
        $url = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
        if (preg_match('#^(https?://|/|\#)#i', $url) !== 1) return $m[1];
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . $m[1] . '</a>';
    }, $text) ?? $text;
    return preg_replace_callback('/\x01(\d+)\x01/u', static fn($m) => $codes[(int)$m[1]], $text) ?? $text;
}
