# ELLSMS Backend & Performance Improvements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Improve ELLSMS's request/worker throughput (OPcache, persistent DB
connections, APCu settings cache, concurrent bulk sends), add a per-user
send rate limit on interactive send paths, and add missing indexes on the
two highest-traffic shared tables.

**Architecture:** Six independent, additive changes to the existing
dependency-free PHP 8.2 / Apache / MySQL stack — no new services, no new
Composer/vendor dependencies (this project has none anywhere and that's
intentional), no framework. Config-only changes (Docker/env) come first,
then the two changes that share the new APCu extension, then the two
changes with the most code (bulk concurrency, indexes).

**Tech Stack:** PHP 8.2 (`php:8.2-apache`), PDO/MySQL, cURL (`curl_multi_*`
for concurrency), APCu, Docker Compose.

## Global Constraints

- No Composer, no `vendor/` directory, no new runtime dependencies — this
  project deliberately has none anywhere (see README). All six changes
  are plain PHP + PHP extensions already installable via `docker-php-ext-*`
  / `pecl`.
- Target PHP version is **8.2** (the Dockerfile's base image) — even if a
  local dev machine has a newer PHP CLI, don't use syntax newer than 8.2.
- This project has **no automated test framework** (no PHPUnit, no
  `tests/` directory) — this is an established, intentional pattern, not
  a gap to unilaterally fix. Verification in this plan uses: (a)
  standalone PHP CLI scripts for logic that has no DB/network dependency
  — written to a scratch path and run directly, not committed to the
  repo — and (b) concrete manual commands run against a real
  `docker compose` dev deployment for anything that needs MySQL or the
  backend API. Follow this same pattern for any test code you write.
- `.env`-with-Settings-override convention: for any new *admin-configurable*
  value, `ellsms_settings` (via `setting()`) always wins over `.env` if
  both are set — see `zarinpal_*` / `telegram_*` in `public/settings.php`
  for the existing pattern. Pure ops-tuning knobs (not business settings)
  are plain env vars with no Settings-page override — see `B` below.
- All new user-facing strings are Persian/Farsi, matching the existing
  tone in the file you're editing (short, plain, no jargon).
- `db/ellsms_extra.sql` migrations for existing/backend-owned tables must
  use the same guarded `information_schema` check + dynamic
  `PREPARE`/`EXECUTE` pattern already used there (see the `uniq_inbound`
  and `twofa_enabled` migrations) — safe to re-run on every deploy.

---

## File Structure

| File | Change |
|---|---|
| `docker/Dockerfile` | Enable OPcache; install + enable APCu extension |
| `app/bootstrap.php` | `db()`: persistent connection + stale-transaction guard. `setting()`/`set_setting()`: APCu layer. New `rate_limit_check()`. |
| `public/send.php` | Call `rate_limit_check()` before direct send |
| `public/new-send.php` | Call `rate_limit_check()` before direct-mode send |
| `public/sms/url_send.html` | Call `rate_limit_check()`; new `error_code: -7` |
| `public/settings.php` | New "حداکثر درخواست ارسال در ثانیه" field in the ارسال card |
| `db/ellsms_extra.sql` | Seed `send_rate_limit_per_sec` setting; guarded index migrations on `outbound_message`/`inbound_message` |
| `app/backend.php` | Extract `backend_api_build_payload()` / `backend_api_decode_response()` from `backend_api_send()`; add `backend_api_send_batch()`; extract `dispatch_message_prepare()` / `dispatch_message_finish()` from `dispatch_message()`; replace `bulk_send_one_item()` with `bulk_send_batch()`; `run_bulk_send_pass()` calls the new batch function |
| `public/index.php` | Rewrite `DATE(sent_at)=CURDATE()` to a sargable range |
| `.env.example` | Add `SEND_RATE_LIMIT_PER_SEC`, `BULK_SEND_CONCURRENCY` |
| `docker-compose.yml` | Pass `SEND_RATE_LIMIT_PER_SEC` to `app`, `BULK_SEND_CONCURRENCY` to `worker` |
| `README.md` | Document new error code `-7`; note rate limit + concurrency knobs |

---

## Task 1: Enable OPcache

**Files:**
- Modify: `docker/Dockerfile`

**Interfaces:** None — config-only, no code depends on this.

- [ ] **Step 1: Add OPcache config to the Dockerfile**

In `docker/Dockerfile`, extend the existing `RUN { ... } > /usr/local/etc/php/conf.d/ellsms.ini` block (currently sets `date.timezone`, `upload_max_filesize`, `post_max_size`, `expose_php`):

```dockerfile
RUN { \
      echo 'date.timezone = Asia/Tehran'; \
      echo 'upload_max_filesize = 10M'; \
      echo 'post_max_size = 24M'; \
      echo 'expose_php = Off'; \
      echo 'opcache.enable = 1'; \
      echo 'opcache.enable_cli = 1'; \
      echo 'opcache.memory_consumption = 128'; \
      echo 'opcache.max_accelerated_files = 4000'; \
      echo 'opcache.validate_timestamps = 0'; \
    } > /usr/local/etc/php/conf.d/ellsms.ini
```

Also enable the extension itself (the `php:8.2-apache` base image ships
the `opcache` extension but doesn't enable it by default), right after the
existing `docker-php-ext-install` line:

```dockerfile
RUN apt-get update && apt-get install -y --no-install-recommends libzip-dev \
 && docker-php-ext-install pdo_mysql zip \
 && docker-php-ext-enable opcache \
 && a2enmod rewrite headers \
 && rm -rf /var/lib/apt/lists/*
```

- [ ] **Step 2: Build and verify OPcache is active**

Run: `docker compose build app`
Expected: build succeeds, no errors from the new Dockerfile lines.

Run: `docker compose run --rm app php -i | grep -E "opcache.enable |opcache.validate_timestamps|Zend OPcache"`
Expected output includes:
```
Zend OPcache
opcache.enable => On => On
opcache.validate_timestamps => Off => Off
```

- [ ] **Step 3: Commit**

```bash
git add docker/Dockerfile
git commit -m "perf: enable OPcache with timestamp validation disabled"
```

---

## Task 2: APCu extension + settings cache

**Files:**
- Modify: `docker/Dockerfile`
- Modify: `app/bootstrap.php:44-60` (`setting()`, `set_setting()`)

**Interfaces:**
- Produces: `setting(string $key, ?string $default = null): ?string` and
  `set_setting(string $key, string $value): void` — same signatures as
  today, callers elsewhere are unaffected. Task 4 (`rate_limit_check`)
  depends on the APCu extension enabled here, not on these two functions
  directly.

- [ ] **Step 1: Install and enable APCu in the Dockerfile**

In `docker/Dockerfile`, install APCu via PECL (it isn't a `docker-php-ext-install`-bundled extension):

```dockerfile
RUN apt-get update && apt-get install -y --no-install-recommends libzip-dev \
 && docker-php-ext-install pdo_mysql zip \
 && docker-php-ext-enable opcache \
 && pecl install apcu \
 && docker-php-ext-enable apcu \
 && a2enmod rewrite headers \
 && rm -rf /var/lib/apt/lists/*
```

Add APCu's own ini alongside the existing block (APCu needs
`apc.enable_cli=1` to be usable from `worker`'s long-lived CLI process):

```dockerfile
RUN { \
      echo 'date.timezone = Asia/Tehran'; \
      echo 'upload_max_filesize = 10M'; \
      echo 'post_max_size = 24M'; \
      echo 'expose_php = Off'; \
      echo 'opcache.enable = 1'; \
      echo 'opcache.enable_cli = 1'; \
      echo 'opcache.memory_consumption = 128'; \
      echo 'opcache.max_accelerated_files = 4000'; \
      echo 'opcache.validate_timestamps = 0'; \
      echo 'apc.enable_cli = 1'; \
    } > /usr/local/etc/php/conf.d/ellsms.ini
```

- [ ] **Step 2: Build and verify APCu is active**

Run: `docker compose build app`
Run: `docker compose run --rm app php -m | grep -i apcu`
Expected: `apcu` printed.

Run: `docker compose run --rm app php -r "var_dump(function_exists('apcu_fetch'));"`
Expected: `bool(true)`

- [ ] **Step 3: Add the APCu layer to `setting()` and `set_setting()`**

In `app/bootstrap.php`, replace the current implementation (lines 44-60):

```php
/* ---------- ELLSMS settings (ellsms_settings key/value, cached) ---------- */
const SETTINGS_APCU_KEY = 'ellsms:settings:v1';
const SETTINGS_APCU_TTL = 60; // safety-net TTL; set_setting() invalidates explicitly on write

function setting(string $key, ?string $default = null): ?string {
    static $cache = null;
    if ($cache === null) {
        $cache = function_exists('apcu_fetch') ? apcu_fetch(SETTINGS_APCU_KEY) : false;
        if ($cache === false) {
            $cache = [];
            foreach (db()->query('SELECT skey, svalue FROM ellsms_settings') as $row) {
                $cache[$row['skey']] = $row['svalue'];
            }
            if (function_exists('apcu_store')) apcu_store(SETTINGS_APCU_KEY, $cache, SETTINGS_APCU_TTL);
        }
    }
    $v = $cache[$key] ?? '';
    return ($v !== '' ? $v : null) ?? $default;
}

function set_setting(string $key, string $value): void {
    $st = db()->prepare('INSERT INTO ellsms_settings (skey, svalue) VALUES (?, ?)
                         ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
    $st->execute([$key, $value]);
    if (function_exists('apcu_delete')) apcu_delete(SETTINGS_APCU_KEY);
}
```

(`function_exists()` guards keep this safe to run even in a shell that
somehow lacks the extension — e.g. a stray host-side `php` invocation
outside Docker — falling back to the pre-existing per-request-only
behavior instead of fataling.)

- [ ] **Step 4: Verify against a real deployment**

This needs a working DB connection, so verify against your dev
deployment (`docker compose up -d --build`, `.env` filled in per the
README's Quick Start):

Run: `docker compose exec app php -r "require '/var/www/html/app/bootstrap.php'; var_dump(setting('api_base_url'));"`
Expected: prints the current value with no PHP errors/warnings.

Run: `docker compose exec app php -r "require '/var/www/html/app/bootstrap.php'; set_setting('_plan_test', 'x'); var_dump(apcu_fetch('ellsms:settings:v1'));"`
Expected: `bool(false)` — confirms `set_setting()` invalidated the cache.

Run the first command again — expected: prints including `_plan_test`
handled correctly, and a second `apcu_fetch('ellsms:settings:v1')` now
returns the repopulated array (not `false`).

Clean up the test key:
Run: `docker compose exec app php -r "require '/var/www/html/app/bootstrap.php'; db()->prepare('DELETE FROM ellsms_settings WHERE skey = ?')->execute(['_plan_test']);"`

- [ ] **Step 5: Commit**

```bash
git add docker/Dockerfile app/bootstrap.php
git commit -m "perf: enable APCu and cache ellsms_settings across requests"
```

---

## Task 3: Persistent DB connections

**Files:**
- Modify: `app/bootstrap.php:25-41` (`db()`)

**Interfaces:**
- Produces: `db(): PDO` — same signature, same return type, all existing
  callers unaffected.

- [ ] **Step 1: Enable `PDO::ATTR_PERSISTENT` with a stale-transaction guard**

Replace `db()` in `app/bootstrap.php`:

```php
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $host = env('BACKEND_DB_HOST', 'localhost');
        $port = env('BACKEND_DB_PORT', '3306');
        $name = env('BACKEND_DB_NAME', 'change_me');
        $user = env('BACKEND_DB_USER', 'change_me');
        $pass = env('BACKEND_DB_PASS', '');
        $dsn  = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        $pdo  = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => true,
        ]);
        // A persistent connection can be reused by a later request on the
        // same Apache child process. If a prior request crashed (fatal
        // error, OOM-kill) mid-transaction, this connection could still
        // have an open transaction — roll it back before handing it out.
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
    return $pdo;
}
```

- [ ] **Step 2: Verify against a real deployment**

Run: `docker compose exec app php -r "require '/var/www/html/app/bootstrap.php'; var_dump(db()->getAttribute(PDO::ATTR_PERSISTENT));"`
Expected: `bool(true)`

Simulate a crashed transaction and confirm the guard clears it:

Run:
```bash
docker compose exec app php -r "
require '/var/www/html/app/bootstrap.php';
\$pdo = db();
\$pdo->beginTransaction();
var_dump(\$pdo->inTransaction());
"
```
Expected: `bool(true)` (the process exits right after, abandoning the open transaction on the persistent connection — this simulates the crash scenario without actually crashing PHP).

Run the same probe again in a fresh process:
```bash
docker compose exec app php -r "
require '/var/www/html/app/bootstrap.php';
var_dump(db()->inTransaction());
"
```
Expected: `bool(false)` — confirms the guard rolled back the inherited transaction. (If your DB pooling/proxy doesn't actually reuse the same underlying connection across separate `php -r` invocations, run this as two sequential requests to the same long-lived Apache worker instead, e.g. via `ab -n 2 -c 1` against a small diagnostic page — the point is the same PHP process/connection must be reused.)

- [ ] **Step 3: Commit**

```bash
git add app/bootstrap.php
git commit -m "perf: use a persistent DB connection with a stale-transaction guard"
```

---

## Task 4: Per-user send rate limit

**Files:**
- Modify: `app/bootstrap.php` (new `rate_limit_check()`, placed near `require_login()`/`require_admin()`)
- Modify: `public/send.php:66-67`
- Modify: `public/new-send.php:63-64`
- Modify: `public/sms/url_send.html`
- Modify: `public/settings.php` (ارسال card)
- Modify: `db/ellsms_extra.sql` (seed default + doc comment)
- Modify: `.env.example`
- Modify: `docker-compose.yml` (`app` service env)
- Modify: `README.md` (error code table)

**Interfaces:**
- Consumes: `apcu_add()`/`apcu_inc()` (Task 2), `setting()` (existing).
- Produces: `rate_limit_check(int $userId, int $max): bool` — pure
  function, no DB/network calls, `$max <= 0` always returns `true`
  (disabled). Callers pass `(int)setting('send_rate_limit_per_sec', '5')`
  as `$max` themselves.

- [ ] **Step 1: Write `rate_limit_check()` in `app/bootstrap.php`**

Add near the other auth/session helpers (after `require_admin()`, around
line 127):

```php
/**
 * Per-second, per-user cap for interactive send endpoints (send.php,
 * new-send.php direct mode, url_send.html) — NOT applied to worker-driven
 * sends (schedules, autoreply, bulk jobs), which are already paced by the
 * worker's own tick/throttle settings and BULK_SEND_CONCURRENCY. $max <= 0
 * disables the check (always allowed). Pure — no DB/network calls; the
 * caller is responsible for resolving $max via setting().
 */
function rate_limit_check(int $userId, int $max): bool {
    if ($max <= 0) return true;
    $key = 'rl:' . $userId . ':' . time();
    apcu_add($key, 0, 2); // seed at 0 with a 2s TTL if not already present; no-op otherwise
    $count = apcu_inc($key, 1);
    return $count !== false && $count <= $max;
}
```

- [ ] **Step 2: Standalone test (no DB needed — pure APCu + logic)**

Write a scratch script (not committed) to confirm the cap and the
1-second window both behave correctly:

```bash
docker compose exec app php -r "
require '/var/www/html/app/bootstrap.php';
\$uid = 999001;
\$max = 3;
\$allowed = 0;
for (\$i = 0; \$i < 5; \$i++) { if (rate_limit_check(\$uid, \$max)) \$allowed++; }
echo \"allowed within one second: {\$allowed} (expected 3)\n\";
sleep(1);
echo 'allowed after 1s: ' . (rate_limit_check(\$uid, \$max) ? 'yes' : 'no') . \" (expected yes)\n\";
echo 'disabled (max=0): ' . (rate_limit_check(\$uid, 0) ? 'yes' : 'no') . \" (expected yes)\n\";
"
```
Expected output:
```
allowed within one second: 3 (expected 3)
allowed after 1s: yes (expected yes)
disabled (max=0): yes (expected yes)
```

- [ ] **Step 3: Wire it into `public/send.php`**

In `public/send.php`, the `else` branch at line 66-71 currently:

```php
    } else {
        [$ok, $info] = dispatch_message($me, $originator, $dests, $content);
        flash($ok ? 'success' : 'error', $info);
        audit((int)$me['id'], 'sms.send', count($dests) . ' dest, ok=' . (int)$ok);
        if ($ok) redirect('/reports.php');
    }
```

Becomes:

```php
    } elseif (!rate_limit_check((int)$me['id'], (int)setting('send_rate_limit_per_sec', '5'))) {
        flash('error', 'تعداد درخواست‌ها زیاد است — چند لحظه صبر کنید.');
    } else {
        [$ok, $info] = dispatch_message($me, $originator, $dests, $content);
        flash($ok ? 'success' : 'error', $info);
        audit((int)$me['id'], 'sms.send', count($dests) . ' dest, ok=' . (int)$ok);
        if ($ok) redirect('/reports.php');
    }
```

(The enclosing `if`/`elseif` chain at line 45 already ends with
`} else {` for the immediate-send case — change that final `} else {`
to `} elseif (!rate_limit_check(...)) { ... } else {` as shown.)

- [ ] **Step 4: Wire it into `public/new-send.php`**

In `public/new-send.php`, the `elseif ($mode === 'direct')` branch at
line 63-67 currently:

```php
    } elseif ($mode === 'direct') {
        [$ok, $info] = dispatch_message($me, $originator, $dests, $content);
        flash($ok ? 'success' : 'error', $info . $blockedNote);
        audit((int)$me['id'], 'new_send.direct', count($dests) . ' dest, ok=' . (int)$ok);
        if ($ok) redirect('/reports.php');
    }
```

Becomes:

```php
    } elseif ($mode === 'direct' && !rate_limit_check((int)$me['id'], (int)setting('send_rate_limit_per_sec', '5'))) {
        flash('error', 'تعداد درخواست‌ها زیاد است — چند لحظه صبر کنید.' . $blockedNote);
    } elseif ($mode === 'direct') {
        [$ok, $info] = dispatch_message($me, $originator, $dests, $content);
        flash($ok ? 'success' : 'error', $info . $blockedNote);
        audit((int)$me['id'], 'new_send.direct', count($dests) . ' dest, ok=' . (int)$ok);
        if ($ok) redirect('/reports.php');
    }
```

- [ ] **Step 5: Wire it into `public/sms/url_send.html`**

In `public/sms/url_send.html`, right before the existing send call at
line 75 (`[$ok, $msg] = dispatch_message(...)`), insert:

```php
if (!rate_limit_check((int)$user['id'], (int)setting('send_rate_limit_per_sec', '5'))) {
    url_send_respond(false, null, -7);
}

[$ok, $msg] = dispatch_message($user, $originator, [$dest], $content);
```

Update the file's doc comment block (lines 22-25) to add the new code:

```php
 * error_code is one of:
 *   -1 missing parameter, -2 authentication failed,
 *   -3 account has no ELLSMS panel access, -4 invalid destination,
 *   -5 insufficient credit, -6 send failed at the gateway,
 *   -7 rate limit exceeded (too many requests from this account)
```

- [ ] **Step 6: Add the Settings page field**

In `public/settings.php`, add `send_rate_limit_per_sec` to the `general`
handler (line 11-16):

```php
    if ($do === 'general') {
        set_setting('api_base_url',       rtrim(trim($_POST['api_base_url'] ?? ''), '/'));
        set_setting('default_originator', trim($_POST['default_originator'] ?? ''));
        set_setting('send_rate_limit_per_sec', (string)max(0, (int)($_POST['send_rate_limit_per_sec'] ?? 5)));
        audit((int)$me['id'], 'settings.update');
        flash('success', 'تنظیمات ذخیره شد.');
    }
```

And add the field to the ارسال card's `<div class="form-row">` (line
49-57):

```php
    <div class="form-row">
      <label>آدرس پایه‌ی API
        <input type="text" name="api_base_url" value="<?= e(setting('api_base_url', '')) ?>" placeholder="https://rest.example.com" class="ltr">
        <div class="hint">پیام‌ها به آدرس <span class="num">{base}/api/messages/send</span> ارسال می‌شوند.</div>
      </label>
      <label>خط ارسال‌کننده‌ی پیش‌فرض
        <input type="text" name="default_originator" value="<?= e(setting('default_originator', '')) ?>" class="ltr">
      </label>
      <label>حداکثر درخواست ارسال در ثانیه (به ازای هر کاربر)
        <input type="number" name="send_rate_limit_per_sec" value="<?= (int)setting('send_rate_limit_per_sec', '5') ?>" min="0">
        <div class="hint">فقط برای ارسال مستقیم از پنل و API — صفر یعنی غیرفعال. ارسال‌های زمان‌بندی‌شده و انبوه با تنظیمات جداگانه‌ی خودشان کنترل می‌شوند.</div>
      </label>
    </div>
```

- [ ] **Step 7: Seed the default setting**

In `db/ellsms_extra.sql`, add `send_rate_limit_per_sec` to the existing
seed `INSERT` at the bottom of the file:

```sql
INSERT INTO ellsms_settings (skey, svalue) VALUES
  ('api_base_url',               ''),
  ('default_originator',         ''),
  ('autoreply_last_inbound_id',  '0'),
  ('rial_per_credit',            '1000'),
  ('min_credit_purchase',        '100'),
  ('credit_packages',            '500,1000,5000,20000'),
  ('send_rate_limit_per_sec',    '5')
ON DUPLICATE KEY UPDATE skey = skey;
```

- [ ] **Step 8: `.env.example` and `docker-compose.yml`**

In `.env.example`, add after the `API_BASE_URL` block:

```
# Max direct-send requests per second, per user, on send.php/new-send.php
# (direct mode)/url_send.html. Can also be set later from Settings in the
# panel (stored in ellsms_settings, which then wins over this default).
# 0 disables the check. Does not apply to schedules, autoreply, or bulk
# jobs (p2p/smart/gradual) — those are paced by the worker instead.
SEND_RATE_LIMIT_PER_SEC=5
```

In `docker-compose.yml`, add to the `app` service's `environment:` block
(after `API_BASE_URL`):

```yaml
      SEND_RATE_LIMIT_PER_SEC: ${SEND_RATE_LIMIT_PER_SEC:-5}
```

- [ ] **Step 9: Verify end-to-end against `url_send.html`**

Against a real dev deployment with a test account (`testuser`/known
password, panel access granted), with `send_rate_limit_per_sec` set to
`2`:

```bash
for i in 1 2 3; do
  curl -s "http://localhost:8080/sms/url_send.html?username=testuser&password=testpass&originator=5000&destination=989120000000&content=test${i}"
  echo
done
```

Expected: first two calls return either a success shape or a non-rate-limit
failure (e.g. `-6` if the backend API itself isn't reachable in your test
setup — that's fine, it means the rate limiter let it through); the third
call in the same second returns
`{"status":"not ok","reference_id":null,"error_code":-7}`.

- [ ] **Step 10: Commit**

```bash
git add app/bootstrap.php public/send.php public/new-send.php public/sms/url_send.html public/settings.php db/ellsms_extra.sql .env.example docker-compose.yml README.md
git commit -m "feat: add per-user rate limit on interactive send endpoints"
```

---

## Task 5: Bulk-send concurrency

**Files:**
- Modify: `app/backend.php` (`backend_api_send`, `dispatch_message`, `bulk_send_one_item`, `run_bulk_send_pass`)
- Modify: `.env.example`
- Modify: `docker-compose.yml` (`worker` service env)

**Interfaces:**
- Produces:
  - `backend_api_build_payload(int $senderUserId, string $originator, array $destinations, string $content): string`
  - `backend_api_decode_response(string|false $body, int $code, string $err): array` — returns `[ok, httpCode, decodedBodyOrNull, rawError]`, same shape `backend_api_send()` has always returned
  - `backend_api_send_batch(array $items, int $concurrency): array` — `$items` is `[key => ['senderUserId'=>int,'originator'=>string,'destinations'=>array,'content'=>string]]`; returns `[key => [ok, httpCode, decodedBodyOrNull, rawError]]`, one entry per input key
  - `dispatch_message_prepare(array $user, string $originator, array $destinations, string $content, array &$creditBudget): array` — returns `[false, errorMessage]` or `[true, ['originator'=>string,'parts'=>int,'cost'=>int]]`; decrements `$creditBudget[$user['id']]` (keyed by user id, starting from `(float)$user['credit']` on first use) when it admits a non-admin send
  - `dispatch_message_finish(array $user, string $originator, array $destinations, string $content, int $parts, array $sendResult): array` — returns `[ok, infoMessage]`, same shape `dispatch_message()` has always returned
  - `bulk_send_batch(PDO $db, array $items, int $concurrency): int` — replaces `bulk_send_one_item()`; returns count actually sent
- Consumes: `setting()`, `env()`, `normalize_originator()`, `sms_parts()`,
  `describe_api_error()`, `to_persian_digits()` (all existing, unchanged).

- [ ] **Step 1: Extract payload-building and response-decoding from `backend_api_send()`**

In `app/backend.php`, replace `backend_api_send()` (lines 34-68) with:

```php
/** Build the JSON payload for one backend API send request. */
function backend_api_build_payload(int $senderUserId, string $originator, array $destinations, string $content): string {
    return json_encode([
        'sender_user_id' => $senderUserId,
        'originator'     => ctype_digit($originator) ? (int)$originator : $originator,
        'destinations'   => array_values(array_map('strval', $destinations)),
        'content'        => $content,
    ], JSON_UNESCAPED_UNICODE);
}

/** Turn a raw curl result into [ok, httpCode, decodedBodyOrNull, rawError]. */
function backend_api_decode_response($body, int $code, string $err): array {
    if ($body === false) {
        return [false, 0, null, $err ?: 'برقراری اتصال ناموفق بود.'];
    }
    $decoded = json_decode($body, true);
    $ok = $code >= 200 && $code < 300 && is_array($decoded);
    return [$ok, $code, $ok ? $decoded : null, $ok ? null : (is_string($body) ? substr($body, 0, 1000) : 'unexpected response')];
}

/** Low-level call to the backend API. Returns [ok, httpCode, decodedBodyOrNull, rawError]. */
function backend_api_send(int $senderUserId, string $originator, array $destinations, string $content): array {
    $base = rtrim((string)setting('api_base_url', env('API_BASE_URL', '')), '/');
    if ($base === '') {
        return [false, 0, null, 'آدرس API تنظیم نشده است — آن را در بخش تنظیمات وارد کنید.'];
    }
    $url = $base . '/api/messages/send';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => backend_api_build_payload($senderUserId, $originator, $destinations, $content),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return backend_api_decode_response($body, $code, $err);
}

/**
 * Same as calling backend_api_send() once per entry in $items, but fires
 * up to $concurrency requests at a time via curl_multi (bounded rolling
 * window). $items is [key => ['senderUserId'=>,'originator'=>,
 * 'destinations'=>,'content'=>]]. Returns [key => [ok, httpCode,
 * decodedBodyOrNull, rawError]], one entry per input key, in the same
 * shape backend_api_send() returns.
 */
function backend_api_send_batch(array $items, int $concurrency): array {
    if (!$items) return [];

    $base = rtrim((string)setting('api_base_url', env('API_BASE_URL', '')), '/');
    if ($base === '') {
        $err = [false, 0, null, 'آدرس API تنظیم نشده است — آن را در بخش تنظیمات وارد کنید.'];
        return array_fill_keys(array_keys($items), $err);
    }
    $url = $base . '/api/messages/send';
    $concurrency = max(1, $concurrency);

    $keys    = array_keys($items);
    $total   = count($keys);
    $cursor  = 0;
    $active  = []; // spl_object_id(handle) => key
    $results = [];
    $mh = curl_multi_init();

    $launch = function () use (&$cursor, $total, $keys, $items, $url, &$active, $mh) {
        $key  = $keys[$cursor];
        $item = $items[$key];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => backend_api_build_payload($item['senderUserId'], $item['originator'], $item['destinations'], $item['content']),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 30,
        ]);
        curl_multi_add_handle($mh, $ch);
        $active[spl_object_id($ch)] = $key;
        $cursor++;
    };

    while ($cursor < $total && count($active) < $concurrency) $launch();

    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);
        while ($info = curl_multi_info_read($mh)) {
            $ch  = $info['handle'];
            $key = $active[spl_object_id($ch)];
            unset($active[spl_object_id($ch)]);

            $body = curl_multi_getcontent($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err  = curl_error($ch);
            $results[$key] = backend_api_decode_response($body, $code, $err);

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            if ($cursor < $total) $launch();
        }
    } while ($running > 0 || count($active) > 0);

    curl_multi_close($mh);
    return $results;
}
```

- [ ] **Step 2: Test `backend_api_send_batch()` concurrency against a local mock server (no MySQL needed)**

Write a scratch mock router (not committed), e.g.
`/tmp/ellsms-mock-router.php`:

```php
<?php
// Simulates ~200ms backend latency and echoes back a "sent" row per destination.
usleep(200000);
$in = json_decode(file_get_contents('php://input'), true);
$rows = array_map(fn($d) => ['id' => rand(1,999999), 'status' => 'sent', 'destination' => $d], $in['destinations']);
header('Content-Type: application/json');
echo json_encode($rows);
```

Start it: `php -S 127.0.0.1:8899 /tmp/ellsms-mock-router.php &`

Then, inside the `app` (or `worker`) container with `API_BASE_URL`
pointed at the host running the mock server (use the container's
host-reachable address, e.g. `host.docker.internal:8899` or the bridge
gateway IP — adjust for your Docker networking) and a real `.env` DB
config (still required because `setting()` calls `db()`):

```bash
docker compose exec -e API_BASE_URL=http://host.docker.internal:8899 app php -r "
require '/var/www/html/app/backend.php';
\$items = [];
for (\$i = 0; \$i < 10; \$i++) {
    \$items[\$i] = ['senderUserId' => 1, 'originator' => '5000', 'destinations' => ['9891000000' . \$i], 'content' => 'x'];
}
\$t0 = microtime(true);
\$results = backend_api_send_batch(\$items, 5);
\$elapsed = microtime(true) - \$t0;
echo 'results: ' . count(\$results) . \" (expected 10)\n\";
echo 'all ok: ' . (count(array_filter(\$results, fn(\$r) => \$r[0])) === 10 ? 'yes' : 'no') . \" (expected yes)\n\";
echo 'elapsed: ' . round(\$elapsed, 2) . \"s (expected ~0.4-0.6s: 10 requests / concurrency 5 * 0.2s latency, well under the 2.0s a fully sequential run would take)\n\";
"
```

Expected: `results: 10`, `all ok: yes`, elapsed noticeably under 2.0s
(sequential baseline), roughly 0.4-0.6s.

Stop the mock server: `kill %1` (or find/kill the `php -S` PID).

- [ ] **Step 3: Extract `dispatch_message_prepare()` / `dispatch_message_finish()` from `dispatch_message()`**

Replace `dispatch_message()` (lines 100-153) with:

```php
/**
 * Validate a single dispatch and (for non-admins) reserve its cost against
 * $creditBudget — callers processing several items for the same user_id in
 * one pass share the same $creditBudget array so concurrent sends can't
 * jointly overspend a low balance (each admission decrements the budget
 * before any network call happens, using the pre-batch credit as the
 * starting point). Returns [false, errorMessage] if the item shouldn't be
 * sent at all (bad input or insufficient credit — no network call), or
 * [true, ['originator'=>normalizedOriginator,'parts'=>int,'cost'=>int]].
 */
function dispatch_message_prepare(array $user, string $originator, array $destinations, string $content, array &$creditBudget): array {
    if (!$destinations)          return [false, 'شماره مقصد معتبری وارد نشده است.'];
    if (trim($content) === '')   return [false, 'متن پیام خالی است.'];

    $normOriginator = normalize_originator($originator);
    if ($normOriginator === null) return [false, 'خط ارسال‌کننده خالی یا غیرعددی است — آن را بالا یا در تنظیمات وارد کنید.'];

    $parts = sms_parts($content);
    $cost  = $parts * count($destinations);

    if ($user['role'] !== 'admin') {
        $available = $creditBudget[$user['id']] ?? (float)$user['credit'];
        if ($available < $cost) {
            return [false, "اعتبار کافی نیست: این ارسال به {$cost} واحد اعتبار نیاز دارد، اعتبار فعلی شما " . (int)$available . ' است.'];
        }
        $creditBudget[$user['id']] = $available - $cost;
    }

    return [true, ['originator' => $normOriginator, 'parts' => $parts, 'cost' => $cost]];
}

/**
 * Apply a completed backend_api_send()/backend_api_send_batch()-shaped
 * result: charges credit for what actually sent (may be less than the
 * reservation dispatch_message_prepare() made — a failed send charges
 * nothing, a partial send charges only the sent portion), writes fallback
 * send_failed rows if the API was unreachable. Returns [ok, infoMessage].
 */
function dispatch_message_finish(array $user, string $originator, array $destinations, string $content, int $parts, array $sendResult): array {
    [$reached, $http, $rows, $err] = $sendResult;

    if (!$reached) {
        $ins = db()->prepare(
            'INSERT INTO outbound_message (sender_user_id, originator, destination, content, status, error_code, sent_at)
             VALUES (?,?,?,?,?,?, NOW())'
        );
        foreach ($destinations as $dest) {
            $ins->execute([$user['id'], $originator, $dest, $content, 'send_failed', -501]);
        }
        return [false, describe_api_error($http, $err) . ' جزئیات در گزارش موجود است.'];
    }

    $sentCount = 0;
    foreach ($rows as $r) {
        if (($r['status'] ?? '') === 'sent') $sentCount++;
    }
    $allOk = $sentCount === count($destinations);

    if ($sentCount > 0 && $user['role'] !== 'admin') {
        db()->prepare('UPDATE user_ SET currentcredit = currentcredit - ? WHERE id = ?')
           ->execute([$parts * $sentCount, $user['id']]);
    }

    if ($allOk) {
        return [true, 'به ' . to_persian_digits((string)count($destinations)) . " شماره ارسال شد — {$parts} بخش برای هرکدام، " . to_persian_digits((string)($parts * $sentCount)) . ' واحد اعتبار.'];
    }
    if ($sentCount > 0) {
        return [true, 'به ' . to_persian_digits((string)$sentCount) . ' از ' . to_persian_digits((string)count($destinations)) . ' شماره ارسال شد — برای مشاهده‌ی موارد ناموفق به گزارش مراجعه کنید.'];
    }
    return [false, 'گیت‌وی همه‌ی مقصدها را رد کرد. جزئیات در گزارش موجود است.'];
}

/**
 * Send a message batch for a user: credit check, API call, and (only if
 * the API itself was unreachable) a fallback row per destination.
 * Returns [ok, infoMessage]. Used by the interactive send paths
 * (send.php, new-send.php direct mode, url_send.html) — NOT by the bulk
 * worker, which uses bulk_send_batch() below for concurrent sends.
 */
function dispatch_message(array $user, string $originator, array $destinations, string $content, ?int $scheduleId = null): array {
    $budget = [];
    [$ok, $prep] = dispatch_message_prepare($user, $originator, $destinations, $content, $budget);
    if (!$ok) return [false, $prep];

    $sendResult = backend_api_send((int)$user['id'], $prep['originator'], $destinations, $content);
    return dispatch_message_finish($user, $prep['originator'], $destinations, $content, $prep['parts'], $sendResult);
}
```

Note the one intentional behavior fix bundled here: the original
`dispatch_message()` had two near-identical `if`/`elseif` branches that
both ran the exact same credit-deduction UPDATE (`$allOk && role !==
'admin'` and `elseif $sentCount > 0 && role !== 'admin'`) — collapsed
into the single `if ($sentCount > 0 && ...)` above with no behavior
change (same condition coverage, same amount charged), just no longer
duplicated.

- [ ] **Step 4: Replace `bulk_send_one_item()` with `bulk_send_batch()`**

Replace `bulk_send_one_item()` (lines 532-557) with:

```php
/**
 * Send a batch of queued bulk items concurrently. Shared by both the
 * throttled and unthrottled paths in run_bulk_send_pass() below. Returns
 * how many items actually sent (status became 'sent' — including partial
 * multi-destination sends, matching dispatch_message()'s own definition
 * of "ok").
 */
function bulk_send_batch(PDO $db, array $items, int $concurrency): int {
    if (!$items) return 0;

    // Phase 1: owner lookup + admission (credit budget), sequential,
    // DB-only, no network calls yet. Items sharing a user_id in this
    // batch share one $creditBudget so they can't jointly overspend.
    $creditBudget = [];
    $prepared = []; // item id => ['user'=>,'originator'=>,'parts'=>,'item'=>]
    foreach ($items as $item) {
        $ust = $db->prepare(
            'SELECT u.id, u.active, u.deleted, u.currentcredit AS credit, m.is_admin
             FROM user_ u JOIN ellsms_meta m ON m.user_id = u.id WHERE u.id = ?'
        );
        $ust->execute([$item['user_id']]);
        $owner = $ust->fetch();

        if (!$owner || !$owner['active'] || $owner['deleted']) {
            $db->prepare("UPDATE ellsms_bulk_items SET status='failed', error=? WHERE id=?")
               ->execute(['حساب مالک ارسال غیرفعال است.', $item['id']]);
            $db->prepare('UPDATE ellsms_bulk_jobs SET failed_rows = failed_rows + 1 WHERE id=?')->execute([$item['job_id']]);
            continue;
        }

        $user = ['id' => (int)$owner['id'], 'role' => $owner['is_admin'] ? 'admin' : 'user', 'credit' => $owner['credit']];
        [$ok, $prep] = dispatch_message_prepare($user, $item['originator'], [$item['mobile']], $item['content'], $creditBudget);

        if (!$ok) {
            $db->prepare("UPDATE ellsms_bulk_items SET status='failed', error=? WHERE id=?")->execute([$prep, $item['id']]);
            $db->prepare('UPDATE ellsms_bulk_jobs SET failed_rows = failed_rows + 1 WHERE id=?')->execute([$item['job_id']]);
            continue;
        }

        $prepared[$item['id']] = ['user' => $user, 'originator' => $prep['originator'], 'parts' => $prep['parts'], 'item' => $item];
    }

    if (!$prepared) return 0;

    // Phase 2: fire concurrently.
    $requests = [];
    foreach ($prepared as $id => $p) {
        $requests[$id] = [
            'senderUserId' => $p['user']['id'],
            'originator'   => $p['originator'],
            'destinations' => [$p['item']['mobile']],
            'content'      => $p['item']['content'],
        ];
    }
    $sendResults = backend_api_send_batch($requests, $concurrency);

    // Phase 3: finish sequentially — DB writes only, network is done.
    $sent = 0;
    foreach ($prepared as $id => $p) {
        [$ok, $info] = dispatch_message_finish($p['user'], $p['originator'], [$p['item']['mobile']], $p['item']['content'], $p['parts'], $sendResults[$id]);

        $db->prepare('UPDATE ellsms_bulk_items SET status=?, error=? WHERE id=?')
           ->execute([$ok ? 'sent' : 'failed', $ok ? null : $info, $id]);
        $counterCol = $ok ? 'sent_rows' : 'failed_rows';
        $db->prepare("UPDATE ellsms_bulk_jobs SET {$counterCol} = {$counterCol} + 1 WHERE id=?")->execute([$p['item']['job_id']]);

        if ($ok) $sent++;
    }

    return $sent;
}
```

- [ ] **Step 5: Update `run_bulk_send_pass()` to call `bulk_send_batch()`**

Replace the two `foreach (... as $item) { bulk_send_one_item(...) }` loops
in `run_bulk_send_pass()` (lines 574-621):

```php
function run_bulk_send_pass(): int {
    $db = db();
    $db->exec("UPDATE ellsms_bulk_jobs SET status='processing' WHERE status='pending' ORDER BY id LIMIT 1");

    $concurrency = max(1, (int)env('BULK_SEND_CONCURRENCY', '5'));
    $sent = 0;

    $throttled = $db->query(
        "SELECT * FROM ellsms_bulk_jobs
         WHERE status = 'processing' AND throttle_count IS NOT NULL AND throttle_minutes IS NOT NULL
           AND (last_throttle_at IS NULL OR last_throttle_at <= DATE_SUB(NOW(), INTERVAL throttle_minutes MINUTE))"
    )->fetchAll();

    foreach ($throttled as $job) {
        $limit = max(1, (int)$job['throttle_count']);
        $jobId = (int)$job['id'];
        $items = $db->query(
            "SELECT * FROM ellsms_bulk_items WHERE job_id = {$jobId} AND status = 'pending' ORDER BY id LIMIT {$limit}"
        )->fetchAll();
        if (!$items) continue;

        $sent += bulk_send_batch($db, $items, $concurrency);
        $db->prepare('UPDATE ellsms_bulk_jobs SET last_throttle_at = NOW() WHERE id = ?')->execute([$jobId]);
    }

    $st = $db->prepare(
        "SELECT i.* FROM ellsms_bulk_items i
         JOIN ellsms_bulk_jobs j ON j.id = i.job_id
         WHERE j.status = 'processing' AND i.status = 'pending' AND j.throttle_count IS NULL
         ORDER BY i.id LIMIT 20"
    );
    $st->execute();
    $sent += bulk_send_batch($db, $st->fetchAll(), $concurrency);

    $db->exec(
        "UPDATE ellsms_bulk_jobs j SET status='done'
         WHERE status='processing' AND NOT EXISTS (
           SELECT 1 FROM ellsms_bulk_items i WHERE i.job_id = j.id AND i.status='pending'
         )"
    );

    return $sent;
}
```

Update the doc comment above `run_bulk_send_pass()` (lines 559-573) to
mention that each batch now sends concurrently
(`BULK_SEND_CONCURRENCY` requests in flight at once) rather than one row
at a time — same paragraph, just note the concurrency instead of leaving
it stale.

- [ ] **Step 6: `.env.example` and `docker-compose.yml`**

In `.env.example`, add after the `API_BASE_URL` block:

```
# How many bulk-send rows the worker sends to the backend API
# concurrently within one batch (still respects each batch's existing
# size — 20 rows/tick unthrottled, or a job's own throttle_count).
# Tune down if the backend API has a concurrency/rate limit you're
# hitting; there's no admin-panel override for this one, it's an ops knob.
BULK_SEND_CONCURRENCY=5
```

In `docker-compose.yml`, add to the `worker` service's `environment:`
block (after `API_BASE_URL`):

```yaml
      BULK_SEND_CONCURRENCY: ${BULK_SEND_CONCURRENCY:-5}
```

- [ ] **Step 7: Verify against a real deployment**

Queue a test بارگذاری (p2p or smart) job with at least 25-30 rows so it
spans more than one worker tick, then watch the worker logs:

```bash
docker compose logs -f worker
```

Expected: `sent {N} bulk row(s)` lines appear, and once the job finishes,
confirm `sent_rows + failed_rows == total_rows` for that job:

```bash
docker compose exec app php -r "
require '/var/www/html/app/bootstrap.php';
\$j = db()->query('SELECT id, total_rows, sent_rows, failed_rows FROM ellsms_bulk_jobs ORDER BY id DESC LIMIT 1')->fetch();
print_r(\$j);
echo (\$j['sent_rows'] + \$j['failed_rows'] === \$j['total_rows']) ? \"reconciled OK\n\" : \"MISMATCH\n\";
"
```

Also spot-check that a single low-credit user's bulk job doesn't
overspend: create a job whose rows would cost more than that user's
`currentcredit`, run it, and confirm
`user_.currentcredit` never goes negative and the count of `sent`
rows times their per-row cost doesn't exceed the starting balance.

- [ ] **Step 8: Commit**

```bash
git add app/backend.php .env.example docker-compose.yml
git commit -m "perf: send bulk-queue batches concurrently via curl_multi"
```

---

## Task 6: Indexes + dashboard query fix

**Files:**
- Modify: `db/ellsms_extra.sql`
- Modify: `public/index.php:16-17`

**Interfaces:** None — pure SQL/query changes, no PHP function signatures
involved.

- [ ] **Step 1: Add guarded index migrations to `db/ellsms_extra.sql`**

Append near the other guarded migrations (after the `ellsms_bulk_jobs`
throttle-columns migration, before the final settings seed `INSERT`):

```sql
-- Indexes on the backend platform's own outbound_message/inbound_message
-- tables. These are NOT ellsms_ tables — CREATE INDEX is additive and
-- non-destructive (no data or column changes, just a lookup structure),
-- so the team decided it's safe to add here rather than only documenting
-- it for the backend team, same spirit as the coordinated-change note the
-- README already has for password hashing. Guarded the same way as the
-- other migrations in this file: safe to re-run, skips if already present.
SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'outbound_message' AND index_name = 'idx_outbound_sent_at'
);
SET @sql = IF(@idx_exists = 0,
  'CREATE INDEX idx_outbound_sent_at ON outbound_message (sent_at)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'outbound_message' AND index_name = 'idx_outbound_user_sent'
);
SET @sql = IF(@idx_exists = 0,
  'CREATE INDEX idx_outbound_user_sent ON outbound_message (sender_user_id, sent_at)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'outbound_message' AND index_name = 'idx_outbound_user_dest'
);
SET @sql = IF(@idx_exists = 0,
  'CREATE INDEX idx_outbound_user_dest ON outbound_message (sender_user_id, destination, id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'inbound_message' AND index_name = 'idx_inbound_received_at'
);
SET @sql = IF(@idx_exists = 0,
  'CREATE INDEX idx_inbound_received_at ON inbound_message (received_at)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'inbound_message' AND index_name = 'idx_inbound_dest_received'
);
SET @sql = IF(@idx_exists = 0,
  'CREATE INDEX idx_inbound_dest_received ON inbound_message (destination, received_at)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
```

- [ ] **Step 2: Fix the non-sargable dashboard queries in `public/index.php`**

Replace lines 16-17:

```php
$todaySent   = $q("SELECT COUNT(*) c FROM outbound_message WHERE status IN ('sent','delivered') AND DATE(sent_at)=CURDATE(){$scope}");
$todayFailed = $q("SELECT COUNT(*) c FROM outbound_message WHERE status IN ('send_failed','failed') AND DATE(sent_at)=CURDATE(){$scope}");
```

With:

```php
$todaySent   = $q("SELECT COUNT(*) c FROM outbound_message WHERE status IN ('sent','delivered') AND sent_at >= CURDATE() AND sent_at < CURDATE() + INTERVAL 1 DAY{$scope}");
$todayFailed = $q("SELECT COUNT(*) c FROM outbound_message WHERE status IN ('send_failed','failed') AND sent_at >= CURDATE() AND sent_at < CURDATE() + INTERVAL 1 DAY{$scope}");
```

And line 21's inbox-today count:

```php
$inboxToday  = $me['role'] === 'admin'
    ? $q("SELECT COUNT(*) c FROM inbound_message WHERE DATE(received_at)=CURDATE()")
    : null;
```

With:

```php
$inboxToday  = $me['role'] === 'admin'
    ? $q("SELECT COUNT(*) c FROM inbound_message WHERE received_at >= CURDATE() AND received_at < CURDATE() + INTERVAL 1 DAY")
    : null;
```

These are functionally identical to the `DATE(...)=CURDATE()` originals
(same rows match) — just sargable, so `idx_outbound_sent_at` /
`idx_inbound_received_at` can actually be used.

- [ ] **Step 3: Verify against a real deployment**

Apply the migration:

```bash
docker exec -i "$BACKEND_DB_HOST" mysql -u"$BACKEND_DB_USER" -p"$BACKEND_DB_PASS" "$BACKEND_DB_NAME" < db/ellsms_extra.sql
```

Confirm the indexes exist:

```bash
docker exec -i "$BACKEND_DB_HOST" mysql -u"$BACKEND_DB_USER" -p"$BACKEND_DB_PASS" "$BACKEND_DB_NAME" -e "SHOW INDEX FROM outbound_message WHERE Key_name LIKE 'idx_outbound%'; SHOW INDEX FROM inbound_message WHERE Key_name LIKE 'idx_inbound%';"
```

Expected: all five new index names listed.

Confirm the rewritten dashboard query actually uses the new index:

```bash
docker exec -i "$BACKEND_DB_HOST" mysql -u"$BACKEND_DB_USER" -p"$BACKEND_DB_PASS" "$BACKEND_DB_NAME" -e "EXPLAIN SELECT COUNT(*) FROM outbound_message WHERE status IN ('sent','delivered') AND sent_at >= CURDATE() AND sent_at < CURDATE() + INTERVAL 1 DAY;"
```

Expected: `key` column shows `idx_outbound_sent_at` (not `NULL`/a full
table scan).

Re-run the migration a second time to confirm it's still a safe no-op:

```bash
docker exec -i "$BACKEND_DB_HOST" mysql -u"$BACKEND_DB_USER" -p"$BACKEND_DB_PASS" "$BACKEND_DB_NAME" < db/ellsms_extra.sql
```
Expected: no errors, no duplicate-index errors.

- [ ] **Step 4: Commit**

```bash
git add db/ellsms_extra.sql public/index.php
git commit -m "perf: index outbound_message/inbound_message, fix non-sargable dashboard queries"
```
