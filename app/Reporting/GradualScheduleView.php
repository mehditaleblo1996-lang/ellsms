<?php

declare(strict_types=1);

/**
 * Build bounded, virtual schedule rows for a throttled bulk job.
 *
 * A gradual send stays a single durable bulk job; we deliberately do NOT copy
 * thousands of destinations into ellsms_schedule. Instead the schedule page
 * projects each throttle window as one row. This keeps sending semantics and
 * idempotency unchanged while making every 5k/50min tranche visible.
 *
 * @return list<array<string,mixed>>
 */
function gradual_schedule_batches(array $job, int $maxRows = 300): array
{
    $throttleCount = max(1, (int)($job['throttle_count'] ?? 0));
    $throttleMinutes = max(1, (int)($job['throttle_minutes'] ?? 0));
    $total = max(0, (int)($job['total_rows'] ?? 0));
    if ($total === 0 || empty($job['throttle_count']) || empty($job['throttle_minutes'])) {
        return [];
    }

    $sent = max(0, (int)($job['sent_rows'] ?? 0));
    $failed = max(0, (int)($job['failed_rows'] ?? 0));
    $processed = min($total, $sent + $failed);
    $batchCount = (int)ceil($total / $throttleCount);
    $maxRows = max(1, $maxRows);

    // Keep a bounded window around current progress. For the common 850k / 5k
    // case there are only 170 batches, so all of them are shown.
    if ($batchCount <= $maxRows) {
        $firstBatch = 1;
        $lastBatch = $batchCount;
    } else {
        $current = min($batchCount, max(1, (int)floor($processed / $throttleCount) + 1));
        $before = (int)floor($maxRows / 2);
        $firstBatch = max(1, $current - $before);
        $lastBatch = min($batchCount, $firstBatch + $maxRows - 1);
        $firstBatch = max(1, $lastBatch - $maxRows + 1);
    }

    $createdAt = (string)($job['created_at'] ?? '');
    $lastThrottleAt = trim((string)($job['last_throttle_at'] ?? ''));
    $completedBatches = min($batchCount, (int)floor($processed / $throttleCount));
    if ($processed >= $total) {
        $completedBatches = $batchCount;
    }

    $anchorBatch = $completedBatches > 0 ? $completedBatches : 1;
    $anchorTime = $lastThrottleAt !== '' ? strtotime($lastThrottleAt) : strtotime($createdAt);
    if ($anchorTime === false) {
        $anchorTime = time();
    }

    $rows = [];
    for ($batch = $firstBatch; $batch <= $lastBatch; $batch++) {
        $startRow = (($batch - 1) * $throttleCount) + 1;
        $endRow = min($total, $batch * $throttleCount);
        $size = $endRow - $startRow + 1;

        if ((string)($job['status'] ?? '') === 'cancelled' && $processed < $startRow) {
            $status = 'cancelled';
        } elseif ($processed >= $endRow) {
            $status = 'done';
        } elseif ((string)($job['status'] ?? '') === 'processing' && $processed >= ($startRow - 1)) {
            $status = 'processing';
        } else {
            $status = 'pending';
        }

        $delta = $batch - $anchorBatch;
        $scheduledTs = $anchorTime + ($delta * $throttleMinutes * 60);
        $rows[] = [
            'job_id' => (int)($job['id'] ?? 0),
            'user_id' => (int)($job['user_id'] ?? 0),
            'title' => (string)($job['title'] ?? 'ارسال تدریجی'),
            'originator' => (string)($job['originator'] ?? ''),
            'batch_no' => $batch,
            'batch_count' => $batchCount,
            'start_row' => $startRow,
            'end_row' => $endRow,
            'size' => $size,
            'status' => $status,
            'scheduled_at' => date('Y-m-d H:i:s', $scheduledTs),
            'throttle_minutes' => $throttleMinutes,
            'total_rows' => $total,
            'processed_rows' => $processed,
        ];
    }
    return $rows;
}

function report_bulk_type_label(array $job): string
{
    if (!empty($job['throttle_count'])) {
        return 'ارسال تدریجی';
    }
    return match ((string)($job['type'] ?? '')) {
        'smart' => 'پیامک هوشمند',
        'p2p' => 'نظیر به نظیر',
        'gradual' => 'ارسال تدریجی',
        default => 'ارسال حجیم',
    };
}
