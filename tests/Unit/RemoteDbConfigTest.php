<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * #45 — the pure parts of the customer database connector: identifier/host validation (the SQL and
 * DSN injection boundary), mapping validation, the per-dialect SQL helpers, the ELLSMS-state →
 * customer-status decision, and the password vault round trip.
 */
final class RemoteDbConfigTest extends TestCase
{
    private string|false $masterKeyBefore;

    protected function setUp(): void
    {
        $this->masterKeyBefore = getenv('SMS_GATEWAY_MASTER_KEY');
    }

    protected function tearDown(): void
    {
        putenv($this->masterKeyBefore === false ? 'SMS_GATEWAY_MASTER_KEY' : 'SMS_GATEWAY_MASTER_KEY=' . $this->masterKeyBefore);
    }

    public static function identifiers(): array
    {
        return [
            'plain' => ['sms_outbound', false, true],
            'schema prefix allowed for table' => ['dbo.SMS_OUT', true, true],
            'schema prefix refused for column' => ['dbo.SMS_OUT', false, false],
            'two dots' => ['a.b.c', true, false],
            'leading digit' => ['1table', false, false],
            'quote' => ['a`b', false, false],
            'bracket' => ['a]b', false, false],
            'double quote' => ['a"b', false, false],
            'space' => ['a b', false, false],
            'semicolon' => ['a;DROP', false, false],
            'comment' => ['a--', false, false],
            'empty' => ['', false, false],
            'too long' => [str_repeat('a', 65), false, false],
            'unicode letters' => ['جدول', false, false],
        ];
    }

    #[DataProvider('identifiers')]
    public function testIdentifierValidation(string $name, bool $allowSchema, bool $expected): void
    {
        $this->assertSame($expected, remote_db_identifier_valid($name, $allowSchema));
    }

    public function testQuotingPerDialect(): void
    {
        $this->assertSame('`sms_outbound`', remote_db_quote_identifier('mysql', 'sms_outbound'));
        $this->assertSame('"public"."sms_outbound"', remote_db_quote_identifier('pgsql', 'public.sms_outbound'));
        $this->assertSame('[dbo].[SMS_OUT]', remote_db_quote_identifier('sqlsrv', 'dbo.SMS_OUT'));
    }

    public function testQuotingRefusesAnInvalidIdentifier(): void
    {
        $this->expectException(RemoteDbException::class);
        remote_db_quote_identifier('mysql', 'x` OR 1=1 --');
    }

    public function testSelectLimitedUsesTopOnSqlServerAndLimitElsewhere(): void
    {
        $this->assertSame('SELECT TOP (5) a FROM t WHERE x ORDER BY a', remote_db_select_limited('sqlsrv', 'a', 't', 'x', 'a', 5));
        $this->assertSame('SELECT a FROM t WHERE x ORDER BY a LIMIT 5', remote_db_select_limited('mysql', 'a', 't', 'x', 'a', 5));
        $this->assertSame('SELECT a FROM t WHERE x ORDER BY a LIMIT 1', remote_db_select_limited('pgsql', 'a', 't', 'x', 'a', 0));
    }

    public function testHostAndDatabaseNameCannotCarryDsnOptions(): void
    {
        $this->assertTrue(remote_db_host_valid('db.example.com'));
        $this->assertTrue(remote_db_host_valid('10.0.0.5'));
        $this->assertTrue(remote_db_host_valid('::1'));
        $this->assertFalse(remote_db_host_valid('db;sslmode=disable'));
        $this->assertFalse(remote_db_host_valid('db host'));
        $this->assertTrue(remote_db_database_name_valid('crm_prod-2'));
        $this->assertFalse(remote_db_database_name_valid('crm;Encrypt=no'));

        $this->expectException(RemoteDbException::class);
        remote_db_dsn(['driver' => 'mysql', 'host' => 'h;port=1', 'port' => 3306, 'database_name' => 'd']);
    }

    public function testDsnPerDriver(): void
    {
        [$dsn] = remote_db_dsn(['driver' => 'pgsql', 'host' => 'pg', 'port' => 5432, 'database_name' => 'crm', 'tls_mode' => 'verify', 'connect_timeout_s' => 7]);
        $this->assertSame('pgsql:host=pg;port=5432;dbname=crm;sslmode=verify-full;connect_timeout=7', $dsn);
        [$dsn] = remote_db_dsn(['driver' => 'sqlsrv', 'host' => 'mssql', 'port' => 1433, 'database_name' => 'crm', 'tls_mode' => 'prefer', 'connect_timeout_s' => 10]);
        $this->assertSame('sqlsrv:Server=mssql,1433;Database=crm;LoginTimeout=10;Encrypt=yes;TrustServerCertificate=yes', $dsn);
        [$dsn] = remote_db_dsn(['driver' => 'mysql', 'host' => 'my', 'port' => 3307, 'database_name' => 'crm']);
        $this->assertSame('mysql:host=my;port=3307;dbname=crm;charset=utf8mb4', $dsn);
    }

    public function testDefaultMappingValidates(): void
    {
        [$errors, $out, $status, $in] = remote_db_validate_mapping(
            remote_db_default_outbound_mapping(), remote_db_default_status_values(), remote_db_default_inbound_mapping(), true
        );
        $this->assertSame([], $errors);
        $this->assertSame('sms_outbound', $out['table']);
        $this->assertSame('null_or_value', $out['pending_mode']);
        $this->assertSame('DELIVERED', $status['delivered']);
        $this->assertSame('sms_inbound', $in['table']);
    }

    public function testMappingRefusesBadIdentifiersDuplicatesAndAStatusEqualToPending(): void
    {
        $out = remote_db_default_outbound_mapping();
        $out['content'] = 'message; DROP TABLE x';
        $out['error'] = 'status';                    // same column as the status column
        $status = remote_db_default_status_values();
        $status['sent'] = 'PENDING';                 // would make a written row look new again
        [$errors] = remote_db_validate_mapping($out, $status, [], false);
        $this->assertArrayHasKey('outbound.content', $errors);
        $this->assertArrayHasKey('outbound.duplicate', $errors);
        $this->assertArrayHasKey('status.sent', $errors);
    }

    public function testRequiredFieldsAndOptionalOnesDropped(): void
    {
        $out = remote_db_default_outbound_mapping();
        unset($out['destination']);
        $out['provider_id'] = '';
        [$errors, $normalized] = remote_db_validate_mapping($out, remote_db_default_status_values(), [], false);
        $this->assertArrayHasKey('outbound.destination', $errors);
        $this->assertArrayNotHasKey('provider_id', $normalized);
    }

    public static function states(): array
    {
        return [
            'refused before queueing'  => ['failed', null, null, 'failed', true],
            'claimed, not queued'      => ['claimed', null, null, null, false],
            'queued, waiting'          => ['queued', 'pending', null, null, false],
            'processing'               => ['queued', 'processing', null, null, false],
            'sent, no report yet'      => ['queued', 'sent', null, 'sent', false],
            'sent, accepted'           => ['queued', 'sent', 'accepted', 'sent', false],
            'delivered'                => ['queued', 'sent', 'delivered', 'delivered', true],
            'not delivered'            => ['queued', 'sent', 'failed', 'not_delivered', true],
            'rejected'                 => ['queued', 'sent', 'rejected', 'rejected', true],
            'expired'                  => ['queued', 'sent', 'expired', 'expired', true],
            'unknown keeps waiting'    => ['queued', 'sent', 'unknown', 'unknown', false],
            'send failed'              => ['queued', 'failed', null, 'failed', true],
            'cancelled'                => ['queued', 'cancelled', null, 'failed', true],
        ];
    }

    #[DataProvider('states')]
    public function testTargetState(string $rowState, ?string $item, ?string $delivery, ?string $expected, bool $final): void
    {
        $this->assertSame([$expected, $final], remote_db_target_state($rowState, $item, $delivery));
    }

    public function testDdlForEachDriverUsesItsQuotingAndTypes(): void
    {
        $out = remote_db_default_outbound_mapping();
        $in = remote_db_default_inbound_mapping();
        $my = remote_db_ddl('mysql', $out, $in);
        $this->assertStringContainsString('CREATE TABLE `sms_outbound`', $my['outbound']);
        $this->assertStringContainsString('AUTO_INCREMENT', $my['outbound']);
        $pg = remote_db_ddl('pgsql', $out, $in);
        $this->assertStringContainsString('"id" BIGSERIAL PRIMARY KEY', $pg['outbound']);
        $ms = remote_db_ddl('sqlsrv', $out, $in);
        $this->assertStringContainsString('[message] NVARCHAR(MAX) NOT NULL', $ms['outbound']);
        $this->assertStringContainsString('WHERE [ellsms_id] IS NOT NULL', $ms['inbound']);
    }

    public function testPasswordVaultRoundTripAndKeyMismatch(): void
    {
        putenv('SMS_GATEWAY_MASTER_KEY=' . str_repeat('k', 40));
        $secret = remote_db_encrypt_password('s3cr3t-رمز');
        $conn = ['password_ciphertext' => $secret['ciphertext'], 'password_nonce' => $secret['nonce'], 'password_tag' => $secret['tag'], 'key_fingerprint' => $secret['fingerprint']];
        $this->assertSame('s3cr3t-رمز', remote_db_decrypt_password($conn));
        $this->assertNotSame('s3cr3t-رمز', $secret['ciphertext']);

        // The gateway vault key and this one are derived with different purposes.
        $this->assertNotSame(hash_hkdf('sha256', str_repeat('k', 40), 32, 'ellsms.sms_gateway.secret.v1'), remote_db_secret_key());

        putenv('SMS_GATEWAY_MASTER_KEY=' . str_repeat('z', 40));
        $this->expectException(RemoteDbException::class);
        remote_db_decrypt_password($conn);
    }

    public function testSafeErrorStripsPasswords(): void
    {
        $e = new RuntimeException("SQLSTATE[28000] login failed; password=hunter2; for user x with hunter2\n next line");
        $msg = remote_db_safe_error($e, 'hunter2');
        $this->assertStringNotContainsString('hunter2', $msg);
        $this->assertStringNotContainsString("\n", $msg);
    }
}
