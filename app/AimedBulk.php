<?php
/**
 * Aimed bulk — send to the subscriber bank ELLSMS holds itself (ellsms_aimed_mobiles), picked by
 * postal code, province / city, number prefix and SIM type. See docs/aimed-bulk.md.
 *
 * The bank is Vesal's TBL_MOBILE, loaded by cron/aimed-mobiles-import.php. Unlike regional bulk
 * (app/RegionalBulk.php, which asks Vesal to send), these messages go out through ELLSMS's own bulk
 * queue, sender lines, gateways and wallet:
 *
 *   1. aimed_bulk_create()      — local checks, then a request row in status 'staging'.
 *   2. aimed_bulk_stage_pass()  — the worker picks the numbers (ellsms_aimed_bulk_targets), drops the
 *                                 customer's own blacklist, prices them through the normal tariff
 *                                 plus the aimed surcharge, and writes them into a 'staged' bulk job.
 *                                 Status becomes 'priced'. Nothing is reserved yet.
 *   3. aimed_bulk_confirm()     — reserves quota and the exact staged price, promotes the bulk job to
 *                                 'pending'; the normal bulk worker sends it and settles every row at
 *                                 its frozen price.
 *
 * The bank is the platform's, not the customer's: the bulk job carries recipients_masked = 1, and
 * every customer-facing place that lists its rows shows bulk_recipient_display() instead of the
 * number (reports, message detail, exports, dashboard, delivery webhooks). Platform admins see it.
 *
 * Settings: aimed_bulk_enabled, aimed_bulk_surcharge_percent (on top of the normal price),
 * aimed_bulk_max_recipients. KYC gate `aimed_bulk` (required by default).
 */

declare(strict_types=1);

const AIMED_BULK_SIM_TYPES = ['all', 'prepaid', 'postpaid'];
const AIMED_BULK_STAGE_BATCH = 2000;
const AIMED_BULK_LEASE_SECONDS = 300;

function aimed_bulk_enabled(): bool {
    return (string)setting_fresh('aimed_bulk_enabled', '0') === '1';
}

/** Percent added to the normal price of every aimed message (0–1000). */
function aimed_bulk_surcharge_percent(): float {
    return max(0.0, min(1000.0, (float)setting_fresh('aimed_bulk_surcharge_percent', '0')));
}

/** Most numbers one request may target. */
function aimed_bulk_max_recipients(): int {
    return max(1, (int)setting_fresh('aimed_bulk_max_recipients', '500000'));
}

/** Applies the surcharge to one frozen price, rounding up so a surcharge never rounds away. */
function aimed_bulk_apply_surcharge(int $amount, float $percent): int {
    if ($amount <= 0 || $percent <= 0) return max(0, $amount);
    return (int)ceil($amount * (1 + $percent / 100));
}

/**
 * The number as a customer may see it when the row belongs to a masked job: «0912***4567».
 */
function msisdn_mask(string $mobile): string {
    $digits = preg_replace('/\D/', '', $mobile) ?? '';
    if (str_starts_with($digits, '98') && strlen($digits) === 12) $digits = '0' . substr($digits, 2);
    if (strlen($digits) < 8) return str_repeat('*', max(3, strlen($digits)));
    return substr($digits, 0, 4) . '***' . substr($digits, -4);
}

/**
 * What a viewer is shown for one recipient: the number, or its mask when the row belongs to a
 * masked job and the viewer is not a platform admin. $viewerIsAdmin = null reads the session.
 */
function bulk_recipient_display(string $mobile, bool $masked, ?bool $viewerIsAdmin = null): string {
    if (!$masked) return $mobile;
    $viewerIsAdmin ??= function_exists('is_admin') && is_admin();
    return $viewerIsAdmin ? $mobile : msisdn_mask($mobile);
}

/** Whether ellsms_bulk_jobs.recipients_masked exists (db/migrations/2026_10_17_aimed_bulk.sql). */
function bulk_jobs_have_mask_column(): bool {
    // Only a "yes" is cached: a long-lived worker started before the migration must notice it once
    // it is applied, or it would keep sending unmasked webhooks for masked jobs until restarted.
    static $has = false;
    if ($has) return true;
    try {
        $st = db()->query("SELECT COUNT(*) FROM information_schema.columns
                           WHERE table_schema = DATABASE() AND table_name = 'ellsms_bulk_jobs' AND column_name = 'recipients_masked'");
        $has = (int)$st->fetchColumn() > 0;
    } catch (PDOException) {
        return false;
    }
    return $has;
}

/** SQL for "this job's recipients are masked", over a bulk-jobs alias; '0' before the migration. */
function bulk_job_masked_sql(string $alias): string {
    return bulk_jobs_have_mask_column() ? "COALESCE({$alias}.recipients_masked, 0)" : '0';
}

/** @return list<array{code:int,name:string}> */
function aimed_bulk_provinces(): array {
    try {
        $rows = db()->query('SELECT code, name FROM ellsms_aimed_provinces ORDER BY name')->fetchAll();
    } catch (PDOException) {
        return [];
    }
    return array_map(static fn(array $r): array => ['code' => (int)$r['code'], 'name' => (string)$r['name']], $rows);
}

/** @return list<array{code:int,name:string}> */
function aimed_bulk_cities(int $provinceCode): array {
    if ($provinceCode <= 0) return [];
    try {
        $st = db()->prepare('SELECT code, name FROM ellsms_aimed_cities WHERE province_code = ? ORDER BY name');
        $st->execute([$provinceCode]);
    } catch (PDOException) {
        return [];
    }
    return array_map(static fn(array $r): array => ['code' => (int)$r['code'], 'name' => (string)$r['name']], $st->fetchAll());
}

/**
 * Validates and normalizes a targeting choice. Every filter is optional, but at least one of postal
 * code, province, city or prefix must be set: "the whole bank" is not a target.
 *
 * @return array{ok:bool, criteria?:array, error?:string}
 */
function aimed_bulk_criteria(array $in): array {
    $digits = static fn(string $k): string => preg_replace('/\D/', '', from_persian_digits((string)($in[$k] ?? ''))) ?? '';

    $postal = $digits('postal_code');
    if ($postal !== '' && (strlen($postal) < 3 || strlen($postal) > 10)) {
        return ['ok' => false, 'error' => 'کد پستی باید ۳ تا ۱۰ رقم (یا ابتدای کد پستی) باشد.'];
    }

    // The prefix is matched against the stored 98XXXXXXXXXX form: «0912», «912» and «98912» are one prefix.
    $prefix = $digits('prefix');
    if ($prefix !== '') {
        if (str_starts_with($prefix, '98')) $prefix = substr($prefix, 2);
        if (str_starts_with($prefix, '0')) $prefix = substr($prefix, 1);
        if (!str_starts_with($prefix, '9') || strlen($prefix) < 2 || strlen($prefix) > 9) {
            return ['ok' => false, 'error' => 'پیش‌شماره نامعتبر است (مثلاً ۰۹۱۲).'];
        }
        $prefix = '98' . $prefix;
    }

    $sim = (string)($in['sim'] ?? 'all');
    if (!in_array($sim, AIMED_BULK_SIM_TYPES, true)) $sim = 'all';

    $criteria = [
        'postal_code'   => $postal,
        'province_code' => max(0, (int)$digits('province_code')),
        'city_code'     => max(0, (int)$digits('city_code')),
        'prefix'        => $prefix,
        'sim'           => $sim,
    ];
    if ($postal === '' && $criteria['province_code'] === 0 && $criteria['city_code'] === 0 && $prefix === '') {
        return ['ok' => false, 'error' => 'دست‌کم یکی از کد پستی، استان، شهر یا پیش‌شماره را مشخص کنید.'];
    }
    return ['ok' => true, 'criteria' => $criteria];
}

/** @return array{0:string,1:list<string|int>} WHERE fragment over alias m, and its parameters */
function aimed_bulk_where(array $criteria): array {
    $where = ['m.blocklisted = 0'];
    $params = [];
    if (($criteria['postal_code'] ?? '') !== '') { $where[] = 'm.postal_code LIKE ?'; $params[] = $criteria['postal_code'] . '%'; }
    if ((int)($criteria['province_code'] ?? 0) > 0) { $where[] = 'm.province_code = ?'; $params[] = (int)$criteria['province_code']; }
    if ((int)($criteria['city_code'] ?? 0) > 0) { $where[] = 'm.city_code = ?'; $params[] = (int)$criteria['city_code']; }
    if (($criteria['prefix'] ?? '') !== '') { $where[] = 'm.mobile LIKE ?'; $params[] = $criteria['prefix'] . '%'; }
    if (in_array($criteria['sim'] ?? 'all', ['prepaid', 'postpaid'], true)) { $where[] = 'm.sim_type = ?'; $params[] = $criteria['sim']; }
    return [implode(' AND ', $where), $params];
}

/** Numbers in the bank matching the criteria (blocklisted ones excluded). */
function aimed_bulk_count(array $criteria): int {
    [$where, $params] = aimed_bulk_where($criteria);
    $st = db()->prepare("SELECT COUNT(*) FROM ellsms_aimed_mobiles m WHERE {$where}");
    $st->execute($params);
    return (int)$st->fetchColumn();
}

/** A one-line Persian description of a criteria set, for lists. */
function aimed_bulk_criteria_label(array $criteria): string {
    $parts = [];
    if (($criteria['postal_code'] ?? '') !== '') $parts[] = 'کد پستی ' . $criteria['postal_code'] . '…';
    if ((int)($criteria['province_code'] ?? 0) > 0) $parts[] = 'استان ' . aimed_bulk_place_name('ellsms_aimed_provinces', (int)$criteria['province_code']);
    if ((int)($criteria['city_code'] ?? 0) > 0) $parts[] = 'شهر ' . aimed_bulk_place_name('ellsms_aimed_cities', (int)$criteria['city_code']);
    if (($criteria['prefix'] ?? '') !== '') $parts[] = 'پیش‌شماره 0' . substr((string)$criteria['prefix'], 2);
    if (($criteria['sim'] ?? 'all') === 'prepaid') $parts[] = 'اعتباری';
    if (($criteria['sim'] ?? 'all') === 'postpaid') $parts[] = 'دائمی';
    return implode('، ', $parts);
}

function aimed_bulk_place_name(string $table, int $code): string {
    static $cache = [];
    $key = $table . ':' . $code;
    if (!isset($cache[$key])) {
        try {
            $st = db()->prepare("SELECT name FROM {$table} WHERE code = ?");
            $st->execute([$code]);
            $cache[$key] = (string)($st->fetchColumn() ?: $code);
        } catch (PDOException) {
            $cache[$key] = (string)$code;
        }
    }
    return $cache[$key];
}

function aimed_bulk_find(int $id, array $user): ?array {
    $st = db()->prepare('SELECT * FROM ellsms_aimed_bulk_requests WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) return null;
    if (($user['role'] ?? null) === 'admin') return $row;
    $orgId = (int)($user['organization_id'] ?? 0);
    $mine = $orgId > 0 ? (int)($row['organization_id'] ?? 0) === $orgId : (int)$row['user_id'] === (int)$user['id'];
    return $mine ? $row : null;
}

/** The caller's requests (an admin sees all), newest first, joined with their bulk job's progress. */
function aimed_bulk_list(array $user, int $limit = 100): array {
    $where = '1=1';
    $params = [];
    if (($user['role'] ?? null) !== 'admin') {
        $orgId = (int)($user['organization_id'] ?? 0);
        if ($orgId > 0) { $where = 'r.organization_id = ?'; $params[] = $orgId; }
        else { $where = 'r.user_id = ?'; $params[] = (int)$user['id']; }
    }
    $st = db()->prepare("SELECT r.*, bj.status AS job_status, bj.total_rows AS job_total_rows, bj.sent_rows AS job_sent_rows, bj.failed_rows AS job_failed_rows
                         FROM ellsms_aimed_bulk_requests r LEFT JOIN ellsms_bulk_jobs bj ON bj.id = r.bulk_job_id
                         WHERE {$where} ORDER BY r.id DESC LIMIT " . max(1, min(500, $limit)));
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * Validates the request and queues it for staging. Nothing is priced or reserved here.
 *
 * @return array{ok:bool, id?:int, error?:string}
 */
function aimed_bulk_create(array $user, array $criteria, string $originator, string $content, ?int $maxCount = null, string $title = ''): array {
    $isAdmin = ($user['role'] ?? null) === 'admin';
    if (!$isAdmin && !aimed_bulk_enabled()) return ['ok' => false, 'error' => 'ارسال با کد پستی فعال نیست.'];
    $orgId = (int)($user['organization_id'] ?? 0);
    // Read fresh (not through setting()'s per-process cache), like regional_bulk_create().
    $gateRequired = setting_fresh(kyc_gate_setting_key('aimed_bulk'), '0') === '1';
    if (!$isAdmin && !kyc_feature_allowed_for_status($gateRequired, (string)(kyc_request_get($orgId)['status'] ?? 'draft'))) {
        return ['ok' => false, 'error' => kyc_gate_denial_message($orgId, 'aimed_bulk')];
    }
    if (!impersonation_action_allowed('send.bulk')) {
        impersonation_record_block('send.bulk');
        return ['ok' => false, 'error' => impersonation_block_message('send.bulk')];
    }
    if (user_has_no_send_line($user)) return ['ok' => false, 'error' => NO_ASSIGNED_LINE_MESSAGE];
    $originator = normalize_originator($originator) ?? '';
    if ($originator === '' || !can_use_originator($user, $originator)) return ['ok' => false, 'error' => 'استفاده از این خط ارسال برای شما مجاز نیست.'];
    $content = trim($content);
    if ($content === '') return ['ok' => false, 'error' => 'متن پیام خالی است.'];
    if (($ruleId = content_policy_violation($content)) !== null) {
        content_policy_log_refusal($ruleId, 'aimed_bulk', (int)$user['id']);
        return ['ok' => false, 'error' => CONTENT_POLICY_ERROR];
    }

    $available = aimed_bulk_count($criteria);
    if ($available === 0) return ['ok' => false, 'error' => 'هیچ شماره‌ای با این مشخصات در بانک شماره نیست.'];
    $target = ($maxCount !== null && $maxCount > 0) ? min($maxCount, $available) : $available;
    $limit = aimed_bulk_max_recipients();
    if ($target > $limit) {
        return ['ok' => false, 'error' => 'این مشخصات ' . to_persian_digits(number_format($available)) . ' شماره دارد؛ هر درخواست حداکثر '
            . to_persian_digits(number_format($limit)) . ' شماره می‌تواند داشته باشد. تعداد را مشخص کنید یا منطقه را کوچک‌تر کنید.'];
    }

    $title = trim($title) !== '' ? mb_substr(trim($title), 0, 160) : bulk_default_send_title((int)$user['id'], 'ارسال با کد پستی');
    $db = db();
    $db->prepare('INSERT INTO ellsms_aimed_bulk_requests
                    (organization_id, user_id, criteria_json, originator, content, title, max_count, surcharge_percent, pricing_at, status)
                  VALUES (?,?,?,?,?,?,?,?,?,\'staging\')')
       ->execute([$orgId ?: null, (int)$user['id'], json_encode($criteria, JSON_UNESCAPED_UNICODE), $originator, $content, $title,
                  $target, $isAdmin ? 0 : aimed_bulk_surcharge_percent(), sms_pricing_now()]);
    $id = (int)$db->lastInsertId();
    audit((int)$user['id'], 'aimed_bulk.created', "#{$id} target={$target} " . aimed_bulk_criteria_label($criteria));
    return ['ok' => true, 'id' => $id];
}

/**
 * Worker pass: stages requests in status 'staging', AIMED_BULK_STAGE_BATCH numbers at a time, at most
 * $maxBatches batches per call so one huge request never starves the rest of the worker loop.
 *
 * @return array{batches:int, priced:int}
 */
function aimed_bulk_stage_pass(int $maxBatches = 10): array {
    $stats = ['batches' => 0, 'priced' => 0];
    $db = db();
    try {
        $ids = $db->query("SELECT id FROM ellsms_aimed_bulk_requests
                           WHERE status = 'staging' AND (lease_expires_at IS NULL OR lease_expires_at < NOW())
                           ORDER BY id LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException) {
        return $stats; // migration not applied yet
    }
    foreach ($ids as $id) {
        if ($stats['batches'] >= $maxBatches) break;
        $token = worker_id() . ':' . bin2hex(random_bytes(4));
        $claim = $db->prepare("UPDATE ellsms_aimed_bulk_requests SET claimed_by = ?, lease_expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND)
                               WHERE id = ? AND status = 'staging' AND (lease_expires_at IS NULL OR lease_expires_at < NOW())");
        $claim->execute([$token, AIMED_BULK_LEASE_SECONDS, (int)$id]);
        if ($claim->rowCount() !== 1) continue;
        try {
            $r = aimed_bulk_stage_request((int)$id, $token, $maxBatches - $stats['batches']);
            $stats['batches'] += $r['batches'];
            if ($r['priced']) $stats['priced']++;
        } catch (Throwable $t) {
            Logger::error('aimed_bulk.stage_failed', ['id' => (int)$id, 'exception' => $t]);
        } finally {
            $db->prepare('UPDATE ellsms_aimed_bulk_requests SET claimed_by = NULL, lease_expires_at = NULL WHERE id = ? AND claimed_by = ?')
               ->execute([(int)$id, $token]);
        }
    }
    return $stats;
}

/**
 * Stages up to $maxBatches batches of one claimed request. Each batch writes its bulk items and
 * advances the request's cursor in ONE transaction, so a crash between batches resumes exactly where
 * it stopped and never stages a number twice.
 *
 * @return array{batches:int, priced:bool}
 */
function aimed_bulk_stage_request(int $id, string $token, int $maxBatches): array {
    $db = db();
    $load = $db->prepare("SELECT * FROM ellsms_aimed_bulk_requests WHERE id = ? AND status = 'staging' AND claimed_by = ?");
    $load->execute([$id, $token]);
    $req = $load->fetch();
    if (!$req) return ['batches' => 0, 'priced' => false];

    // 1. The staged bulk job, and the snapshot of matching numbers, once.
    if ($req['bulk_job_id'] === null || !(int)$req['targets_ready']) {
        $criteria = json_decode((string)$req['criteria_json'], true) ?: [];
        [$where, $params] = aimed_bulk_where($criteria);
        $jobId = db_transaction(function (PDO $db) use ($req, $where, $params, $id): int {
            $jobId = $req['bulk_job_id'] !== null ? (int)$req['bulk_job_id'] : 0;
            if ($jobId === 0) {
                $db->prepare("INSERT INTO ellsms_bulk_jobs (user_id, organization_id, type, title, originator, status, total_rows, recipients_masked)
                              VALUES (?,?,'p2p',?,?,'staged',0,1)")
                   ->execute([(int)$req['user_id'], $req['organization_id'] !== null ? (int)$req['organization_id'] : null, (string)$req['title'], (string)$req['originator']]);
                $jobId = (int)$db->lastInsertId();
            }
            $db->prepare('DELETE FROM ellsms_aimed_bulk_targets WHERE request_id = ?')->execute([$id]);
            $ins = $db->prepare("INSERT INTO ellsms_aimed_bulk_targets (request_id, mobile)
                                 SELECT ?, m.mobile FROM ellsms_aimed_mobiles m WHERE {$where} ORDER BY m.id LIMIT " . max(1, (int)$req['max_count']));
            $ins->execute(array_merge([$id], $params));
            $db->prepare('UPDATE ellsms_aimed_bulk_requests SET bulk_job_id = ?, targets_ready = 1, matched_rows = ?, cursor_id = 0 WHERE id = ?')
               ->execute([$jobId, $ins->rowCount(), $id]);
            return $jobId;
        });
        $req['bulk_job_id'] = $jobId;
        $req['cursor_id'] = 0;
    }
    $jobId = (int)$req['bulk_job_id'];
    $cursor = (int)$req['cursor_id'];
    $surcharge = (float)$req['surcharge_percent'];
    $content = (string)$req['content'];
    $segments = sms_parts($content);
    $encoding = sms_encoding($content);

    $batches = 0;
    $read = $db->prepare('SELECT id, mobile FROM ellsms_aimed_bulk_targets WHERE request_id = ? AND id > ? ORDER BY id LIMIT ' . AIMED_BULK_STAGE_BATCH);
    while ($batches < $maxBatches) {
        $read->execute([$id, $cursor]);
        $batch = $read->fetchAll();
        if ($batch === []) {
            aimed_bulk_finish_staging($id, $jobId);
            return ['batches' => $batches, 'priced' => true];
        }
        $batches++;
        $lastId = (int)end($batch)['id'];
        $mobiles = array_map(static fn(array $r): string => (string)$r['mobile'], $batch);
        [$kept, $blocked] = filter_blacklist((int)$req['user_id'], $mobiles, (string)$req['originator']);

        $rows = [];
        $unpriced = 0;
        if ($kept !== []) {
            $priced = sms_pricing_price_messages(
                array_map(static fn(string $m): array => ['mobile' => $m, 'segments' => $segments, 'encoding' => $encoding], $kept),
                (string)$req['originator'], null, (string)$req['pricing_at'], false, (int)$req['user_id']
            );
            foreach ($kept as $index => $mobile) {
                $p = $priced['per_index'][$index] ?? null;
                if ($p === null || !($p['ok'] ?? false)) { $unpriced++; continue; }
                $rows[] = [
                    $jobId, $mobile, $content,
                    aimed_bulk_apply_surcharge((int)$p['unit_price'], $surcharge),
                    aimed_bulk_apply_surcharge((int)$p['cost'], $surcharge),
                    (string)($p['operator_code'] ?? ''), $p['route_id'] ?? null, (string)($p['group_key'] ?? ''),
                ];
            }
        }

        $ok = db_transaction(function (PDO $db) use ($id, $token, $cursor, $lastId, $rows, $blocked, $unpriced): bool {
            // The cursor moves only from the position this batch read, and only while this worker
            // still holds the request in 'staging' (a cancel in between wins).
            $adv = $db->prepare("UPDATE ellsms_aimed_bulk_requests
                                 SET cursor_id = ?, staged_rows = staged_rows + ?, blacklisted_rows = blacklisted_rows + ?, unpriced_rows = unpriced_rows + ?,
                                     lease_expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND)
                                 WHERE id = ? AND status = 'staging' AND claimed_by = ? AND cursor_id = ?");
            $adv->execute([$lastId, count($rows), $blocked, $unpriced, AIMED_BULK_LEASE_SECONDS, $id, $token, $cursor]);
            if ($adv->rowCount() !== 1) return false;
            foreach (array_chunk($rows, 500) as $chunk) {
                import_insert_bulk_items_batch($db, $chunk);
            }
            return true;
        });
        if (!$ok) return ['batches' => $batches, 'priced' => false];
        $cursor = $lastId;
    }
    return ['batches' => $batches, 'priced' => false];
}

function aimed_bulk_finish_staging(int $id, int $jobId): void {
    $db = db();
    $sum = $db->prepare('SELECT COUNT(*) AS cnt, COALESCE(SUM(price_cost_credits),0) AS cost FROM ellsms_bulk_items WHERE job_id = ?');
    $sum->execute([$jobId]);
    $totals = $sum->fetch();
    $rows = (int)$totals['cnt'];
    db_transaction(function (PDO $db) use ($id, $jobId, $rows, $totals): void {
        if ($rows === 0) {
            $done = $db->prepare("UPDATE ellsms_aimed_bulk_requests SET status = 'failed', error = ?, finished_at = NOW() WHERE id = ? AND status = 'staging'");
            $done->execute(['هیچ شماره‌ی قابل‌ارسالی باقی نماند (همه در لیست سیاه بودند یا قیمت نداشتند).', $id]);
            if ($done->rowCount() === 1) {
                $db->prepare("UPDATE ellsms_bulk_jobs SET status = 'cancelled' WHERE id = ? AND status = 'staged'")->execute([$jobId]);
            }
        } else {
            $done = $db->prepare("UPDATE ellsms_aimed_bulk_requests SET status = 'priced', total_cost = ?, staged_rows = ?, priced_at = NOW() WHERE id = ? AND status = 'staging'");
            $done->execute([(int)$totals['cost'], $rows, $id]);
            if ($done->rowCount() === 1) {
                $db->prepare('UPDATE ellsms_bulk_jobs SET total_rows = ? WHERE id = ?')->execute([$rows, $jobId]);
            }
        }
        $db->prepare('DELETE FROM ellsms_aimed_bulk_targets WHERE request_id = ?')->execute([$id]);
    });
    Logger::info('aimed_bulk.staged', ['id' => $id, 'bulk_job_id' => $jobId, 'rows' => $rows, 'cost' => (int)$totals['cost']]);
}

/**
 * Reserves quota and the staged price and hands the bulk job to the send worker.
 *
 * @return array{ok:bool, bulk_job_id?:int, error?:string}
 */
function aimed_bulk_confirm(array $user, int $id): array {
    $row = aimed_bulk_find($id, $user);
    if ($row === null) return ['ok' => false, 'error' => 'درخواست پیدا نشد.'];
    if ($row['status'] !== 'priced') return ['ok' => false, 'error' => 'این درخواست در وضعیت قابل تأیید نیست.'];
    if (!impersonation_action_allowed('send.bulk')) {
        impersonation_record_block('send.bulk');
        return ['ok' => false, 'error' => impersonation_block_message('send.bulk')];
    }
    $db = db();
    // Claim the transition first so a double click cannot reserve twice.
    $claim = $db->prepare("UPDATE ellsms_aimed_bulk_requests SET status = 'confirmed', confirmed_at = NOW() WHERE id = ? AND status = 'priced'");
    $claim->execute([$id]);
    if ($claim->rowCount() !== 1) return ['ok' => false, 'error' => 'این درخواست همین حالا تأیید شد.'];

    $jobId = (int)$row['bulk_job_id'];
    $ownerId = (int)$row['user_id'];
    $orgId = $row['organization_id'] !== null ? (int)$row['organization_id'] : null;
    $adminSt = $db->prepare('SELECT is_admin FROM ellsms_meta WHERE user_id = ?');
    $adminSt->execute([$ownerId]);
    $ownerIsAdmin = (int)$adminSt->fetchColumn() === 1; // the bulk worker settles nothing for an admin owner either
    $sum = $db->prepare('SELECT COUNT(*) AS cnt, COALESCE(SUM(price_cost_credits),0) AS cost FROM ellsms_bulk_items WHERE job_id = ?');
    $sum->execute([$jobId]);
    $totals = $sum->fetch();
    $rowCount = (int)$totals['cnt'];
    $cost = (int)$totals['cost'];

    $revert = static function () use ($db, $id, $jobId): void {
        wallet_release_reservation('bulk_job', (string)$jobId);
        usage_release_messages('bulk_job', (string)$jobId);
        $db->prepare("UPDATE ellsms_aimed_bulk_requests SET status = 'priced', confirmed_at = NULL WHERE id = ? AND status = 'confirmed'")->execute([$id]);
    };
    try {
        if ($orgId !== null && $orgId > 0) {
            $quota = usage_reserve_messages($orgId, $rowCount, 'bulk_job', (string)$jobId);
            if (!$quota['ok']) {
                $revert();
                return ['ok' => false, 'error' => 'سقف ارسال پیامک پلن سازمان شما برای این ارسال کافی نیست.'];
            }
            usage_commit_messages('bulk_job', (string)$jobId, $rowCount);
        }
        if (!$ownerIsAdmin && $cost > 0) {
            $reservation = wallet_reserve($ownerId, $cost, 'bulk_job', (string)$jobId, "reserve:bulk_job:{$jobId}");
            if (!$reservation['ok']) {
                $revert();
                return ['ok' => false, 'error' => 'اعتبار کافی نیست: این ارسال به ' . to_persian_digits(number_format($cost)) . ' ریال نیاز دارد.'];
            }
        }
        $db->prepare("UPDATE ellsms_bulk_jobs SET status = 'pending', total_rows = ? WHERE id = ? AND status = 'staged'")->execute([$rowCount, $jobId]);
    } catch (Throwable $t) {
        Logger::error('aimed_bulk.confirm_failed', ['id' => $id, 'exception' => $t]);
        $revert();
        return ['ok' => false, 'error' => 'خطا در تأیید درخواست. لطفاً دوباره تلاش کنید.'];
    }
    audit((int)$user['id'], 'aimed_bulk.confirmed', "#{$id} job={$jobId} rows={$rowCount} cost={$cost}");
    return ['ok' => true, 'bulk_job_id' => $jobId];
}

/** Only before confirmation; a confirmed request is cancelled like any other bulk job. */
function aimed_bulk_cancel(array $user, int $id): bool {
    $row = aimed_bulk_find($id, $user);
    if ($row === null) return false;
    $db = db();
    $st = $db->prepare("UPDATE ellsms_aimed_bulk_requests SET status = 'cancelled', finished_at = NOW() WHERE id = ? AND status IN ('staging','priced')");
    $st->execute([$id]);
    if ($st->rowCount() !== 1) return false;
    if ($row['bulk_job_id'] !== null) {
        $jobId = (int)$row['bulk_job_id'];
        $db->prepare("UPDATE ellsms_bulk_jobs SET status = 'cancelled' WHERE id = ? AND status = 'staged'")->execute([$jobId]);
        // Never sent and never charged: the staged rows have nothing left to report.
        $db->prepare("DELETE FROM ellsms_bulk_items WHERE job_id = ? AND status = 'pending'")->execute([$jobId]);
    }
    $db->prepare('DELETE FROM ellsms_aimed_bulk_targets WHERE request_id = ?')->execute([$id]);
    audit((int)$user['id'], 'aimed_bulk.cancelled', "#{$id}");
    return true;
}

/* ==========================================================================
   Loading the bank from Vesal's exports (cron/aimed-mobiles-import.php)
   ========================================================================== */

/**
 * The bank's size as last counted by the importer: ['total', 'sendable', 'provinces', 'cities',
 * 'counted_at'], or null before the first count. Counting tens of millions of rows takes seconds to
 * minutes, so the admin page shows this stored figure instead of counting on every view.
 */
function aimed_bulk_bank_stats(): ?array {
    $stats = json_decode((string)setting_fresh('aimed_bulk_bank_stats', ''), true);
    return is_array($stats) && isset($stats['total']) ? $stats : null;
}

/** Counts the bank and stores the result for aimed_bulk_bank_stats(). CLI only: it is slow. */
function aimed_bulk_bank_stats_refresh(): array {
    $db = db();
    $stats = [
        'total'      => (int)$db->query('SELECT COUNT(*) FROM ellsms_aimed_mobiles')->fetchColumn(),
        'sendable'   => (int)$db->query('SELECT COUNT(*) FROM ellsms_aimed_mobiles WHERE blocklisted = 0')->fetchColumn(),
        'provinces'  => (int)$db->query('SELECT COUNT(*) FROM ellsms_aimed_provinces')->fetchColumn(),
        'cities'     => (int)$db->query('SELECT COUNT(*) FROM ellsms_aimed_cities')->fetchColumn(),
        'counted_at' => date('Y-m-d H:i:s'),
    ];
    set_setting('aimed_bulk_bank_stats', (string)json_encode($stats));
    return $stats;
}

/** Header aliases, normalized to upper case without spaces: Vesal's own column names first. */
const AIMED_IMPORT_COLUMNS = [
    'mobiles' => [
        'mobile'        => ['NUMBER_', 'NUMBER', 'MOBILE', 'MSISDN'],
        'postal_code'   => ['POSTALCODE', 'POSTAL_CODE'],
        'city_code'     => ['CITYCODE', 'CITY_CODE'],
        'province_code' => ['PROVINCECODE', 'PROVINCE_CODE'],
        'sim_type'      => ['SIMTYPE', 'SIM_TYPE'],
        'gender'        => ['GENDER'],
        'blocklisted'   => ['BLOCKLISTED', 'BLACKLISTED'],
    ],
    'provinces' => [
        'source_id' => ['ID'],
        'name'      => ['NAME'],
        'code'      => ['CODE'],
    ],
    'cities' => [
        'name'          => ['NAME'],
        'code'          => ['CODE'],
        'province_id'   => ['PROVINCE_ID', 'PROVINCEID'],
        'province_code' => ['PROVINCECODE', 'PROVINCE_CODE'],
    ],
];

/**
 * Opens a CSV export and maps its header row onto the fields of $kind.
 *
 * @return array{fh:resource, map:array<string,int>, delimiter:string}
 */
function aimed_import_open(string $path, string $kind, ?string $delimiter = null): array {
    $fh = @fopen($path, 'r');
    if ($fh === false) throw new RuntimeException("cannot open {$path}");
    $first = (string)fgets($fh);
    rewind($fh);
    if (str_starts_with($first, "\xEF\xBB\xBF")) fread($fh, 3);
    $delimiter ??= substr_count($first, ';') > substr_count($first, ',') ? ';' : (str_contains($first, "\t") && !str_contains($first, ',') ? "\t" : ',');
    $header = fgetcsv($fh, null, $delimiter, '"', '');
    if (!is_array($header)) throw new RuntimeException("{$path} is empty");
    $normalized = array_map(static fn($h): string => strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', (string)$h) ?? ''), $header);
    $map = [];
    foreach (AIMED_IMPORT_COLUMNS[$kind] as $field => $aliases) {
        foreach ($aliases as $alias) {
            $i = array_search($alias, $normalized, true);
            if ($i !== false) { $map[$field] = (int)$i; break; }
        }
    }
    $required = ['mobiles' => ['mobile'], 'provinces' => ['name', 'code'], 'cities' => ['name', 'code']][$kind];
    foreach ($required as $field) {
        if (!isset($map[$field])) {
            fclose($fh);
            throw new RuntimeException("{$path}: column for '{$field}' not found (expected one of " . implode(', ', AIMED_IMPORT_COLUMNS[$kind][$field]) . ')');
        }
    }
    return ['fh' => $fh, 'map' => $map, 'delimiter' => $delimiter];
}

/** One TBL_MOBILE CSV row as an ellsms_aimed_mobiles row, or null when the number is not a mobile. */
function aimed_import_mobile_row(array $cells, array $map): ?array {
    $cell = static fn(string $f): string => isset($map[$f]) ? trim((string)($cells[$map[$f]] ?? '')) : '';
    $mobile = normalize_msisdn($cell('mobile'));
    if ($mobile === null || preg_match('/^989\d{9}$/', $mobile) !== 1) return null;

    $sim = strtoupper(str_replace([' ', '-'], '_', $cell('sim_type')));
    $simType = match ($sim) {
        'PRE_PAID', 'PREPAID', '1' => 'prepaid',
        'POST_PAID', 'POSTPAID', '0' => 'postpaid',
        default => null,
    };
    $genderRaw = strtoupper($cell('gender'));
    $gender = match ($genderRaw) { '0', 'MALE' => 0, '1', 'FEMALE' => 1, default => null };
    $blocked = in_array(strtoupper($cell('blocklisted')), ['1', 'TRUE', 'Y', 'YES', 'T'], true) ? 1 : 0;
    $postal = substr(preg_replace('/\D/', '', $cell('postal_code')) ?? '', 0, 10);
    $code = static fn(string $v): ?int => ctype_digit($v) && (int)$v > 0 ? (int)$v : null;

    return [
        $mobile,
        $postal !== '' ? $postal : null,
        $code($cell('province_code')),
        $code($cell('city_code')),
        $simType,
        $gender,
        $blocked,
    ];
}

/**
 * Upserts TBL_MOBILE rows into ellsms_aimed_mobiles, $batch rows per statement. A number already in
 * the bank is updated in place, so re-running with a newer export refreshes it.
 *
 * @return array{read:int, imported:int, invalid:int}
 */
function aimed_import_mobiles(string $path, bool $dryRun = false, int $batch = 1000, ?callable $progress = null): array {
    ['fh' => $fh, 'map' => $map, 'delimiter' => $delimiter] = aimed_import_open($path, 'mobiles');
    $stats = ['read' => 0, 'imported' => 0, 'invalid' => 0];
    $buffer = [];
    $flush = static function () use (&$buffer, &$stats, $dryRun): void {
        if ($buffer === []) return;
        if (!$dryRun) {
            $placeholders = implode(',', array_fill(0, count($buffer), '(?,?,?,?,?,?,?)'));
            db()->prepare("INSERT INTO ellsms_aimed_mobiles (mobile, postal_code, province_code, city_code, sim_type, gender, blocklisted)
                           VALUES {$placeholders}
                           ON DUPLICATE KEY UPDATE postal_code = VALUES(postal_code), province_code = VALUES(province_code),
                             city_code = VALUES(city_code), sim_type = VALUES(sim_type), gender = VALUES(gender), blocklisted = VALUES(blocklisted)")
                ->execute(array_merge([], ...$buffer));
        }
        $stats['imported'] += count($buffer);
        $buffer = [];
    };
    try {
        while (($cells = fgetcsv($fh, null, $delimiter, '"', '')) !== false) {
            if ($cells === [null]) continue; // blank line
            $stats['read']++;
            $row = aimed_import_mobile_row($cells, $map);
            if ($row === null) { $stats['invalid']++; continue; }
            // The same number twice in one statement would just be updated twice; keyed to keep the last.
            $buffer[$row[0]] = $row;
            if (count($buffer) >= max(1, $batch)) {
                $flush();
                if ($progress !== null) $progress($stats);
            }
        }
        $flush();
    } finally {
        fclose($fh);
    }
    return $stats;
}

/** @return array{read:int, imported:int, invalid:int} */
function aimed_import_provinces(string $path, bool $dryRun = false): array {
    ['fh' => $fh, 'map' => $map, 'delimiter' => $delimiter] = aimed_import_open($path, 'provinces');
    $stats = ['read' => 0, 'imported' => 0, 'invalid' => 0];
    $ins = db()->prepare('INSERT INTO ellsms_aimed_provinces (code, name, source_id) VALUES (?,?,?)
                          ON DUPLICATE KEY UPDATE name = VALUES(name), source_id = VALUES(source_id)');
    try {
        while (($cells = fgetcsv($fh, null, $delimiter, '"', '')) !== false) {
            if ($cells === [null]) continue;
            $stats['read']++;
            $code = trim((string)($cells[$map['code']] ?? ''));
            $name = trim((string)($cells[$map['name']] ?? ''));
            $sourceId = isset($map['source_id']) ? trim((string)($cells[$map['source_id']] ?? '')) : '';
            if (!ctype_digit($code) || $name === '') { $stats['invalid']++; continue; }
            if (!$dryRun) $ins->execute([(int)$code, mb_substr($name, 0, 150), ctype_digit($sourceId) ? (int)$sourceId : null]);
            $stats['imported']++;
        }
    } finally {
        fclose($fh);
    }
    return $stats;
}

/**
 * TBL_CITY points at its province by TBL_PROVINCE.ID (PROVINCE_ID), so provinces are imported first
 * and that id is resolved through ellsms_aimed_provinces.source_id. A PROVINCECODE column, when the
 * export has one instead, is used as is.
 *
 * @return array{read:int, imported:int, invalid:int}
 */
function aimed_import_cities(string $path, bool $dryRun = false): array {
    ['fh' => $fh, 'map' => $map, 'delimiter' => $delimiter] = aimed_import_open($path, 'cities');
    $stats = ['read' => 0, 'imported' => 0, 'invalid' => 0];
    $bySource = [];
    foreach (db()->query('SELECT source_id, code FROM ellsms_aimed_provinces WHERE source_id IS NOT NULL')->fetchAll() as $p) {
        $bySource[(int)$p['source_id']] = (int)$p['code'];
    }
    $ins = db()->prepare('INSERT INTO ellsms_aimed_cities (code, province_code, name) VALUES (?,?,?)
                          ON DUPLICATE KEY UPDATE province_code = VALUES(province_code), name = VALUES(name)');
    try {
        while (($cells = fgetcsv($fh, null, $delimiter, '"', '')) !== false) {
            if ($cells === [null]) continue;
            $stats['read']++;
            $code = trim((string)($cells[$map['code']] ?? ''));
            $name = trim((string)($cells[$map['name']] ?? ''));
            if (!ctype_digit($code) || $name === '') { $stats['invalid']++; continue; }
            $provinceCode = null;
            if (isset($map['province_code']) && ctype_digit(trim((string)($cells[$map['province_code']] ?? '')))) {
                $provinceCode = (int)trim((string)$cells[$map['province_code']]);
            } elseif (isset($map['province_id'])) {
                $provinceCode = $bySource[(int)trim((string)($cells[$map['province_id']] ?? ''))] ?? null;
            }
            if (!$dryRun) $ins->execute([(int)$code, $provinceCode, mb_substr($name, 0, 150)]);
            $stats['imported']++;
        }
    } finally {
        fclose($fh);
    }
    return $stats;
}
