<?php
/**
 * #45 — Customer database connector: configuration, validation and the pure decision functions
 * (docs/remote-db.md).
 *
 * Everything an admin types on /admin/remote-db ends up in a SQL statement against somebody else's
 * database, so this file is deliberately strict:
 *   - table and column names are IDENTIFIERS ONLY, checked against a closed regex and quoted per
 *     dialect by app/RemoteDb/Dialect.php — never raw SQL, never a fragment;
 *   - every value (status words, ids, text) is a bound parameter;
 *   - host / database name are checked before they reach a PDO DSN, where a ';' would otherwise
 *     smuggle in extra driver options.
 *
 * The functions here are pure (no database, no network) so they are unit-tested directly.
 */

declare(strict_types=1);

const REMOTE_DB_DRIVERS = [
    'mysql'  => ['label' => 'MySQL / MariaDB',      'port' => 3306, 'pdo' => 'pdo_mysql'],
    'pgsql'  => ['label' => 'PostgreSQL',           'port' => 5432, 'pdo' => 'pdo_pgsql'],
    'sqlsrv' => ['label' => 'Microsoft SQL Server', 'port' => 1433, 'pdo' => 'pdo_sqlsrv'],
];

const REMOTE_DB_TLS_MODES = ['disable', 'prefer', 'require', 'verify'];

/** Canonical write-back states and the Persian label the panel shows next to each value field. */
const REMOTE_DB_STATUS_KEYS = [
    'in_progress'   => 'برداشته شد / در حال ارسال',
    'sent'          => 'ارسال شد (تحویل اپراتور)',
    'delivered'     => 'تحویل شد',
    'not_delivered' => 'تحویل نشد',
    'rejected'      => 'رد شد (اپراتور/گوشی)',
    'expired'       => 'منقضی شد',
    'failed'        => 'ارسال نشد (خطا)',
    'unknown'       => 'نامشخص',
];

/** Outbound mapping fields: [label, required]. Order is the order the panel shows them in. */
const REMOTE_DB_OUTBOUND_FIELDS = [
    'table'        => ['جدول پیام‌های خروجی', true],
    'id'           => ['ستون شناسه (کلید اصلی)', true],
    'destination'  => ['ستون شماره گیرنده', true],
    'content'      => ['ستون متن پیام', true],
    'status'       => ['ستون وضعیت', true],
    'originator'   => ['ستون خط فرستنده (اختیاری)', false],
    'due_at'       => ['ستون زمان ارسال (اختیاری)', false],
    'priority'     => ['ستون اولویت (اختیاری، کمتر = زودتر)', false],
    'reference'    => ['ستون شناسه ELLSMS (اختیاری)', false],
    'provider_id'  => ['ستون شناسه اپراتور (اختیاری)', false],
    'parts'        => ['ستون تعداد پارت (اختیاری)', false],
    'error'        => ['ستون متن خطا (اختیاری)', false],
    'sent_at'      => ['ستون زمان ارسال‌شدن (اختیاری)', false],
    'delivered_at' => ['ستون زمان تحویل (اختیاری)', false],
    'updated_at'   => ['ستون زمان آخرین تغییر (اختیاری)', false],
];

const REMOTE_DB_INBOUND_FIELDS = [
    'table'       => ['جدول پیام‌های دریافتی', true],
    'originator'  => ['ستون شماره فرستنده', true],
    'destination' => ['ستون خط دریافت‌کننده', true],
    'content'     => ['ستون متن پیام', true],
    'received_at' => ['ستون زمان دریافت (اختیاری)', false],
    'external_id' => ['ستون شناسه ELLSMS (اختیاری، جلوی درج تکراری را می‌گیرد)', false],
];

const REMOTE_DB_PENDING_MODES = [
    'null'          => 'ستون وضعیت خالی (NULL) باشد',
    'value'         => 'ستون وضعیت برابر مقدار «در انتظار» باشد',
    'null_or_value' => 'خالی یا برابر مقدار «در انتظار»',
];

/** The standard layout the panel offers as a CREATE TABLE script. */
function remote_db_default_outbound_mapping(): array {
    return [
        'table' => 'sms_outbound', 'id' => 'id', 'destination' => 'destination', 'content' => 'message',
        'status' => 'status', 'originator' => 'sender', 'due_at' => 'send_at', 'priority' => 'priority',
        'reference' => 'ellsms_id', 'provider_id' => 'provider_id', 'parts' => 'parts', 'error' => 'error',
        'sent_at' => 'sent_at', 'delivered_at' => 'delivered_at', 'updated_at' => 'updated_at',
        'pending_mode' => 'null_or_value', 'pending_value' => 'PENDING',
    ];
}

function remote_db_default_status_values(): array {
    return [
        'in_progress' => 'IN_PROGRESS', 'sent' => 'SENT', 'delivered' => 'DELIVERED',
        'not_delivered' => 'NOT_DELIVERED', 'rejected' => 'REJECTED', 'expired' => 'EXPIRED',
        'failed' => 'FAILED', 'unknown' => 'UNKNOWN',
    ];
}

function remote_db_default_inbound_mapping(): array {
    return [
        'table' => 'sms_inbound', 'originator' => 'sender', 'destination' => 'line',
        'content' => 'message', 'received_at' => 'received_at', 'external_id' => 'ellsms_id',
    ];
}

/**
 * A table or column name: letters, digits, underscore, not starting with a digit, ≤ 64 chars.
 * A table may carry ONE schema prefix (dbo.sms_outbound, public.sms_outbound). Nothing else — no
 * quotes, spaces, brackets or dots beyond that — so the quoting in Dialect.php cannot be escaped.
 */
function remote_db_identifier_valid(string $name, bool $allowSchema = false): bool {
    $part = '[A-Za-z_][A-Za-z0-9_]{0,63}';
    $pattern = $allowSchema ? "/^{$part}(\\.{$part})?$/" : "/^{$part}$/";
    return preg_match($pattern, $name) === 1;
}

/** A hostname or an IP address — nothing a DSN would read as a separator. */
function remote_db_host_valid(string $host): bool {
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return true;
    }
    return strlen($host) <= 190
        && preg_match('/^(?=.{1,190}$)([A-Za-z0-9]([A-Za-z0-9\-]{0,61}[A-Za-z0-9])?)(\.[A-Za-z0-9]([A-Za-z0-9\-]{0,61}[A-Za-z0-9])?)*$/', $host) === 1;
}

function remote_db_database_name_valid(string $name): bool {
    return preg_match('/^[A-Za-z0-9_][A-Za-z0-9_\-$]{0,127}$/', $name) === 1;
}

/** A status word written to / compared with the customer's status column. Bound, but still bounded. */
function remote_db_status_value_valid(string $value): bool {
    return $value !== '' && mb_strlen($value) <= 40 && preg_match('/^[\p{L}\p{N}_\-. ]+$/u', $value) === 1;
}

/**
 * Validates the mapping part of a connection form. Returns [errors, outbound, statusValues, inbound]
 * where errors is field => Persian message and the three arrays are normalized (trimmed, empty
 * optional fields removed). An invalid configuration is never saved.
 */
function remote_db_validate_mapping(array $outboundIn, array $statusIn, array $inboundIn, bool $inboundEnabled): array {
    $errors = [];
    $outbound = [];
    foreach (REMOTE_DB_OUTBOUND_FIELDS as $field => [$label, $required]) {
        $value = trim((string)($outboundIn[$field] ?? ''));
        if ($value === '') {
            if ($required) $errors["outbound.{$field}"] = "«{$label}» لازم است.";
            continue;
        }
        if (!remote_db_identifier_valid($value, $field === 'table')) {
            $errors["outbound.{$field}"] = "«{$label}» باید فقط حروف لاتین، عدد و زیرخط باشد" . ($field === 'table' ? ' (با یک پیشوند schema اختیاری مثل dbo.).' : '.');
            continue;
        }
        $outbound[$field] = $value;
    }
    // The same column cannot play two roles: writing a status word into the destination column, say,
    // would corrupt the customer's data.
    $columns = array_diff_key($outbound, ['table' => true]);
    $dupes = array_keys(array_filter(array_count_values(array_map('strtolower', $columns)), static fn(int $n): bool => $n > 1));
    if ($dupes !== []) {
        $errors['outbound.duplicate'] = 'یک ستون برای دو کار انتخاب شده است: ' . implode('، ', $dupes);
    }

    $mode = (string)($outboundIn['pending_mode'] ?? 'null');
    if (!isset(REMOTE_DB_PENDING_MODES[$mode])) $mode = 'null';
    $outbound['pending_mode'] = $mode;
    $pendingValue = trim((string)($outboundIn['pending_value'] ?? ''));
    if ($mode !== 'null') {
        if (!remote_db_status_value_valid($pendingValue)) {
            $errors['outbound.pending_value'] = 'مقدار «در انتظار» لازم است (حداکثر ۴۰ نویسه).';
        }
        $outbound['pending_value'] = $pendingValue;
    }

    $statusValues = [];
    foreach (REMOTE_DB_STATUS_KEYS as $key => $label) {
        $value = trim((string)($statusIn[$key] ?? ''));
        if (!remote_db_status_value_valid($value)) {
            $errors["status.{$key}"] = "مقدار وضعیت «{$label}» لازم است (حداکثر ۴۰ نویسه).";
            continue;
        }
        $statusValues[$key] = $value;
    }
    // A written state must never look "pending" again, or the next poll would pick the row up as new.
    if (isset($outbound['pending_value'])) {
        foreach ($statusValues as $key => $value) {
            if ($value === $outbound['pending_value']) {
                $errors["status.{$key}"] = 'مقدار وضعیت نباید با مقدار «در انتظار» یکی باشد؛ وگرنه ردیف دوباره برداشته می‌شود.';
            }
        }
    }

    $inbound = [];
    if ($inboundEnabled) {
        foreach (REMOTE_DB_INBOUND_FIELDS as $field => [$label, $required]) {
            $value = trim((string)($inboundIn[$field] ?? ''));
            if ($value === '') {
                if ($required) $errors["inbound.{$field}"] = "«{$label}» لازم است.";
                continue;
            }
            if (!remote_db_identifier_valid($value, $field === 'table')) {
                $errors["inbound.{$field}"] = "«{$label}» باید فقط حروف لاتین، عدد و زیرخط باشد.";
                continue;
            }
            $inbound[$field] = $value;
        }
    }
    return [$errors, $outbound, $statusValues, $inbound];
}

/**
 * The state a customer row should show for one ELLSMS row, or null while it is still on its way
 * (nothing to write yet — the row keeps the in-progress value it got when it was taken).
 *
 * @return array{0: ?string, 1: bool} [status key from REMOTE_DB_STATUS_KEYS, final]
 */
function remote_db_target_state(string $rowState, ?string $itemStatus, ?string $deliveryStatus): array {
    if ($rowState === 'failed') {
        return ['failed', true];          // refused before it was ever queued (bad number, sender, …)
    }
    if ($itemStatus === null) {
        return [null, false];             // claimed, not queued yet
    }
    return match ($itemStatus) {
        'failed', 'cancelled' => ['failed', true],
        'sent' => match ($deliveryStatus) {
            'delivered' => ['delivered', true],
            'failed'    => ['not_delivered', true],
            'rejected'  => ['rejected', true],
            'expired'   => ['expired', true],
            'unknown'   => ['unknown', false],
            default     => ['sent', false],  // null / accepted / queued / sent: the delivery report may still come
        },
        default => [null, false],         // pending / processing
    };
}

/** A short, stable machine code → the Persian text written into the customer's error column. */
function remote_db_error_text(string $code): string {
    return match ($code) {
        'invalid_destination' => 'شماره گیرنده نامعتبر است',
        'empty_content'       => 'متن پیام خالی است',
        'content_too_long'    => 'متن پیام بیش از ۲۰۰۰ نویسه است',
        'originator_missing'  => 'خط فرستنده مشخص نیست',
        'originator_not_allowed' => 'این خط برای این حساب مجاز نیست',
        'content_prohibited'  => 'متن پیام شامل عبارت غیرمجاز است',
        'queue_timeout'       => 'پیام در مهلت تعیین‌شده در صف ارسال قرار نگرفت',
        'account_inactive'    => 'حساب کاربری غیرفعال است',
        default               => $code,
    };
}

/**
 * CREATE TABLE scripts for the configured (or default) layout, so a customer with no table yet can
 * be handed a ready script. Optional columns that are not mapped are left out.
 *
 * @return array{outbound: string, inbound: string}
 */
function remote_db_ddl(string $driver, array $outbound, array $inbound): array {
    $q = static fn(string $name): string => remote_db_quote_identifier($driver, $name);
    $types = match ($driver) {
        'pgsql'  => ['pk' => 'BIGSERIAL PRIMARY KEY', 'text' => 'TEXT', 'str' => 'VARCHAR', 'ts' => 'TIMESTAMP', 'now' => 'CURRENT_TIMESTAMP', 'int' => 'INTEGER', 'big' => 'BIGINT'],
        'sqlsrv' => ['pk' => 'BIGINT IDENTITY(1,1) PRIMARY KEY', 'text' => 'NVARCHAR(MAX)', 'str' => 'NVARCHAR', 'ts' => 'DATETIME2', 'now' => 'SYSDATETIME()', 'int' => 'INT', 'big' => 'BIGINT'],
        default  => ['pk' => 'BIGINT AUTO_INCREMENT PRIMARY KEY', 'text' => 'TEXT', 'str' => 'VARCHAR', 'ts' => 'DATETIME', 'now' => 'CURRENT_TIMESTAMP', 'int' => 'INT', 'big' => 'BIGINT'],
    };
    $tail = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

    $cols = [];
    $add = static function (string $field, string $type) use (&$cols, $outbound, $q): void {
        if (!empty($outbound[$field])) $cols[] = '  ' . $q($outbound[$field]) . ' ' . $type;
    };
    $add('id', $types['pk']);
    $add('destination', $types['str'] . '(20) NOT NULL');
    $add('content', $types['text'] . ' NOT NULL');
    $add('originator', $types['str'] . '(20) NULL');
    $add('status', $types['str'] . '(40) NULL');
    $add('due_at', $types['ts'] . ' NULL');
    $add('priority', $types['int'] . ' NOT NULL DEFAULT 0');
    $add('reference', $types['big'] . ' NULL');
    $add('provider_id', $types['str'] . '(100) NULL');
    $add('parts', $types['int'] . ' NULL');
    $add('error', $types['str'] . '(255) NULL');
    $cols[] = '  ' . $q('created_at') . ' ' . $types['ts'] . ' NOT NULL DEFAULT ' . $types['now'];
    $add('sent_at', $types['ts'] . ' NULL');
    $add('delivered_at', $types['ts'] . ' NULL');
    $add('updated_at', $types['ts'] . ' NULL');
    $table = $outbound['table'] ?? 'sms_outbound';
    $indexName = 'ix_' . str_replace('.', '_', $table) . '_status';
    $statusIndex = !empty($outbound['status']) && !empty($outbound['id'])
        ? "\nCREATE INDEX " . $q($indexName) . ' ON ' . $q($table) . ' (' . $q($outbound['status']) . ', ' . $q($outbound['id']) . ');'
        : '';
    $outSql = 'CREATE TABLE ' . $q($table) . " (\n" . implode(",\n", $cols) . "\n)" . $tail . ';' . $statusIndex;

    $inTable = $inbound['table'] ?? 'sms_inbound';
    $inCols = ['  ' . $q('id') . ' ' . $types['pk']];
    $inAdd = static function (string $field, string $type) use (&$inCols, $inbound, $q): void {
        if (!empty($inbound[$field])) $inCols[] = '  ' . $q($inbound[$field]) . ' ' . $type;
    };
    $inAdd('originator', $types['str'] . '(20) NOT NULL');
    $inAdd('destination', $types['str'] . '(20) NOT NULL');
    $inAdd('content', $types['text'] . ' NULL');
    $inAdd('received_at', $types['ts'] . ' NULL');
    $inAdd('external_id', $types['big'] . ' NULL');
    $inSql = 'CREATE TABLE ' . $q($inTable) . " (\n" . implode(",\n", $inCols) . "\n)" . $tail . ';';
    if (!empty($inbound['external_id'])) {
        // SQL Server lets a plain UNIQUE index hold only ONE NULL, so it gets a filtered index.
        $inSql .= "\nCREATE UNIQUE INDEX " . $q('ux_' . str_replace('.', '_', $inTable) . '_ext') . ' ON ' . $q($inTable) . ' (' . $q($inbound['external_id']) . ')'
            . ($driver === 'sqlsrv' ? ' WHERE ' . $q($inbound['external_id']) . ' IS NOT NULL' : '') . ';';
    }
    return ['outbound' => $outSql, 'inbound' => $inSql];
}
