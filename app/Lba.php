<?php
/**
 * LBA — location-based sending (ارسال پیام منطقه‌ای) through a provider's smart SMS service, today
 * PishgamRayan's (app/Lba/PishgamSmartSms.php). docs/lba.md.
 *
 * LBA gateways are their own kind, beside (not among) the ordinary SMS gateways: an admin defines them
 * on /admin/lba-gateways (token, optional username/password, the per-part tariff) and pins one to each
 * number that may send LBA (ellsms_numbers.lba_gateway_id). A number's ordinary gateway is untouched.
 *
 * The provider's model is followed step by step:
 *   1. audience ("request"): one of eight Create*SmartSmsRequest methods, built asynchronously by the
 *      provider; the worker polls it (RefreshSmartSmsRequest + GetSmartSmsRequestById) until it is
 *      completed, then its per-operator segments (id, count, coordinates) are known;
 *   2. send: SendSimpleSmartSms once, or Send{Percentage,Count}PeriodicSmartSms on a periodic request,
 *      edited with Update*PeriodicSmartSms and switched with UpdatePeriodicSmartSmsRequestActivation;
 *   3. follow: the statistics methods.
 * Gray lists (campaigns) and clubs live in the provider account, which all of a gateway's customers
 * share, so each one is recorded locally with its owner; titles sent to the provider carry the local id
 * ("… #12") because the provider requires them unique, which also lets a lost answer be recovered.
 *
 * Money (Rial, from the customer's wallet; platform admins are not charged): the gateway's fixed tariff
 * per part — price_fa_part for a Unicode (Persian) message, price_en_part otherwise — times parts times
 * recipients. Every message sent is charged, delivered or not.
 *   - one-off send: the full cost is reserved, then committed once the provider accepts the send, or
 *     released when it refuses. An answer that never arrives leaves the send `unknown` with the money
 *     reserved, for an admin to settle as sent or refund.
 *   - periodic send: maxSendCount (required here, optional at the provider) × parts × tariff is
 *     reserved; the worker commits what the provider's periodic statistics report as sent (partsCount),
 *     and releases the rest once the period is over or the request is deleted.
 *
 * Checks before anything reaches the provider: KYC gate `lba` (required by default), impersonation
 * (send.bulk), the customer may use the line, the line has a usable LBA gateway, the content policy.
 */

declare(strict_types=1);

const LBA_KINDS = [
    'simple'           => ['ارسال ساده (چندضلعی روی نقشه)', 'CreateSimpleSmartSmsRequest'],
    'welcome'          => ['خوشامدگویی (چندضلعی + لیست خاکستری)', 'CreateWelcomeSmartSmsRequest'],
    'welcome_cells'    => ['خوشامدگویی با سلول‌های مخابراتی', 'CreateWelcomeSmartSmsRequestWithCellList'],
    'club'             => ['باشگاه هوشمند', 'CreateClubSmartSmsRequest'],
    'welcome_city'     => ['خوشامدگویی شهر', 'CreateWelcomeCitySmartSmsRequest'],
    'welcome_province' => ['خوشامدگویی استان', 'CreateWelcomeProvinceSmartSmsRequest'],
    'welcome_airport'  => ['خوشامدگویی فرودگاه', 'CreateWelcomeAirportSmartSmsRequest'],
    'periodic'         => ['دوره‌ای (کمپین)', 'CreatePeriodicSmartSmsRequest'],
];
/** Kinds whose audience is drawn on the map. */
const LBA_POLYGON_KINDS = ['simple', 'welcome', 'club', 'periodic'];
/** Kinds that take the demographic / device filters of the base model. */
const LBA_FILTER_KINDS = ['simple', 'welcome', 'welcome_cells', 'club', 'periodic'];
/** Kinds that need a gray list. */
const LBA_GRAYLIST_KINDS = ['welcome', 'welcome_cells', 'club', 'welcome_city', 'welcome_province', 'welcome_airport'];
/** Kinds of the welcome-by-place family (no polygon, no demographic filters). */
const LBA_PLACE_KINDS = ['welcome_city', 'welcome_province', 'welcome_airport'];

const LBA_RESIDENCE_STATES = [0 => 'ساکن در محل', 1 => 'مهمان', 2 => 'همه'];
const LBA_EMPLOYMENT_STATES = [0 => 'شاغل در محل', 1 => 'ساکن در محل', 2 => 'همه'];
const LBA_PASSENGER_STATES = [0 => 'مقیم استان', 1 => 'مسافر از استان دیگر', 2 => 'همه'];
const LBA_GENDERS = [0 => 'مرد', 1 => 'زن'];

/** One-off sends are followed (delivery statistics) for this long after they were accepted. */
const LBA_SIMPLE_FOLLOW_SECONDS = 3 * 86400;
/** A periodic send is settled this long after its period ended. */
const LBA_PERIODIC_SETTLE_GRACE_SECONDS = 86400;
/** Pending audiences are given up on after this long. */
const LBA_REQUEST_PENDING_MAX_SECONDS = 2 * 86400;

/* ---------------------------------------------------------------- availability */

/** True once the migration is applied (the pages and the worker are no-ops before). */
function lba_tables_ready(): bool {
    if (array_key_exists('__lba_tables_ready', $GLOBALS)) return $GLOBALS['__lba_tables_ready'];
    try {
        db()->query('SELECT 1 FROM ellsms_lba_gateways LIMIT 1');
        return $GLOBALS['__lba_tables_ready'] = true;
    } catch (PDOException) {
        return $GLOBALS['__lba_tables_ready'] = false;
    }
}

function lba_gateway_get(int $id): ?array {
    if ($id <= 0 || !lba_tables_ready()) return null;
    $st = db()->prepare('SELECT * FROM ellsms_lba_gateways WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** @return list<array> */
function lba_gateways_list(bool $withArchived = true): array {
    if (!lba_tables_ready()) return [];
    return db()->query('SELECT * FROM ellsms_lba_gateways' . ($withArchived ? '' : " WHERE status = 'active'") . ' ORDER BY status, name')->fetchAll();
}

/** Why a gateway cannot carry LBA right now, or null when it can. */
function lba_gateway_unusable_reason(?array $g): ?string {
    if ($g === null) return 'درگاه LBA برای این خط تعریف نشده است.';
    if ($g['status'] !== 'active') return 'درگاه LBA این خط بایگانی شده است.';
    if (!(int)$g['enabled']) return 'درگاه LBA این خط غیرفعال است.';
    if ($g['price_fa_part'] === null || $g['price_en_part'] === null) return 'تعرفه‌ی درگاه LBA این خط تعیین نشده است.';
    if (!lba_secrets_stored((int)$g['id'])['token']) return 'توکن درگاه LBA این خط ذخیره نشده است.';
    return null;
}

/** The LBA gateway pinned to a number (usable or not), or null. */
function lba_gateway_for_originator(string $originator): ?array {
    if (!lba_tables_ready()) return null;
    $st = db()->prepare('SELECT g.* FROM ellsms_numbers n JOIN ellsms_lba_gateways g ON g.id = n.lba_gateway_id WHERE n.number = ?');
    $st->execute([$originator]);
    return $st->fetch() ?: null;
}

/**
 * The lines $user may send LBA from: the lines they may send from, that have a usable LBA gateway.
 *
 * @return list<array{number:string, label:string, gateway:array}>
 */
function lba_originators_for(array $user): array {
    if (!lba_tables_ready()) return [];
    $rows = db()->query("SELECT n.number, n.label, n.lba_gateway_id FROM ellsms_numbers n WHERE n.lba_gateway_id IS NOT NULL ORDER BY n.number")->fetchAll();
    if ($rows === []) return [];
    $sendable = sendable_originators($user);
    $all = in_array('*', $sendable, true);
    $gateways = [];
    $out = [];
    foreach ($rows as $r) {
        if (!$all && !in_array((string)$r['number'], $sendable, true)) continue;
        $gid = (int)$r['lba_gateway_id'];
        $gateways[$gid] ??= lba_gateway_get($gid);
        if (lba_gateway_unusable_reason($gateways[$gid]) !== null) continue;
        $out[] = ['number' => (string)$r['number'], 'label' => (string)$r['label'], 'gateway' => $gateways[$gid]];
    }
    return $out;
}

/** The menu entry is shown to admins, and to a customer with at least one LBA line. */
function lba_menu_visible(array $user): bool {
    if (!lba_tables_ready()) return false;
    return ($user['role'] ?? null) === 'admin' || lba_originators_for($user) !== [];
}

/* ---------------------------------------------------------------- admin: gateways and numbers */

/**
 * Creates ($id = null) or updates an LBA gateway. Secret fields left blank keep the stored value;
 * $in['clear_username'] / ['clear_password'] remove the optional ones.
 *
 * @return array{ok:bool, id?:int, error?:string}
 */
function lba_gateway_save(array $in, int $actorId, ?int $id = null): array {
    $name = trim((string)($in['name'] ?? ''));
    $code = strtolower(trim((string)($in['code'] ?? '')));
    $baseUrl = rtrim(trim((string)($in['base_url'] ?? '')), '/');
    if ($baseUrl === '') $baseUrl = LBA_PISHGAM_DEFAULT_BASE_URL;
    if ($name === '' || mb_strlen($name) > 120) return ['ok' => false, 'error' => 'نام درگاه را وارد کنید.'];
    if ($id === null && preg_match('/^[a-z0-9_]{2,40}$/', $code) !== 1) return ['ok' => false, 'error' => 'شناسه‌ی درگاه باید ۲ تا ۴۰ نویسه‌ی لاتین کوچک، عدد یا زیرخط باشد.'];
    if (preg_match(app_env() === 'production' ? '#^https://[^\s]+$#i' : '#^https?://[^\s]+$#i', $baseUrl) !== 1) {
        return ['ok' => false, 'error' => 'آدرس سرویس نامعتبر است' . (app_env() === 'production' ? ' (باید با https:// شروع شود).' : '.')];
    }
    $price = static function (mixed $v): ?int {
        $v = preg_replace('/\D/', '', from_persian_digits((string)$v)) ?? '';
        return $v === '' ? null : (int)$v;
    };
    $priceFa = $price($in['price_fa_part'] ?? '');
    $priceEn = $price($in['price_en_part'] ?? '');
    $enabled = !empty($in['enabled']) ? 1 : 0;
    $token = trim((string)($in['token'] ?? ''));
    if ($enabled && ($priceFa === null || $priceEn === null)) return ['ok' => false, 'error' => 'برای فعال‌سازی، تعرفه‌ی هر پارت فارسی و انگلیسی را تعیین کنید.'];
    if ($enabled && $token === '' && ($id === null || !lba_secrets_stored($id)['token'])) return ['ok' => false, 'error' => 'برای فعال‌سازی، توکن را وارد کنید.'];
    if (($token !== '' || trim((string)($in['username'] ?? '')) !== '' || (string)($in['password'] ?? '') !== '') && lba_secret_key() === null) {
        return ['ok' => false, 'error' => 'برای ذخیره‌ی توکن، SMS_GATEWAY_MASTER_KEY (حداقل ۳۲ نویسه) باید تنظیم شده باشد.'];
    }

    $db = db();
    try {
        if ($id === null) {
            $db->prepare('INSERT INTO ellsms_lba_gateways (code, name, base_url, enabled, price_fa_part, price_en_part) VALUES (?,?,?,?,?,?)')
               ->execute([$code, $name, $baseUrl, $enabled, $priceFa, $priceEn]);
            $id = (int)$db->lastInsertId();
        } else {
            if (lba_gateway_get($id) === null) return ['ok' => false, 'error' => 'درگاه پیدا نشد.'];
            $db->prepare('UPDATE ellsms_lba_gateways SET name = ?, base_url = ?, enabled = ?, price_fa_part = ?, price_en_part = ? WHERE id = ?')
               ->execute([$name, $baseUrl, $enabled, $priceFa, $priceEn, $id]);
        }
    } catch (PDOException) {
        return ['ok' => false, 'error' => 'این شناسه‌ی درگاه قبلاً ثبت شده است.'];
    }
    if ($token !== '') lba_secret_put($id, 'token', $token);
    $username = trim((string)($in['username'] ?? ''));
    if ($username !== '') lba_secret_put($id, 'username', $username);
    elseif (!empty($in['clear_username'])) lba_secret_put($id, 'username', '');
    $password = (string)($in['password'] ?? '');
    if ($password !== '') lba_secret_put($id, 'password', $password);
    elseif (!empty($in['clear_password'])) lba_secret_put($id, 'password', '');
    // The trail records what changed, never a secret's value.
    audit($actorId, 'lba.gateway.saved', json_encode(['id' => $id, 'enabled' => $enabled, 'host' => parse_url($baseUrl, PHP_URL_HOST),
        'price_fa_part' => $priceFa, 'price_en_part' => $priceEn, 'token_changed' => $token !== ''], JSON_UNESCAPED_UNICODE) ?: '');
    return ['ok' => true, 'id' => $id];
}

function lba_gateway_set_archived(int $id, bool $archived, int $actorId): bool {
    $st = db()->prepare('UPDATE ellsms_lba_gateways SET status = ?, enabled = IF(? = 1, 0, enabled) WHERE id = ?');
    $st->execute([$archived ? 'archived' : 'active', $archived ? 1 : 0, $id]);
    audit($actorId, $archived ? 'lba.gateway.archived' : 'lba.gateway.restored', '#' . $id);
    return $st->rowCount() === 1;
}

/** Checks the token and the server's IP: reads the province list. @return array{ok:bool, message:string} */
function lba_gateway_test(array $g, int $actorId): array {
    $r = lba_pishgam_call($g, 'GET', 'GetProvinces');
    $ok = $r['ok'];
    $message = $ok ? 'اتصال برقرار است (' . to_persian_digits((string)count(lba_list_of($r['data']))) . ' استان دریافت شد).' : lba_call_error($r);
    db()->prepare('UPDATE ellsms_lba_gateways SET last_test_at = NOW(), last_test_ok = ?, last_test_message = ? WHERE id = ?')
       ->execute([$ok ? 1 : 0, mb_strimwidth($message, 0, 480, '…'), (int)$g['id']]);
    audit($actorId, 'lba.gateway.test', '#' . (int)$g['id'] . ' ' . ($ok ? 'ok' : 'failed'));
    return ['ok' => $ok, 'message' => $message];
}

/** Pins an LBA gateway to a number (null = the number cannot send LBA). @return array{ok:bool, error?:string} */
function lba_number_set_gateway(int $numberId, ?int $gatewayId, int $actorId): array {
    if ($gatewayId !== null) {
        $g = lba_gateway_get($gatewayId);
        if ($g === null || $g['status'] !== 'active') return ['ok' => false, 'error' => 'این درگاه LBA فعال نیست.'];
    }
    $st = db()->prepare('SELECT number FROM ellsms_numbers WHERE id = ?');
    $st->execute([$numberId]);
    $number = $st->fetchColumn();
    if ($number === false) return ['ok' => false, 'error' => 'شماره پیدا نشد.'];
    db()->prepare('UPDATE ellsms_numbers SET lba_gateway_id = ? WHERE id = ?')->execute([$gatewayId, $numberId]);
    audit($actorId, 'number.lba_gateway', $number . ' -> ' . ($gatewayId ?? 'none'));
    return ['ok' => true];
}

/* ---------------------------------------------------------------- shared helpers */

/** [SQL condition, params] limiting rows to what $user may see (admins: everything). */
function lba_scope_sql(array $user, string $alias = ''): array {
    $p = $alias === '' ? '' : $alias . '.';
    if (($user['role'] ?? null) === 'admin') return ['1=1', []];
    $orgId = (int)($user['organization_id'] ?? 0);
    return $orgId > 0 ? ["{$p}organization_id = ?", [$orgId]] : ["{$p}user_id = ?", [(int)$user['id']]];
}

function lba_row_visible(array $row, array $user): bool {
    if (($user['role'] ?? null) === 'admin') return true;
    $orgId = (int)($user['organization_id'] ?? 0);
    return $orgId > 0 ? (int)($row['organization_id'] ?? 0) === $orgId : (int)$row['user_id'] === (int)$user['id'];
}

function lba_find(string $table, int $id, array $user): ?array {
    $tables = ['campaign' => 'ellsms_lba_campaigns', 'club' => 'ellsms_lba_clubs', 'request' => 'ellsms_lba_requests', 'send' => 'ellsms_lba_sends'];
    if (!isset($tables[$table]) || $id <= 0) return null;
    $st = db()->prepare('SELECT * FROM ' . $tables[$table] . ' WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row && lba_row_visible($row, $user) ? $row : null;
}

/**
 * The checks every action that costs money or creates something at the provider goes through.
 *
 * @return array{ok:bool, gateway?:array, error?:string}
 */
function lba_guard(array $user, string $originator): array {
    $orgId = (int)($user['organization_id'] ?? 0);
    if (($user['role'] ?? null) !== 'admin') {
        $gateRequired = setting_fresh(kyc_gate_setting_key('lba'), '1') === '1';
        if (!kyc_feature_allowed_for_status($gateRequired, (string)(kyc_request_get($orgId)['status'] ?? 'draft'))) {
            return ['ok' => false, 'error' => kyc_gate_denial_message($orgId, 'lba')];
        }
    }
    if (!impersonation_action_allowed('send.bulk')) {
        impersonation_record_block('send.bulk');
        return ['ok' => false, 'error' => impersonation_block_message('send.bulk')];
    }
    $originator = normalize_originator($originator) ?? '';
    if ($originator === '' || !can_use_originator($user, $originator)) return ['ok' => false, 'error' => 'استفاده از این خط ارسال برای شما مجاز نیست.'];
    $g = lba_gateway_for_originator($originator);
    $reason = lba_gateway_unusable_reason($g);
    if ($reason !== null) return ['ok' => false, 'error' => $reason];
    return ['ok' => true, 'gateway' => $g];
}

/** The provider-side title: the customer's title plus the local id, unique across the shared account. */
function lba_provider_title(string $title, int $id): string {
    return mb_substr($title, 0, 150) . ' #' . $id;
}

/** A Tehran-time Jalali "YYYY/MM/DD HH:MM:SS" for a Unix time. */
function lba_jalali(int $ts, bool $withTime = true): string {
    $local = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Asia/Tehran'));
    [$jy, $jm, $jd] = gregorian_to_jalali((int)$local->format('Y'), (int)$local->format('n'), (int)$local->format('j'));
    return sprintf('%04d/%02d/%02d', $jy, $jm, $jd) . ($withTime ? ' ' . $local->format('H:i:s') : '');
}

/**
 * Reads a Jalali date-time the customer typed ("1404/08/10 10:00", Persian digits, "-" or "/").
 *
 * @return array{0:string, 1:int}|null [normalized "YYYY/MM/DD HH:MM:SS", Unix time], or null
 */
function lba_parse_jalali(string $raw, bool $withTime = true): ?array {
    $raw = trim(str_replace(['-', '٫', 'T'], ['/', '/', ' '], from_persian_digits($raw)));
    $pattern = $withTime ? '#^(\d{4})/(\d{1,2})/(\d{1,2})\s+(\d{1,2}):(\d{2})(?::(\d{2}))?$#' : '#^(\d{4})/(\d{1,2})/(\d{1,2})$#';
    if (preg_match($pattern, $raw, $m) !== 1) return null;
    [$jy, $jm, $jd] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    [$h, $i, $s] = $withTime ? [(int)$m[4], (int)$m[5], (int)($m[6] ?? 0)] : [0, 0, 0];
    if ($jy < 1390 || $jy > 1500 || $jm < 1 || $jm > 12 || $jd < 1 || $jd > jalali_month_length($jy, $jm) || $h > 23 || $i > 59 || $s > 59) return null;
    [$gy, $gm, $gd] = jalali_to_gregorian($jy, $jm, $jd);
    $ts = (new DateTimeImmutable(sprintf('%04d-%02d-%02d %02d:%02d:%02d', $gy, $gm, $gd, $h, $i, $s), new DateTimeZone('Asia/Tehran')))->getTimestamp();
    return [sprintf('%04d/%02d/%02d', $jy, $jm, $jd) . ($withTime ? sprintf(' %02d:%02d:%02d', $h, $i, $s) : ''), $ts];
}

/** "HH:MM[:SS]" -> "HH:MM:SS", or null. */
function lba_parse_time(string $raw): ?string {
    $raw = trim(from_persian_digits($raw));
    if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $raw, $m) !== 1 || (int)$m[1] > 23 || (int)$m[2] > 59 || (int)($m[3] ?? 0) > 59) return null;
    return sprintf('%02d:%02d:%02d', (int)$m[1], (int)$m[2], (int)($m[3] ?? 0));
}

/** A list of small integers from a form value (array, or "1,2،3"), restricted to $allowed when given. */
function lba_int_list(mixed $v, ?array $allowed = null): array {
    $items = is_array($v) ? $v : preg_split('/[\s,،]+/u', from_persian_digits((string)$v), -1, PREG_SPLIT_NO_EMPTY);
    $out = [];
    foreach ((array)$items as $item) {
        $item = trim(from_persian_digits((string)$item));
        if ($item === '' || preg_match('/^\d+$/', $item) !== 1) continue;
        $n = (int)$item;
        if ($allowed !== null && !in_array($n, $allowed, true)) continue;
        $out[$n] = $n;
    }
    return array_values($out);
}

/** An optional integer form value: null when blank, false when not a whole number. */
function lba_opt_int(mixed $v): int|null|false {
    $v = trim(from_persian_digits((string)$v));
    if ($v === '') return null;
    return preg_match('/^\d+$/', $v) === 1 ? (int)$v : false;
}

/**
 * Polygons from the map (or typed): a JSON list of polygons, each a list of [longitude, latitude]
 * points — the provider's own order. A GeoJSON Polygon / MultiPolygon / Feature(Collection) is read too.
 * Each polygon is closed (its first point repeated at the end) as the guide's examples are.
 *
 * @return list<list<array{0:float,1:float}>>|string the polygons, or an error message
 */
function lba_parse_polygons(string $json): array|string {
    $data = json_decode(trim($json), true);
    if (!is_array($data) || $data === []) return 'محدوده‌ای روی نقشه رسم نشده است.';
    $type = is_string($data['type'] ?? null) ? $data['type'] : null;
    if ($type === 'FeatureCollection') {
        $data = array_map(static fn($f) => $f['geometry'] ?? null, (array)($data['features'] ?? []));
        $polys = [];
        foreach ($data as $geom) {
            if (($geom['type'] ?? '') === 'Polygon') $polys[] = $geom['coordinates'][0] ?? [];
            if (($geom['type'] ?? '') === 'MultiPolygon') foreach ((array)$geom['coordinates'] as $p) $polys[] = $p[0] ?? [];
        }
        $data = $polys;
    } elseif ($type === 'Feature') {
        $data = $data['geometry'] ?? [];
        $type = $data['type'] ?? null;
    }
    if ($type === 'Polygon') $data = [$data['coordinates'][0] ?? []];
    elseif ($type === 'MultiPolygon') $data = array_map(static fn($p) => $p[0] ?? [], (array)$data['coordinates']);
    // A single polygon (a list of points) instead of a list of polygons.
    if (isset($data[0][0]) && is_numeric($data[0][0])) $data = [$data];

    $out = [];
    foreach ($data as $n => $poly) {
        if (!is_array($poly)) return 'ساختار محدوده نامعتبر است.';
        $points = [];
        foreach ($poly as $pt) {
            if (!is_array($pt) || count($pt) < 2 || !is_numeric($pt[0]) || !is_numeric($pt[1])) return 'نقطه‌ی نامعتبر در محدوده‌ی ' . to_persian_digits((string)($n + 1)) . '.';
            [$lon, $lat] = [round((float)$pt[0], 7), round((float)$pt[1], 7)];
            // Iran and its waters, roughly. Also catches swapped [lat, lon] pairs.
            if ($lon < 43.0 || $lon > 64.5 || $lat < 24.0 || $lat > 40.5) return 'نقطه‌ای از محدوده‌ی ' . to_persian_digits((string)($n + 1)) . ' خارج از ایران است (ترتیب باید [طول، عرض] باشد).';
            $points[] = [$lon, $lat];
        }
        if ($points !== [] && $points[0] !== $points[count($points) - 1]) $points[] = $points[0];
        if (count(array_unique(array_map('json_encode', $points))) < 3) return 'هر محدوده دست‌کم سه نقطه‌ی متفاوت لازم دارد.';
        if (count($points) > 500) return 'هر محدوده حداکثر ۵۰۰ نقطه می‌تواند داشته باشد.';
        $out[] = $points;
    }
    if (count($out) > 20) return 'حداکثر ۲۰ محدوده در یک درخواست.';
    return $out;
}

/**
 * Number prefixes: up to three, each 4–8 digits starting with 98 ("0912" is read as "98912").
 *
 * @return list<string>|string
 */
function lba_parse_prefixes(string $raw): array|string {
    $items = preg_split('/[\s,،]+/u', from_persian_digits($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $out = [];
    foreach ($items as $p) {
        $p = preg_replace('/\D/', '', $p) ?? '';
        if (str_starts_with($p, '0')) $p = '98' . substr($p, 1);
        elseif (str_starts_with($p, '9') && !str_starts_with($p, '98')) $p = '98' . $p;
        if (preg_match('/^98\d{2,6}$/', $p) !== 1) return 'پیش‌شماره‌ی «' . $p . '» نامعتبر است: باید ۴ تا ۸ رقم و با ۹۸ شروع شود (مثلاً ۹۸۹۱۲).';
        $out[$p] = $p;
    }
    if (count($out) > 3) return 'حداکثر سه پیش‌شماره در هر درخواست مجاز است.';
    return array_values($out);
}

/* ---------------------------------------------------------------- lookups (cached a day) */

/** @return list<array{id:int,name:string}>|string */
function lba_lookup(array $gateway, string $method, array $query = []): array|string {
    if (!in_array($method, ['GetProvinces', 'GetCities', 'GetAirports', 'GetDeviceBrands', 'GetDeviceTypes'], true)) return 'متد نامعتبر.';
    $key = 'lba_cache.' . (int)$gateway['id'] . '.' . substr(md5($method . '|' . json_encode($query)), 0, 20);
    $cached = json_decode((string)setting_fresh($key, ''), true);
    if (is_array($cached) && (int)($cached['at'] ?? 0) > time() - 86400 && is_array($cached['rows'] ?? null)) return $cached['rows'];
    $rows = lba_pishgam_lookup($gateway, $method, $query);
    if (is_array($rows) && $rows !== []) set_setting($key, json_encode(['at' => time(), 'rows' => $rows], JSON_UNESCAPED_UNICODE));
    return $rows;
}

/** CheckPolygonCells: whether each operator covers the drawn area. @return array{ok:bool, rows?:list<array>, error?:string} */
function lba_check_polygons(array $user, string $originator, array $operators, string $polygonsJson): array {
    $guard = lba_guard($user, $originator);
    if (!$guard['ok']) return $guard;
    $operators = lba_int_list($operators, array_keys(LBA_OPERATORS));
    if ($operators === []) return ['ok' => false, 'error' => 'دست‌کم یک اپراتور انتخاب کنید.'];
    $polygons = lba_parse_polygons($polygonsJson);
    if (is_string($polygons)) return ['ok' => false, 'error' => $polygons];
    $r = lba_pishgam_call($guard['gateway'], 'POST', 'CheckPolygonCells', [], ['mobileOperators' => $operators, 'polygon' => $polygons]);
    if (!$r['ok']) return ['ok' => false, 'error' => lba_call_error($r)];
    $rows = [];
    foreach (lba_list_of($r['data']) as $row) {
        $rows[] = ['operator' => (int)lba_field($row, 'mobileOperator'), 'valid' => (bool)lba_field($row, 'isValid'), 'valid_area' => (bool)lba_field($row, 'isValidArea')];
    }
    return ['ok' => true, 'rows' => $rows];
}

/* ---------------------------------------------------------------- gray lists (campaigns) */

/** @return list<array> the visible campaigns, of one gateway when given */
function lba_campaigns_list(array $user, ?int $gatewayId = null): array {
    [$where, $params] = lba_scope_sql($user);
    $sql = "SELECT * FROM ellsms_lba_campaigns WHERE status <> 'deleted' AND {$where}";
    if ($gatewayId !== null) { $sql .= ' AND gateway_id = ?'; $params[] = $gatewayId; }
    $st = db()->prepare($sql . ' ORDER BY id DESC LIMIT 200');
    $st->execute($params);
    return $st->fetchAll();
}

/** Recovers an id the provider created when its answer was lost: finds the unique title in a list. */
function lba_recover_id(array $gateway, string $listMethod, string $title): ?int {
    $r = lba_pishgam_call($gateway, 'GET', $listMethod);
    if (!$r['ok']) return null;
    foreach (lba_list_of($r['data']) as $row) {
        if (is_array($row) && trim((string)lba_field($row, 'title')) === $title && is_numeric(lba_field($row, 'id'))) return (int)lba_field($row, 'id');
    }
    return null;
}

/** @return array{ok:bool, id?:int, error?:string} */
function lba_campaign_create(array $user, string $originator, string $title): array {
    $guard = lba_guard($user, $originator);
    if (!$guard['ok']) return $guard;
    $title = trim($title);
    if ($title === '' || mb_strlen($title) > 150) return ['ok' => false, 'error' => 'عنوان لیست خاکستری را وارد کنید (حداکثر ۱۵۰ نویسه).'];
    $g = $guard['gateway'];
    $db = db();
    $db->prepare('INSERT INTO ellsms_lba_campaigns (gateway_id, organization_id, user_id, title) VALUES (?,?,?,?)')
       ->execute([(int)$g['id'], (int)($user['organization_id'] ?? 0) ?: null, (int)$user['id'], $title]);
    $id = (int)$db->lastInsertId();
    $providerTitle = lba_provider_title($title, $id);
    $r = lba_pishgam_call($g, 'POST', 'CreateSmartSmsCampaign', [], ['title' => $providerTitle]);
    $providerId = $r['ok'] && is_numeric(lba_field($r['data'], 'campaignId')) ? (int)lba_field($r['data'], 'campaignId') : null;
    if ($providerId === null && $r['transport_error']) $providerId = lba_recover_id($g, 'GetCurrentOrganizationSmartSmsCampaigns', $providerTitle);
    if ($providerId === null) {
        $error = $r['ok'] ? 'پیشگام رایان شناسه‌ی لیست خاکستری را برنگرداند.' : lba_call_error($r);
        $db->prepare("UPDATE ellsms_lba_campaigns SET status = 'failed', error = ? WHERE id = ?")->execute([mb_strimwidth($error, 0, 480, '…'), $id]);
        return ['ok' => false, 'error' => $error];
    }
    $db->prepare('UPDATE ellsms_lba_campaigns SET provider_campaign_id = ?, operators_json = ? WHERE id = ?')
       ->execute([$providerId, json_encode(lba_field($r['data'], 'operatorsResult'), JSON_UNESCAPED_UNICODE), $id]);
    audit((int)$user['id'], 'lba.campaign.created', "#{$id} provider={$providerId}");
    return ['ok' => true, 'id' => $id];
}

/** GetSmartSmsCampaignCreateStatus: is the gray list ready at each operator? @return array{ok:bool, rows?:list<array{operator:int,created:bool}>, error?:string} */
function lba_campaign_status(array $user, int $id): array {
    $c = lba_find('campaign', $id, $user);
    if ($c === null || $c['provider_campaign_id'] === null) return ['ok' => false, 'error' => 'لیست خاکستری پیدا نشد.'];
    $g = lba_gateway_get((int)$c['gateway_id']);
    if ($g === null) return ['ok' => false, 'error' => 'درگاه LBA پیدا نشد.'];
    $r = lba_pishgam_call($g, 'GET', 'GetSmartSmsCampaignCreateStatus', ['smartSmsCampaignId' => (int)$c['provider_campaign_id']]);
    if (!$r['ok']) return ['ok' => false, 'error' => lba_call_error($r)];
    $rows = [];
    foreach (lba_list_of($r['data']) as $row) $rows[] = ['operator' => (int)lba_field($row, 'operator'), 'created' => (bool)lba_field($row, 'isCreated')];
    return ['ok' => true, 'rows' => $rows];
}

/** @return array{ok:bool, error?:string} */
function lba_campaign_delete(array $user, int $id): array {
    $c = lba_find('campaign', $id, $user);
    if ($c === null || $c['status'] === 'deleted') return ['ok' => false, 'error' => 'لیست خاکستری پیدا نشد.'];
    if (!impersonation_action_allowed('send.bulk')) return ['ok' => false, 'error' => impersonation_block_message('send.bulk')];
    if ($c['provider_campaign_id'] !== null && ($g = lba_gateway_get((int)$c['gateway_id'])) !== null) {
        $r = lba_pishgam_call($g, 'DELETE', 'DeleteSmartSmsCampaign', [], ['id' => (int)$c['provider_campaign_id']]);
        // 58 = already gone at the provider: delete it here too.
        if (!$r['ok'] && $r['status_code'] !== 58) return ['ok' => false, 'error' => lba_call_error($r)];
    }
    db()->prepare("UPDATE ellsms_lba_campaigns SET status = 'deleted' WHERE id = ?")->execute([$id]);
    audit((int)$user['id'], 'lba.campaign.deleted', '#' . $id);
    return ['ok' => true];
}

/* ---------------------------------------------------------------- clubs */

/** @return list<array> */
function lba_clubs_list(array $user, ?int $gatewayId = null): array {
    [$where, $params] = lba_scope_sql($user);
    $sql = "SELECT * FROM ellsms_lba_clubs WHERE status <> 'deleted' AND {$where}";
    if ($gatewayId !== null) { $sql .= ' AND gateway_id = ?'; $params[] = $gatewayId; }
    $st = db()->prepare($sql . ' ORDER BY id DESC LIMIT 200');
    $st->execute($params);
    return $st->fetchAll();
}

/** @return array{ok:bool, id?:int, error?:string} */
function lba_club_create(array $user, string $originator, string $title): array {
    $guard = lba_guard($user, $originator);
    if (!$guard['ok']) return $guard;
    $title = trim($title);
    if ($title === '' || mb_strlen($title) > 150) return ['ok' => false, 'error' => 'عنوان باشگاه را وارد کنید (حداکثر ۱۵۰ نویسه).'];
    $g = $guard['gateway'];
    $db = db();
    $db->prepare('INSERT INTO ellsms_lba_clubs (gateway_id, organization_id, user_id, title) VALUES (?,?,?,?)')
       ->execute([(int)$g['id'], (int)($user['organization_id'] ?? 0) ?: null, (int)$user['id'], $title]);
    $id = (int)$db->lastInsertId();
    $providerTitle = lba_provider_title($title, $id);
    $r = lba_pishgam_call($g, 'POST', 'CreateSmartSmsClub', [], ['title' => $providerTitle]);
    $providerId = $r['ok'] && is_numeric(lba_field($r['data'], 'clubId')) ? (int)lba_field($r['data'], 'clubId') : null;
    if ($providerId === null && $r['transport_error']) $providerId = lba_recover_id($g, 'GetCurrentOrganizationSmartSmsClubs', $providerTitle);
    if ($providerId === null) {
        $error = $r['ok'] ? 'پیشگام رایان شناسه‌ی باشگاه را برنگرداند.' : lba_call_error($r);
        $db->prepare("UPDATE ellsms_lba_clubs SET status = 'failed', error = ? WHERE id = ?")->execute([mb_strimwidth($error, 0, 480, '…'), $id]);
        return ['ok' => false, 'error' => $error];
    }
    $db->prepare('UPDATE ellsms_lba_clubs SET provider_club_id = ?, operators_json = ? WHERE id = ?')
       ->execute([$providerId, json_encode(lba_field($r['data'], 'operatorsResult'), JSON_UNESCAPED_UNICODE), $id]);
    audit((int)$user['id'], 'lba.club.created', "#{$id} provider={$providerId}");
    return ['ok' => true, 'id' => $id];
}

/**
 * AddNumbersToSmartSmsClub, in batches of 100 (the provider's limit, statusCode 60).
 *
 * @return array{ok:bool, added?:int, numbers?:int, invalid?:int, error?:string, per_operator?:array<int,int>}
 */
function lba_club_add_numbers(array $user, int $id, string $raw): array {
    $c = lba_find('club', $id, $user);
    if ($c === null || $c['status'] !== 'active' || $c['provider_club_id'] === null) return ['ok' => false, 'error' => 'باشگاه پیدا نشد.'];
    if (!impersonation_action_allowed('send.bulk')) return ['ok' => false, 'error' => impersonation_block_message('send.bulk')];
    $g = lba_gateway_get((int)$c['gateway_id']);
    if ($g === null) return ['ok' => false, 'error' => 'درگاه LBA پیدا نشد.'];
    $numbers = [];
    $invalid = 0;
    foreach (preg_split('/[\s,،;]+/u', from_persian_digits($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $item) {
        $n = normalize_msisdn($item);
        if ($n === null || preg_match('/^989\d{9}$/', $n) !== 1) { $invalid++; continue; }
        $numbers[$n] = true;
    }
    $numbers = array_map('strval', array_keys($numbers));
    if ($numbers === []) return ['ok' => false, 'error' => 'شماره‌ی موبایل معتبری وارد نشده است.'];
    if (count($numbers) > 50000) return ['ok' => false, 'error' => 'در هر بار حداکثر ۵۰٬۰۰۰ شماره.'];
    $added = 0;
    $perOperator = [];
    $error = null;
    foreach (array_chunk($numbers, 100) as $chunk) {
        $r = lba_pishgam_call($g, 'POST', 'AddNumbersToSmartSmsClub', [], ['clubId' => (int)$c['provider_club_id'], 'numbers' => $chunk]);
        if (!$r['ok']) { $error = lba_call_error($r); break; }
        foreach (lba_list_of($r['data']) as $item) {
            if (!is_array($item) || !lba_field($item, 'isSuccessful')) continue;
            $count = (int)lba_field($item, 'addedCount');
            $perOperator[(int)lba_field($item, 'operator')] = ($perOperator[(int)lba_field($item, 'operator')] ?? 0) + $count;
        }
        $added += count($chunk);
    }
    if ($added > 0) db()->prepare('UPDATE ellsms_lba_clubs SET added_count = added_count + ? WHERE id = ?')->execute([$added, $id]);
    audit((int)$user['id'], 'lba.club.numbers_added', "#{$id} sent={$added}" . ($error ? ' error' : ''));
    if ($error !== null) return ['ok' => false, 'added' => $added, 'numbers' => count($numbers), 'invalid' => $invalid, 'error' => $error, 'per_operator' => $perOperator];
    return ['ok' => true, 'added' => $added, 'numbers' => count($numbers), 'invalid' => $invalid, 'per_operator' => $perOperator];
}

/** @return array{ok:bool, error?:string} */
function lba_club_delete(array $user, int $id): array {
    $c = lba_find('club', $id, $user);
    if ($c === null || $c['status'] === 'deleted') return ['ok' => false, 'error' => 'باشگاه پیدا نشد.'];
    if (!impersonation_action_allowed('send.bulk')) return ['ok' => false, 'error' => impersonation_block_message('send.bulk')];
    if ($c['provider_club_id'] !== null && ($g = lba_gateway_get((int)$c['gateway_id'])) !== null) {
        $r = lba_pishgam_call($g, 'DELETE', 'DeleteSmartSmsClub', [], ['id' => (int)$c['provider_club_id']]);
        if (!$r['ok'] && $r['status_code'] !== 59) return ['ok' => false, 'error' => lba_call_error($r)];
    }
    db()->prepare("UPDATE ellsms_lba_clubs SET status = 'deleted' WHERE id = ?")->execute([$id]);
    audit((int)$user['id'], 'lba.club.deleted', '#' . $id);
    return ['ok' => true];
}

/* ---------------------------------------------------------------- audiences (requests) */

/**
 * Validates a request form and builds the provider payload for its kind. Ids of gray lists and clubs
 * are local ones, translated to the provider's after an ownership and same-gateway check.
 *
 * @return array{ok:bool, payload?:array, criteria?:array, error?:string}
 */
function lba_request_payload(string $kind, array $in, array $user, array $gateway): array {
    $bad = static fn(string $error): array => ['ok' => false, 'error' => $error];
    if (!isset(LBA_KINDS[$kind])) return $bad('نوع درخواست نامعتبر است.');
    $title = trim((string)($in['title'] ?? ''));
    if ($title === '' || mb_strlen($title) > 150) return $bad('عنوان درخواست را وارد کنید (حداکثر ۱۵۰ نویسه).');
    $operators = lba_int_list($in['operators'] ?? [], array_keys(LBA_OPERATORS));
    if ($operators === []) return $bad('دست‌کم یک اپراتور انتخاب کنید.');
    $p = ['operators' => $operators];

    if ($kind !== 'periodic') {
        $start = lba_parse_jalali((string)($in['start'] ?? ''));
        $end = lba_parse_jalali((string)($in['end'] ?? ''));
        if ($start === null || $end === null) return $bad('تاریخ و ساعت شروع و پایان را به شکل ۱۴۰۴/۰۸/۱۰ ۱۰:۰۰ وارد کنید.');
        if ($end[1] <= $start[1]) return $bad('زمان پایان باید بعد از زمان شروع باشد.');
        // The guide: "اختلاف ساعت شروع و پایان وارد شده حداکثر ۱۱ ساعت می‌باشد" (CreateSimpleSmartSmsRequest).
        if ($kind === 'simple' && $end[1] - $start[1] > 11 * 3600) return $bad('در ارسال ساده، فاصله‌ی شروع تا پایان حداکثر ۱۱ ساعت است.');
        $p['startDatetime'] = $start[0];
        $p['endDatetime'] = $end[0];
    }

    if (in_array($kind, LBA_POLYGON_KINDS, true)) {
        $polygons = lba_parse_polygons((string)($in['polygons'] ?? ''));
        if (is_string($polygons)) return $bad($polygons);
        $p['polygons'] = $polygons;
    }
    if ($kind === 'welcome_cells') {
        $cells = [];
        foreach (preg_split('/[\s,،]+/u', from_persian_digits((string)($in['cells'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $cell) {
            if (preg_match('/^\d{1,18}$/', $cell) !== 1) return $bad('شناسه‌ی سلول «' . $cell . '» نامعتبر است.');
            $cells[$cell] = (int)$cell;
        }
        if ($cells === []) return $bad('شناسه‌ی سلول‌های مخابراتی را وارد کنید.');
        if (count($cells) > 5000) return $bad('حداکثر ۵۰۰۰ سلول در یک درخواست.');
        $p['cellList'] = array_values($cells);
    }

    if (in_array($kind, LBA_FILTER_KINDS, true)) {
        $fromAge = lba_opt_int($in['from_age'] ?? '');
        $toAge = lba_opt_int($in['to_age'] ?? '');
        if ($fromAge === false || $toAge === false) return $bad('سن باید عدد صحیح باشد.');
        if ($fromAge !== null && $fromAge < 18) return $bad('حداقل سن مخاطب ۱۸ سال است.');
        if ($toAge !== null && ($toAge < max(18, (int)$fromAge) || $toAge > 120)) return $bad('حداکثر سن نامعتبر است.');
        if ($fromAge !== null) $p['fromAge'] = $fromAge;
        if ($toAge !== null) $p['toAge'] = $toAge;
        if (($gender = lba_int_list($in['gender'] ?? [], array_keys(LBA_GENDERS))) !== []) $p['gender'] = $gender;
        if (($types = lba_int_list($in['device_type'] ?? [])) !== []) $p['deviceType'] = $types;
        if (($brands = lba_int_list($in['device_brand'] ?? [])) !== []) $p['deviceBrand'] = $brands;
        $prefixes = lba_parse_prefixes((string)($in['prefixes'] ?? ''));
        if (is_string($prefixes)) return $bad($prefixes);
        if ($prefixes !== []) $p['numberprefixes'] = implode(',', $prefixes);
        foreach (['employment_state' => ['employmentStatus', LBA_EMPLOYMENT_STATES], 'passenger_state' => ['passengerState', LBA_PASSENGER_STATES]] as $field => [$name, $allowed]) {
            $v = lba_opt_int($in[$field] ?? '');
            if ($v === false || ($v !== null && !isset($allowed[$v]))) return $bad('مقدار فیلتر نامعتبر است.');
            if ($v !== null) $p[$name] = $v;
        }
    }
    if (in_array($kind, LBA_FILTER_KINDS, true) || in_array($kind, LBA_PLACE_KINDS, true)) {
        if (($res = lba_int_list($in['residence_provinces'] ?? [])) !== []) $p['residenceProvinces'] = $res;
        $state = lba_opt_int($in['residence_state'] ?? '');
        if ($state === false || ($state !== null && !isset(LBA_RESIDENCE_STATES[$state]))) return $bad('وضعیت سکونت نامعتبر است.');
        if ($state !== null) $p['residenceState'] = $state;
    }

    $local = [];
    if (in_array($kind, LBA_GRAYLIST_KINDS, true)) {
        $c = lba_find('campaign', (int)($in['campaign_id'] ?? 0), $user);
        if ($c === null || $c['status'] !== 'active' || $c['provider_campaign_id'] === null || (int)$c['gateway_id'] !== (int)$gateway['id']) {
            return $bad('یک لیست خاکستری از همین خط (همین درگاه) انتخاب کنید.');
        }
        $hours = lba_opt_int($in['graylist_hours'] ?? '');
        if (!is_int($hours) || $hours < 1 || $hours > 8760) return $bad('مدت ماندن شماره در لیست خاکستری را به ساعت وارد کنید (۱ تا ۸۷۶۰).');
        $p['campaignId'] = (int)$c['provider_campaign_id'];
        $p['grayListExpireDate'] = $hours;
        $local['campaign_id'] = (int)$c['id'];
    }
    if ($kind === 'club') {
        $club = lba_find('club', (int)($in['club_id'] ?? 0), $user);
        if ($club === null || $club['status'] !== 'active' || $club['provider_club_id'] === null || (int)$club['gateway_id'] !== (int)$gateway['id']) {
            return $bad('یک باشگاه از همین خط (همین درگاه) انتخاب کنید.');
        }
        $op = lba_opt_int($in['club_operation'] ?? '');
        if (!in_array($op, [0, 1], true)) return $bad('نوع عملیات باشگاه را انتخاب کنید.');
        $p['smartSmsClubId'] = (int)$club['provider_club_id'];
        $p['operationType'] = $op;
        $local['club_id'] = (int)$club['id'];
    }
    if ($kind === 'welcome_city') {
        $cities = lba_int_list($in['cities'] ?? []);
        if ($cities === []) return $bad('دست‌کم یک شهر انتخاب کنید.');
        $p['cities'] = $cities;
    }
    if ($kind === 'welcome_province') {
        $provinces = lba_int_list($in['provinces'] ?? []);
        if ($provinces === []) return $bad('دست‌کم یک استان انتخاب کنید.');
        $p['provinces'] = $provinces;
    }
    if ($kind === 'welcome_airport') {
        $airport = lba_opt_int($in['airport_id'] ?? '');
        if (!is_int($airport) || $airport <= 0) return $bad('فرودگاه را انتخاب کنید.');
        $p['targetAirportId'] = $airport;
    }
    return ['ok' => true, 'payload' => $p, 'criteria' => ['title' => $title, 'local' => $local] + $p];
}

/**
 * Creates an audience at the provider. The worker then polls it until it is built.
 *
 * @return array{ok:bool, id?:int, error?:string, warnings?:list<string>}
 */
function lba_request_create(array $user, string $originator, string $kind, array $in): array {
    $guard = lba_guard($user, $originator);
    if (!$guard['ok']) return $guard;
    $g = $guard['gateway'];
    $built = lba_request_payload($kind, $in, $user, $g);
    if (!$built['ok']) return $built;
    $originator = (string)normalize_originator($originator);
    $db = db();
    $db->prepare('INSERT INTO ellsms_lba_requests (gateway_id, organization_id, user_id, originator, kind, title, criteria_json, is_periodic) VALUES (?,?,?,?,?,?,?,?)')
       ->execute([(int)$g['id'], (int)($user['organization_id'] ?? 0) ?: null, (int)$user['id'], $originator, $kind, $built['criteria']['title'],
           json_encode($built['criteria'], JSON_UNESCAPED_UNICODE), $kind === 'periodic' ? 1 : 0]);
    $id = (int)$db->lastInsertId();
    $providerTitle = lba_provider_title($built['criteria']['title'], $id);
    $r = lba_pishgam_call($g, 'POST', LBA_KINDS[$kind][1], [], ['title' => $providerTitle] + $built['payload']);
    $providerId = $r['ok'] && is_numeric(lba_field($r['data'], 'smartSmsRequestId')) ? (int)lba_field($r['data'], 'smartSmsRequestId') : null;
    if ($providerId === null && $r['transport_error']) $providerId = lba_recover_id($g, 'GetCurrentOrganizationSmartSmsRequests', $providerTitle);
    if ($providerId === null) {
        $error = $r['ok'] ? 'پیشگام رایان شناسه‌ی درخواست را برنگرداند.' : lba_call_error($r);
        $db->prepare("UPDATE ellsms_lba_requests SET status = 'failed', error = ? WHERE id = ?")->execute([mb_strimwidth($error, 0, 990, '…'), $id]);
        return ['ok' => false, 'id' => $id, 'error' => $error];
    }
    // Per operator, the provider tells whether that operator accepted the request.
    $warnings = [];
    foreach (lba_list_of(lba_field($r['data'], 'items')) as $item) {
        if (is_array($item) && lba_field($item, 'isSuccessful') === false) {
            $warnings[] = (LBA_OPERATORS[(int)lba_field($item, 'operator')] ?? 'اپراتور ' . (int)lba_field($item, 'operator')) . ': ' . trim((string)lba_field($item, 'message'));
        }
    }
    $db->prepare('UPDATE ellsms_lba_requests SET provider_request_id = ?, provider_status = 1, error = ? WHERE id = ?')
       ->execute([$providerId, $warnings ? mb_strimwidth(implode('؛ ', $warnings), 0, 990, '…') : null, $id]);
    audit((int)$user['id'], 'lba.request.created', "#{$id} kind={$kind} provider={$providerId}");
    lba_request_refresh((int)$id);
    return ['ok' => true, 'id' => $id, 'warnings' => $warnings];
}

/**
 * RefreshSmartSmsRequest + GetSmartSmsRequestById: the audience's state, and once built, its
 * per-operator segments.
 *
 * @return array{ok:bool, error?:string}
 */
function lba_request_refresh(int $id): array {
    $st = db()->prepare('SELECT * FROM ellsms_lba_requests WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row || $row['provider_request_id'] === null || $row['status'] === 'deleted') return ['ok' => false, 'error' => 'درخواست پیدا نشد.'];
    $g = lba_gateway_get((int)$row['gateway_id']);
    if ($g === null) return ['ok' => false, 'error' => 'درگاه LBA پیدا نشد.'];
    db()->prepare('UPDATE ellsms_lba_requests SET last_refreshed_at = NOW() WHERE id = ?')->execute([$id]);
    $pid = (int)$row['provider_request_id'];
    $refresh = lba_pishgam_call($g, 'POST', 'RefreshSmartSmsRequest', [], ['id' => $pid]);
    if (!$refresh['ok'] && $refresh['status_code'] === 63) {
        db()->prepare("UPDATE ellsms_lba_requests SET status = 'failed', error = ? WHERE id = ?")->execute([lba_status_message(63), $id]);
        return ['ok' => false, 'error' => lba_status_message(63)];
    }
    $byId = lba_pishgam_call($g, 'GET', 'GetSmartSmsRequestById', ['smartSmsRequestId' => $pid]);
    if (!$byId['ok']) {
        return ['ok' => false, 'error' => lba_call_error($byId)];
    }
    $result = lba_field($byId['data'], 'result');
    $result = is_array($result) ? $result : [];
    $state = lba_field($result, 'status');
    if (!is_numeric($state)) $state = lba_field($refresh['data'], 'smartSmsRequestStatus');
    $state = is_numeric($state) ? (int)$state : (int)($row['provider_status'] ?? 1);
    $segments = [];
    $total = 0;
    foreach (lba_list_of(lba_field($result, 'recipients')) as $rec) {
        if (!is_array($rec)) continue;
        $count = (int)lba_field($rec, 'count');
        $segments[] = [
            'id' => (int)lba_field($rec, 'id'), 'operator' => (int)lba_field($rec, 'operator'), 'count' => $count,
            'persian_price' => (int)lba_field($rec, 'persianPrice'), 'english_price' => (int)lba_field($rec, 'englishPrice'),
            'coordinates' => lba_field($rec, 'coordinates'),
        ];
        $total += $count;
    }
    $expire = lba_field($result, 'expireDate');
    // 0 completed (and 5/6, a periodic request's active/inactive) can be sent; 2 failed, 3 not allowed, 4 expired.
    $local = match (true) {
        in_array($state, [0, 5, 6], true) => 'ready',
        in_array($state, [2, 3, 4], true) => 'failed',
        default => 'pending',
    };
    $error = $local === 'failed' ? 'وضعیت در پیشگام رایان: ' . (LBA_REQUEST_STATES[$state] ?? (string)$state) : $row['error'];
    db()->prepare('UPDATE ellsms_lba_requests SET provider_status = ?, recipients_json = ?, total_count = ?, status = ?, error = ? WHERE id = ?')
       ->execute([$state, json_encode(['segments' => $segments, 'expire' => is_scalar($expire) ? (string)$expire : null], JSON_UNESCAPED_UNICODE),
           $local === 'pending' && $segments === [] ? null : $total, $local, $error === null ? null : mb_strimwidth((string)$error, 0, 990, '…'), $id]);
    return ['ok' => true];
}

/** The request's segments (from its last refresh). @return list<array{id:int,operator:int,count:int,persian_price:int,english_price:int,coordinates:mixed}> */
function lba_request_segments(array $row): array {
    $data = json_decode((string)($row['recipients_json'] ?? ''), true);
    return is_array($data['segments'] ?? null) ? $data['segments'] : [];
}

/** @return list<array> the visible requests, newest first */
function lba_requests_list(array $user, int $limit = 100, int $offset = 0, ?string $originator = null): array {
    [$where, $params] = lba_scope_sql($user);
    $sql = "SELECT * FROM ellsms_lba_requests WHERE status <> 'deleted' AND {$where}";
    if ($originator !== null) { $sql .= ' AND originator = ?'; $params[] = $originator; }
    $st = db()->prepare($sql . ' ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset));
    $st->execute($params);
    return $st->fetchAll();
}

/** @return list<array> the sends of one request, newest first */
function lba_request_sends(int $requestId): array {
    $st = db()->prepare('SELECT * FROM ellsms_lba_sends WHERE request_id = ? ORDER BY id DESC');
    $st->execute([$requestId]);
    return $st->fetchAll();
}

/** DeleteSmartSmsRequest. A periodic send of the request is settled. @return array{ok:bool, error?:string} */
function lba_request_delete(array $user, int $id): array {
    $row = lba_find('request', $id, $user);
    if ($row === null || $row['status'] === 'deleted') return ['ok' => false, 'error' => 'درخواست پیدا نشد.'];
    if (!impersonation_action_allowed('send.bulk')) return ['ok' => false, 'error' => impersonation_block_message('send.bulk')];
    if ($row['provider_request_id'] !== null && ($g = lba_gateway_get((int)$row['gateway_id'])) !== null) {
        $r = lba_pishgam_call($g, 'DELETE', 'DeleteSmartSmsRequest', [], ['id' => (int)$row['provider_request_id']]);
        if (!$r['ok'] && $r['status_code'] !== 63) return ['ok' => false, 'error' => lba_call_error($r)];
    }
    db()->prepare("UPDATE ellsms_lba_requests SET status = 'deleted' WHERE id = ?")->execute([$id]);
    foreach (lba_request_sends($id) as $send) {
        if (in_array($send['kind'], ['percent', 'count'], true) && in_array($send['status'], ['active', 'inactive'], true)) {
            lba_periodic_poll($send, true);
        }
    }
    audit((int)$user['id'], 'lba.request.deleted', '#' . $id);
    return ['ok' => true];
}

/* ---------------------------------------------------------------- money */

/** The per-part tariff that applies to $body on $gateway, and the part count. @return array{parts:int, unicode:bool, price:int} */
function lba_message_price(string $body, array $gateway): array {
    $unicode = (bool)preg_match('/[^\x20-\x7E\r\n]/u', $body);
    return ['parts' => sms_parts($body), 'unicode' => $unicode, 'price' => (int)($unicode ? $gateway['price_fa_part'] : $gateway['price_en_part'])];
}

/** What $user pays (admins: nothing). */
function lba_cost_for(array $user, int $recipients, int $parts, int $pricePerPart): int {
    return ($user['role'] ?? null) === 'admin' ? 0 : max(0, $recipients) * max(0, $parts) * max(0, $pricePerPart);
}

/** Checks the message text. @return string|null an error */
function lba_body_error(string $body, int $userId): ?string {
    $body = trim($body);
    if ($body === '') return lba_status_message(24);
    if (mb_strlen($body) > 1000) return 'متن پیامک حداکثر ۱۰۰۰ نویسه می‌تواند باشد.';
    if (($ruleId = content_policy_violation($body)) !== null) {
        content_policy_log_refusal($ruleId, 'lba', $userId);
        return CONTENT_POLICY_ERROR;
    }
    return null;
}

function lba_send_fail(int $id, string $error): void {
    db()->prepare("UPDATE ellsms_lba_sends SET status = 'failed', error = ?, finished_at = NOW() WHERE id = ?")->execute([mb_strimwidth($error, 0, 990, '…'), $id]);
}

/* ---------------------------------------------------------------- one-off send */

/**
 * SendSimpleSmartSms for one operator's segments of a completed, non-periodic request.
 * $in: body, operator, send_at (Jalali, blank = now), and per segment from_<id> / count_<id>.
 *
 * @return array{ok:bool, id?:int, error?:string, cost?:int}
 */
function lba_send_simple(array $user, int $requestId, array $in): array {
    $req = lba_find('request', $requestId, $user);
    if ($req === null || $req['status'] === 'deleted') return ['ok' => false, 'error' => 'درخواست پیدا نشد.'];
    if ((int)$req['is_periodic'] === 1) return ['ok' => false, 'error' => 'درخواست دوره‌ای با «ارسال دوره‌ای» فرستاده می‌شود.'];
    $guard = lba_guard($user, (string)$req['originator']);
    if (!$guard['ok']) return $guard;
    if ((int)$guard['gateway']['id'] !== (int)$req['gateway_id']) return ['ok' => false, 'error' => 'درگاه LBA این خط پس از ساخت درخواست عوض شده است؛ درخواست را دوباره بسازید.'];
    $g = $guard['gateway'];
    // Fresh state: the audience must be completed (0) and not expired.
    lba_request_refresh($requestId);
    $req = lba_find('request', $requestId, $user) ?? $req;
    if ($req['status'] !== 'ready' || (int)$req['provider_status'] !== 0) return ['ok' => false, 'error' => 'این درخواست هنوز آماده‌ی ارسال نیست (وضعیت: ' . (LBA_REQUEST_STATES[(int)$req['provider_status']] ?? '—') . ').'];

    $body = trim((string)($in['body'] ?? ''));
    if (($error = lba_body_error($body, (int)$user['id'])) !== null) return ['ok' => false, 'error' => $error];
    $operator = (int)($in['operator'] ?? 0);
    if (!isset(LBA_OPERATORS[$operator])) return ['ok' => false, 'error' => 'اپراتور ارسال را انتخاب کنید.'];
    $recipients = [];
    $total = 0;
    foreach (lba_request_segments($req) as $seg) {
        if ((int)$seg['operator'] !== $operator) continue;
        $from = lba_opt_int($in['from_' . $seg['id']] ?? '1');
        $count = lba_opt_int($in['count_' . $seg['id']] ?? (string)$seg['count']);
        if ($from === false || $count === false) return ['ok' => false, 'error' => 'اندیس شروع و تعداد باید عدد صحیح باشد.'];
        $from = $from ?? 1;
        $count = $count ?? 0;
        if ($count === 0) continue;
        if ($from < 1 || $from + $count - 1 > (int)$seg['count']) {
            return ['ok' => false, 'error' => 'بخش ' . to_persian_digits((string)$seg['id']) . ' فقط ' . to_persian_digits(number_format((int)$seg['count'])) . ' گیرنده دارد.'];
        }
        $recipients[] = ['id' => (int)$seg['id'], 'fromIndex' => $from, 'count' => $count];
        $total += $count;
    }
    if ($recipients === []) return ['ok' => false, 'error' => 'برای این اپراتور گیرنده‌ای انتخاب نشده است.'];
    $sendAtRaw = trim((string)($in['send_at'] ?? ''));
    if ($sendAtRaw === '') {
        $sendAt = lba_jalali(time());
    } else {
        $parsed = lba_parse_jalali($sendAtRaw);
        if ($parsed === null) return ['ok' => false, 'error' => 'زمان ارسال را به شکل ۱۴۰۴/۰۸/۱۰ ۱۰:۰۰ وارد کنید.'];
        if ($parsed[1] < time() - 300) return ['ok' => false, 'error' => 'زمان ارسال گذشته است.'];
        $sendAt = $parsed[0];
    }

    $price = lba_message_price($body, $g);
    $cost = lba_cost_for($user, $total, $price['parts'], $price['price']);
    $params = ['operator' => $operator, 'recipients' => $recipients, 'sendDateTime' => $sendAt];
    $db = db();
    $db->prepare('INSERT INTO ellsms_lba_sends (request_id, gateway_id, organization_id, user_id, originator, kind, body, parts, is_unicode, price_per_part, params_json, recipient_count, reserved_credits)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
       ->execute([$requestId, (int)$g['id'], $req['organization_id'], (int)$user['id'], (string)$req['originator'], 'simple', $body, $price['parts'],
           $price['unicode'] ? 1 : 0, $price['price'], json_encode($params, JSON_UNESCAPED_UNICODE), $total, $cost]);
    $id = (int)$db->lastInsertId();
    if ($cost > 0) {
        $res = wallet_reserve((int)$user['id'], $cost, 'lba_send', (string)$id, 'reserve:lba_send:' . $id);
        if (!$res['ok']) {
            lba_send_fail($id, 'اعتبار کافی نیست.');
            return ['ok' => false, 'error' => 'اعتبار کافی نیست: این ارسال به ' . to_persian_digits(number_format($cost)) . ' ریال نیاز دارد.'];
        }
    }
    $r = lba_pishgam_call($g, 'POST', 'SendSimpleSmartSms', [], [
        'smartSmsRequestId' => (int)$req['provider_request_id'], 'smsBody' => $body, 'privateNumber' => (string)$req['originator'],
        'sendDateTime' => $sendAt, 'operator' => $operator, 'recipients' => $recipients,
    ]);
    if ($r['ok']) {
        $scheduled = lba_field($r['data'], 'scheduledMessageId');
        if ($cost > 0) wallet_commit_reservation('lba_send', (string)$id, $cost, 'commit:lba_send:' . $id, null, ['recipients' => $total, 'parts' => $price['parts']]);
        $db->prepare("UPDATE ellsms_lba_sends SET status = 'accepted', scheduled_message_id = ?, charged_credits = ?, sent_parts = ? WHERE id = ?")
           ->execute([is_numeric($scheduled) ? (int)$scheduled : null, $cost, $total * $price['parts'], $id]);
        audit((int)$user['id'], 'lba.send.simple', "#{$id} request=#{$requestId} recipients={$total} cost={$cost}");
        return ['ok' => true, 'id' => $id, 'cost' => $cost];
    }
    if ($r['transport_error']) {
        // The provider may have queued it: keep the money reserved for an admin to settle.
        $db->prepare("UPDATE ellsms_lba_sends SET status = 'unknown', error = ? WHERE id = ?")->execute([mb_strimwidth(lba_call_error($r), 0, 990, '…'), $id]);
        Logger::warning('lba.send.outcome_unknown', ['id' => $id]);
        return ['ok' => false, 'id' => $id, 'error' => 'پاسخ پیشگام رایان نرسید و معلوم نیست ارسال ثبت شده یا نه. هزینه رزرو ماند تا مدیر سامانه وضعیت را بررسی کند.'];
    }
    if ($cost > 0) wallet_release_reservation('lba_send', (string)$id);
    lba_send_fail($id, lba_call_error($r));
    return ['ok' => false, 'id' => $id, 'error' => lba_call_error($r)];
}

/** An admin settles a send whose answer was lost: as sent (charged) or not sent (refunded). */
function lba_send_resolve(array $admin, int $id, bool $sent): array {
    if (($admin['role'] ?? null) !== 'admin') return ['ok' => false, 'error' => 'فقط مدیر سامانه.'];
    $st = db()->prepare("UPDATE ellsms_lba_sends SET status = ?, finished_at = IF(? = 'failed', NOW(), finished_at) WHERE id = ? AND status = 'unknown'");
    $status = $sent ? 'accepted' : 'failed';
    $st->execute([$status, $status, $id]);
    if ($st->rowCount() !== 1) return ['ok' => false, 'error' => 'این ارسال در وضعیت نامشخص نیست.'];
    $row = db()->prepare('SELECT * FROM ellsms_lba_sends WHERE id = ?');
    $row->execute([$id]);
    $send = $row->fetch();
    if ($sent) {
        if ((int)$send['reserved_credits'] > 0) wallet_commit_reservation('lba_send', (string)$id, (int)$send['reserved_credits'], 'commit:lba_send:' . $id, (int)$admin['id']);
        db()->prepare('UPDATE ellsms_lba_sends SET charged_credits = reserved_credits, sent_parts = recipient_count * parts WHERE id = ?')->execute([$id]);
    } elseif ((int)$send['reserved_credits'] > 0) {
        wallet_release_reservation('lba_send', (string)$id);
    }
    audit((int)$admin['id'], 'lba.send.resolved', "#{$id} " . ($sent ? 'sent' : 'refunded'));
    return ['ok' => true];
}

/* ---------------------------------------------------------------- periodic send */

/**
 * The parameters of Send/Update{Percentage,Count}PeriodicSmartSms from a form.
 *
 * @return array{ok:bool, params?:array, end_ts?:int, max?:int, error?:string}
 */
function lba_periodic_params(string $mode, array $in, bool $update): array {
    $bad = static fn(string $e): array => ['ok' => false, 'error' => $e];
    $p = [];
    $startTs = null;
    if (!$update) {
        $start = lba_parse_jalali((string)($in['period_start'] ?? ''));
        if ($start === null) return $bad('تاریخ شروع کمپین را به شکل ۱۴۰۴/۰۸/۱۰ ۰۸:۰۰ وارد کنید.');
        $p['periodStart'] = $start[0];
        $startTs = $start[1];
    }
    $end = lba_parse_jalali((string)($in['period_end'] ?? ''));
    if ($end === null) return $bad('تاریخ پایان کمپین را به شکل ۱۴۰۴/۰۸/۳۰ ۲۰:۰۰ وارد کنید.');
    if ($end[1] <= ($startTs ?? time())) return $bad('پایان کمپین باید بعد از شروع آن (و در آینده) باشد.');
    $p['periodEnd'] = $end[0];
    $type = (int)($in['period_type'] ?? 0);
    if (!in_array($type, [1, 2], true)) return $bad('نوع دوره (دقیقه / ساعت) را انتخاب کنید.');
    $value = lba_opt_int($in['period_value'] ?? '');
    // The guide: periodValue is at least 10 minutes and at most 11 hours.
    $minutes = is_int($value) ? ($type === 1 ? $value : $value * 60) : 0;
    if ($minutes < 10 || $minutes > 660) return $bad('طول هر دوره باید حداقل ۱۰ دقیقه و حداکثر ۱۱ ساعت باشد.');
    $p['periodType'] = $type;
    $p['periodValue'] = $value;
    if ($mode === 'percent') {
        $percent = lba_opt_int($in['send_percent'] ?? '');
        if (!is_int($percent) || $percent < 1 || $percent > 100) return $bad('درصد ارسال در هر دوره باید بین ۱ تا ۱۰۰ باشد.');
        $p['sendPercent'] = $percent;
    } else {
        $count = lba_opt_int($in['send_count'] ?? '');
        if (!is_int($count) || $count < 1) return $bad('تعداد ارسال در هر دوره را وارد کنید.');
        $p['sendCount'] = $count;
    }
    // Optional at the provider; required here, because it bounds what is reserved from the wallet.
    $max = lba_opt_int($in['max_send_count'] ?? '');
    if (!is_int($max) || $max < 1 || $max > 50000000) return $bad('حداکثر تعداد کل ارسال در طول کمپین را وارد کنید (سقف هزینه بر اساس آن رزرو می‌شود).');
    $p['maxSendCount'] = $max;
    $daily = lba_opt_int($in['max_daily_send_count'] ?? '');
    if ($daily === false || ($daily !== null && $daily < 1)) return $bad('حداکثر ارسال روزانه نامعتبر است.');
    if ($daily !== null) $p['maxDailySendCount'] = $daily;
    foreach (['start_time_at_day' => 'startTimeAtDay', 'end_time_at_day' => 'endTimeAtDay'] as $field => $name) {
        $raw = trim((string)($in[$field] ?? ''));
        if ($raw === '') continue;
        $t = lba_parse_time($raw);
        if ($t === null) return $bad('ساعت ارسال در روز را به شکل ۱۰:۰۰ وارد کنید.');
        $p[$name] = $t;
    }
    if (isset($p['startTimeAtDay'], $p['endTimeAtDay']) && $p['endTimeAtDay'] <= $p['startTimeAtDay']) return $bad('ساعت پایان ارسال روزانه باید بعد از ساعت شروع باشد.');
    $p['continueSendIfAnyOperatorHaveError'] = !empty($in['continue_on_error']);
    return ['ok' => true, 'params' => $p, 'end_ts' => $end[1], 'max' => $max];
}

/**
 * Starts the periodic send of a periodic request (Send{Percentage,Count}PeriodicSmartSms), reserving
 * maxSendCount × parts × tariff.
 *
 * @return array{ok:bool, id?:int, error?:string, cost?:int}
 */
function lba_send_periodic(array $user, int $requestId, string $mode, array $in): array {
    if (!in_array($mode, ['percent', 'count'], true)) return ['ok' => false, 'error' => 'نوع ارسال دوره‌ای نامعتبر است.'];
    $req = lba_find('request', $requestId, $user);
    if ($req === null || $req['status'] === 'deleted') return ['ok' => false, 'error' => 'درخواست پیدا نشد.'];
    if ((int)$req['is_periodic'] !== 1) return ['ok' => false, 'error' => 'فقط درخواست دوره‌ای ارسال دوره‌ای دارد.'];
    foreach (lba_request_sends($requestId) as $s) {
        if (in_array($s['status'], ['sending', 'active', 'inactive', 'unknown'], true)) return ['ok' => false, 'error' => 'این درخواست یک ارسال دوره‌ی فعال دارد؛ آن را ویرایش کنید.'];
    }
    $guard = lba_guard($user, (string)$req['originator']);
    if (!$guard['ok']) return $guard;
    if ((int)$guard['gateway']['id'] !== (int)$req['gateway_id']) return ['ok' => false, 'error' => 'درگاه LBA این خط پس از ساخت درخواست عوض شده است؛ درخواست را دوباره بسازید.'];
    $g = $guard['gateway'];
    lba_request_refresh($requestId);
    $req = lba_find('request', $requestId, $user) ?? $req;
    if ($req['status'] !== 'ready') return ['ok' => false, 'error' => 'این درخواست هنوز آماده نیست (وضعیت: ' . (LBA_REQUEST_STATES[(int)$req['provider_status']] ?? '—') . ').'];
    $body = trim((string)($in['body'] ?? ''));
    if (($error = lba_body_error($body, (int)$user['id'])) !== null) return ['ok' => false, 'error' => $error];
    $built = lba_periodic_params($mode, $in, false);
    if (!$built['ok']) return $built;
    $criteria = json_decode((string)$req['criteria_json'], true) ?: [];
    $operators = lba_int_list($in['operators'] ?? ($criteria['operators'] ?? []), array_values((array)($criteria['operators'] ?? array_keys(LBA_OPERATORS))));
    if ($operators === []) return ['ok' => false, 'error' => 'دست‌کم یک اپراتور از اپراتورهای درخواست انتخاب کنید.'];

    $price = lba_message_price($body, $g);
    $cost = lba_cost_for($user, $built['max'], $price['parts'], $price['price']);
    $params = $built['params'] + ['operators' => $operators];
    $db = db();
    $db->prepare('INSERT INTO ellsms_lba_sends (request_id, gateway_id, organization_id, user_id, originator, kind, body, parts, is_unicode, price_per_part, params_json, recipient_count, reserved_credits, period_end_at)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
       ->execute([$requestId, (int)$g['id'], $req['organization_id'], (int)$user['id'], (string)$req['originator'], $mode, $body, $price['parts'],
           $price['unicode'] ? 1 : 0, $price['price'], json_encode($params, JSON_UNESCAPED_UNICODE), $built['max'], $cost, gmdate('Y-m-d H:i:s', $built['end_ts'])]);
    $id = (int)$db->lastInsertId();
    if ($cost > 0) {
        $res = wallet_reserve((int)$user['id'], $cost, 'lba_send', (string)$id, 'reserve:lba_send:' . $id);
        if (!$res['ok']) {
            lba_send_fail($id, 'اعتبار کافی نیست.');
            return ['ok' => false, 'error' => 'اعتبار کافی نیست: سقف این کمپین ' . to_persian_digits(number_format($cost)) . ' ریال است و باید رزرو شود.'];
        }
    }
    $method = $mode === 'percent' ? 'SendPercentagePeriodicSmartSms' : 'SendCountPeriodicSmartSms';
    $r = lba_pishgam_call($g, 'POST', $method, [], ['smartSmsRequestId' => (int)$req['provider_request_id'], 'smsBody' => $body, 'privateNumber' => (string)$req['originator']] + $params);
    if ($r['ok']) {
        $db->prepare("UPDATE ellsms_lba_sends SET status = 'active' WHERE id = ?")->execute([$id]);
        audit((int)$user['id'], 'lba.send.periodic', "#{$id} request=#{$requestId} mode={$mode} reserved={$cost}");
        lba_request_refresh($requestId);
        return ['ok' => true, 'id' => $id, 'cost' => $cost];
    }
    if ($r['transport_error']) {
        // Unknown outcome: treat it as running. The worker charges whatever the statistics report as
        // sent, and releases the rest when the period is over, so nothing is charged that was not sent.
        $db->prepare("UPDATE ellsms_lba_sends SET status = 'active', error = ? WHERE id = ?")->execute(['پاسخ ثبت کمپین نرسید؛ وضعیت از آمار پیگیری می‌شود.', $id]);
        return ['ok' => true, 'id' => $id, 'cost' => $cost];
    }
    if ($cost > 0) wallet_release_reservation('lba_send', (string)$id);
    lba_send_fail($id, lba_call_error($r));
    return ['ok' => false, 'id' => $id, 'error' => lba_call_error($r)];
}

/**
 * Update{Percentage,Count}PeriodicSmartSms: new text, end, period, amount or cap (and possibly the
 * other mode). A higher cap reserves the difference first.
 *
 * @return array{ok:bool, error?:string}
 */
function lba_send_periodic_update(array $user, int $sendId, string $mode, array $in): array {
    if (!in_array($mode, ['percent', 'count'], true)) return ['ok' => false, 'error' => 'نوع ارسال دوره‌ای نامعتبر است.'];
    $send = lba_find('send', $sendId, $user);
    if ($send === null || !in_array($send['kind'], ['percent', 'count'], true)) return ['ok' => false, 'error' => 'ارسال دوره‌ای پیدا نشد.'];
    if (!in_array($send['status'], ['active', 'inactive'], true)) return ['ok' => false, 'error' => 'شما نمی‌توانید درخواستی که گذشته است را ویرایش کنید.'];
    $req = lba_find('request', (int)$send['request_id'], $user);
    if ($req === null || $req['status'] === 'deleted') return ['ok' => false, 'error' => 'درخواست پیدا نشد.'];
    $guard = lba_guard($user, (string)$send['originator']);
    if (!$guard['ok']) return $guard;
    $g = lba_gateway_get((int)$send['gateway_id']);
    $body = trim((string)($in['body'] ?? ''));
    if (($error = lba_body_error($body, (int)$user['id'])) !== null) return ['ok' => false, 'error' => $error];
    $built = lba_periodic_params($mode, $in, true);
    if (!$built['ok']) return $built;
    // Parts and tariff of the new text: the tariff in force now (the change is a new commitment).
    $price = lba_message_price($body, $g);
    // maxSendCount bounds the whole campaign, what was already sent included.
    $needed = max((int)$send['charged_credits'], lba_cost_for($user, $built['max'], $price['parts'], $price['price']));
    $delta = $needed - (int)$send['reserved_credits'];
    if ($delta > 0) {
        $ext = wallet_extend_reservation((int)$send['user_id'], $delta, 'lba_send', (string)$sendId);
        if (!$ext['ok']) return ['ok' => false, 'error' => 'اعتبار کافی نیست: سقف تازه ' . to_persian_digits(number_format($delta)) . ' ریال رزرو بیشتر لازم دارد.'];
        db()->prepare('UPDATE ellsms_lba_sends SET reserved_credits = reserved_credits + ? WHERE id = ?')->execute([$delta, $sendId]);
    }
    $method = $mode === 'percent' ? 'UpdatePercentagePeriodicSmartSms' : 'UpdateCountPeriodicSmartSms';
    $r = lba_pishgam_call($g, 'PUT', $method, [], ['smartSmsRequestId' => (int)$req['provider_request_id'], 'smsBody' => $body, 'privateNumber' => (string)$send['originator']] + $built['params']);
    if (!$r['ok']) return ['ok' => false, 'error' => lba_call_error($r)];
    $old = json_decode((string)$send['params_json'], true) ?: [];
    $params = $built['params'] + ['periodStart' => $old['periodStart'] ?? null, 'operators' => $old['operators'] ?? []];
    db()->prepare('UPDATE ellsms_lba_sends SET kind = ?, body = ?, parts = ?, is_unicode = ?, price_per_part = ?, params_json = ?, recipient_count = ?, period_end_at = ? WHERE id = ?')
       ->execute([$mode, $body, $price['parts'], $price['unicode'] ? 1 : 0, $price['price'], json_encode($params, JSON_UNESCAPED_UNICODE), $built['max'], gmdate('Y-m-d H:i:s', $built['end_ts']), $sendId]);
    audit((int)$user['id'], 'lba.send.periodic_updated', "#{$sendId} mode={$mode}");
    return ['ok' => true];
}

/** UpdatePeriodicSmartSmsRequestActivation. @return array{ok:bool, error?:string} */
function lba_request_set_active(array $user, int $requestId, bool $active): array {
    $req = lba_find('request', $requestId, $user);
    if ($req === null || (int)$req['is_periodic'] !== 1 || $req['status'] === 'deleted') return ['ok' => false, 'error' => 'درخواست ارسال هوشمند یافت نشد.'];
    if (!impersonation_action_allowed('send.bulk')) return ['ok' => false, 'error' => impersonation_block_message('send.bulk')];
    $g = lba_gateway_get((int)$req['gateway_id']);
    if ($g === null) return ['ok' => false, 'error' => 'درگاه LBA پیدا نشد.'];
    $r = lba_pishgam_call($g, 'PUT', 'UpdatePeriodicSmartSmsRequestActivation', [], ['smartSmsRequestId' => (int)$req['provider_request_id'], 'isActive' => $active]);
    if (!$r['ok']) return ['ok' => false, 'error' => lba_call_error($r)];
    $state = lba_field($r['data'], 'smartSmsRequestStatus');
    db()->prepare('UPDATE ellsms_lba_requests SET provider_status = ? WHERE id = ?')->execute([is_numeric($state) ? (int)$state : ($active ? 5 : 6), $requestId]);
    db()->prepare("UPDATE ellsms_lba_sends SET status = ? WHERE request_id = ? AND status IN ('active','inactive')")->execute([$active ? 'active' : 'inactive', $requestId]);
    audit((int)$user['id'], 'lba.request.activation', "#{$requestId} " . ($active ? 'on' : 'off'));
    return ['ok' => true];
}

/* ---------------------------------------------------------------- statistics */

/** Rows of the statistics methods, normalized. @return list<array{sms:int, parts:int, status:int, operator:int, date:?string}> */
function lba_stats_rows(mixed $data): array {
    $out = [];
    foreach (lba_list_of($data) as $row) {
        if (!is_array($row)) continue;
        $date = lba_field($row, 'statisticsDateTime');
        $out[] = ['sms' => (int)lba_field($row, 'smsCount'), 'parts' => (int)lba_field($row, 'partsCount'),
            'status' => (int)lba_field($row, 'statisticsSendStatus'), 'operator' => (int)lba_field($row, 'mobileOperator'),
            'date' => is_scalar($date) ? (string)$date : null];
    }
    return $out;
}

/**
 * The statistics shown for a request: periodic (GetPeriodicSmartSmsStatistics), daily
 * (GetSmartSmsRequestDailyStatistics, optional Jalali day range and operator) and financial
 * (GetSmartSmsFinancialStatistics).
 *
 * @return array{ok:bool, rows?:list<array>, financial?:array, error?:string}
 */
function lba_request_statistics(array $user, int $requestId, string $type, array $in = []): array {
    $req = lba_find('request', $requestId, $user);
    if ($req === null || $req['provider_request_id'] === null) return ['ok' => false, 'error' => 'درخواست پیدا نشد.'];
    $g = lba_gateway_get((int)$req['gateway_id']);
    if ($g === null) return ['ok' => false, 'error' => 'درگاه LBA پیدا نشد.'];
    $pid = (int)$req['provider_request_id'];
    if ($type === 'financial') {
        $r = lba_pishgam_call($g, 'GET', 'GetSmartSmsFinancialStatistics', ['smartSmsRequestId' => $pid]);
        if (!$r['ok']) return ['ok' => false, 'error' => lba_call_error($r)];
        $d = lba_field($r['data'], 'data');
        return ['ok' => true, 'financial' => ['total' => (int)lba_field($d, 'totalPrice'), 'refunded' => (int)lba_field($d, 'refundedPrice'), 'pure' => (int)lba_field($d, 'purePrice')]];
    }
    if ($type === 'daily') {
        $query = ['SmartSmsRequestId' => $pid];
        foreach (['from' => 'StartDateTime', 'to' => 'EndDateTime'] as $field => $name) {
            $raw = trim((string)($in[$field] ?? ''));
            if ($raw === '') continue;
            $d = lba_parse_jalali($raw, false);
            if ($d === null) return ['ok' => false, 'error' => 'تاریخ را به شکل ۱۴۰۴/۰۸/۱۰ وارد کنید.'];
            $query[$name] = $d[0];
        }
        $op = (int)($in['operator'] ?? 0);
        if (isset(LBA_OPERATORS[$op])) $query['operator'] = $op;
        $r = lba_pishgam_call($g, 'GET', 'GetSmartSmsRequestDailyStatistics', $query);
    } else {
        $r = lba_pishgam_call($g, 'GET', 'GetPeriodicSmartSmsStatistics', ['SmartSmsRequestId' => $pid]);
    }
    if (!$r['ok']) return ['ok' => false, 'error' => lba_call_error($r)];
    return ['ok' => true, 'rows' => lba_stats_rows($r['data'])];
}

/* ---------------------------------------------------------------- worker */

/**
 * A periodic send: charge what the statistics report as sent (all statuses: every message sent is
 * charged) beyond what was already charged, capped by the reservation; once the period is over (or
 * $final) settle and release the rest.
 */
function lba_periodic_poll(array $send, bool $final = false): void {
    $id = (int)$send['id'];
    $g = lba_gateway_get((int)$send['gateway_id']);
    $req = db()->prepare('SELECT provider_request_id FROM ellsms_lba_requests WHERE id = ?');
    $req->execute([(int)$send['request_id']]);
    $pid = $req->fetchColumn();
    db()->prepare('UPDATE ellsms_lba_sends SET last_polled_at = NOW() WHERE id = ?')->execute([$id]);
    $rows = null;
    if ($g !== null && $pid !== false && $pid !== null) {
        $r = lba_pishgam_call($g, 'GET', 'GetPeriodicSmartSmsStatistics', ['SmartSmsRequestId' => (int)$pid]);
        if ($r['ok']) $rows = lba_stats_rows($r['data']);
    }
    $endTs = $send['period_end_at'] !== null ? strtotime($send['period_end_at'] . ' UTC') : null;
    $over = $final || ($endTs !== null && $endTs + LBA_PERIODIC_SETTLE_GRACE_SECONDS < time());
    if ($rows !== null) {
        $sentParts = array_sum(array_column($rows, 'parts'));
        db()->prepare('UPDATE ellsms_lba_sends SET stats_json = ?, sent_parts = GREATEST(sent_parts, ?) WHERE id = ?')
           ->execute([json_encode($rows, JSON_UNESCAPED_UNICODE), $sentParts, $id]);
        $send['sent_parts'] = max((int)$send['sent_parts'], $sentParts);
    } elseif (!$over) {
        return;
    }
    $target = min((int)$send['reserved_credits'], (int)$send['sent_parts'] * (int)$send['price_per_part']);
    if ((int)$send['reserved_credits'] === 0) $target = 0;
    $delta = $target - (int)$send['charged_credits'];
    if ($delta > 0) {
        $c = wallet_commit_reservation('lba_send', (string)$id, $delta, 'commit:lba_send:' . $id . ':' . $target, null, ['sent_parts' => (int)$send['sent_parts']]);
        if ($c['ok']) db()->prepare('UPDATE ellsms_lba_sends SET charged_credits = ? WHERE id = ? AND charged_credits < ?')->execute([$target, $id, $target]);
    }
    if ($over) {
        $done = db()->prepare("UPDATE ellsms_lba_sends SET status = 'done', finished_at = NOW() WHERE id = ? AND status IN ('active','inactive')");
        $done->execute([$id]);
        if ($done->rowCount() === 1 && (int)$send['reserved_credits'] > 0) wallet_release_reservation('lba_send', (string)$id);
        Logger::info('lba.periodic.settled', ['id' => $id, 'charged' => max($target, (int)$send['charged_credits'])]);
    }
}

/** A one-off send: its delivery statistics, followed for a few days. */
function lba_simple_poll(array $send): void {
    $id = (int)$send['id'];
    db()->prepare('UPDATE ellsms_lba_sends SET last_polled_at = NOW() WHERE id = ?')->execute([$id]);
    $g = lba_gateway_get((int)$send['gateway_id']);
    if ($g !== null && $send['scheduled_message_id'] !== null) {
        $r = lba_pishgam_call($g, 'GET', 'GetSmartOutboxMessageAggregatedStatistics', ['scheduledMessageId' => (int)$send['scheduled_message_id']]);
        if ($r['ok']) db()->prepare('UPDATE ellsms_lba_sends SET stats_json = ? WHERE id = ?')->execute([json_encode(lba_stats_rows($r['data']), JSON_UNESCAPED_UNICODE), $id]);
    }
    if (strtotime($send['created_at'] . ' UTC') + LBA_SIMPLE_FOLLOW_SECONDS < time()) {
        db()->prepare("UPDATE ellsms_lba_sends SET status = 'done', finished_at = NOW() WHERE id = ? AND status = 'accepted'")->execute([$id]);
    }
}

/**
 * One worker pass: builds pending audiences, follows sends, settles periodic ones.
 *
 * @return array{requests:int, sends:int}
 */
function lba_worker_pass(int $limit = 50): array {
    $stats = ['requests' => 0, 'sends' => 0];
    if (!lba_tables_ready()) return $stats;
    $db = db();
    $st = $db->prepare("SELECT id, created_at FROM ellsms_lba_requests WHERE status = 'pending' AND provider_request_id IS NOT NULL
                        AND (last_refreshed_at IS NULL OR last_refreshed_at <= NOW() - INTERVAL 60 SECOND) ORDER BY last_refreshed_at IS NOT NULL, last_refreshed_at LIMIT " . max(1, $limit));
    $st->execute();
    foreach ($st->fetchAll() as $row) {
        if (strtotime($row['created_at'] . ' UTC') + LBA_REQUEST_PENDING_MAX_SECONDS < time()) {
            $db->prepare("UPDATE ellsms_lba_requests SET status = 'failed', error = ? WHERE id = ? AND status = 'pending'")->execute(['ساخت مخاطبان در پیشگام رایان بیش از دو روز طول کشید.', (int)$row['id']]);
            continue;
        }
        lba_request_refresh((int)$row['id']);
        $stats['requests']++;
    }
    $st = $db->prepare("SELECT * FROM ellsms_lba_sends WHERE
                          (status IN ('active','inactive') AND (last_polled_at IS NULL OR last_polled_at <= NOW() - INTERVAL 300 SECOND))
                       OR (status = 'accepted' AND (last_polled_at IS NULL OR last_polled_at <= NOW() - INTERVAL 600 SECOND))
                        ORDER BY last_polled_at IS NOT NULL, last_polled_at LIMIT " . max(1, $limit));
    $st->execute();
    foreach ($st->fetchAll() as $send) {
        $claim = $db->prepare('UPDATE ellsms_lba_sends SET last_polled_at = NOW() WHERE id = ? AND (last_polled_at IS NULL OR last_polled_at <=> ?)');
        $claim->execute([(int)$send['id'], $send['last_polled_at']]);
        if ($claim->rowCount() !== 1) continue;
        $send['kind'] === 'simple' ? lba_simple_poll($send) : lba_periodic_poll($send);
        $stats['sends']++;
    }
    return $stats;
}

/* ---------------------------------------------------------------- display */

const LBA_DEFAULT_TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';

/** The map tile template of the polygon editor (setting lba_map_tile_url; https only). */
function lba_map_tile_url(): string {
    $url = trim((string)setting('lba_map_tile_url', LBA_DEFAULT_TILE_URL));
    return preg_match('#^https://[a-z0-9.-]+(:\d+)?/[^\s"\'<>]*\{z\}[^\s"\'<>]*$#i', $url) === 1 ? $url : LBA_DEFAULT_TILE_URL;
}

/** The tile server's origin, for the page's Content-Security-Policy. */
function lba_map_tile_origin(): string {
    $p = parse_url(lba_map_tile_url());
    return 'https://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
}

function lba_request_status_label(array $row): string {
    return match ($row['status']) {
        'pending' => 'در حال ساخت مخاطبان',
        'ready'   => (int)$row['is_periodic'] === 1 && in_array((int)$row['provider_status'], [5, 6], true)
            ? LBA_REQUEST_STATES[(int)$row['provider_status']] : 'آماده‌ی ارسال',
        'failed'  => 'ناموفق',
        'deleted' => 'حذف شده',
        default   => (string)$row['status'],
    };
}

function lba_send_status_label(string $status): string {
    return ['sending' => 'در حال ثبت', 'accepted' => 'ثبت شد', 'unknown' => 'نامشخص (در انتظار بررسی مدیر)', 'active' => 'فعال',
        'inactive' => 'غیرفعال', 'done' => 'پایان یافته', 'failed' => 'ناموفق'][$status] ?? $status;
}
