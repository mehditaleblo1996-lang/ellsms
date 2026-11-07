<?php
/**
 * ELLSMS — «پیام اول»: the AI secretary writes and sends the first message itself (phase 5,
 * docs/ai-outreach.md).
 *
 * A campaign takes the rows of one of the owner's tables that has a phone column («سفارش‌ها»,
 * «نوبت‌ها» …), optionally only those matching a search, and an instruction («به مشتری خبر بده
 * سفارشش ارسال شد و کد رهگیری را بده»). The ai-worker writes one message per number from that
 * number's row — through the line's assistant (its instructions, business information and model) —
 * and sends it from the line by SMS, Bale, or Bale then SMS. A reply from the customer continues as
 * an ordinary secretary conversation, and the AI sees its own first message in the memory.
 *
 * Guards: one message per number per campaign, numbers that opted out of the line are skipped, only
 * mobile numbers, and the line's daily cap counts these messages too. The AI part is charged per
 * message like an answer (app/Ai/AiBilling.php); the SMS/Bale part like any send.
 *
 * Schema: db/migrations/2026_11_07_ai_phase5.sql (ellsms_ai_outreach; jobs of kind 'outreach').
 */

declare(strict_types=1);

const AI_OUTREACH_MAX_ROWS = 5000;
const AI_OUTREACH_CHANNELS = ['sms' => 'پیامک', 'bale' => 'پیام‌رسان بله', 'bale_sms' => 'بله، و پیامک برای کسی که بله ندارد'];
const AI_OUTREACH_MESSAGE_TYPES = ['transactional' => 'اطلاع‌رسانی (مثل پیگیری سفارش یا یادآوری نوبت)', 'promotional' => 'تبلیغاتی'];

function ai_outreach_available(?bool $set = null): bool {
    static $available = null;
    if ($set !== null) return $available = $set;
    if ($available === null) {
        $available = false;
        if (ai_data_available()) {
            try {
                db()->query('SELECT 1 FROM ellsms_ai_outreach LIMIT 0');
                db()->query('SELECT outreach_id, context FROM ellsms_ai_jobs LIMIT 0');
                $available = true;
            } catch (PDOException) {
                $available = false;
            }
        }
    }
    return $available;
}

/** The channels this install can use right now. */
function ai_outreach_channels(): array {
    return bale_configured() ? AI_OUTREACH_CHANNELS : ['sms' => AI_OUTREACH_CHANNELS['sms']];
}

/**
 * Pure: the chat messages that write one first message — the assistant's own system prompt (no
 * tables, no hand-off: nobody wrote yet) and a request with the customer's row.
 */
function ai_outreach_messages(array $assistant, array $dataset, array $rowData, string $instruction): array {
    $record = ai_data_format_results([['dataset' => $dataset, 'data' => $rowData, 'score' => 1]]);
    return [
        ['role' => 'system', 'content' => ai_secretary_system_prompt($assistant)],
        ['role' => 'user', 'content' => "Write the FIRST message the business sends to this customer — they have not written to you. "
            . "Address them by name if the record has one. Write only the message itself, ready to send.\n\n"
            . "What the message should do:\n" . trim($instruction) . "\n\nThe customer's record:\n" . $record],
    ];
}

/**
 * The rows a campaign would message: rows of $dataset matching $query ('' = all), one per phone,
 * at most AI_OUTREACH_MAX_ROWS + 1 (so the caller can tell «too many»).
 * @return list<array{id:int, phone:string, data:array}>
 */
function ai_outreach_rows(array $dataset, string $query): array {
    $where = 'dataset_id = ? AND phone IS NOT NULL';
    $params = [$dataset['id']];
    foreach (ai_data_query_terms($query, 5) as $term) {
        $where .= ' AND search_text LIKE ?';
        $params[] = '%' . $term . '%';
    }
    $st = db()->prepare("SELECT id, phone, data_json FROM ellsms_ai_dataset_rows WHERE $where ORDER BY id LIMIT " . (AI_OUTREACH_MAX_ROWS * 2));
    $st->execute($params);
    $out = [];
    $seen = [];
    foreach ($st->fetchAll() as $r) {
        $phone = (string)$r['phone'];
        if (isset($seen[$phone])) continue;
        $seen[$phone] = true;
        $out[] = ['id' => (int)$r['id'], 'phone' => $phone, 'data' => json_decode((string)$r['data_json'], true) ?: []];
        if (count($out) > AI_OUTREACH_MAX_ROWS) break;
    }
    return $out;
}

/** Validates a campaign's input; returns [assistant, dataset, rows]. Throws AppException. */
function ai_outreach_validate(array $assistant, int $datasetId, string $query, string $instruction, string $channel): array {
    if (!(int)$assistant['enabled']) throw new AppException('ابتدا منشی هوشمند این خط را روشن کنید.');
    if (str_starts_with((string)$assistant['line'], 'tg:')) throw new AppException('«پیام اول» برای خط پیامک است، نه اکانت تلگرام.');
    $dataset = ai_dataset_find($datasetId, (int)$assistant['user_id']) ?? throw new AppException('جدول پیدا نشد.');
    if ($dataset['phone_column'] === null) throw new AppException('جدول باید «ستون موبایل» داشته باشد تا معلوم باشد پیام به چه شماره‌ای برود.');
    $instruction = trim($instruction);
    if (mb_strlen($instruction) < 5 || mb_strlen($instruction) > 2000) throw new AppException('بنویسید پیام چه کاری انجام دهد (۵ تا ۲۰۰۰ نویسه).');
    if (!array_key_exists($channel, ai_outreach_channels())) throw new AppException('روش ارسال در دسترس نیست.');
    $rows = ai_outreach_rows($dataset, $query);
    if ($rows === []) throw new AppException('هیچ ردیفی با شماره‌ی موبایل معتبر (و مطابق جست‌وجو) پیدا نشد.');
    if (count($rows) > AI_OUTREACH_MAX_ROWS) throw new AppException('حداکثر ' . number_format(AI_OUTREACH_MAX_ROWS) . ' شماره در هر ارسال؛ با جست‌وجو ردیف‌ها را محدود کنید.');
    return [$assistant, $dataset, $rows];
}

/**
 * Writes the first message for the first $n rows WITHOUT sending — what the customer reviews before
 * starting. Charged like a test for a customer ($charge), free for an admin.
 * @return array{messages: list<array{phone:string, text?:string, error?:string}>, total:int}
 */
function ai_outreach_preview(array $assistant, int $datasetId, string $query, string $instruction, string $channel, int $actorId, bool $charge, int $n = 2): array {
    [$assistant, $dataset, $rows] = ai_outreach_validate($assistant, $datasetId, $query, $instruction, $channel);
    $model = ai_secretary_resolve_model($assistant) ?? throw new AppException(ai_secretary_reason_label('no_model'));
    $out = [];
    foreach (array_slice($rows, 0, $n) as $row) {
        if ($charge && !ai_can_afford((int)$assistant['user_id'], (int)$model['id'])) {
            $out[] = ['phone' => $row['phone'], 'error' => ai_secretary_reason_label('insufficient_credit')];
            break;
        }
        try {
            $text = ai_outreach_write($assistant, $model, $dataset, $row['data'], $instruction);
            if ($charge) {
                ai_charge('test:' . bin2hex(random_bytes(8)), (int)$assistant['user_id'], $model, $text['usage']);
            }
            $out[] = ['phone' => $row['phone'], 'text' => $text['text']];
        } catch (AiProviderException $e) {
            $out[] = ['phone' => $row['phone'], 'error' => $e->getMessage()];
        }
    }
    return ['messages' => $out, 'total' => count($rows)];
}

/**
 * One first message from one row.
 * @return array{text:string, usage:array}
 * @throws AiProviderException
 */
function ai_outreach_write(array $assistant, array $model, array $dataset, array $rowData, string $instruction): array {
    $provider = ai_provider_find((int)$model['provider_id']) ?? throw new AiProviderException('not_found');
    $messages = ai_outreach_messages($assistant, $dataset, $rowData, $instruction);
    $parts = max(1, (int)$assistant['max_parts']);
    $reply = ai_secretary_chat(ai_provider_config($provider), $model, $messages, ai_model_token_cap($model, ai_secretary_max_tokens($parts)));
    [$text] = ai_secretary_parse_handoff($reply['text']);
    $promptChars = array_sum(array_map(static fn(array $m): int => mb_strlen($m['content']), $messages));
    $billable = ai_billable_tokens($reply['input_tokens'], $reply['output_tokens'], $promptChars, mb_strlen($reply['text']));
    if (trim($text) === '') throw new AiProviderException('empty_response');
    return ['text' => ai_secretary_fit_parts($text, $parts), 'usage' => [
        'input_tokens' => $billable['input'], 'output_tokens' => $billable['output'], 'estimated' => $billable['estimated'],
        'cost' => ai_call_cost($reply['cost'] ?? null, $billable['input'], $billable['output'], $model),
        'latency_ms' => $reply['latency_ms'],
    ]];
}

/** Creates a campaign and its jobs; returns its id. */
function ai_outreach_create(array $assistant, int $datasetId, string $title, string $query, string $instruction, string $channel, string $messageType, int $actorId): int {
    [$assistant, $dataset, $rows] = ai_outreach_validate($assistant, $datasetId, $query, $instruction, $channel);
    $messageType = array_key_exists($messageType, AI_OUTREACH_MESSAGE_TYPES) ? $messageType : 'transactional';
    return db_transaction(function (PDO $db) use ($assistant, $dataset, $rows, $title, $query, $instruction, $channel, $messageType, $actorId): int {
        $db->prepare('INSERT INTO ellsms_ai_outreach (assistant_id, user_id, dataset_id, title, query, instruction, channel, status, total, created_by)
                      VALUES (?,?,?,?,?,?,?,?,?,?)')
           ->execute([(int)$assistant['id'], (int)$assistant['user_id'], $dataset['id'], mb_substr(trim($title), 0, 120), mb_substr(trim($query), 0, 300),
                      trim($instruction), $channel, 'queued', count($rows), $actorId]);
        $id = (int)$db->lastInsertId();
        $ins = $db->prepare("INSERT IGNORE INTO ellsms_ai_jobs (inbound_key, kind, outreach_id, assistant_id, user_id, line, sender, content, context, status, next_attempt_at)
                             VALUES (?, 'outreach', ?, ?, ?, ?, ?, '', ?, 'pending', NOW())");
        foreach ($rows as $row) {
            $ins->execute(['o:' . $id . ':' . $row['id'], $id, (int)$assistant['id'], (int)$assistant['user_id'], (string)$assistant['line'], $row['phone'],
                json_encode(['row' => $row['data'], 'dataset_id' => $dataset['id'], 'message_type' => $messageType], JSON_UNESCAPED_UNICODE)]);
        }
        audit($actorId, 'ai.outreach.create', json_encode(['outreach_id' => $id, 'line' => $assistant['line'], 'rows' => count($rows), 'channel' => $channel]) ?: '');
        return $id;
    });
}

function ai_outreach_cancel(int $id, int $userId, int $actorId): void {
    $st = db()->prepare("UPDATE ellsms_ai_outreach SET status = 'cancelled', finished_at = NOW() WHERE id = ? AND user_id = ? AND status IN ('queued','running')");
    $st->execute([$id, $userId]);
    if ($st->rowCount() === 1) {
        db()->prepare("UPDATE ellsms_ai_jobs SET status = 'skipped', skip_reason = 'outreach_cancelled', completed_at = NOW()
                       WHERE outreach_id = ? AND status = 'pending'")->execute([$id]);
        audit($actorId, 'ai.outreach.cancel', json_encode(['outreach_id' => $id]) ?: '');
    }
}

/** @return list<array> campaigns of these lines (null = all), newest first, with progress counts. */
function ai_outreach_list(?array $lines, int $limit = 20): array {
    if (!ai_outreach_available() || $lines === []) return [];
    $where = $lines === null ? '' : ' WHERE s.line IN (' . implode(',', array_fill(0, count($lines), '?')) . ')';
    $st = db()->prepare("SELECT o.*, s.line, d.name AS dataset_name,
                                (SELECT COUNT(*) FROM ellsms_ai_jobs j WHERE j.outreach_id = o.id AND j.status = 'sent') AS sent,
                                (SELECT COUNT(*) FROM ellsms_ai_jobs j WHERE j.outreach_id = o.id AND j.status IN ('skipped','failed')) AS not_sent,
                                (SELECT COALESCE(SUM(j.ai_charge), 0) FROM ellsms_ai_jobs j WHERE j.outreach_id = o.id) AS ai_cost
                         FROM ellsms_ai_outreach o JOIN ellsms_ai_assistants s ON s.id = o.assistant_id
                         LEFT JOIN ellsms_ai_datasets d ON d.id = o.dataset_id$where ORDER BY o.id DESC LIMIT " . max(1, $limit));
    $st->execute($lines === null ? [] : array_values($lines));
    return $st->fetchAll();
}

/** Marks campaigns with nothing left to do as done. */
function ai_outreach_settle(): void {
    if (!ai_outreach_available()) return;
    db()->exec("UPDATE ellsms_ai_outreach o SET status = 'done', finished_at = NOW()
                WHERE status IN ('queued','running')
                  AND NOT EXISTS (SELECT 1 FROM ellsms_ai_jobs j WHERE j.outreach_id = o.id AND j.status IN ('pending','processing'))");
}

/**
 * Handles one claimed outreach job (called from ai_secretary_process_job() after the owner checks).
 * $user is the owner as dispatch expects it.
 */
function ai_outreach_process_job(array $job, array $assistant, array $user): void {
    $jobId = (int)$job['id'];
    $campaign = db()->prepare('SELECT * FROM ellsms_ai_outreach WHERE id = ?');
    $campaign->execute([(int)$job['outreach_id']]);
    $campaign = $campaign->fetch();
    if (!$campaign || $campaign['status'] === 'cancelled') {
        ai_secretary_finish($jobId, 'skipped', ['skip_reason' => 'outreach_cancelled']);
        return;
    }
    db()->prepare("UPDATE ellsms_ai_outreach SET status = 'running' WHERE id = ? AND status = 'queued'")->execute([(int)$campaign['id']]);
    $phone = (string)$job['sender'];
    $context = json_decode((string)$job['context'], true) ?: [];

    $reply = $job['reply_text'] !== null ? (string)$job['reply_text'] : null;
    if ($reply === null) {
        $lineDay = db()->prepare("SELECT COUNT(*) FROM ellsms_ai_jobs WHERE line = ? AND status = 'sent' AND completed_at >= CURDATE()");
        $lineDay->execute([(string)$job['line']]);
        $skip = match (true) {
            !ai_secretary_is_mobile($phone)                                                           => 'not_mobile',
            line_optout_filter((string)$job['line'], [$phone]) !== []                                => 'opted_out',
            (int)$assistant['line_daily_limit'] > 0 && (int)$lineDay->fetchColumn() >= (int)$assistant['line_daily_limit'] => 'line_limit',
            default                                                                                    => null,
        };
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
        $dataset = ai_dataset_find((int)($context['dataset_id'] ?? 0), (int)$assistant['user_id']) ?? ['id' => 0, 'name' => '', 'description' => '', 'columns' => []];
        try {
            $written = ai_outreach_write($assistant, $model, $dataset, (array)($context['row'] ?? []), (string)$campaign['instruction']);
        } catch (AiProviderException $e) {
            if (in_array($e->errorCode, AI_SECRETARY_RETRYABLE_ERRORS, true) && (int)$job['attempt_count'] < ai_secretary_setting('ai_secretary_max_attempts')) {
                ai_secretary_finish($jobId, 'pending', ['model_id' => (int)$model['id'], 'error_code' => $e->errorCode,
                    'info' => mb_strimwidth($e->getMessage(), 0, 500, '…')], job_retry_backoff_seconds((int)$job['attempt_count']));
                return;
            }
            ai_secretary_finish($jobId, 'failed', ['model_id' => (int)$model['id'], 'error_code' => $e->errorCode, 'info' => mb_strimwidth($e->getMessage(), 0, 500, '…')]);
            return;
        }
        $reply = $written['text'];
        db()->prepare('UPDATE ellsms_ai_jobs SET model_id = ?, reply_text = ?, reply_parts = ?, input_tokens = ?, output_tokens = ?, tokens_estimated = ?,
                              cost_estimate = ?, ai_latency_ms = ? WHERE id = ?')
            ->execute([(int)$model['id'], $reply, sms_parts($reply), $written['usage']['input_tokens'], $written['usage']['output_tokens'],
                       $written['usage']['estimated'] ? 1 : 0, $written['usage']['cost'], $written['usage']['latency_ms'], $jobId]);
    }

    $sendAttempts = (int)$job['send_attempts'] + 1;
    db()->prepare('UPDATE ellsms_ai_jobs SET send_attempts = ? WHERE id = ?')->execute([$sendAttempts, $jobId]);
    $messageType = (string)($context['message_type'] ?? 'transactional');
    // «عامل ارسال پیام» = منشی هوشمند in the status report (app/SendContext.php).
    if ($campaign['channel'] === 'sms') {
        [$ok, $info, $retryable] = send_context_with(['channel' => 'ai'], static fn(): array =>
            dispatch_message_retryable($user, (string)$job['line'], [$phone], $reply, 'ai_outreach', (string)$jobId, $sendAttempts, null, $messageType));
    } else {
        $r = send_context_with(['channel' => 'ai'], static fn(): array =>
            dispatch_with_channel($user, (string)$job['line'], [$phone], $reply, (string)$campaign['channel'], 'ai_outreach', (string)$jobId, $messageType));
        [$ok, $info, $retryable] = [(bool)$r[0], (string)$r[1], (bool)$r[2]];
    }
    if ($ok) {
        $charge = ai_secretary_charge_job($jobId, (int)$assistant['user_id']);
        ai_secretary_finish($jobId, 'sent', ['info' => mb_strimwidth((string)$info, 0, 500, '…')] + ($charge !== null ? ['ai_charge' => $charge['amount']] : []));
        return;
    }
    if ($retryable && $sendAttempts < job_max_attempts()) {
        ai_secretary_finish($jobId, 'pending', ['info' => mb_strimwidth('در حال تلاش مجدد ارسال: ' . $info, 0, 500, '…')], job_retry_backoff_seconds($sendAttempts));
        return;
    }
    ai_secretary_finish($jobId, 'failed', ['error_code' => 'send_failed', 'info' => mb_strimwidth((string)$info, 0, 500, '…')]);
}
