<?php
/**
 * A PishgamRayan smart-SMS-shaped server (tests/Integration/LbaTest.php), modelled on the provider's
 * guide: /SmartSms/<Method>, header `Authorization: <token>`, JSON in and out, statusCode 0 = success.
 *
 * Every request is appended to ELLSMS_RECORDER_FILE (one JSON line: method, path, query, headers, body).
 * State (gray lists, clubs, requests, sends) lives in ELLSMS_RECORDER_FILE . '.state'; a test changes it
 * to steer the server:
 *   drop_next: true       the next creating call does its work, then answers 502 (a lost answer)
 *   periodic_parts: {rid: n}  what GetPeriodicSmartSmsStatistics reports as sent for request rid
 * A request is built (status 1 -> 0) on its second RefreshSmartSmsRequest.
 *
 *   php -S 127.0.0.1:PORT tests/fixtures/fake_pishgam_smartsms_server.php
 */

declare(strict_types=1);

$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$query = [];
parse_str((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_QUERY) ?? ''), $query);
$raw = (string)file_get_contents('php://input');
$in = json_decode($raw, true) ?: [];
$httpMethod = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$recordFile = (string)getenv('ELLSMS_RECORDER_FILE');
if ($recordFile !== '') {
    file_put_contents($recordFile, json_encode(['method' => $httpMethod, 'path' => $path, 'query' => $query,
        'authorization' => (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''), 'body' => $in], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}
header('Content-Type: application/json; charset=utf-8');
$out = static function (array $data, int $http = 200): void { http_response_code($http); echo json_encode($data, JSON_UNESCAPED_UNICODE); };

if (!str_starts_with($path, '/SmartSms/')) { $out(['statusCode' => 25], 404); return; }
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'test-lba-token') { $out(['statusCode' => 34, 'message' => 'Token not found'], 401); return; }
$method = substr($path, strlen('/SmartSms/'));

$stateFile = $recordFile . '.state';
$state = is_file($stateFile) ? (json_decode((string)file_get_contents($stateFile), true) ?: []) : [];
$state += ['next' => 100, 'campaigns' => [], 'clubs' => [], 'requests' => [], 'sends' => [], 'drop_next' => false, 'periodic_parts' => []];
$save = static function () use (&$state, $stateFile): void { file_put_contents($stateFile, json_encode($state, JSON_UNESCAPED_UNICODE), LOCK_EX); };
$newId = static function () use (&$state): int { return ++$state['next']; };
$dropped = static function () use (&$state, $save): bool {
    if (!$state['drop_next']) return false;
    $state['drop_next'] = false;
    $save();
    http_response_code(502);
    echo 'bad gateway';
    return true;
};
$opsResult = static fn(): array => [['operator' => 'MCI', 'message' => 'Campaign created successfully.'], ['operator' => 'Irancell', 'message' => 'Campaign created successfully.']];
$expect = static function (string $verb) use ($httpMethod, $out): bool {
    if ($httpMethod === $verb) return true;
    $out(['statusCode' => 25, 'message' => 'wrong method ' . $httpMethod], 405);
    return false;
};

switch ($method) {
    /* ---- gray lists ---- */
    case 'CreateSmartSmsCampaign':
        if (!$expect('POST')) return;
        if (trim((string)($in['title'] ?? '')) === '') { $out(['statusCode' => 61, 'validationErrors' => ['title' => ['required']]]); return; }
        $id = $newId();
        $state['campaigns'][$id] = ['id' => $id, 'title' => $in['title']];
        $save();
        if ($dropped()) return;
        $out(['campaignId' => $id, 'operatorsResult' => $opsResult(), 'statusCode' => 0, 'validationErrors' => null]);
        return;
    case 'GetCurrentOrganizationSmartSmsCampaigns':
        $out(array_values($state['campaigns']));
        return;
    case 'GetSmartSmsCampaignCreateStatus':
        if (!isset($state['campaigns'][(int)($query['smartSmsCampaignId'] ?? 0)])) { $out(['statusCode' => 58]); return; }
        $out(['data' => [['operator' => 1, 'isCreated' => true], ['operator' => 2, 'isCreated' => false]], 'statusCode' => 0]);
        return;
    case 'DeleteSmartSmsCampaign':
        if (!$expect('DELETE')) return;
        if (!isset($state['campaigns'][(int)($in['id'] ?? 0)])) { $out(['statusCode' => 58]); return; }
        unset($state['campaigns'][(int)$in['id']]);
        $save();
        $out(['message' => 'کمپین با موفقیت حذف شد', 'statusCode' => 0]);
        return;

    /* ---- clubs ---- */
    case 'CreateSmartSmsClub':
        if (!$expect('POST')) return;
        $id = $newId();
        $state['clubs'][$id] = ['id' => $id, 'title' => $in['title'] ?? '', 'numbers' => []];
        $save();
        if ($dropped()) return;
        $out(['clubId' => $id, 'operatorsResult' => [['operator' => 'MCI', 'message' => 'ok']], 'statusCode' => 0, 'validationErrors' => null]);
        return;
    case 'GetCurrentOrganizationSmartSmsClubs':
        $out(array_map(static fn($c) => ['id' => $c['id'], 'Title' => $c['title']], array_values($state['clubs'])));
        return;
    case 'AddNumbersToSmartSmsClub':
        if (!isset($state['clubs'][(int)($in['clubId'] ?? 0)])) { $out(['statusCode' => 59]); return; }
        if (count((array)($in['numbers'] ?? [])) > 100) { $out(['statusCode' => 60]); return; }
        $mci = count(array_filter((array)$in['numbers'], static fn($n) => str_starts_with((string)$n, '9891')));
        $state['clubs'][(int)$in['clubId']]['numbers'] = array_merge($state['clubs'][(int)$in['clubId']]['numbers'], (array)$in['numbers']);
        $save();
        $out(['items' => [['isSuccessful' => true, 'operator' => 1, 'addedCount' => $mci, 'message' => 'ok'],
            ['isSuccessful' => true, 'operator' => 2, 'addedCount' => count((array)$in['numbers']) - $mci, 'message' => 'ok']], 'statusCode' => 0]);
        return;
    case 'DeleteSmartSmsClub':
        if (!isset($state['clubs'][(int)($in['id'] ?? 0)])) { $out(['statusCode' => 59]); return; }
        unset($state['clubs'][(int)$in['id']]);
        $save();
        $out(['statusCode' => 0, 'message' => 'ok']);
        return;

    /* ---- requests ---- */
    case 'CreateSimpleSmartSmsRequest':
    case 'CreateWelcomeSmartSmsRequest':
    case 'CreateWelcomeSmartSmsRequestWithCellList':
    case 'CreateClubSmartSmsRequest':
    case 'CreateWelcomeCitySmartSmsRequest':
    case 'CreateWelcomeProvinceSmartSmsRequest':
    case 'CreateWelcomeAirportSmartSmsRequest':
    case 'CreatePeriodicSmartSmsRequest':
        if (!$expect('POST')) return;
        if (str_contains($method, 'Welcome') || $method === 'CreateClubSmartSmsRequest') {
            if (!isset($state['campaigns'][(int)($in['campaignId'] ?? 0)])) { $out(['statusCode' => 58]); return; }
        }
        if ($method === 'CreateClubSmartSmsRequest' && !isset($state['clubs'][(int)($in['smartSmsClubId'] ?? 0)])) { $out(['statusCode' => 59]); return; }
        if (isset($in['startDatetime']) && preg_match('#^\d{4}/\d{2}/\d{2} \d{2}:\d{2}:\d{2}$#', (string)$in['startDatetime']) !== 1) { $out(['statusCode' => 37]); return; }
        $id = $newId();
        $state['requests'][$id] = ['id' => $id, 'title' => $in['title'] ?? '', 'method' => $method, 'status' => 1, 'refreshes' => 0,
            'operators' => $in['operators'] ?? [1], 'periodic' => $method === 'CreatePeriodicSmartSmsRequest'];
        $save();
        if ($dropped()) return;
        $items = array_map(static fn($op) => ['isSuccessful' => $op !== 3, 'operator' => $op, 'message' => $op === 3 ? 'اپراتور پشتیبانی نمی‌شود' : 'با موفقیت تکمیل شد'], (array)($in['operators'] ?? []));
        $out(['items' => $items, 'message' => 'عملیات با موفقیت انجام شد', 'smartSmsRequestId' => $id, 'statusCode' => 0, 'validationErrors' => null]);
        return;
    case 'GetCurrentOrganizationSmartSmsRequests':
        $out(array_map(static fn($r) => ['id' => $r['id'], 'title' => $r['title'], 'status' => $r['status'], 'isPeriodic' => $r['periodic']], array_values($state['requests'])));
        return;
    case 'RefreshSmartSmsRequest':
        $rid = (int)($in['id'] ?? 0);
        if (!isset($state['requests'][$rid])) { $out(['statusCode' => 63]); return; }
        $r = &$state['requests'][$rid];
        $r['refreshes']++;
        if ($r['status'] === 1 && $r['refreshes'] >= 2) $r['status'] = 0;
        $status = $r['status'];
        unset($r);
        $save();
        $out(['smartSmsRequestStatus' => $status, 'statusCode' => 0, 'message' => 'ok']);
        return;
    case 'GetSmartSmsRequestById':
        $rid = (int)($query['smartSmsRequestId'] ?? 0);
        if (!isset($state['requests'][$rid])) { $out(['statusCode' => 63]); return; }
        $r = $state['requests'][$rid];
        $recipients = $r['status'] === 1 ? [] : array_map(static fn($op) => ['id' => $rid * 10 + $op, 'operator' => $op, 'count' => 1000 * $op,
            'persianPrice' => 500, 'englishPrice' => 1200, 'coordinates' => [[51.67, 32.66]]], array_values(array_filter((array)$r['operators'], static fn($op) => $op !== 3)));
        $out(['message' => 'درخواست ارسال هوشمند یافت شد', 'result' => ['status' => $r['status'], 'expireDate' => '1404/08/30 18:00:00', 'recipients' => $recipients], 'statusCode' => 0]);
        return;
    case 'DeleteSmartSmsRequest':
        if (!$expect('DELETE')) return;
        if (!isset($state['requests'][(int)($in['id'] ?? 0)])) { $out(['statusCode' => 63]); return; }
        unset($state['requests'][(int)$in['id']]);
        $save();
        $out(['statusCode' => 0]);
        return;
    case 'UpdatePeriodicSmartSmsRequestActivation':
        if (!$expect('PUT')) return;
        $rid = (int)($in['smartSmsRequestId'] ?? 0);
        if (!isset($state['requests'][$rid]) || !$state['requests'][$rid]['periodic'] || !in_array($state['requests'][$rid]['status'], [5, 6], true)) { $out(['statusCode' => 63]); return; }
        $state['requests'][$rid]['status'] = !empty($in['isActive']) ? 5 : 6;
        $save();
        $out(['smartSmsRequestStatus' => $state['requests'][$rid]['status'], 'statusCode' => 0]);
        return;

    /* ---- sends ---- */
    case 'SendSimpleSmartSms':
        if (!$expect('POST')) return;
        $rid = (int)($in['smartSmsRequestId'] ?? 0);
        if (!isset($state['requests'][$rid])) { $out(['statusCode' => 63]); return; }
        if ($state['requests'][$rid]['status'] !== 0) { $out(['statusCode' => 64]); return; }
        if (str_contains((string)($in['smsBody'] ?? ''), 'http')) { $out(['statusCode' => 6]); return; }
        if (preg_match('#^\d{4}/\d{2}/\d{2} \d{2}:\d{2}:\d{2}$#', (string)($in['sendDateTime'] ?? '')) !== 1) { $out(['statusCode' => 37]); return; }
        $id = $newId();
        $state['sends'][$id] = $in;
        $save();
        if ($dropped()) return;
        $out(['message' => 'ارسال هوشمند با موفقیت در صف ارسال قرار گرفت', 'scheduledMessageId' => $id, 'statusCode' => 0, 'validationErrors' => null]);
        return;
    case 'SendPercentagePeriodicSmartSms':
    case 'SendCountPeriodicSmartSms':
    case 'UpdatePercentagePeriodicSmartSms':
    case 'UpdateCountPeriodicSmartSms':
        if (!$expect(str_starts_with($method, 'Update') ? 'PUT' : 'POST')) return;
        $rid = (int)($in['smartSmsRequestId'] ?? 0);
        if (!isset($state['requests'][$rid]) || !$state['requests'][$rid]['periodic']) { $out(['statusCode' => 63]); return; }
        if (str_starts_with($method, 'Update') && !in_array($state['requests'][$rid]['status'], [5, 6], true)) { $out(['statusCode' => 63]); return; }
        $state['requests'][$rid]['status'] = 5;
        $state['requests'][$rid]['periodic_params'] = $in;
        $save();
        $out(['message' => 'ارسال هوشمند دوره‌ای با موفقیت ثبت شد', 'statusCode' => 0, 'validationErrors' => null]);
        return;

    /* ---- statistics ---- */
    case 'GetPeriodicSmartSmsStatistics':
        $rid = (int)($query['SmartSmsRequestId'] ?? 0);
        $parts = (int)($state['periodic_parts'][$rid] ?? 0);
        $out(['data' => [['smsCount' => intdiv($parts, 2), 'partsCount' => intdiv($parts, 2), 'statisticsSendStatus' => 1, 'mobileOperator' => 1],
            ['smsCount' => $parts - intdiv($parts, 2), 'partsCount' => $parts - intdiv($parts, 2), 'statisticsSendStatus' => 2, 'mobileOperator' => 2]], 'statusCode' => 0]);
        return;
    case 'GetSmartSmsRequestDailyStatistics':
        $out(['data' => [['smsCount' => 10, 'partsCount' => 20, 'statisticsSendStatus' => 1, 'mobileOperator' => (int)($query['operator'] ?? 1), 'statisticsDateTime' => $query['StartDateTime'] ?? '1404/08/01']], 'statusCode' => 0]);
        return;
    case 'GetSmartSmsFinancialStatistics':
        $out(['data' => ['totalPrice' => 100000, 'refundedPrice' => 5000, 'purePrice' => 95000], 'statusCode' => 0]);
        return;
    case 'GetSmartOutboxMessageAggregatedStatistics':
        $out(['data' => [['smsCount' => 900, 'partsCount' => 900, 'statisticsSendStatus' => 1, 'mobileOperator' => 1], ['smsCount' => 100, 'partsCount' => 100, 'statisticsSendStatus' => 2, 'mobileOperator' => 1]], 'statusCode' => 0]);
        return;

    /* ---- lookups ---- */
    case 'GetProvinces':
        $out([['id' => 10, 'name' => 'تهران'], ['id' => 11, 'name' => 'گیلان'], ['id' => 24, 'name' => 'فارس']]);
        return;
    case 'GetCities':
        $out((int)($query['ProvinceId'] ?? 0) === 10 ? [['id' => 401, 'name' => 'تهران'], ['id' => 402, 'name' => 'شهریار']] : []);
        return;
    case 'GetAirports':
        $out([['id' => 1, 'name' => 'فرودگاه بین‌المللی مهرآباد'], ['id' => 2, 'name' => 'فرودگاه بین‌المللی امام خمینی']]);
        return;
    case 'GetDeviceBrands':
        $out([['id' => 1, 'name' => 'Samsung'], ['id' => 2, 'name' => 'Apple']]);
        return;
    case 'GetDeviceTypes':
        $out([['id' => 1, 'name' => 'هوشمند'], ['id' => 2, 'name' => 'ساده']]);
        return;
    case 'CheckPolygonCells':
        if (!$expect('POST')) return;
        $out(['message' => 'اعتبارسنجی با موفقیت انجام شد', 'data' => array_map(static fn($op) => ['mobileOperator' => $op, 'isValid' => $op === 1, 'isValidArea' => true], (array)($in['mobileOperators'] ?? [])), 'statusCode' => 0]);
        return;
}
$out(['statusCode' => 62, 'message' => 'unknown method ' . $method], 404);
