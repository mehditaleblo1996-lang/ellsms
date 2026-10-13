<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The contact Excel import's parsing (app/Contacts.php): how a sheet's columns are found, and how
 * each optional cell (date, gender, landline, email) is read or rejected. The database half —
 * groups, replacing, the plan limit — is ContactsAndGradualHttpTest.
 */
final class ContactImportParsingTest extends TestCase
{
    /** The header row and first data row of the template users download and fill in. */
    private const TEMPLATE_HEADER = ['شماره موبایل', 'نام', 'نام خانوادگی', 'تلفن', 'تاریخ تولد', 'تاریخ مناسبت', 'جنسیت', 'ایمیل', 'آدرس'];

    public function testATemplateRowIsReadIntoEveryField(): void
    {
        $parsed = contacts_parse_sheet([
            self::TEMPLATE_HEADER,
            ['9123281175', 'علی', 'حیدری', '02144452586', '1362/06/12', '1397/11/12', '0', 'usery773@gmail.com', 'تهران – باغ فیض'],
        ]);

        $this->assertSame([], $parsed['errors']);
        $this->assertSame([], $parsed['warnings']);
        $this->assertSame([[
            'row' => 2,
            'mobile' => '989123281175',
            'details' => [
                'name' => 'علی', 'last_name' => 'حیدری', 'phone' => '02144452586', 'gender' => 0,
                'birth_date' => '1983-09-03', 'occasion_date' => '2019-02-01',
                'email' => 'usery773@gmail.com', 'address' => 'تهران – باغ فیض',
            ],
        ]], $parsed['contacts']);
    }

    public function testColumnsAreFoundByTheirTitlesInAnyOrder(): void
    {
        $parsed = contacts_parse_sheet([
            ['ایميل', 'آدرس', 'موبایل', 'something else', 'نام‌خانوادگی'],
            ['a@example.com', 'Tehran', '۰۹۱۲۱۲۳۴۵۶۷', 'ignored', 'Rezaei'],
        ]);

        $c = $parsed['contacts'][0];
        $this->assertSame('989121234567', $c['mobile']);
        $this->assertSame('a@example.com', $c['details']['email']);
        $this->assertSame('Tehran', $c['details']['address']);
        $this->assertSame('Rezaei', $c['details']['last_name']);
        $this->assertSame('', $c['details']['name']);
    }

    public function testASheetWithoutAHeaderIsReadInTemplateOrder(): void
    {
        $parsed = contacts_parse_sheet([
            ['09121234567', 'Ali'],
            ['09351234567', 'Sara', 'Ahmadi'],
        ]);

        $this->assertCount(2, $parsed['contacts']);
        $this->assertSame(1, $parsed['contacts'][0]['row']);
        $this->assertSame('Ali', $parsed['contacts'][0]['details']['name']);
        $this->assertSame('Ahmadi', $parsed['contacts'][1]['details']['last_name']);
    }

    public function testASheetWithoutAMobileColumnIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        contacts_parse_sheet([['نام', 'ایمیل'], ['Ali', 'a@example.com']]);
    }

    public function testAnEmptySheetIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        contacts_parse_sheet([['', ''], []]);
    }

    public function testBadRowsAreReportedAndRepeatedNumbersKeepTheFirstRow(): void
    {
        $parsed = contacts_parse_sheet([
            self::TEMPLATE_HEADER,
            ['09121234567', 'First'],
            ['12345', 'Bad'],
            [],
            ['989121234567', 'Again'],
            ['09351234567', 'Odd', '', 'abc', '1362/13/01', '', '7', 'not-an-email'],
        ]);

        $this->assertSame(['First', 'Odd'], array_map(fn($c) => $c['details']['name'], $parsed['contacts']));
        $this->assertSame(1, $parsed['repeated']);
        $this->assertCount(1, $parsed['errors']);
        $this->assertStringContainsString('ردیف ۳', $parsed['errors'][0]);
        $this->assertCount(4, $parsed['warnings'], 'phone, birth date, gender and email of the last row');
        $odd = $parsed['contacts'][1]['details'];
        $this->assertSame(['', null, null, ''], [$odd['phone'], $odd['birth_date'], $odd['gender'], $odd['email']]);
    }

    public function testAFileOverTheRowLimitIsRefusedBeforeItsLastBatch(): void
    {
        $rows = (function (): \Generator {
            yield 1 => ['شماره موبایل'];
            for ($i = 0; $i <= CONTACT_IMPORT_MAX_ROWS; $i++) {
                yield $i + 2 => [(string)(9120000000 + $i)];
            }
        })();
        $handed = 0;
        try {
            contacts_import_rows($rows, function (array $chunk) use (&$handed): void { $handed += count($chunk); });
            $this->fail('a sheet one row over the limit must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('هیچ مخاطبی از این فایل ثبت نشد', $e->getMessage());
        }
        $this->assertSame(CONTACT_IMPORT_MAX_ROWS, $handed, 'everything handed over so far belongs to the transaction the caller rolls back');
    }

    public function testRowsAreHandedOverInBatchesWithTheirRealRowNumbers(): void
    {
        $chunks = [];
        $report = contacts_import_rows(
            [1 => self::TEMPLATE_HEADER, 2 => ['09120000001'], 5 => ['09120000002'], 6 => ['09120000003']],
            function (array $chunk) use (&$chunks): void { $chunks[] = array_column($chunk, 'row'); },
            2
        );
        $this->assertSame([[2, 5], [6]], $chunks);
        $this->assertSame(3, $report['contacts']);
    }

    public function testOnlyTheFirstReportLinesAreKeptButTheCountIsExact(): void
    {
        $rows = [self::TEMPLATE_HEADER];
        for ($i = 0; $i < CONTACT_IMPORT_REPORT_LINES + 5; $i++) {
            $rows[] = ['bad'];
        }
        $parsed = contacts_parse_sheet($rows);
        $this->assertCount(CONTACT_IMPORT_REPORT_LINES, $parsed['errors']);
        $this->assertSame(CONTACT_IMPORT_REPORT_LINES + 5, $parsed['error_count']);
    }

    public function testTheSampleFileAndACsvAreStreamedRowByRow(): void
    {
        $xlsx = iterator_to_array(contacts_file_rows(dirname(__DIR__, 2) . '/public/assets/samples/contacts-sample.xlsx', 'xlsx'));
        // Its first 1,000 rows are pre-formatted as text (so Excel keeps a typed leading 0); past the
        // two examples they are empty, and contacts_import_rows() skips them.
        $this->assertSame(range(1, 1000), array_keys($xlsx));
        $this->assertSame([], array_filter(array_merge(...array_slice($xlsx, 3))));
        $this->assertCount(2, contacts_parse_sheet(array_values($xlsx))['contacts']);
        $this->assertSame(self::TEMPLATE_HEADER, $xlsx[1]);
        $this->assertSame('09121234567', $xlsx[2][0]);

        $csv = tempnam(sys_get_temp_dir(), 'contacts');
        file_put_contents($csv, "\xEF\xBB\xBFشماره موبایل,نام\n09121234567, Ali \n");
        try {
            $this->assertSame([1 => ['شماره موبایل', 'نام'], 2 => ['09121234567', 'Ali']], iterator_to_array(contacts_file_rows($csv, 'csv')));
        } finally {
            unlink($csv);
        }
    }

    public function testDatesInEveryAcceptedForm(): void
    {
        $this->assertSame('1983-09-03', contact_parse_date('1362/06/12'));
        $this->assertSame('1983-09-03', contact_parse_date('۱۳۶۲/۰۶/۱۲'));
        $this->assertSame('1983-09-03', contact_parse_date('1362-6-12'));
        $this->assertSame('1983-09-03', contact_parse_date('13620612'));
        $this->assertSame('1983-09-03', contact_parse_date('1983-09-03'));
        $this->assertSame('1983-09-03', contact_parse_date('1983/09/03 00:00:00'));
        $this->assertSame('1983-09-03', contact_parse_date('30562'), 'an Excel date serial');
        $this->assertSame('2021-03-20', contact_parse_date('1399/12/30'), '1399 is a leap year');
    }

    public function testImpossibleDatesAreRejected(): void
    {
        foreach (['1398/12/30', '1362/13/01', '1362/07/31', '2023-02-29', '1362', 'دیروز', '99/01/01'] as $bad) {
            $this->assertNull(contact_parse_date($bad), $bad);
        }
    }

    public function testGenderCodesAndWords(): void
    {
        $this->assertSame(0, contact_parse_gender('0'));
        $this->assertSame(0, contact_parse_gender('آقا'));
        $this->assertSame(1, contact_parse_gender('۱'));
        $this->assertSame(1, contact_parse_gender('خانم'));
        $this->assertNull(contact_parse_gender('2'));
    }

    public function testLandlineGetsItsLeadingZeroBack(): void
    {
        $this->assertSame('02144452586', contact_normalize_phone('02144452586'));
        $this->assertSame('02144452586', contact_normalize_phone('2144452586'), 'Excel stored it as a number');
        $this->assertSame('02144452586', contact_normalize_phone('۰۲۱-۴۴۴۵۲۵۸۶'));
        $this->assertNull(contact_normalize_phone('12'));
    }

    public function testGroupsFromTheFormAreTrimmedAndDeduplicated(): void
    {
        $this->assertSame(['VIP', 'New one'], contacts_groups_from_request(['groups' => ['VIP', ' VIP '], 'new_group' => '  New   one ']));
        $this->assertSame(['old field'], contacts_groups_from_request(['group_name' => 'old field']));
        $this->assertSame([''], contacts_groups_from_request([]));
    }
}
