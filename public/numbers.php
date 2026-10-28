<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/xlsx_reader.php';
$me = require_admin();
$pageTitle = 'شماره‌ها';
$active = 'numbers';
$detailsReady = number_details_ready();
$extrasReady = number_extras_ready();

// A stored commitment letter (تعهدنامه), served only to an admin and only from storage/.
if (isset($_GET['commitment'])) {
    $path = null;
    if ($detailsReady) {
        $st = db()->prepare('SELECT commitment_file FROM ellsms_numbers WHERE id = ?');
        $st->execute([(int)$_GET['commitment']]);
        $path = number_commitment_path(($f = $st->fetchColumn()) === false ? null : $f);
    }
    if ($path === null) {
        http_response_code(404);
        exit('فایلی برای این شماره ثبت نشده است.');
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mimeByExt = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
    header('Content-Type: ' . ($mimeByExt[$ext] ?? 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="commitment-' . (int)$_GET['commitment'] . '.' . $ext . '"');
    header('Cache-Control: private, max-age=0, no-store');
    readfile($path);
    exit;
}

// Sample file for «افزودن از فایل»: one line per row, column A.
if (isset($_GET['sample'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="numbers-sample.csv"');
    echo "\xEF\xBB\xBFnumber\r\n5000435801\r\n5000435802\r\n5000435803\r\n";
    exit;
}

// The per-number result of the last range/file add, kept under storage/imports for this session only.
if (isset($_GET['bulk_report'])) {
    $name = (string)($_SESSION['number_bulk_report']['file'] ?? '');
    $path = APP_ROOT . '/storage/imports/' . $name;
    if ($name === '' || $name !== (string)$_GET['bulk_report'] || !preg_match('/^numbers-add-[0-9a-f]{16}\.csv$/', $name) || !is_file($path)) {
        http_response_code(404);
        exit('گزارشی برای دانلود پیدا نشد.');
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="numbers-add-result.csv"');
    header('Cache-Control: private, max-age=0, no-store');
    readfile($path);
    exit;
}

// The list's filters, kept on every link, pager and redirect back to the list.
$filterKeys = ['q', 'user', 'bypass', 'optout', 'confidential', 'gateway', 'status', 'expiry', 'page'];
$listQuery = static function (array $src) use ($filterKeys): array {
    $out = [];
    foreach ($filterKeys as $k) {
        $v = trim((string)($src[$k] ?? ''));
        if ($v !== '') $out[$k] = $v;
    }
    return $out;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';

    // «افزودن شماره»: one number, a range (از … تا …) or a file, with a label, owner, status and expiry
    // for all of them. A range or file leaves a per-number report (added / already there / invalid).
    if ($do === 'create') {
        $mode = (string)($_POST['mode'] ?? 'single');
        $raw = [];
        $error = null;
        if ($mode === 'range') {
            $r = number_range((string)($_POST['from'] ?? ''), (string)($_POST['to'] ?? ''));
            $r['ok'] ? $raw = $r['numbers'] : $error = $r['error'];
        } elseif ($mode === 'file') {
            $f = $_FILES['file'] ?? [];
            $ext = strtolower(pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION));
            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $error = 'فایل شماره‌ها را انتخاب کنید.';
            } elseif ((int)$f['size'] > 5 * 1024 * 1024) {
                $error = 'حجم فایل نباید بیشتر از ۵ مگابایت باشد.';
            } elseif (!in_array($ext, ['xlsx', 'csv', 'txt'], true)) {
                $error = 'فرمت فایل باید xlsx، csv یا txt باشد.';
            } else {
                try {
                    // Column A of each row; a first row with no digit at all is a header.
                    $rows = $ext === 'txt'
                        ? array_map(static fn(string $l): array => [trim($l)], preg_split('/\R/u', (string)file_get_contents($f['tmp_name'])) ?: [])
                        : read_spreadsheet_rows($f['tmp_name'], (string)$f['name']);
                    $raw = array_map(static fn(array $row): string => (string)($row[0] ?? ''), $rows);
                    if ($raw && !preg_match('/[0-9۰-۹]/u', $raw[0])) array_shift($raw);
                } catch (RuntimeException $e) {
                    $error = $e->getMessage();
                }
            }
        } else {
            $raw = [(string)($_POST['number'] ?? '')];
        }
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($error === null && $userId > 0 && !resolve_ellsms_managed_user($userId)) $error = 'کاربر معتبری انتخاب نشده است.';
        if ($error === null) {
            $r = number_bulk_add($raw, [
                'label'      => mb_substr(trim((string)($_POST['label'] ?? '')), 0, 120),
                'user_id'    => $userId,
                'status'     => (string)($_POST['status'] ?? 'active'),
                'expires_on' => jalali_request_to_gregorian('add_expires'),
            ], (int)$me['id']);
            if (!$r['ok']) {
                $error = $r['error'];
            } elseif ($mode === 'single') {
                [$n, $code] = $r['rows'][0] ?? ['', 'invalid'];
                $code === 'added' ? flash('success', "شماره {$n} افزوده شد.")
                    : flash('error', $code === 'exists' ? "شماره {$n} قبلاً ثبت شده است." : 'شماره معتبر نیست؛ ۳ تا ۲۰ رقم وارد کنید.');
            } else {
                $file = 'numbers-add-' . bin2hex(random_bytes(8)) . '.csv';
                $dir = APP_ROOT . '/storage/imports';
                if (!is_dir($dir)) mkdir($dir, 0750, true);
                $old = (string)($_SESSION['number_bulk_report']['file'] ?? '');
                if (preg_match('/^numbers-add-[0-9a-f]{16}\.csv$/', $old)) @unlink($dir . '/' . $old);
                $out = fopen($dir . '/' . $file, 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['شماره', 'نتیجه']);
                // A cell starting with = + - @ would run as a formula in a spreadsheet.
                foreach ($r['rows'] as [$n, $code]) fputcsv($out, [preg_match('/^[=+\-@]/', $n) ? "'" . $n : $n, NUMBER_BULK_RESULTS[$code]]);
                fclose($out);
                $_SESSION['number_bulk_report'] = ['file' => $file, 'counts' => $r['counts']];
                flash($r['counts']['added'] > 0 ? 'success' : 'info', 'بررسی شماره‌ها انجام شد؛ نتیجه در کادر «نتیجه‌ی آخرین افزودن گروهی» آمده است.');
            }
        }
        if ($error !== null) flash('error', $error);
    }

    // Quick actions from the list's «عملیات» menu and the edit page. Blocking or deactivating asks
    // for a reason, stored with the date (status_reason / status_changed_at).
    if ($do === 'set_status') {
        $r = number_set_status((int)($_POST['id'] ?? 0), (string)($_POST['status'] ?? ''), (string)($_POST['reason'] ?? ''), (int)$me['id']);
        $r['ok'] ? flash('success', "وضعیت شماره {$r['number']} «" . number_status_label((string)$_POST['status']) . '» شد.') : flash('error', $r['error']);
    }

    if ($do === 'renew') {
        $r = number_renew((int)($_POST['id'] ?? 0), (int)$me['id']);
        $r['ok'] ? flash('success', "شماره {$r['number']} تا " . jdate($r['expires_on'], false) . ' تمدید شد.') : flash('error', $r['error']);
    }

    // On/off switches set to the posted value (not flipped), so a double submit cannot undo itself.
    if (in_array($do, ['confidential', 'bypass_optouts'], true) && ($do === 'confidential' ? $detailsReady : $extrasReady)) {
        $id = (int)($_POST['id'] ?? 0);
        $on = ($_POST['value'] ?? '') === '1';
        $st = db()->prepare('SELECT number FROM ellsms_numbers WHERE id = ?');
        $st->execute([$id]);
        if (($number = $st->fetchColumn()) === false) {
            flash('error', 'شماره‌ی معتبری انتخاب نشده است.');
        } else {
            db()->prepare("UPDATE ellsms_numbers SET {$do} = ? WHERE id = ?")->execute([$on ? 1 : 0, $id]);
            audit((int)$me['id'], "number.{$do}_" . ($on ? 'on' : 'off'), (string)$number);
            if ($do === 'bypass_optouts') number_bypasses_optouts((string)$number, true);
            $what = $do === 'confidential' ? 'ارسال محرمانه' : 'عبور از لیست سیاه داخلی (لغو عضویت «۱۱»)';
            flash('success', "{$what} برای شماره {$number} " . ($on ? 'فعال' : 'غیرفعال') . ' شد.');
        }
    }

    if ($do === 'user_quota' && $extrasReady) {
        $userId = (int)($_POST['user_id'] ?? 0);
        $raw = trim(from_persian_digits((string)($_POST['bandwidth_max'] ?? '')));
        if ($userId <= 0 || !resolve_ellsms_managed_user($userId)) {
            flash('error', 'کاربر معتبری انتخاب نشده است.');
        } elseif ($raw !== '' && (!ctype_digit($raw) || (int)$raw < 1 || (int)$raw > 1000000)) {
            flash('error', 'سقف پهنای باند باید عددی بین ۱ و ۱٬۰۰۰٬۰۰۰ باشد (خالی = بدون سقف).');
        } else {
            number_set_user_quota($userId, $raw === '' ? null : (int)$raw, (int)$me['id']);
            $used = number_user_bandwidth_used($userId);
            flash($raw !== '' && $used > (int)$raw ? 'info' : 'success', $raw === '' ? 'سقف پهنای باند کاربر برداشته شد.'
                : 'سقف پهنای باند کاربر ذخیره شد.' . ($used > (int)$raw ? ' توجه: همین حالا ' . to_persian_digits((string)$used) . ' استفاده شده و تا کم شدن آن، خط جدیدی به او داده نمی‌شود.' : ''));
        }
    }

    if ($do === 'owner' && $extrasReady) {
        $id = (int)($_POST['id'] ?? 0);
        $st = db()->prepare('SELECT 1 FROM ellsms_numbers WHERE id = ?');
        $st->execute([$id]);
        if ($st->fetchColumn() === false) {
            flash('error', 'شماره‌ی معتبری انتخاب نشده است.');
        } else {
            $r = number_owner_save($id, $_POST, jalali_request_to_gregorian('owner_birth'), jalali_request_to_gregorian('owner_start'), jalali_request_to_gregorian('owner_expire'), (int)$me['id']);
            $r['ok'] ? flash('success', 'اطلاعات مالک شماره ذخیره شد.') : flash('error', $r['error']);
        }
    }

    // «ویرایش شماره»: label, status, expiry, priority, bandwidth, confidential, inbound cost and the
    // commitment letter. Only status and expiry change sending (app/NumberDetails.php).
    if ($do === 'details') {
        $id = (int)($_POST['id'] ?? 0);
        $st = db()->prepare('SELECT * FROM ellsms_numbers WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        $num = static fn(string $k, int $default): int => (int)from_persian_digits(trim((string)($_POST[$k] ?? (string)$default)));
        if (!$row) {
            flash('error', 'شماره‌ی معتبری انتخاب نشده است.');
        } elseif (!$detailsReady) {
            db()->prepare('UPDATE ellsms_numbers SET label = ? WHERE id = ?')->execute([trim((string)($_POST['label'] ?? '')), $id]);
            audit((int)$me['id'], 'number.label', (string)$row['number']);
            flash('success', 'برچسب ذخیره شد. برای بقیه‌ی فیلدها ابتدا مایگریشن 2026_10_28_number_details.sql را اجرا کنید.');
        } else {
            $status       = (string)($_POST['status'] ?? 'active');
            $expiresOn    = !empty($_POST['no_expiry']) ? null : jalali_request_to_gregorian('expires');
            $priority     = $num('priority', 5);
            $bandwidth    = $num('bandwidth', 1);
            $error = null;
            if (!isset(NUMBER_STATUSES[$status]))          $error = 'وضعیت انتخاب‌شده معتبر نیست.';
            elseif ($priority < 1 || $priority > 10)       $error = 'اولویت پیام باید عددی بین ۱ تا ۱۰ باشد.';
            elseif ($bandwidth < 1 || $bandwidth > 100000) $error = 'پهنای باند باید عددی بین ۱ و ۱۰۰٬۰۰۰ باشد.';
            elseif ($row['assigned_user_id'] !== null)     $error = number_quota_error((int)$row['assigned_user_id'], $bandwidth, $status, $id);
            $file = null;
            if ($error === null) {
                try {
                    $file = number_commitment_store_upload('commitment', $id);
                } catch (AppException $e) {
                    $error = $e->getMessage();
                }
            }
            if ($error !== null) {
                flash('error', $error);
            } else {
                db()->prepare(
                    'UPDATE ellsms_numbers SET label = ?, expires_on = ?, priority = ?, bandwidth = ?,
                            confidential = ?, pay_inbound_cost = ?, commitment_file = COALESCE(?, commitment_file) WHERE id = ?'
                )->execute([
                    trim((string)($_POST['label'] ?? '')), $expiresOn, $priority, $bandwidth,
                    !empty($_POST['confidential']) ? 1 : 0, !empty($_POST['pay_inbound_cost']) ? 1 : 0, $file, $id,
                ]);
                // Through number_set_status() so a block/deactivation keeps its reason and date. The
                // ceiling was already checked above with the new bandwidth.
                if ($row['status'] !== $status) number_set_status($id, $status, (string)($_POST['status_reason'] ?? ''), (int)$me['id']);
                if ($file !== null && ($old = number_commitment_path($row['commitment_file'] ?? null)) !== null) @unlink($old);
                $changes = [];
                if ($row['status'] !== $status) $changes[] = "status {$row['status']} -> {$status}";
                if (($row['expires_on'] ?? null) !== $expiresOn) $changes[] = 'expires ' . ($row['expires_on'] ?? '—') . ' -> ' . ($expiresOn ?? '—');
                if ($file !== null) $changes[] = 'commitment uploaded';
                audit((int)$me['id'], 'number.details', $row['number'] . ($changes ? ' (' . implode(', ', $changes) . ')' : ''));
                $reason = number_unsendable_reason_for($status, $expiresOn);
                $reason === null
                    ? flash('success', 'مشخصات شماره ذخیره شد.')
                    : flash('info', "مشخصات شماره ذخیره شد. ارسال از این خط ممکن نیست: {$reason}.");
            }
        }
    }

    if ($do === 'commitment_remove' && $detailsReady) {
        $id = (int)($_POST['id'] ?? 0);
        $st = db()->prepare('SELECT number, commitment_file FROM ellsms_numbers WHERE id = ?');
        $st->execute([$id]);
        if ($row = $st->fetch()) {
            db()->prepare('UPDATE ellsms_numbers SET commitment_file = NULL WHERE id = ?')->execute([$id]);
            if (($old = number_commitment_path($row['commitment_file'])) !== null) @unlink($old);
            audit((int)$me['id'], 'number.commitment_remove', (string)$row['number']);
            flash('info', 'فایل تعهدنامه حذف شد.');
        }
    }

    if ($do === 'assign') {
        $id = (int)($_POST['id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);
        $quotaError = null;
        if ($userId > 0 && $detailsReady) {
            $st = db()->prepare('SELECT bandwidth, status FROM ellsms_numbers WHERE id = ?');
            $st->execute([$id]);
            if ($line = $st->fetch()) $quotaError = number_quota_error($userId, (int)$line['bandwidth'], (string)$line['status'], $id);
        }
        if ($quotaError !== null) {
            flash('error', $quotaError);
        } else {
            db()->prepare('UPDATE ellsms_numbers SET assigned_user_id = ? WHERE id = ?')
               ->execute([$userId ?: null, $id]);
            audit((int)$me['id'], 'number.assign', "#{$id} -> {$userId}");
            flash('success', $userId ? 'شماره تخصیص داده شد.' : 'تخصیص شماره لغو شد.');
        }
    }

    if ($do === 'gateway') {
        // Which gateway this number sends through. Empty = as before (its route's gateway, else the
        // default). Only an active gateway with sending switched on can be chosen; the price does not change.
        $id = (int)($_POST['id'] ?? 0);
        $gatewayId = (int)($_POST['gateway_id'] ?? 0);
        $ok = true;
        if ($gatewayId > 0) {
            $st = db()->prepare("SELECT code FROM ellsms_sms_gateways WHERE id = ? AND status = 'active' AND send_enabled = 1");
            $st->execute([$gatewayId]);
            $ok = $st->fetchColumn() !== false;
        }
        if (!$ok) {
            flash('error', 'این درگاه فعال نیست یا ارسال آن خاموش است.');
        } else {
            db()->prepare('UPDATE ellsms_numbers SET gateway_id = ? WHERE id = ?')->execute([$gatewayId ?: null, $id]);
            gateway_cache_reset();
            audit((int)$me['id'], 'number.gateway', "#{$id} -> " . ($gatewayId ?: 'route'));
            flash('success', $gatewayId ? 'درگاه ارسال این شماره تنظیم شد.' : 'درگاه این شماره به حالت پیش‌فرض (مسیر) برگشت.');
        }
    }

    // LBA (docs/lba.md): the LBA gateway this number's location-based sends go through, apart from its
    // ordinary gateway above. Empty = the number cannot send LBA.
    if ($do === 'lba_gateway') {
        $lbaGatewayId = (int)($_POST['lba_gateway_id'] ?? 0);
        $r = lba_number_set_gateway((int)($_POST['id'] ?? 0), $lbaGatewayId > 0 ? $lbaGatewayId : null, (int)$me['id']);
        $r['ok'] ? flash('success', $lbaGatewayId > 0 ? 'درگاه LBA این شماره تنظیم شد.' : 'ارسال LBA این شماره برداشته شد.') : flash('error', $r['error']);
    }

    // Service line (docs/service-lines.md): sends from it skip the do-not-contact list, like Vesal's
    // byPassBlackList. Set explicitly to the posted value rather than flipped, so a double-submitted
    // form cannot undo itself. Line opt-outs ("11") still apply.
    if ($do === 'bypass_blacklist') {
        $id = (int)($_POST['id'] ?? 0);
        $on = ($_POST['value'] ?? '') === '1';
        $st = db()->prepare('SELECT number FROM ellsms_numbers WHERE id = ?');
        $st->execute([$id]);
        $number = $st->fetchColumn();
        if ($number === false) {
            flash('error', 'شماره‌ی معتبری انتخاب نشده است.');
        } else {
            db()->prepare('UPDATE ellsms_numbers SET bypass_blacklist = ? WHERE id = ?')->execute([$on ? 1 : 0, $id]);
            audit((int)$me['id'], $on ? 'number.bypass_blacklist_on' : 'number.bypass_blacklist_off', (string)$number);
            flash('success', $on
                ? "شماره {$number} خدماتی شد: ارسال از آن دیگر با لیست سیاه بررسی نمی‌شود."
                : "شماره {$number} از حالت خدماتی خارج شد: ارسال از آن دوباره با لیست سیاه بررسی می‌شود.");
        }
    }

    // Shared lines (docs/shared-numbers.md). A share is additive and send-only: it never moves the
    // number's own assignment, and never widens the inbox/auto-reply/opt-out scope, which stay with
    // assigned_user_id / organization_id. Granting the same line twice is a no-op (PRIMARY KEY).
    if ($do === 'share_add') {
        $id     = (int)($_POST['id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);
        // The number is re-checked rather than trusted from the form: INSERT IGNORE downgrades a
        // foreign-key failure to a warning, so a crafted id would otherwise insert nothing while
        // still reporting success. resolve_ellsms_managed_user() is the same gate users.php uses —
        // a real backend account that actually has ELLSMS panel access, never an arbitrary id.
        $numberExists = false;
        if ($id > 0) {
            $st = db()->prepare('SELECT 1 FROM ellsms_numbers WHERE id = ?');
            $st->execute([$id]);
            $numberExists = $st->fetchColumn() !== false;
        }
        if (!$numberExists) {
            flash('error', 'شماره‌ی معتبری انتخاب نشده است.');
        } elseif ($userId <= 0 || !resolve_ellsms_managed_user($userId)) {
            flash('error', 'کاربر معتبری انتخاب نشده است.');
        } else {
            db()->prepare('INSERT IGNORE INTO ellsms_number_shares (number_id, user_id, created_by) VALUES (?,?,?)')
               ->execute([$id, $userId, (int)$me['id']]);
            audit((int)$me['id'], 'number.share_add', "#{$id} -> {$userId}");
            flash('success', 'شماره برای این کاربر هم فعال شد.');
        }
    }

    if ($do === 'share_remove') {
        $id     = (int)($_POST['id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);
        db()->prepare('DELETE FROM ellsms_number_shares WHERE number_id = ? AND user_id = ?')
           ->execute([$id, $userId]);
        audit((int)$me['id'], 'number.share_remove', "#{$id} -> {$userId}");
        flash('info', 'اشتراک این شماره برای آن کاربر برداشته شد.');
    }

    if ($do === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $file = null;
        if ($detailsReady) {
            $st = db()->prepare('SELECT commitment_file FROM ellsms_numbers WHERE id = ?');
            $st->execute([$id]);
            $file = $st->fetchColumn() ?: null;
        }
        db()->prepare('DELETE FROM ellsms_numbers WHERE id = ?')->execute([$id]);
        if (($old = number_commitment_path($file)) !== null) @unlink($old);
        audit((int)$me['id'], 'number.delete', "#{$id}");
        flash('info', 'شماره حذف شد.');
        unset($_POST['return_id']);
    }

    // Back to the edit page the form was on, else to the list with the filters the admin was using.
    $returnId = (int)($_POST['return_id'] ?? 0);
    if ($returnId > 0) redirect('/numbers.php?id=' . $returnId);
    parse_str((string)($_POST['back'] ?? ''), $back);
    $back = $listQuery(is_array($back) ? $back : []);
    redirect('/numbers.php' . ($back ? '?' . http_build_query($back) : ''));
}

$panelUsers = backend_panel_access_users();
$panelUsersById = array_column($panelUsers, null, 'id');

// Every active gateway: selectable ones can send; a number still pinned to one that can no longer send is
// shown with a warning, because its messages are held (retried) rather than sent another way.
$gateways = db()->query("SELECT id, code, name, send_enabled, is_mock, default_slot FROM ellsms_sms_gateways WHERE status = 'active' ORDER BY name")->fetchAll();
$gatewaysById = array_column($gateways, null, 'id');

$today = number_today();
$soonDays = 30;
$soonLimit = (new DateTimeImmutable($today))->modify("+{$soonDays} days")->format('Y-m-d');

/** The status a row is shown (and coloured) with: an active line past its expiry reads as «منقضی». */
$rowState = static function (array $n) use ($detailsReady): string {
    if (!$detailsReady) return 'active';
    $status = (string)($n['status'] ?? 'active');
    return $status === 'active' && number_is_expired($n['expires_on'] ?? null) ? 'expired' : $status;
};
$stateLabel = static fn(string $state): string => $state === 'expired' ? 'منقضی' : number_status_label($state);

$expiryCell = static function (?string $expiresOn) use ($today, $soonLimit): string {
    if ($expiresOn === null || $expiresOn === '') return '<span class="muted">بدون انقضا</span>';
    $date = e(jdate($expiresOn, false));
    if ($expiresOn < $today) return '<span class="num-expired">' . $date . ' (منقضی)</span>';
    if ($expiresOn <= $soonLimit) {
        $days = (int)(new DateTimeImmutable($today))->diff(new DateTimeImmutable($expiresOn))->days;
        return '<span class="num-soon">' . $date . ' (' . ($days === 0 ? 'امروز' : to_persian_digits((string)$days) . ' روز مانده') . ')</span>';
    }
    return $date;
};

/** «علت و تاریخ مسدودی / غیرفعال‌سازی» under a line's status, when it is not active. */
$statusNote = static function (array $n) use ($extrasReady): string {
    if (!$extrasReady || ($n['status'] ?? 'active') === 'active' || empty($n['status_changed_at'])) return '';
    return '<div class="num-status-note">' . e(jdate_from_utc($n['status_changed_at'])) . ($n['status_reason'] !== '' ? ' — ' . e($n['status_reason']) : '') . '</div>';
};

/**
 * The quick actions for one line, each its own small POST form with an icon: activate / deactivate /
 * block (with a reason), service line, confidential, opt-out bypass, renew, delete / restore. $hidden
 * carries the CSRF token, the id and where to go back to.
 */
$lineActions = static function (array $n, string $hidden) use ($detailsReady, $extrasReady): string {
    $form = static function (string $do, string $icon, string $label, array $fields = [], string $confirm = '', string $class = '') use ($hidden): string {
        $h = '<form method="post" class="num-act' . ($class !== '' ? ' ' . $class : '') . '"'
            . ($confirm !== '' ? ' onsubmit="return confirm(' . e(json_encode($confirm, JSON_UNESCAPED_UNICODE)) . ')"' : '') . '>'
            . $hidden . '<input type="hidden" name="do" value="' . e($do) . '">';
        foreach ($fields as $k => $v) $h .= '<input type="hidden" name="' . e($k) . '" value="' . e((string)$v) . '">';
        return $h . '<button type="submit">' . panel_icon($icon) . '<span>' . e($label) . '</span></button></form>';
    };
    // Block / deactivate ask for the reason in the same small form.
    $withReason = static function (string $status, string $icon, string $label) use ($hidden): string {
        return '<form method="post" class="num-act num-act-reason">' . $hidden
            . '<input type="hidden" name="do" value="set_status"><input type="hidden" name="status" value="' . $status . '">'
            . '<input type="text" name="reason" maxlength="255" placeholder="علت (اختیاری)" aria-label="علت ' . e($label) . '">'
            . '<button type="submit">' . panel_icon($icon) . '<span>' . e($label) . '</span></button></form>';
    };
    $status = (string)($n['status'] ?? 'active');
    $out = [];
    if ($detailsReady) {
        if ($status === 'active') {
            $out[] = $withReason('inactive', 'pause', 'غیرفعال‌سازی');
            $out[] = $withReason('blocked', 'lock', 'مسدود کن');
        } elseif ($status === 'blocked') {
            $out[] = $form('set_status', 'unlock', 'رفع مسدودی', ['status' => 'active'], 'مسدودی این شماره برداشته و دوباره فعال شود؟');
        } elseif ($status !== 'deleted') {
            $out[] = $form('set_status', 'play', 'فعال‌سازی', ['status' => 'active']);
        }
        if (!empty($n['expires_on'])) {
            $out[] = $form('renew', 'renew', 'تمدید یک‌ساله', [], 'تاریخ انقضای این شماره یک سال تمدید شود؟');
        }
    }
    $bypass = (int)($n['bypass_blacklist'] ?? 0) === 1;
    $out[] = $form('bypass_blacklist', 'shield', $bypass ? 'خدماتی: خاموش کن' : 'خدماتی (عبور از لیست سیاه): روشن کن', ['value' => $bypass ? '0' : '1'],
        $bypass ? 'ارسال از این شماره دوباره با لیست سیاه بررسی شود؟' : 'این شماره خدماتی شود؟ ارسال از آن دیگر با لیست سیاه مشتری بررسی نمی‌شود.');
    if ($extrasReady) {
        $optout = (int)($n['bypass_optouts'] ?? 0) === 1;
        $out[] = $form('bypass_optouts', 'ban', $optout ? 'عبور از لیست سیاه داخلی: خاموش کن' : 'عبور از لیست سیاه داخلی: روشن کن', ['value' => $optout ? '0' : '1'],
            $optout ? 'لغو عضویت «۱۱» گیرندگان دوباره برای این خط رعایت شود؟'
                    : 'ارسال از این خط به گیرندگانی که با «۱۱» لغو عضویت کرده‌اند هم انجام شود؟ فقط برای خطوط خاص مثل کد یکبارمصرف.');
    }
    if ($detailsReady) {
        $conf = (int)($n['confidential'] ?? 0) === 1;
        $out[] = $form('confidential', $conf ? 'eye' : 'eye-off', $conf ? 'ارسال عمومی کن' : 'ارسال محرمانه کن', ['value' => $conf ? '0' : '1']);
        $out[] = $status === 'deleted'
            ? $form('set_status', 'restore', 'بازیابی', ['status' => 'active'], 'این شماره بازیابی و فعال شود؟')
            : $form('set_status', 'trash', 'حذف (قابل بازیابی)', ['status' => 'deleted'], 'این شماره حذف شود؟ می‌توانید بعداً آن را بازیابی کنید.', 'is-danger');
    }
    return implode('', $out);
};

/* =============================== «ویرایش شماره» =============================== */
$editId = (int)($_GET['id'] ?? 0);
if ($editId > 0) {
    $st = db()->prepare('SELECT * FROM ellsms_numbers WHERE id = ?');
    $st->execute([$editId]);
    $n = $st->fetch();
    if (!$n) {
        flash('error', 'این شماره پیدا نشد.');
        redirect('/numbers.php');
    }
    $pageTitle = 'ویرایش شماره ' . $n['number'];
    $ownerName = $n['assigned_user_id'] !== null
        ? (backend_usernames_by_ids([(int)$n['assigned_user_id']])[(int)$n['assigned_user_id']] ?? ('#' . (int)$n['assigned_user_id']))
        : null;
    $st = db()->prepare('SELECT user_id FROM ellsms_number_shares WHERE number_id = ?');
    $st->execute([$editId]);
    $shareIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $shareNames = backend_usernames_by_ids($shareIds);
    $shares = array_map(static fn(int $uid): array => ['user_id' => $uid, 'username' => $shareNames[$uid] ?? ('#' . $uid)], $shareIds);
    $lbaGateways = lba_gateways_list();
    $lbaGatewaysById = array_column($lbaGateways, null, 'id');
    $fw = null;
    if (number_forward_available()) {
        $st = db()->prepare('SELECT mo_enabled, mo_url, dlr_enabled, dlr_url, consecutive_failures FROM ellsms_number_forwards WHERE number = ?');
        $st->execute([(string)$n['number']]);
        $fw = $st->fetch() ?: null;
    }
    $state = $rowState($n);
    $reason = $detailsReady ? number_unsendable_reason_for((string)$n['status'], $n['expires_on']) : null;
    $isSystemLine = system_default_originator() === (string)$n['number'];
    $hidden = static fn() => csrf_field() . '<input type="hidden" name="id" value="' . (int)$n['id'] . '"><input type="hidden" name="return_id" value="' . (int)$n['id'] . '">';
    $bypass = (int)($n['bypass_blacklist'] ?? 0) === 1;
    $pinned = $n['gateway_id'] !== null ? (int)$n['gateway_id'] : 0;
    $pinnedRow = $gatewaysById[$pinned] ?? null;
    $lbaPinned = (int)($n['lba_gateway_id'] ?? 0);
    $ownerQuota = $n['assigned_user_id'] !== null ? number_user_quota((int)$n['assigned_user_id']) : null;
    // Usage without this line, plus this line when its status counts.
    $ownerUsed = $n['assigned_user_id'] !== null
        ? number_user_bandwidth_used((int)$n['assigned_user_id'], (int)$n['id']) + (in_array($n['status'] ?? 'active', NUMBER_QUOTA_FREE_STATUSES, true) ? 0 : (int)($n['bandwidth'] ?? 0))
        : 0;
    $owner = number_owner_get((int)$n['id']) ?? [];
    $ov = static fn(string $k): string => (string)($owner[$k] ?? '');

    require __DIR__ . '/../app/views/header.php';
    ?>
<div class="card">
  <div class="num-head">
    <h2>ویرایش شماره <span class="ltr"><?= e($n['number']) ?></span>
      <?php if ($detailsReady): ?><span class="num-chip num-st-<?= $state ?>"><?= e($stateLabel($state)) ?></span><?php endif; ?></h2>
    <a class="btn btn-sm" href="/numbers.php">→ بازگشت به فهرست شماره‌ها</a>
  </div>
  <?= $statusNote($n) ?>
  <div class="num-actbar"><?= $lineActions($n, $hidden()) ?></div>
  <?php if (!$detailsReady): ?>
    <p class="flash flash-info">برای وضعیت، تاریخ انقضا، اولویت، پهنای باند و تعهدنامه ابتدا مایگریشن <code>db/migrations/2026_10_28_number_details.sql</code> را اجرا کنید (<code>make db-migrations-apply</code>). تا آن زمان فقط برچسب ذخیره می‌شود.</p>
  <?php elseif ($reason !== null): ?>
    <p class="flash flash-error">ارسال از این خط اکنون ممکن نیست: <?= e($reason) ?>. پیامک‌های دریافتی و گزارش تحویل این خط همچنان دریافت می‌شوند.<?= $isSystemLine ? ' (این خط، خط سیستمی پنل است و پیام‌های خودِ سامانه مثل کد ورود همچنان از آن ارسال می‌شوند.)' : '' ?></p>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <?= $hidden() ?>
    <input type="hidden" name="do" value="details">
    <table class="num-kv">
      <tr>
        <th>شماره</th><td class="ltr"><b><?= e($n['number']) ?></b></td>
        <th>تاریخ ثبت</th><td><?= e(jdate_from_utc($n['created_at'] ?? null)) ?></td>
      </tr>
      <tr>
        <th>برچسب</th><td><input type="text" name="label" value="<?= e($n['label']) ?>" placeholder="مثلاً خط پشتیبانی"></td>
        <th>مالک (تخصیص به کاربر)</th><td><?= $ownerName !== null ? e($ownerName) : '<span class="muted">تخصیص‌نیافته</span>' ?></td>
      </tr>
      <?php if ($detailsReady): ?>
      <tr>
        <th>وضعیت</th>
        <td>
          <select name="status">
            <?php foreach (NUMBER_STATUSES as $k => $label): ?>
              <option value="<?= $k ?>" <?= $n['status'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($extrasReady): ?>
            <input type="text" name="status_reason" maxlength="255" placeholder="علت تغییر وضعیت (برای مسدود یا غیرفعال)" style="margin-top:6px">
          <?php endif; ?>
          <div class="hint">فقط خط «فعال» می‌تواند پیامک ارسال کند.</div>
        </td>
        <th>تاریخ انقضا</th>
        <td>
          <?php // Picking a date unticks «بدون تاریخ انقضا», so the date is not silently discarded on save. ?>
          <div onchange="if (event.target.tagName === 'SELECT') this.querySelector('[name=no_expiry]').checked = false">
            <?= jalali_date_select('expires', $n['expires_on'] ?? null, 10, 1, true) ?>
            <label style="margin-top:6px"><input type="checkbox" name="no_expiry" value="1" <?= empty($n['expires_on']) ? 'checked' : '' ?>> بدون تاریخ انقضا</label>
          </div>
          <div class="hint">وضعیت فعلی: <?= $expiryCell($n['expires_on'] ?? null) ?> — خط تا پایان همان روز ارسال می‌کند.</div>
        </td>
      </tr>
      <tr>
        <th>اولویت پیام</th><td><input type="number" name="priority" min="1" max="10" value="<?= (int)$n['priority'] ?>" class="ltr" style="max-width:120px"></td>
        <th>ارسال محرمانه</th>
        <td><select name="confidential"><option value="0">خیر</option><option value="1" <?= (int)$n['confidential'] ? 'selected' : '' ?>>بلی</option></select></td>
      </tr>
      <tr>
        <th>پهنای باند</th><td><input type="number" name="bandwidth" min="1" value="<?= (int)$n['bandwidth'] ?>" class="ltr" style="max-width:160px"></td>
        <th>حداکثر پهنای باند قابل اختصاص</th>
        <td>
          <?php if ($n['assigned_user_id'] === null): ?><span class="muted">شماره به کاربری تخصیص داده نشده است.</span>
          <?php elseif ($ownerQuota === null): ?><span class="muted">بدون سقف</span> (<?= e($ownerName) ?> اکنون <?= to_persian_digits((string)$ownerUsed) ?> استفاده می‌کند)
          <?php else: ?><b><?= to_persian_digits((string)max(0, $ownerQuota - $ownerUsed)) ?></b> <span class="muted">از سقف <?= to_persian_digits((string)$ownerQuota) ?>ِ <?= e($ownerName) ?> (<?= to_persian_digits((string)$ownerUsed) ?> استفاده‌شده)</span><?php endif; ?>
          <div class="hint">سقف هر کاربر در کادر «تخصیص» پایین همین صفحه تنظیم می‌شود.</div>
        </td>
      </tr>
      <tr>
        <th>پرداخت هزینه پیامک دریافتی</th>
        <td><select name="pay_inbound_cost"><option value="0">خیر</option><option value="1" <?= (int)$n['pay_inbound_cost'] ? 'selected' : '' ?>>بلی</option></select></td>
        <th>بارگذاری تعهدنامه شماره</th>
        <td>
          <input type="file" name="commitment" accept=".jpg,.jpeg,.png,.webp,.pdf">
          <div class="hint">JPG، PNG، WEBP یا PDF، حداکثر ۸ مگابایت. فایل جدید جایگزین فایل قبلی می‌شود.</div>
          <?php if (number_commitment_path($n['commitment_file'] ?? null) !== null): ?>
            <a href="/numbers.php?commitment=<?= (int)$n['id'] ?>">دریافت فایل تعهدنامه</a>
          <?php endif; ?>
        </td>
      </tr>
      <?php endif; ?>
    </table>
    <div style="margin-top:16px"><button class="btn btn-primary">ذخیره</button></div>
  </form>
  <?php if ($detailsReady && number_commitment_path($n['commitment_file'] ?? null) !== null): ?>
    <form method="post" style="margin-top:8px" onsubmit="return confirm('فایل تعهدنامه حذف شود؟')">
      <?= $hidden() ?><input type="hidden" name="do" value="commitment_remove">
      <button class="btn btn-sm btn-danger">حذف فایل تعهدنامه</button>
    </form>
  <?php endif; ?>
  <?php if ($detailsReady): ?>
  <p class="hint">
    <b>وضعیت و تاریخ انقضا:</b> از خطی که «فعال» نیست یا تاریخ انقضایش گذشته، در هیچ مسیری (ارسال تکی و گروهی، زمان‌بندی، API، منشی پیامک و …)
    نمی‌توان ارسال کرد و این خط از فهرست «ارسال‌کننده»‌ی کاربران هم برداشته می‌شود؛ ارسال‌های در صف نیز رد می‌شوند. پیامک ورودی، صندوق دریافت،
    گزارش تحویل و ارسال به URL تغییری نمی‌کنند. تغییر حداکثر ظرف ۳۰ ثانیه روی workerها اعمال می‌شود.
    اولویت، پهنای باند، ارسال محرمانه و هزینه‌ی پیامک دریافتی فعلاً فقط ثبت و نمایش داده می‌شوند.
  </p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>تخصیص، ارسال و درگاه‌ها</h2>
  <table class="num-kv">
    <tr>
      <th>تخصیص به کاربر</th>
      <td>
        <form method="post" class="toolbar" style="margin:0">
          <?= $hidden() ?><input type="hidden" name="do" value="assign">
          <select name="user_id" onchange="this.form.submit()">
            <option value="">— تخصیص‌نیافته —</option>
            <?php foreach ($panelUsers as $u): ?>
              <option value="<?= $u['id'] ?>" <?= (int)$n['assigned_user_id'] === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['username']) ?></option>
            <?php endforeach; ?>
          </select>
          <noscript><button class="btn btn-sm">اعمال</button></noscript>
        </form>
        <div class="hint">وقتی کاربری حداقل یک شماره تخصیص‌یافته داشته باشد، در ارسال پیامک و منشی پیامک به‌جای نوشتن آزاد، از میان شماره‌های خودش انتخاب می‌کند.</div>
      </td>
    </tr>
    <?php if ($extrasReady && $n['assigned_user_id'] !== null): ?>
    <tr>
      <th>سقف پهنای باند <?= e($ownerName) ?></th>
      <td>
        <form method="post" class="toolbar" style="margin:0">
          <?= $hidden() ?><input type="hidden" name="do" value="user_quota">
          <input type="hidden" name="user_id" value="<?= (int)$n['assigned_user_id'] ?>">
          <input type="text" name="bandwidth_max" class="ltr" style="max-width:140px" value="<?= $ownerQuota !== null ? (int)$ownerQuota : '' ?>" placeholder="بدون سقف">
          <button class="btn btn-sm">ذخیره‌ی سقف</button>
          <span class="muted">استفاده‌شده: <?= to_persian_digits((string)$ownerUsed) ?><?= $ownerQuota !== null ? ' از ' . to_persian_digits((string)$ownerQuota) : '' ?></span>
        </form>
        <div class="hint">
          جمع پهنای باند خطوطی که به این کاربر تخصیص داده می‌شود (به‌جز خطوط رزرو شده، منتقل شده و حذف) نمی‌تواند از این سقف بیشتر شود؛
          تخصیص خط، افزودن گروهی برای او، افزایش پهنای باند یا فعال کردن دوباره‌ی خطی که از این سقف بگذرد رد می‌شود. خالی = بدون سقف.
        </div>
      </td>
    </tr>
    <?php endif; ?>
    <tr>
      <th>کاربران اشتراکی (فقط ارسال)</th>
      <td>
        <?php foreach ($shares as $s): ?>
          <form method="post" class="toolbar" style="margin:0 0 4px">
            <?= $hidden() ?><input type="hidden" name="do" value="share_remove">
            <input type="hidden" name="user_id" value="<?= $s['user_id'] ?>">
            <span><?= e($s['username']) ?></span>
            <button class="btn btn-sm btn-danger" title="برداشتن اشتراک">×</button>
          </form>
        <?php endforeach; ?>
        <form method="post" class="toolbar" style="margin:0">
          <?= $hidden() ?><input type="hidden" name="do" value="share_add">
          <select name="user_id" onchange="this.form.submit()">
            <option value="">— افزودن کاربر —</option>
            <?php
            $alreadyShared = array_column($shares, 'user_id');
            foreach ($panelUsers as $u):
                // The outright owner is already covered by the assignment row; offering them
                // here would create a share row that changes nothing.
                if ((int)$u['id'] === (int)$n['assigned_user_id']) continue;
                if (in_array((int)$u['id'], $alreadyShared, true)) continue;
            ?>
              <option value="<?= $u['id'] ?>"><?= e($u['username']) ?></option>
            <?php endforeach; ?>
          </select>
          <noscript><button class="btn btn-sm">افزودن</button></noscript>
        </form>
        <p class="hint">
          یک شماره را می‌توان علاوه بر مالکش، به چند کاربر دیگر هم داد. آن کاربران این خط را در فهرست
          «ارسال‌کننده» صفحات ارسال، نظیر‌به‌نظیر، پیامک هوشمند و ارسال منطقه‌ای می‌بینند و می‌توانند از آن بفرستند — هزینه‌ی هر
          ارسال هم از اعتبار خودِ همان کاربر کم می‌شود.
          اشتراک <b>فقط اجازه‌ی ارسال</b> می‌دهد: صندوق دریافت، منشی پیامک و فهرست لغو عضویتِ این خط همچنان فقط برای مالک
          (یا سازمان مالک) دیده می‌شود. برای اینکه کاربری پیام‌های ورودی یک خط را هم ببیند، باید خط به خودش یا به سازمانش
          تخصیص داده شود، نه اشتراکی.
        </p>
      </td>
    </tr>
    <tr>
      <th>خدماتی (عبور از لیست سیاه)</th>
      <td>
        <form method="post" class="toolbar" style="margin:0"
              onsubmit="return confirm(<?= e(json_encode($bypass
                  ? 'ارسال از این شماره دوباره با لیست سیاه بررسی شود؟'
                  : 'این شماره خدماتی شود؟ ارسال از آن دیگر با لیست سیاه مشتری بررسی نمی‌شود (لغو عضویت «۱۱» همچنان اعمال می‌شود).', JSON_UNESCAPED_UNICODE)) ?>)">
          <?= $hidden() ?><input type="hidden" name="do" value="bypass_blacklist">
          <input type="hidden" name="value" value="<?= $bypass ? '0' : '1' ?>">
          <span class="badge <?= $bypass ? 'badge-ok' : 'badge-unknown' ?>"><?= $bypass ? 'فعال' : 'غیرفعال' ?></span>
          <button class="btn btn-sm"><?= $bypass ? 'غیرفعال کن' : 'فعال کن' ?></button>
        </form>
        <p class="hint">
          برای خطوط خدماتی مثل اطلاع‌رسانی تراکنش، قبض یا نوبت‌دهی — همان «bypassBlackList» در وصال.
          وقتی فعال باشد، ارسال از این شماره در هیچ مسیری (ارسال تکی و گروهی، زمان‌بندی، نظیر‌به‌نظیر، پیامک هوشمند، ارسال با کد پستی و پیش‌نمایش هزینه)
          با لیست سیاه مشتری بررسی نمی‌شود، حتی اگر گزینه‌ی «فقط ارسال به لیست سفید» زده شده باشد.
          لغو عضویتِ گیرنده از همین خط («۱۱») همچنان رعایت می‌شود. پیش‌فرض برای هر شماره‌ی جدید غیرفعال است و هر تغییر در گزارش رویدادها ثبت می‌شود.
          کارهای گروهی‌ای که پیش از تغییر، بررسی لیست سیاه‌شان تمام شده، با همان نتیجه ارسال می‌شوند.
        </p>
      </td>
    </tr>
    <?php if ($extrasReady): $optout = (int)($n['bypass_optouts'] ?? 0) === 1; ?>
    <tr>
      <th>عبور از لیست سیاه داخلی</th>
      <td>
        <form method="post" class="toolbar" style="margin:0"
              onsubmit="return confirm(<?= e(json_encode($optout
                  ? 'لغو عضویت «۱۱» گیرندگان دوباره برای این خط رعایت شود؟'
                  : 'ارسال از این خط به گیرندگانی که با «۱۱» لغو عضویت کرده‌اند هم انجام شود؟', JSON_UNESCAPED_UNICODE)) ?>)">
          <?= $hidden() ?><input type="hidden" name="do" value="bypass_optouts">
          <input type="hidden" name="value" value="<?= $optout ? '0' : '1' ?>">
          <span class="badge <?= $optout ? 'badge-ok' : 'badge-unknown' ?>"><?= $optout ? 'فعال' : 'غیرفعال' ?></span>
          <button class="btn btn-sm"><?= $optout ? 'غیرفعال کن' : 'فعال کن' ?></button>
        </form>
        <p class="hint">
          همان «عبور از لیست سیاه داخلی» در وصال: وقتی فعال باشد، فهرست لغو عضویت همین خط («۱۱») در هیچ مسیری از ارسال بررسی نمی‌شود.
          فقط برای خطوط خاص مثل کد یکبارمصرف و اطلاع‌رسانی ضروری روشن کنید؛ برای خطوط تبلیغاتی روشن کردن آن با حقوق گیرنده در تضاد است.
          هر تغییر در گزارش رویدادها ثبت می‌شود و حداکثر ظرف ۳۰ ثانیه روی workerها اعمال می‌شود.
        </p>
      </td>
    </tr>
    <?php endif; ?>
    <tr>
      <th>درگاه ارسال</th>
      <td>
        <form method="post" class="toolbar" style="margin:0">
          <?= $hidden() ?><input type="hidden" name="do" value="gateway">
          <select name="gateway_id" onchange="this.form.submit()">
            <option value="">— پیش‌فرض (طبق مسیر) —</option>
            <?php foreach ($gateways as $g): if (!(int)$g['send_enabled'] && (int)$g['id'] !== $pinned) continue; ?>
              <option value="<?= (int)$g['id'] ?>" <?= (int)$g['id'] === $pinned ? 'selected' : '' ?>><?= e($g['name']) ?> (<?= e($g['code']) ?>)<?= (int)$g['is_mock'] ? ' — آزمایشی' : '' ?></option>
            <?php endforeach; ?>
          </select>
          <noscript><button class="btn btn-sm">اعمال</button></noscript>
        </form>
        <?php if ($pinned > 0 && ($pinnedRow === null || !(int)$pinnedRow['send_enabled'])): ?>
          <div class="muted" style="color:var(--danger,#b00)">⚠ این درگاه اکنون قادر به ارسال نیست؛ پیام‌های این شماره تا رفع مشکل ارسال نمی‌شوند.</div>
        <?php endif; ?>
        <p class="hint">
          هر پیامی که از این شماره ارسال شود فقط از درگاه انتخاب‌شده می‌رود (پیامک ورودی تغییری نمی‌کند).
          قیمت پیام همان قیمت مسیر فعلی است و تغییر نمی‌کند. اگر درگاه انتخاب‌شده در دسترس نباشد، پیام از درگاه دیگری فرستاده نمی‌شود:
          ارسال گروهی بعداً دوباره تلاش می‌کند و ارسال مستقیم خطا می‌دهد. تغییر حداکثر ظرف چند ثانیه روی workerها اعمال می‌شود.
          <?php if (!gateway_transport_enabled()): ?><br><b style="color:var(--danger,#b00)">توجه: ارسال از طریق درگاه‌ها (SMS_GATEWAY_TRANSPORT) خاموش است؛ این انتخاب تا روشن شدن آن اثری ندارد.</b><?php endif; ?>
        </p>
      </td>
    </tr>
    <tr>
      <th>درگاه LBA</th>
      <td>
        <?php if (lba_tables_ready()): ?>
        <form method="post" class="toolbar" style="margin:0">
          <?= $hidden() ?><input type="hidden" name="do" value="lba_gateway">
          <select name="lba_gateway_id" onchange="this.form.submit()">
            <option value="">— بدون LBA —</option>
            <?php foreach ($lbaGateways as $lg): if ($lg['status'] !== 'active' && (int)$lg['id'] !== $lbaPinned) continue; ?>
              <option value="<?= (int)$lg['id'] ?>" <?= (int)$lg['id'] === $lbaPinned ? 'selected' : '' ?>><?= e($lg['name']) ?> (<?= e($lg['code']) ?>)</option>
            <?php endforeach; ?>
          </select>
          <noscript><button class="btn btn-sm">اعمال</button></noscript>
        </form>
        <?php if ($lbaPinned > 0 && ($lbaReason = lba_gateway_unusable_reason($lbaGatewaysById[$lbaPinned] ?? null)) !== null): ?>
          <div class="muted" style="color:var(--danger,#b00)">⚠ <?= e($lbaReason) ?></div>
        <?php endif; ?>
        <?php else: ?>—<?php endif; ?>
        <p class="hint">
          ارسال پیام منطقه‌ای (LBA) این شماره فقط از درگاه LBA انتخاب‌شده می‌رود و ارسال عادی آن تغییری نمی‌کند.
          شماره‌ی بدون درگاه LBA در صفحه‌ی ارسال منطقه‌ای دیده نمی‌شود. درگاه‌ها، توکن و تعرفه در <a href="/lba-gateways.php">درگاه‌های LBA</a>.
        </p>
      </td>
    </tr>
    <tr>
      <th>آدرس تحویل پیام‌های دریافتی مشتری</th>
      <td>
        <?php if ($fw && $fw['mo_url'] !== ''): ?>
          <span class="badge <?= (int)$fw['mo_enabled'] ? 'badge-ok' : 'badge-off' ?>"><?= (int)$fw['mo_enabled'] ? 'روشن' : 'خاموش' ?></span>
          <code class="ltr"><?= e($fw['mo_url']) ?></code>
        <?php else: ?><span class="muted">تنظیم نشده</span><?php endif; ?>
      </td>
    </tr>
    <tr>
      <th>آدرس تحویل وضعیت پیام‌های ارسالی</th>
      <td>
        <?php if ($fw && $fw['dlr_url'] !== ''): ?>
          <span class="badge <?= (int)$fw['dlr_enabled'] ? 'badge-ok' : 'badge-off' ?>"><?= (int)$fw['dlr_enabled'] ? 'روشن' : 'خاموش' ?></span>
          <code class="ltr"><?= e($fw['dlr_url']) ?></code>
        <?php else: ?><span class="muted">تنظیم نشده</span><?php endif; ?>
        <?php if ($fw && (int)$fw['consecutive_failures'] >= NUMBER_FORWARD_WARN_FAILURES): ?><div style="color:var(--danger,#b00)">⚠ ارسال به این آدرس‌ها پشت سر هم خطا داده است.</div><?php endif; ?>
        <div style="margin-top:6px"><a class="btn btn-sm" href="/number-forwards.php?edit=<?= e(rawurlencode((string)$n['number'])) ?>#lines">تنظیم ارسال به URL</a></div>
        <p class="hint">
          پیامک‌های دریافتی روی این خط و گزارش تحویل پیامک‌های ارسالی از آن را به آدرس http/https مشتری
          می‌فرستد (مثل receiveUrl / deliveryUrl در وصال). تنظیمات، گزارش و خاموش/روشن کردن سرویس در
          <a href="/number-forwards.php">فوروارد پیامک به URL</a>.
        </p>
      </td>
    </tr>
    <tr>
      <th>حذف کامل شماره</th>
      <td>
        <form method="post" onsubmit="return confirm('این شماره برای همیشه حذف شود؟ برای توقف موقت، وضعیت را «غیرفعال» یا «مسدود» کنید.')">
          <?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
          <button class="btn btn-sm btn-danger">حذف شماره</button>
        </form>
        <div class="hint">شماره و تخصیص‌ها و اشتراک‌هایش پاک می‌شوند. برای نگه داشتن سابقه، به‌جای آن وضعیت را «حذف» یا «غیرفعال» کنید.</div>
      </td>
    </tr>
  </table>
</div>

<?php if ($extrasReady): $otype = $ov('owner_type') === 'legal' ? 'legal' : 'real'; ?>
<div class="card" id="owner">
  <div class="num-head">
    <h2><?= panel_icon('id-card') ?> اطلاعات مالک شماره</h2>
    <?php if ($owner): ?><span class="muted">آخرین تغییر: <?= e(jdate_from_utc($owner['updated_at'])) ?></span><?php endif; ?>
  </div>
  <form method="post" class="num-owner" data-owner-type="<?= $otype ?>">
    <?= $hidden() ?><input type="hidden" name="do" value="owner">
    <div class="num-owner-type">
      <label><input type="radio" name="owner_type" value="real" <?= $otype === 'real' ? 'checked' : '' ?>> شخص حقیقی</label>
      <label><input type="radio" name="owner_type" value="legal" <?= $otype === 'legal' ? 'checked' : '' ?>> شخص حقوقی</label>
    </div>
    <div class="form-grid">
      <label data-for="legal" class="span-2">نام شرکت <input type="text" name="company_name" value="<?= e($ov('company_name')) ?>"></label>
      <label data-for="legal">شناسه‌ی ملی شرکت <input type="text" name="company_national_id" class="ltr" maxlength="11" value="<?= e($ov('company_national_id')) ?>"></label>
      <label data-for="legal">شماره‌ی ثبت <input type="text" name="company_registration_no" class="ltr" value="<?= e($ov('company_registration_no')) ?>"></label>
      <label>نام <span class="muted" data-for="legal">(مدیرعامل)</span> <input type="text" name="first_name" value="<?= e($ov('first_name')) ?>"></label>
      <label>نام خانوادگی <span class="muted" data-for="legal">(مدیرعامل)</span> <input type="text" name="last_name" value="<?= e($ov('last_name')) ?>"></label>
      <label>کد ملی <input type="text" name="national_id" class="ltr" maxlength="10" value="<?= e($ov('national_id')) ?>"></label>
      <label data-for="real">نام پدر <input type="text" name="father_name" value="<?= e($ov('father_name')) ?>"></label>
      <label data-for="real">جنسیت
        <select name="gender">
          <option value="">—</option>
          <option value="male" <?= $ov('gender') === 'male' ? 'selected' : '' ?>>مرد</option>
          <option value="female" <?= $ov('gender') === 'female' ? 'selected' : '' ?>>زن</option>
        </select>
      </label>
      <div class="field" data-for="real">تاریخ تولد <?= jalali_date_select('owner_birth', $ov('birth_date') ?: null, 0, 100, true) ?></div>
      <label>تلفن همراه <input type="text" name="mobile" class="ltr" value="<?= e($ov('mobile')) ?>"></label>
      <label>تلفن ثابت <input type="text" name="tel" class="ltr" value="<?= e($ov('tel')) ?>"></label>
      <label>ایمیل <input type="email" name="email" class="ltr" value="<?= e($ov('email')) ?>"></label>
      <label>استان <input type="text" name="province" value="<?= e($ov('province')) ?>"></label>
      <label>شهر <input type="text" name="city" value="<?= e($ov('city')) ?>"></label>
      <label>کد پستی <input type="text" name="postal_code" class="ltr" maxlength="10" value="<?= e($ov('postal_code')) ?>"></label>
      <label class="span-full">نشانی <input type="text" name="address" value="<?= e($ov('address')) ?>"></label>
      <div class="field">تاریخ شروع مالکیت <?= jalali_date_select('owner_start', $ov('start_date') ?: null, 2, 10, true) ?></div>
      <div class="field">تاریخ پایان مالکیت <?= jalali_date_select('owner_expire', $ov('expire_date') ?: null, 10, 2, true) ?></div>
    </div>
    <div style="margin-top:16px"><button class="btn btn-primary">ذخیره‌ی اطلاعات مالک</button></div>
    <p class="hint">برای ثبت مالکیت شماره در سامانه‌ی رگولاتوری (مثل «اطلاعات مالک» در وصال). این اطلاعات فقط در همین پنل نگه داشته می‌شود و به جایی ارسال نمی‌شود.</p>
  </form>
</div>
<script>
// Real / legal owner: show only the fields of the chosen type (without JS every field stays visible).
document.querySelectorAll('.num-owner').forEach(function (form) {
  form.addEventListener('change', function (e) {
    if (e.target.name === 'owner_type') form.dataset.ownerType = e.target.value;
  });
});
</script>
<?php endif; ?>
<?php
    require __DIR__ . '/../app/views/footer.php';
    exit;
}

/* =============================== مالکان شماره‌ها =============================== */
if (($_GET['tab'] ?? '') === 'owners' && $extrasReady) {
    $pageTitle = 'مالکان شماره‌ها';
    $oq = trim(from_persian_digits((string)($_GET['oq'] ?? '')));
    $ownerWhere = '';
    $ownerParams = [];
    if ($oq !== '') {
        $like = '%' . addcslashes($oq, '%_\\') . '%';
        $ownerWhere = 'WHERE n.number LIKE ? OR o.national_id LIKE ? OR o.company_national_id LIKE ? OR o.first_name LIKE ? OR o.last_name LIKE ? OR o.company_name LIKE ? OR o.mobile LIKE ?';
        $ownerParams = array_fill(0, 7, $like);
    }
    $st = db()->prepare("SELECT COUNT(*) FROM ellsms_number_owners o JOIN ellsms_numbers n ON n.id = o.number_id {$ownerWhere}");
    $st->execute($ownerParams);
    $ownerTotal = (int)$st->fetchColumn();
    [$page, $offset] = pager_offset($ownerTotal, 25);
    $st = db()->prepare("SELECT o.*, n.number FROM ellsms_number_owners o JOIN ellsms_numbers n ON n.id = o.number_id {$ownerWhere} ORDER BY o.updated_at DESC LIMIT 25 OFFSET {$offset}");
    $st->execute($ownerParams);
    $owners = $st->fetchAll();
    require __DIR__ . '/../app/views/header.php';
    ?>
<div class="card">
  <div class="num-head">
    <h2><?= panel_icon('id-card') ?> مالکان شماره‌ها</h2>
    <a class="btn btn-sm" href="/numbers.php">→ بازگشت به فهرست شماره‌ها</a>
  </div>
  <form method="get" class="toolbar">
    <input type="hidden" name="tab" value="owners">
    <label>جستجو <input type="text" name="oq" value="<?= e($oq) ?>" placeholder="شماره، نام، کد یا شناسه‌ی ملی، همراه…"></label>
    <button class="btn btn-primary"><?= panel_icon('search') ?> جستجو</button>
  </form>
  <div class="table-wrap">
  <table class="num-table">
    <tr><th>ردیف</th><th>شماره</th><th>نوع</th><th>نام / شرکت</th><th>کد یا شناسه‌ی ملی</th><th>همراه</th><th>شروع مالکیت</th><th>پایان مالکیت</th><th></th></tr>
    <?php foreach ($owners as $i => $o): $legal = $o['owner_type'] === 'legal'; ?>
      <tr>
        <td><?= to_persian_digits((string)($offset + $i + 1)) ?></td>
        <td><a class="num-link msisdn" href="/numbers.php?id=<?= (int)$o['number_id'] ?>#owner"><?= e($o['number']) ?></a></td>
        <td><?= $legal ? 'حقوقی' : 'حقیقی' ?></td>
        <td><?= e($legal ? $o['company_name'] : trim($o['first_name'] . ' ' . $o['last_name'])) ?></td>
        <td class="ltr"><?= e($legal ? $o['company_national_id'] : $o['national_id']) ?></td>
        <td class="ltr"><?= e($o['mobile']) ?></td>
        <td><?= e(jdate($o['start_date'], false)) ?></td>
        <td><?= $expiryCell($o['expire_date']) ?></td>
        <td><a class="num-icon-btn" href="/numbers.php?id=<?= (int)$o['number_id'] ?>#owner" title="ویرایش"><?= panel_icon('edit') ?></a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$owners): ?><tr><td colspan="9" class="empty"><?= $oq !== '' ? 'مالکی با این جستجو پیدا نشد.' : 'هنوز اطلاعات مالکی ثبت نشده؛ از صفحه‌ی «ویرایش شماره» ثبت کنید.' ?></td></tr><?php endif; ?>
  </table>
  </div>
  <?= pager_links($page, pager_pages($ownerTotal, 25), ['tab' => 'owners'] + ($oq !== '' ? ['oq' => $oq] : []), 'page', $ownerTotal) ?>
</div>
<?php
    require __DIR__ . '/../app/views/footer.php';
    exit;
}

/* =============================== فهرست شماره‌ها =============================== */
$f = $listQuery($_GET);
unset($f['page']);
$where = [];
$params = [];
if (($f['q'] ?? '') !== '') {
    $q = from_persian_digits($f['q']);
    $where[] = '(n.number LIKE ? OR n.label LIKE ?)';
    $like = '%' . addcslashes($q, '%_\\') . '%';
    array_push($params, $like, $like);
}
if (($f['user'] ?? '') === 'none') {
    $where[] = 'n.assigned_user_id IS NULL';
} elseif ((int)($f['user'] ?? 0) > 0) {
    $where[] = 'n.assigned_user_id = ?';
    $params[] = (int)$f['user'];
}
if (in_array($f['bypass'] ?? '', ['1', '0'], true)) {
    $where[] = 'n.bypass_blacklist = ?';
    $params[] = (int)$f['bypass'];
}
if ($extrasReady && in_array($f['optout'] ?? '', ['1', '0'], true)) {
    $where[] = 'n.bypass_optouts = ?';
    $params[] = (int)$f['optout'];
}
if ($detailsReady && in_array($f['confidential'] ?? '', ['1', '0'], true)) {
    $where[] = 'n.confidential = ?';
    $params[] = (int)$f['confidential'];
}
if (($f['gateway'] ?? '') === 'route') {
    $where[] = 'n.gateway_id IS NULL';
} elseif ((int)($f['gateway'] ?? 0) > 0) {
    $where[] = 'n.gateway_id = ?';
    $params[] = (int)$f['gateway'];
}
if ($detailsReady) {
    $statusFilter = (string)($f['status'] ?? '');
    if ($statusFilter === 'expired') {
        $where[] = "n.status = 'active' AND n.expires_on < ?";
        $params[] = $today;
    } elseif (isset(NUMBER_STATUSES[$statusFilter])) {
        $where[] = 'n.status = ?';
        $params[] = $statusFilter;
        if ($statusFilter === 'active') { $where[] = '(n.expires_on IS NULL OR n.expires_on >= ?)'; $params[] = $today; }
    }
    switch ($f['expiry'] ?? '') {
        case 'expired': $where[] = 'n.expires_on < ?'; $params[] = $today; break;
        case 'soon':    $where[] = 'n.expires_on BETWEEN ? AND ?'; array_push($params, $today, $soonLimit); break;
        case 'valid':   $where[] = 'n.expires_on >= ?'; $params[] = $today; break;
        case 'none':    $where[] = 'n.expires_on IS NULL'; break;
    }
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// «خروجی Excel»: every line matching the current filters, as a CSV Excel opens (UTF-8 with BOM).
if (($_GET['export'] ?? '') === 'csv') {
    $st = db()->prepare("SELECT n.* FROM ellsms_numbers n {$whereSql} ORDER BY n.number");
    $st->execute($params);
    $all = $st->fetchAll();
    $names = backend_usernames_by_ids(array_column($all, 'assigned_user_id'));
    audit((int)$me['id'], 'number.export', 'rows=' . count($all));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="numbers-' . gmdate('Ymd') . '.csv"');
    header('Cache-Control: private, max-age=0, no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $head = ['شماره', 'برچسب', 'کاربر', 'درگاه ارسال', 'خدماتی', 'تاریخ ثبت'];
    if ($detailsReady) array_push($head, 'وضعیت', 'تاریخ انقضا', 'اولویت', 'پهنای باند', 'ارسال محرمانه', 'پرداخت هزینه پیامک دریافتی');
    if ($extrasReady) array_push($head, 'عبور از لیست سیاه داخلی', 'علت وضعیت', 'تاریخ تغییر وضعیت');
    fputcsv($out, $head);
    // A cell starting with = + - @ would run as a formula in a spreadsheet.
    $cell = static fn($v): string => preg_match('/^[=+\-@]/', (string)$v) ? "'" . $v : (string)$v;
    $yn = static fn($v): string => (int)$v ? 'بلی' : 'خیر';
    foreach ($all as $n) {
        $gw = $n['gateway_id'] !== null ? ($gatewaysById[(int)$n['gateway_id']]['name'] ?? '#' . (int)$n['gateway_id']) : 'طبق مسیر';
        $line = [$n['number'], $n['label'], $n['assigned_user_id'] !== null ? ($names[(int)$n['assigned_user_id']] ?? '#' . (int)$n['assigned_user_id']) : '',
                 $gw, $yn($n['bypass_blacklist'] ?? 0), jdate_from_utc($n['created_at'] ?? null)];
        if ($detailsReady) array_push($line, $stateLabel($rowState($n)), $n['expires_on'] ? jdate($n['expires_on'], false) : '',
                                      $n['priority'], $n['bandwidth'], $yn($n['confidential']), $yn($n['pay_inbound_cost']));
        if ($extrasReady) array_push($line, $yn($n['bypass_optouts']), $n['status_reason'], jdate_from_utc($n['status_changed_at']));
        fputcsv($out, array_map($cell, $line));
    }
    fclose($out);
    exit;
}

$st = db()->prepare("SELECT COUNT(*) FROM ellsms_numbers n {$whereSql}");
$st->execute($params);
$total = (int)$st->fetchColumn();
$perPage = 25;
[$page, $offset] = pager_offset($total, $perPage);
$st = db()->prepare("SELECT n.* FROM ellsms_numbers n {$whereSql} ORDER BY (n.assigned_user_id IS NULL) DESC, n.number LIMIT {$perPage} OFFSET {$offset}");
$st->execute($params);
$numbers = $st->fetchAll();
$allCount = (int)db()->query('SELECT COUNT(*) FROM ellsms_numbers')->fetchColumn();

$usernames = backend_usernames_by_ids(array_column($numbers, 'assigned_user_id'));
$shareCounts = [];
if ($numbers) {
    $ids = array_map('intval', array_column($numbers, 'id'));
    $st = db()->prepare('SELECT number_id, COUNT(*) FROM ellsms_number_shares WHERE number_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY number_id');
    $st->execute($ids);
    $shareCounts = array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
}
$statusCounts = [];
if ($detailsReady) {
    $st = db()->prepare("SELECT IF(status = 'active' AND expires_on < ?, 'expired', status) AS s, COUNT(*) FROM ellsms_numbers GROUP BY s");
    $st->execute([$today]);
    $statusCounts = array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
}
$backQuery = http_build_query($f + ($page > 1 ? ['page' => $page] : []));
$bulkReport = isset($_SESSION['number_bulk_report']['file']) ? $_SESSION['number_bulk_report'] : null;

require __DIR__ . '/../app/views/header.php';
?>
<div class="card">
  <h2>افزودن شماره</h2>
  <form method="post" enctype="multipart/form-data" class="num-add" data-mode="single">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="create">
    <input type="hidden" name="back" value="<?= e($backQuery) ?>">
    <div class="num-tabs" role="radiogroup" aria-label="روش افزودن">
      <label><input type="radio" name="mode" value="single" checked><?= panel_icon('plus') ?> یک شماره</label>
      <label><input type="radio" name="mode" value="range"><?= panel_icon('more') ?> بازه‌ی شماره</label>
      <label><input type="radio" name="mode" value="file"><?= panel_icon('upload') ?> از فایل</label>
    </div>
    <div class="form-grid cols-4">
      <label data-mode="single">شماره <input type="text" name="number" class="ltr" placeholder="5000435800"></label>
      <label data-mode="range">از شماره <input type="text" name="from" class="ltr" placeholder="5000435800"></label>
      <label data-mode="range">تا شماره <input type="text" name="to" class="ltr" placeholder="5000435899"></label>
      <label data-mode="file" class="span-2">فایل شماره‌ها (xlsx، csv یا txt؛ ستون اول)
        <input type="file" name="file" accept=".xlsx,.csv,.txt">
        <a class="hint" href="/numbers.php?sample=1"><?= panel_icon('download') ?> فایل نمونه</a>
      </label>
      <label>برچسب (اختیاری) <input type="text" name="label" placeholder="مثلاً خط پشتیبانی"></label>
      <label>تخصیص به کاربر
        <select name="user_id">
          <option value="">— تخصیص‌نیافته —</option>
          <?php foreach ($panelUsers as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e($u['username']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <?php if ($detailsReady): ?>
      <label>وضعیت اولیه
        <select name="status">
          <?php foreach (NUMBER_STATUSES as $k => $label): if ($k === 'deleted') continue; ?><option value="<?= $k ?>"><?= e($label) ?></option><?php endforeach; ?>
        </select>
      </label>
      <div class="field">تاریخ انقضا (اختیاری) <?= jalali_date_select('add_expires', null, 10, 0, true) ?></div>
      <?php endif; ?>
    </div>
    <div style="margin-top:14px"><button class="btn btn-primary"><?= panel_icon('plus') ?> افزودن</button></div>
  </form>
  <p class="hint">
    در «بازه» همه‌ی شماره‌های بین ابتدا و انتها (هم‌طول، حداکثر <?= to_persian_digits(number_format(NUMBER_BULK_MAX)) ?> شماره) و در «فایل» شماره‌های ستون اول ثبت می‌شوند.
    شماره‌های تکراری یا نامعتبر رد می‌شوند و نتیجه‌ی هر شماره را می‌توانید دانلود کنید. اگر کاربر سقف پهنای باند داشته باشد و جا نباشد، هیچ شماره‌ای اضافه نمی‌شود.
  </p>
  <?php if ($bulkReport): ?>
    <div class="num-report">
      <b>نتیجه‌ی آخرین افزودن گروهی:</b>
      <?php foreach (NUMBER_BULK_RESULTS as $code => $label): ?>
        <span class="num-chip <?= $code === 'added' ? 'num-st-active' : ($code === 'exists' ? 'num-st-reserved' : 'num-st-deleted') ?>"><?= e($label) ?>: <?= to_persian_digits(number_format((int)($bulkReport['counts'][$code] ?? 0))) ?></span>
      <?php endforeach; ?>
      <a class="btn btn-sm" href="/numbers.php?bulk_report=<?= e($bulkReport['file']) ?>"><?= panel_icon('download') ?> دانلود نتیجه‌ی بررسی</a>
    </div>
  <?php endif; ?>
</div>
<script>
// «افزودن شماره»: show only the fields of the chosen method (without JS all of them stay visible).
document.querySelectorAll('.num-add').forEach(function (form) {
  form.addEventListener('change', function (e) { if (e.target.name === 'mode') form.dataset.mode = e.target.value; });
});
</script>

<div class="card">
  <div class="num-head">
    <h2>فهرست شماره‌ها</h2>
    <div class="num-tools">
      <a class="btn btn-sm" href="/numbers.php?<?= e(http_build_query($f + ['export' => 'csv'])) ?>"><?= panel_icon('download') ?> خروجی Excel</a>
      <?php if ($extrasReady): ?><a class="btn btn-sm" href="/numbers.php?tab=owners"><?= panel_icon('id-card') ?> مالکان شماره‌ها</a><?php endif; ?>
    </div>
  </div>
  <?php if (!$detailsReady): ?>
    <p class="flash flash-info">برای وضعیت و تاریخ انقضای شماره‌ها، مایگریشن <code>db/migrations/2026_10_28_number_details.sql</code> را اجرا کنید (<code>make db-migrations-apply</code>).</p>
  <?php endif; ?>
  <form method="get" class="form-grid cols-4" style="margin-bottom:16px">
    <label>شماره یا برچسب <input type="text" name="q" value="<?= e($f['q'] ?? '') ?>" placeholder="جستجو…"></label>
    <label>کاربر
      <select name="user">
        <option value="">همه</option>
        <option value="none" <?= ($f['user'] ?? '') === 'none' ? 'selected' : '' ?>>— تخصیص‌نیافته —</option>
        <?php foreach ($panelUsers as $u): ?>
          <option value="<?= (int)$u['id'] ?>" <?= (string)($f['user'] ?? '') === (string)$u['id'] ? 'selected' : '' ?>><?= e($u['username']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>نوع خط
      <select name="bypass">
        <option value="">همه</option>
        <option value="1" <?= ($f['bypass'] ?? '') === '1' ? 'selected' : '' ?>>خدماتی — بدون بررسی لیست سیاه</option>
        <option value="0" <?= ($f['bypass'] ?? '') === '0' ? 'selected' : '' ?>>عادی — با بررسی لیست سیاه</option>
      </select>
    </label>
    <?php if ($detailsReady): ?>
    <label>نوع ارسال
      <select name="confidential">
        <option value="">همه</option>
        <option value="1" <?= ($f['confidential'] ?? '') === '1' ? 'selected' : '' ?>>ارسال محرمانه</option>
        <option value="0" <?= ($f['confidential'] ?? '') === '0' ? 'selected' : '' ?>>ارسال عمومی</option>
      </select>
    </label>
    <?php endif; ?>
    <?php if ($extrasReady): ?>
    <label>لیست سیاه داخلی
      <select name="optout">
        <option value="">همه</option>
        <option value="1" <?= ($f['optout'] ?? '') === '1' ? 'selected' : '' ?>>عبور از لیست سیاه داخلی</option>
        <option value="0" <?= ($f['optout'] ?? '') === '0' ? 'selected' : '' ?>>رعایت لغو عضویت «۱۱»</option>
      </select>
    </label>
    <?php endif; ?>
    <label>درگاه ارسال
      <select name="gateway">
        <option value="">همه</option>
        <option value="route" <?= ($f['gateway'] ?? '') === 'route' ? 'selected' : '' ?>>— پیش‌فرض (طبق مسیر) —</option>
        <?php foreach ($gateways as $g): ?>
          <option value="<?= (int)$g['id'] ?>" <?= (string)($f['gateway'] ?? '') === (string)$g['id'] ? 'selected' : '' ?>><?= e($g['name']) ?> (<?= e($g['code']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php if ($detailsReady): ?>
    <label>وضعیت
      <select name="status">
        <option value="">همه</option>
        <?php foreach (NUMBER_STATUSES + ['expired' => 'منقضی (فعال ولی تاریخ گذشته)'] as $k => $label): ?>
          <option value="<?= $k ?>" <?= ($f['status'] ?? '') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>تاریخ انقضا
      <select name="expiry">
        <option value="">همه</option>
        <option value="expired" <?= ($f['expiry'] ?? '') === 'expired' ? 'selected' : '' ?>>گذشته</option>
        <option value="soon" <?= ($f['expiry'] ?? '') === 'soon' ? 'selected' : '' ?>>تا <?= to_persian_digits((string)$soonDays) ?> روز آینده</option>
        <option value="valid" <?= ($f['expiry'] ?? '') === 'valid' ? 'selected' : '' ?>>دارای انقضا و معتبر</option>
        <option value="none" <?= ($f['expiry'] ?? '') === 'none' ? 'selected' : '' ?>>بدون انقضا</option>
      </select>
    </label>
    <?php endif; ?>
    <div class="field" style="display:flex;gap:8px;align-items:end">
      <button class="btn btn-primary">جستجو</button>
      <?php if ($f): ?><a class="btn" href="/numbers.php">پاک کردن فیلترها</a><?php endif; ?>
    </div>
  </form>

  <?php if ($detailsReady): ?>
  <div class="num-legend">
    <?php foreach (NUMBER_STATUSES + ['expired' => 'منقضی'] as $k => $label):
        $chipQuery = $f; unset($chipQuery['status']);
        if (($f['status'] ?? '') !== $k) $chipQuery['status'] = $k; ?>
      <a class="num-chip num-st-<?= $k ?><?= ($f['status'] ?? '') === $k ? ' is-current' : '' ?>" href="/numbers.php<?= $chipQuery ? '?' . e(http_build_query($chipQuery)) : '' ?>"><?= e($label) ?> (<?= to_persian_digits((string)($statusCounts[$k] ?? 0)) ?>)</a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="table-wrap">
  <table class="num-table">
    <tr>
      <th>ردیف</th><th>شماره</th><th>برچسب</th><th>کاربر</th><th>اشتراکی</th><th>درگاه ارسال</th><th>خدماتی</th>
      <th>تاریخ ثبت</th><?php if ($detailsReady): ?><th>تاریخ انقضا</th><th>وضعیت</th><?php endif; ?><th></th>
    </tr>
    <?php foreach ($numbers as $i => $n):
        $state = $rowState($n);
        $editUrl = '/numbers.php?id=' . (int)$n['id'];
        $pinned = $n['gateway_id'] !== null ? (int)$n['gateway_id'] : 0;
        $pinnedRow = $gatewaysById[$pinned] ?? null; ?>
      <tr class="num-row<?= $detailsReady ? ' num-st-' . $state : '' ?>">
        <td><?= to_persian_digits((string)($offset + $i + 1)) ?></td>
        <td><a class="num-link msisdn" href="<?= $editUrl ?>"><?= e($n['number']) ?></a></td>
        <td><?= e($n['label']) ?></td>
        <td><?= $n['assigned_user_id'] !== null ? e($usernames[(int)$n['assigned_user_id']] ?? ('#' . (int)$n['assigned_user_id'])) : '<span class="muted">—</span>' ?></td>
        <td><?= ($c = $shareCounts[(int)$n['id']] ?? 0) ? to_persian_digits((string)$c) . ' کاربر' : '<span class="muted">—</span>' ?></td>
        <td>
          <?php if ($pinned === 0): ?><span class="muted">طبق مسیر</span>
          <?php else: ?><?= $pinnedRow ? e($pinnedRow['name']) : '#' . $pinned ?><?php if ($pinnedRow === null || !(int)$pinnedRow['send_enabled']): ?> <span style="color:var(--danger,#b00)" title="این درگاه اکنون قادر به ارسال نیست">⚠</span><?php endif; ?><?php endif; ?>
        </td>
        <td><?= (int)($n['bypass_blacklist'] ?? 0) === 1 ? '<span class="badge badge-ok">بله</span>' : '<span class="muted">خیر</span>' ?></td>
        <td><?= e(jdate_from_utc($n['created_at'] ?? null)) ?></td>
        <?php if ($detailsReady): ?>
          <td><?= $expiryCell($n['expires_on'] ?? null) ?></td>
          <td><b><?= e($stateLabel($state)) ?></b><?= $statusNote($n) ?></td>
        <?php endif; ?>
        <td class="num-ops">
          <a class="num-icon-btn" href="<?= $editUrl ?>" title="ویرایش" aria-label="ویرایش شماره <?= e($n['number']) ?>"><?= panel_icon('edit') ?></a>
          <details class="num-menu">
            <summary class="num-icon-btn" title="عملیات" aria-label="عملیات شماره <?= e($n['number']) ?>"><?= panel_icon('more') ?></summary>
            <div class="num-menu-panel">
              <?= $lineActions($n, csrf_field() . '<input type="hidden" name="id" value="' . (int)$n['id'] . '"><input type="hidden" name="back" value="' . e($backQuery) . '">') ?>
              <?php if ($extrasReady): ?><a class="num-act" href="<?= $editUrl ?>#owner"><?= panel_icon('id-card') ?><span>اطلاعات مالک</span></a><?php endif; ?>
            </div>
          </details>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$numbers): ?><tr><td colspan="<?= $detailsReady ? 11 : 9 ?>" class="empty"><?= $allCount > 0 ? 'شماره‌ای با این فیلتر نیست.' : 'هنوز شماره‌ای ثبت نشده.' ?></td></tr><?php endif; ?>
  </table>
  </div>
  <?php if ($total > 0): ?>
    <p class="hint">نمایش <?= to_persian_digits((string)($offset + 1)) ?> تا <?= to_persian_digits((string)min($offset + $perPage, $total)) ?> از <?= to_persian_digits(number_format($total)) ?> شماره<?= $total !== $allCount ? ' (از ' . to_persian_digits(number_format($allCount)) . ' شماره‌ی کل)' : '' ?>.</p>
  <?php endif; ?>
  <?= pager_links($page, pager_pages($total, $perPage), $f, 'page', $total) ?>
  <script>
  // Row «…» menus: one open at a time, placed with position:fixed next to their button so the
  // horizontally scrolling table does not clip them; opens upwards near the bottom of the window.
  (function () {
    var menus = document.querySelectorAll('.num-menu');
    function place(menu) {
      var panel = menu.querySelector('.num-menu-panel');
      var r = menu.querySelector('summary').getBoundingClientRect();
      var w = panel.offsetWidth, h = panel.offsetHeight;
      var left = Math.min(Math.max(8, r.left), window.innerWidth - w - 8);
      var top = r.bottom + 4 + h > window.innerHeight - 8 ? Math.max(8, r.top - 4 - h) : r.bottom + 4;
      panel.style.left = left + 'px';
      panel.style.top = top + 'px';
    }
    menus.forEach(function (menu) {
      menu.addEventListener('toggle', function () {
        if (!menu.open) return;
        menus.forEach(function (m) { if (m !== menu) m.open = false; });
        place(menu);
      });
    });
    function closeAll() { menus.forEach(function (m) { m.open = false; }); }
    document.addEventListener('click', function (e) { if (!e.target.closest('.num-menu')) closeAll(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAll(); });
    window.addEventListener('resize', closeAll);
    document.addEventListener('scroll', function (e) { if (!(e.target.closest && e.target.closest('.num-menu-panel'))) closeAll(); }, true);
  })();
  </script>
  <p class="hint">
    تخصیص به کاربر، کاربران اشتراکی، خدماتی بودن، درگاه ارسال، درگاه LBA، ارسال به URL، وضعیت، تاریخ انقضا و تعهدنامه‌ی هر شماره
    در صفحه‌ی «ویرایش شماره» تنظیم می‌شود (روی شماره یا دکمه‌ی «ویرایش» کلیک کنید).
    <?php if ($detailsReady): ?>از خطی که «فعال» نیست یا تاریخ انقضایش گذشته نمی‌توان ارسال کرد؛ دریافت پیامک روی آن ادامه دارد.<?php endif; ?>
  </p>
</div>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
