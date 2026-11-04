<?php
/**
 * ELLSMS — the AI secretary on Telegram (phase 5, docs/ai-secretary.md §Telegram).
 *
 * A customer who connected its own Telegram account («ارسال با تلگرام», app/TelegramUser.php) can
 * switch an assistant on for that account: its «line» is 'tg:<account id>'. The tg-worker stores every
 * private text message the account receives (ellsms_tg_inbound); the ai-worker turns each into a job
 * (kind 'telegram', sender 'tgu:<Telegram user id>'), answers it like an SMS — same instructions,
 * tables, memory, hand-off and guards, minus the SMS-only ones — and queues the answer as a
 * one-recipient Telegram message on the same account, addressed to the stored peer (a sender's phone
 * is usually hidden). The Telegram message is priced and charged by the Telegram service; the AI part
 * by the AI tariff.
 *
 * Tables restricted to the sender's number work only when Telegram shares the sender's phone.
 *
 * Schema: db/migrations/2026_11_07_ai_phase5.sql (ellsms_tg_inbound, job kind 'telegram').
 */

declare(strict_types=1);

const AI_TG_LINE_PREFIX = 'tg:';
const AI_TG_SENDER_PREFIX = 'tgu:';

function ai_tg_available(?bool $set = null): bool {
    static $available = null;
    if ($set !== null) return $available = $set;
    if ($available === null) {
        $available = false;
        if (ai_outreach_available() && function_exists('tg_schema_ready') && tg_schema_ready()) {
            try {
                db()->query('SELECT 1 FROM ellsms_tg_inbound LIMIT 0');
                $available = true;
            } catch (PDOException) {
                $available = false;
            }
        }
    }
    return $available;
}

/** Pure: whether a line is a Telegram account, and which. */
function ai_tg_account_of(string $line): ?int {
    return preg_match('/^tg:(\d+)$/', $line, $m) === 1 ? (int)$m[1] : null;
}

/** The user's Telegram accounts as secretary lines: 'tg:<id>' => label. */
function ai_tg_lines_for(array $user): array {
    if (!ai_tg_available() || !tg_user_allowed($user)) return [];
    $out = [];
    foreach (tg_accounts_for_user((int)$user['id']) as $a) {
        if ($a['status'] === 'disconnected') continue;
        $out[AI_TG_LINE_PREFIX . $a['id']] = 'تلگرام ' . ($a['tg_username'] ? '@' . $a['tg_username'] : '+' . $a['phone']);
    }
    return $out;
}

/** Labels of Telegram lines, for lists that show lines of any owner (admin). */
function ai_tg_line_label(string $line): ?string {
    $accountId = ai_tg_account_of($line);
    if ($accountId === null || !ai_tg_available()) return null;
    $st = db()->prepare('SELECT phone, tg_username FROM ellsms_tg_accounts WHERE id = ?');
    $st->execute([$accountId]);
    $a = $st->fetch();
    return $a ? 'تلگرام ' . ($a['tg_username'] ? '@' . $a['tg_username'] : '+' . $a['phone']) : 'تلگرام #' . $accountId;
}

/** Turns messages the tg-worker stored into secretary jobs. Returns how many became jobs. */
function ai_tg_enqueue_pass(int $limit = 100): int {
    if (!ai_tg_available()) return 0;
    $rows = db()->query('SELECT * FROM ellsms_tg_inbound WHERE job_id IS NULL ORDER BY id LIMIT ' . max(1, $limit))->fetchAll();
    $lines = ai_secretary_active_lines();
    $made = 0;
    foreach ($rows as $r) {
        $line = AI_TG_LINE_PREFIX . (int)$r['account_id'];
        $assistantId = $lines[$line] ?? null;
        $jobId = 0; // 0 = looked at, no assistant (switched off since the worker stored it)
        if ($assistantId !== null) {
            $content = trim((string)$r['text']);
            $skip = $content === '' ? 'empty' : null;
            db()->prepare("INSERT IGNORE INTO ellsms_ai_jobs (inbound_key, kind, assistant_id, user_id, line, sender, content, context, received_at,
                                                             status, skip_reason, next_attempt_at, completed_at)
                           VALUES (?, 'telegram', ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), " . ($skip ? 'NOW()' : 'NULL') . ')')
                ->execute(['t:' . (int)$r['id'], $assistantId, (int)$r['user_id'], $line, AI_TG_SENDER_PREFIX . (int)$r['tg_peer_id'],
                    mb_substr($content, 0, 4000), json_encode(['peer_id' => (int)$r['tg_peer_id'], 'access_hash' => (int)$r['tg_access_hash'],
                        'phone' => $r['sender_phone'], 'name' => $r['sender_name']], JSON_UNESCAPED_UNICODE),
                    $r['received_at'], $skip ? 'skipped' : 'pending', $skip]);
            $id = db()->prepare('SELECT id FROM ellsms_ai_jobs WHERE inbound_key = ?');
            $id->execute(['t:' . (int)$r['id']]);
            $jobId = (int)$id->fetchColumn();
            $made++;
        }
        db()->prepare('UPDATE ellsms_tg_inbound SET job_id = ? WHERE id = ?')->execute([$jobId, (int)$r['id']]);
    }
    return $made;
}

/** The guard facts of a Telegram job: the SMS-only guards (mobile, own line, opt-out) do not apply. */
function ai_tg_guard_facts(array $job, array $assistant): array {
    $facts = ai_secretary_guard_facts($job, $assistant);
    return ['is_mobile' => true, 'own_line' => false, 'opted_out' => false] + $facts;
}

/**
 * Handles one claimed Telegram job (called from ai_secretary_process_job() after the owner checks).
 * $user: the owner as tg_campaign_create() expects it.
 */
function ai_tg_process_job(array $job, array $assistant, array $user): void {
    $jobId = (int)$job['id'];
    $accountId = ai_tg_account_of((string)$job['line']);
    $account = $accountId !== null ? tg_account_find((int)$assistant['user_id'], $accountId) : null;
    if ($account === null || !in_array($account['status'], ['active', 'limited'], true)) {
        ai_secretary_finish($jobId, 'skipped', ['skip_reason' => 'line_not_owned']);
        return;
    }
    $context = json_decode((string)$job['context'], true) ?: [];
    $reply = $job['reply_text'] !== null ? (string)$job['reply_text'] : null;
    if ($reply === null) {
        $skip = ai_secretary_guard(ai_tg_guard_facts($job, $assistant));
        if ($skip !== null) {
            ai_secretary_finish($jobId, 'skipped', ['skip_reason' => $skip]);
            return;
        }
        $model = ai_secretary_resolve_model($assistant);
        if ($model === null) {
            ai_secretary_finish($jobId, 'skipped', ['skip_reason' => 'no_model']);
            return;
        }
        if (!ai_can_afford((int)$assistant['user_id'], (int)$model['id'])) {
            ai_secretary_finish($jobId, 'skipped', ['skip_reason' => 'insufficient_credit']);
            return;
        }
        try {
            // Tables restricted to a phone only answer when Telegram shared the sender's number.
            $answer = ai_secretary_generate($assistant, $model, ai_secretary_history($job, $assistant), (string)$job['content'],
                !empty($context['phone']) ? (string)$context['phone'] : null);
        } catch (AiProviderException $e) {
            if (in_array($e->errorCode, AI_SECRETARY_RETRYABLE_ERRORS, true) && (int)$job['attempt_count'] < ai_secretary_setting('ai_secretary_max_attempts')) {
                ai_secretary_finish($jobId, 'pending', ['model_id' => (int)$model['id'], 'error_code' => $e->errorCode,
                    'info' => mb_strimwidth($e->getMessage(), 0, 500, '…')], job_retry_backoff_seconds((int)$job['attempt_count']));
                return;
            }
            ai_secretary_finish($jobId, 'failed', ['model_id' => (int)$model['id'], 'error_code' => $e->errorCode, 'info' => mb_strimwidth($e->getMessage(), 0, 500, '…')]);
            return;
        }
        $reply = $answer['text'];
        db()->prepare('UPDATE ellsms_ai_jobs SET model_id = ?, reply_text = ?, input_tokens = ?, output_tokens = ?, tokens_estimated = ?,
                              cost_estimate = ?, ai_latency_ms = ?, handoff = ?, records_used = ? WHERE id = ?')
            ->execute([(int)$model['id'], $reply, $answer['input_tokens'], $answer['output_tokens'], $answer['tokens_estimated'] ? 1 : 0,
                       $answer['cost'], $answer['latency_ms'], $answer['handoff'] ? 1 : 0, $answer['records_used'], $jobId]);
        if ($answer['handoff']) {
            ai_conversation_handoff($assistant, (string)$job['line'], (string)$job['sender'], (string)$job['content']);
        }
    }

    // The answer goes out as a one-recipient Telegram message on the same account, to the stored peer.
    $peerId = (int)($context['peer_id'] ?? 0);
    $result = tg_campaign_create($user, ['kind' => 'notify', 'title' => 'منشی هوشمند', 'account_id' => $accountId, 'content' => $reply,
        'recipients' => [AI_TG_SENDER_PREFIX . $peerId], 'source' => 'panel', 'acknowledged' => true]);
    if (!$result['ok']) {
        ai_secretary_finish($jobId, 'failed', ['error_code' => 'send_failed', 'info' => mb_strimwidth((string)($result['error'] ?? ''), 0, 500, '…')]);
        return;
    }
    db()->prepare('UPDATE ellsms_tg_messages SET tg_peer_id = ?, tg_access_hash = ? WHERE campaign_id = ?')
        ->execute([$peerId, (int)($context['access_hash'] ?? 0), (int)$result['id']]);
    $charge = ai_secretary_charge_job($jobId, (int)$assistant['user_id']);
    ai_secretary_finish($jobId, 'sent', ['info' => 'در صف ارسال تلگرام (#' . (int)$result['id'] . ')'] + ($charge !== null ? ['ai_charge' => $charge['amount']] : []));
}
