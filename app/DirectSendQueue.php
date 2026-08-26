<?php
/**
 * Async queue for interactive direct sends.
 *
 * The browser only validates and enqueues. The long-running provider call is executed by cron/worker.php,
 * so a slow gateway can never leave the confirmation modal hanging in an HTTP request.
 */
declare(strict_types=1);

function direct_send_queue_policy_allowed(array $user): array {
    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) return ['ok' => false, 'message' => 'کاربر معتبر نیست.'];

    if (!user_send_policy_ip_allowed($userId)) {
        Logger::warning('sms.send.rejected_ip_policy', ['user_id' => $userId, 'ip' => function_exists('client_ip') ? client_ip() : null]);
        return ['ok' => false, 'message' => 'ارسال پیامک از IP فعلی برای این حساب مجاز نیست.'];
    }

    $policy = user_send_policy_get($userId);
    if (!empty($policy['rate_limit_enabled'])) {
        $max = max(1, (int)$policy['rate_limit_count']);
        $window = in_array((int)$policy['rate_limit_window_seconds'], [1, 60], true)
            ? (int)$policy['rate_limit_window_seconds'] : 60;
        $bucket = rate_limit_bucket('user_send_policy', 'user', (string)$userId);
        if (!rate_limit_hit($bucket, $max, $window)) {
            Logger::warning('sms.send.rejected_user_rate_limit', ['user_id' => $userId, 'max' => $max, 'window_seconds' => $window]);
            return ['ok' => false, 'message' => 'تعداد درخواست‌های ارسال شما از حد مجاز این بازه بیشتر شده است. کمی بعد دوباره تلاش کنید.'];
        }
    }

    return ['ok' => true];
}

function direct_send_queue_dedup_key(int $userId, string $originator, array $destinations, string $content): string {
    $sorted = array_values(array_map('strval', $destinations));
    sort($sorted, SORT_STRING);
    return hash('sha256', $userId . '|' . $originator . '|' . implode(',', $sorted) . '|' . $content . '|' . (int)floor(time() / 10));
}

/** @return array{ok:bool,id?:int,duplicate?:bool,error?:string} */
function direct_send_queue_enqueue(array $user, string $originator, array $destinations, string $content): array {
    $userId = (int)($user['id'] ?? 0);
    $organizationId = isset($user['organization_id']) ? (int)$user['organization_id'] : null;
    $originator = (string)(normalize_originator($originator) ?? '');
    $destinations = array_values(array_unique(array_filter(array_map('strval', $destinations))));
    $content = trim($content);

    if ($userId <= 0) return ['ok' => false, 'error' => 'کاربر معتبر نیست.'];
    if ($originator === '') return ['ok' => false, 'error' => 'خط ارسال‌کننده معتبر نیست.'];
    if ($destinations === []) return ['ok' => false, 'error' => 'شماره مقصد معتبری وجود ندارد.'];
    if ($content === '') return ['ok' => false, 'error' => 'متن پیام خالی است.'];
    if (!can_use_originator($user, $originator)) return ['ok' => false, 'error' => 'استفاده از این خط ارسال برای شما مجاز نیست.'];

    $dedupKey = direct_send_queue_dedup_key($userId, $originator, $destinations, $content);
    $requestId = Logger::currentRequestId();

    try {
        $st = db()->prepare(
            'INSERT INTO ellsms_direct_send_queue
             (user_id,organization_id,originator,destinations_json,content,status,available_at,dedup_key,request_id)
             VALUES (?,?,?,?,?,\'queued\',UTC_TIMESTAMP(),?,?)'
        );
        $st->execute([
            $userId,
            $organizationId && $organizationId > 0 ? $organizationId : null,
            $originator,
            json_encode($destinations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $content,
            $dedupKey,
            $requestId,
        ]);
        $id = (int)db()->lastInsertId();
        Logger::info('sms.direct_send.queued', ['queue_id' => $id, 'user_id' => $userId, 'destination_count' => count($destinations)]);
        audit($userId, 'sms.direct_send.queued', 'queue=#' . $id . ' dest=' . count($destinations));
        return ['ok' => true, 'id' => $id, 'duplicate' => false];
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') {
            $st = db()->prepare('SELECT id FROM ellsms_direct_send_queue WHERE dedup_key=? LIMIT 1');
            $st->execute([$dedupKey]);
            $id = (int)($st->fetchColumn() ?: 0);
            if ($id > 0) {
                Logger::info('sms.direct_send.duplicate_enqueue_absorbed', ['queue_id' => $id, 'user_id' => $userId]);
                return ['ok' => true, 'id' => $id, 'duplicate' => true];
            }
        }
        Logger::error('sms.direct_send.enqueue_failed', ['user_id' => $userId, 'exception' => $e]);
        return ['ok' => false, 'error' => 'ثبت درخواست در صف ارسال ممکن نشد.'];
    } catch (Throwable $e) {
        Logger::error('sms.direct_send.enqueue_failed', ['user_id' => $userId, 'exception' => $e]);
        return ['ok' => false, 'error' => 'ثبت درخواست در صف ارسال ممکن نشد.'];
    }
}

function direct_send_queue_claim_one(): ?array {
    return db_transaction(function (PDO $db): ?array {
        $leaseCutoff = date('Y-m-d H:i:s', time() - job_lease_seconds());
        $st = $db->prepare(
            "SELECT * FROM ellsms_direct_send_queue
             WHERE (
               (status='queued' AND available_at<=UTC_TIMESTAMP())
               OR (status='processing' AND claimed_at IS NOT NULL AND claimed_at<?)
             )
             ORDER BY id
             LIMIT 1
             FOR UPDATE SKIP LOCKED"
        );
        $st->execute([$leaseCutoff]);
        $row = $st->fetch();
        if (!$row) return null;

        $db->prepare(
            "UPDATE ellsms_direct_send_queue
             SET status='processing', claimed_at=UTC_TIMESTAMP(), claimed_by=?, attempts=attempts+1
             WHERE id=?"
        )->execute([worker_id(), $row['id']]);
        $row['attempts'] = (int)$row['attempts'] + 1;
        $row['claimed_by'] = worker_id();
        return $row;
    });
}

function direct_send_queue_user(array $row): ?array {
    $userId = (int)$row['user_id'];
    $user = backend_find_user_by_id($userId);
    if (!$user || empty($user['active']) || !empty($user['deleted']) || empty($user['panel_access'])) return null;
    $user['role'] = !empty($user['is_admin']) ? 'admin' : 'user';
    $user['full_name'] = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
    if (!empty($row['organization_id'])) $user['organization_id'] = (int)$row['organization_id'];
    return $user;
}

function direct_send_queue_finish(int $id, bool $ok, string $info, bool $retryable, int $attempts): void {
    if ($ok) {
        db()->prepare(
            "UPDATE ellsms_direct_send_queue
             SET status='sent', completed_at=UTC_TIMESTAMP(), claimed_at=NULL, claimed_by=NULL, result_info=?
             WHERE id=?"
        )->execute([mb_substr($info, 0, 1000), $id]);
        return;
    }

    if ($retryable && $attempts < job_max_attempts()) {
        $available = date('Y-m-d H:i:s', time() + job_retry_backoff_seconds($attempts));
        db()->prepare(
            "UPDATE ellsms_direct_send_queue
             SET status='queued', available_at=?, claimed_at=NULL, claimed_by=NULL, result_info=?
             WHERE id=?"
        )->execute([$available, mb_substr($info, 0, 1000), $id]);
        return;
    }

    db()->prepare(
        "UPDATE ellsms_direct_send_queue
         SET status='failed', completed_at=UTC_TIMESTAMP(), claimed_at=NULL, claimed_by=NULL, result_info=?
         WHERE id=?"
    )->execute([mb_substr($info, 0, 1000), $id]);
}

function direct_send_queue_process_one(array $row): bool {
    $id = (int)$row['id'];
    $attempts = (int)$row['attempts'];
    try {
        $user = direct_send_queue_user($row);
        if ($user === null) {
            direct_send_queue_finish($id, false, 'حساب کاربر غیرفعال یا فاقد دسترسی پنل است.', false, $attempts);
            return false;
        }

        $destinations = json_decode((string)$row['destinations_json'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($destinations)) $destinations = [];

        [$ok, $info, $retryable] = dispatch_message(
            $user,
            (string)$row['originator'],
            array_values(array_map('strval', $destinations)),
            (string)$row['content'],
            null,
            'direct_send_queue',
            (string)$id
        );

        direct_send_queue_finish($id, (bool)$ok, (string)$info, (bool)$retryable, $attempts);
        Logger::log($ok ? 'info' : 'warning', 'sms.direct_send.worker_completed', [
            'queue_id' => $id, 'user_id' => (int)$row['user_id'], 'ok' => (bool)$ok,
            'retryable' => (bool)$retryable, 'attempts' => $attempts,
        ]);
        audit((int)$row['user_id'], $ok ? 'sms.direct_send.sent' : 'sms.direct_send.failed', 'queue=#' . $id);
        return (bool)$ok;
    } catch (Throwable $e) {
        Logger::error('sms.direct_send.worker_exception', ['queue_id' => $id, 'exception' => $e]);
        direct_send_queue_finish($id, false, 'خطای داخلی هنگام پردازش صف ارسال.', true, $attempts);
        return false;
    }
}

function run_direct_send_queue_pass(int $limit = 20): int {
    $processed = 0;
    $limit = max(1, min(100, $limit));
    for ($i = 0; $i < $limit; $i++) {
        $row = direct_send_queue_claim_one();
        if ($row === null) break;
        direct_send_queue_process_one($row);
        $processed++;
    }
    return $processed;
}
