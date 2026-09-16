<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/app/Observability/DatabasePerformance.php';

final class DatabasePerformanceTest extends TestCase
{
    public function testSummarizesCountersPeaksAndDerivedRatios(): void
    {
        $samples = [
            [
                'captured_at' => 100.0,
                'available' => true,
                'error' => null,
                'values' => [
                    'Threads_connected' => 10,
                    'Threads_running' => 2,
                    'Questions' => 1000,
                    'Innodb_row_lock_waits' => 5,
                    'Innodb_buffer_pool_read_requests' => 10000,
                    'Innodb_buffer_pool_reads' => 100,
                    'Created_tmp_tables' => 100,
                    'Created_tmp_disk_tables' => 10,
                ],
            ],
            [
                'captured_at' => 110.0,
                'available' => true,
                'error' => null,
                'values' => [
                    'Threads_connected' => 30,
                    'Threads_running' => 7,
                    'Questions' => 2000,
                    'Innodb_row_lock_waits' => 8,
                    'Innodb_buffer_pool_read_requests' => 12000,
                    'Innodb_buffer_pool_reads' => 120,
                    'Created_tmp_tables' => 140,
                    'Created_tmp_disk_tables' => 14,
                ],
            ],
        ];

        $result = db_performance_summarize_samples($samples, ['max_connections' => '100']);

        self::assertTrue($result['available']);
        self::assertSame(1000.0, $result['counter_delta']['Questions']);
        self::assertSame(100.0, $result['counter_per_second']['Questions']);
        self::assertSame(30.0, $result['peak']['Threads_connected']);
        self::assertSame(7.0, $result['peak']['Threads_running']);
        self::assertEqualsWithDelta(0.01, $result['derived']['buffer_pool_physical_read_ratio'], 0.000001);
        self::assertEqualsWithDelta(0.10, $result['derived']['tmp_disk_table_ratio'], 0.000001);
        self::assertEqualsWithDelta(0.30, $result['derived']['peak_connection_utilization_ratio'], 0.000001);
    }

    public function testCounterResetNeverProducesNegativeDelta(): void
    {
        $samples = [
            ['captured_at' => 1.0, 'available' => true, 'error' => null, 'values' => ['Questions' => 500]],
            ['captured_at' => 2.0, 'available' => true, 'error' => null, 'values' => ['Questions' => 20]],
        ];
        $result = db_performance_summarize_samples($samples);
        self::assertSame(0.0, $result['counter_delta']['Questions']);
    }

    public function testInsufficientSuccessfulSamplesIsUnavailable(): void
    {
        $result = db_performance_summarize_samples([
            ['captured_at' => 1.0, 'available' => false, 'error' => 'denied', 'values' => []],
        ]);
        self::assertFalse($result['available']);
        self::assertSame(0, $result['sample_count']);
    }

    public function testExpectedIndexVerifierReportsMissingIndex(): void
    {
        $inventory = [];
        foreach (db_performance_index_expectations() as $table => $indexes) {
            foreach ($indexes as $index) {
                $inventory[] = ['table_name' => $table, 'index_name' => $index];
            }
        }
        $checks = db_performance_verify_expected_indexes($inventory);
        self::assertNotContains(false, array_column($checks, 'present'));

        array_pop($inventory);
        $checks = db_performance_verify_expected_indexes($inventory);
        self::assertContains(false, array_column($checks, 'present'));
    }
}
