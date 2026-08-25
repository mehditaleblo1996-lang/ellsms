<?php
/**
 * Full SMS gateway cloning for platform administrators.
 *
 * Copies connector rows, parameters, operator assignments and (optionally) encrypted gateway
 * secrets into a NEW gateway. The clone is deliberately created archived with sending disabled,
 * so copying a production gateway can never start routing traffic by accident. An admin must review
 * and explicitly activate it afterwards.
 */
declare(strict_types=1);

function gateway_clone_table_columns(string $table): array {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    $allowed = [
        'ellsms_sms_gateway_send_connectors',
        'ellsms_sms_gateway_status_connectors',
        'ellsms_sms_gateway_parameters',
        'ellsms_sms_gateway_operators',
    ];
    if (!in_array($table, $allowed, true)) {
        throw new InvalidArgumentException('Unsupported gateway clone table.');
    }
    $rows = db()->query("SHOW COLUMNS FROM `{$table}`")->fetchAll();
    $cache[$table] = $rows;
    return $rows;
}

/** Copy every row belonging to $sourceGatewayId while safely rewriting gateway-scoped unique slots. */
function gateway_clone_child_rows(string $table, int $sourceGatewayId, int $targetGatewayId): int {
    $columns = gateway_clone_table_columns($table);
    $columnNames = array_column($columns, 'Field');
    if (!in_array('gateway_id', $columnNames, true)) return 0;

    $st = db()->prepare("SELECT * FROM `{$table}` WHERE gateway_id = ?");
    $st->execute([$sourceGatewayId]);
    $rows = $st->fetchAll();
    if (!$rows) return 0;

    $insertColumns = [];
    foreach ($columns as $column) {
        if (str_contains(strtolower((string)($column['Extra'] ?? '')), 'auto_increment')) continue;
        $insertColumns[] = (string)$column['Field'];
    }

    $quoted = implode(',', array_map(static fn(string $c): string => "`{$c}`", $insertColumns));
    $placeholders = implode(',', array_fill(0, count($insertColumns), '?'));
    $insert = db()->prepare("INSERT INTO `{$table}` ({$quoted}) VALUES ({$placeholders})");

    $copied = 0;
    foreach ($rows as $row) {
        $row['gateway_id'] = $targetGatewayId;
        // Parameters and some mapping tables use an application-maintained uniqueness token whose
        // value embeds the gateway id. Reusing the source token would collide with the source row.
        if (array_key_exists('active_slot', $row) && $row['active_slot'] !== null) {
            $slot = (string)$row['active_slot'];
            $row['active_slot'] = preg_replace('/^' . preg_quote((string)$sourceGatewayId, '/') . ':/', $targetGatewayId . ':', $slot, 1);
        }
        $values = [];
        foreach ($insertColumns as $column) $values[] = $row[$column] ?? null;
        $insert->execute($values);
        $copied++;
    }
    return $copied;
}

/**
 * @return array{gateway_id:int,connectors:int,parameters:int,operators:int,secrets:int}
 */
function gateway_clone_full(int $sourceGatewayId, string $newCode, string $newName, bool $copySecrets, int $actorUserId): array {
    $newCode = strtolower(trim($newCode));
    $newName = trim($newName);
    if ($sourceGatewayId <= 0) throw new GatewayConfigException('درگاه مبدا نامعتبر است.');
    if (preg_match('/^[a-z0-9_]{2,40}$/', $newCode) !== 1) {
        throw new GatewayConfigException('شناسه‌ی درگاه جدید باید ۲ تا ۴۰ نویسه‌ی لاتین/عدد/زیرخط باشد.');
    }
    if ($newName === '') throw new GatewayConfigException('نام درگاه جدید نمی‌تواند خالی باشد.');

    $st = db()->prepare('SELECT * FROM ellsms_sms_gateways WHERE id = ?');
    $st->execute([$sourceGatewayId]);
    $source = $st->fetch();
    if (!$source) throw new GatewayConfigException('درگاه مبدا پیدا نشد.');

    $exists = db()->prepare('SELECT id FROM ellsms_sms_gateways WHERE code = ? LIMIT 1');
    $exists->execute([$newCode]);
    if ($exists->fetch()) throw new GatewayConfigException('این شناسه‌ی درگاه قبلاً استفاده شده است.');

    $secretNames = gateway_secret_keys($sourceGatewayId);
    if ($copySecrets && $secretNames !== [] && !gateway_secrets_configured()) {
        throw new GatewaySecretException('برای کپی کلیدهای محرمانه، SMS_GATEWAY_MASTER_KEY باید روی سرور تنظیم شده باشد.');
    }
    // Decrypt before opening the transaction so a broken/stale secret fails before creating anything.
    $secretValues = $copySecrets ? gateway_secrets_load($sourceGatewayId) : [];
    if ($copySecrets && count($secretValues) !== count($secretNames)) {
        throw new GatewaySecretException('همه‌ی کلیدهای محرمانه‌ی درگاه مبدا قابل خواندن نیستند؛ کپی متوقف شد.');
    }

    return db_transaction(function (PDO $db) use ($sourceGatewayId, $source, $newCode, $newName, $copySecrets, $secretValues, $actorUserId): array {
        // Safety rule: clone configuration completely, but NEVER clone activation/default state.
        $db->prepare(
            "INSERT INTO ellsms_sms_gateways
                (code, name, status, send_mode, send_enabled, status_enabled, is_default, config_version, default_slot)
             VALUES (?,?,'archived',?,0,?,0,1,NULL)"
        )->execute([
            $newCode,
            $newName,
            ($source['send_mode'] ?? 'per_message') === 'batch' ? 'batch' : 'per_message',
            !empty($source['status_enabled']) ? 1 : 0,
        ]);
        $targetGatewayId = (int)$db->lastInsertId();

        $connectors = 0;
        $connectors += gateway_clone_child_rows('ellsms_sms_gateway_send_connectors', $sourceGatewayId, $targetGatewayId);
        $connectors += gateway_clone_child_rows('ellsms_sms_gateway_status_connectors', $sourceGatewayId, $targetGatewayId);
        $parameters = gateway_clone_child_rows('ellsms_sms_gateway_parameters', $sourceGatewayId, $targetGatewayId);
        $operators = gateway_clone_child_rows('ellsms_sms_gateway_operators', $sourceGatewayId, $targetGatewayId);

        $secrets = 0;
        if ($copySecrets) {
            foreach ($secretValues as $key => $plaintext) {
                gateway_secret_put($targetGatewayId, (string)$key, (string)$plaintext);
                $secrets++;
            }
        }

        gateway_bump_version(
            $targetGatewayId,
            'gateway.clone',
            $actorUserId,
            json_encode([
                'source_gateway_id' => $sourceGatewayId,
                'source_code' => (string)$source['code'],
                'new_code' => $newCode,
                'secrets_copied' => $secrets,
                'activation_copied' => false,
            ], JSON_UNESCAPED_UNICODE) ?: ''
        );
        audit($actorUserId, 'gateway.clone', 'source=' . $sourceGatewayId . ' target=' . $targetGatewayId . ' secrets=' . $secrets);
        Logger::info('gateway.clone.completed', [
            'source_gateway_id' => $sourceGatewayId,
            'target_gateway_id' => $targetGatewayId,
            'connectors' => $connectors,
            'parameters' => $parameters,
            'operators' => $operators,
            'secrets_copied' => $secrets,
        ]);

        return [
            'gateway_id' => $targetGatewayId,
            'connectors' => $connectors,
            'parameters' => $parameters,
            'operators' => $operators,
            'secrets' => $secrets,
        ];
    });
}
