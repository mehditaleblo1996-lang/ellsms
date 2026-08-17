<?php
/**
 * ELLSMS — platform-admin KYC review panel (docs/profile-kyc.md §Admin review flow).
 *
 * PLATFORM-ADMIN-ONLY, same as every other identity-adjacent admin surface in this codebase
 * (public/users.php's kyc_save, public/kyc-photo.php) — require_admin() at the top, never delegated
 * to an organization role. Permissions::KYC_VIEW/KYC_MANAGE stay reserved (app/rbac.php), so this is
 * not a gap: it is the documented, existing model, continued.
 *
 * Documents are never rendered inline here — every preview/download goes through the existing
 * authorizing endpoint, public/profile-document.php, which already grants a platform admin (outside
 * impersonation) access to any document.
 */
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_admin();
$pageTitle = 'بررسی احراز هویت (KYC)';
$active = 'kyc_review';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    $organizationId = (int)($_POST['organization_id'] ?? 0);

    if ($do === 'transition') {
        $toStatus = (string)($_POST['to_status'] ?? '');
        $note = (string)($_POST['review_note'] ?? '');
        $result = kyc_transition($organizationId, $toStatus, (int)$me['id'], $note);
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'وضعیت درخواست به‌روزرسانی شد.' : 'این تغییر وضعیت مجاز نیست.');
    } elseif ($do === 'document_review') {
        $documentId = (int)($_POST['document_id'] ?? 0);
        $reviewStatus = (string)($_POST['review_status'] ?? '');
        $note = (string)($_POST['review_note'] ?? '');
        // Cross-tenant guard: the document must actually belong to the organization this admin
        // screen is currently open on, so a crafted document_id from a DIFFERENT organization's
        // review page cannot be replayed here.
        $document = profile_document_find($documentId);
        $belongsToOrg = $document !== null && profile_document_belongs_to($document, 'organization', $organizationId);
        $belongsToOwner = $document !== null && profile_document_belongs_to($document, 'user', (int)($_POST['owner_user_id'] ?? 0));
        if ($document === null || (!$belongsToOrg && !$belongsToOwner)) {
            flash('error', 'مدرک یافت نشد.');
        } else {
            $result = kyc_document_review($documentId, $reviewStatus, (int)$me['id'], $note);
            flash($result['ok'] ? 'success' : 'error', $result['ok'] ? 'وضعیت مدرک به‌روزرسانی شد.' : 'ثبت وضعیت ممکن نشد.');
        }
    }
    redirect('/kyc-review.php' . ($organizationId > 0 ? '?id=' . $organizationId : ''));
}

$detailId = (int)($_GET['id'] ?? 0);

require __DIR__ . '/../app/views/header.php';

if ($detailId > 0):
    $orgProfile = profile_organization_get($detailId);
    $orgRow = db()->prepare('SELECT * FROM ellsms_organizations WHERE id = ?');
    $orgRow->execute([$detailId]);
    $organization = $orgRow->fetch();
    if (!$organization):
        echo '<div class="card"><p class="empty">سازمان یافت نشد.</p></div>';
    else:
        $address = profile_address_get($detailId);
        $kycRequest = kyc_request_get($detailId);
        $organizationDocuments = profile_documents_list(['organization' => $detailId]);
        // The individual-account owner's documents live under the organization's OWNER user, so the
        // reviewer can see personal identity documents for an individual-account organization too.
        $ownerRow = db()->prepare("SELECT user_id FROM ellsms_organization_memberships WHERE organization_id = ? AND role = 'owner' AND status = 'active' LIMIT 1");
        $ownerRow->execute([$detailId]);
        $ownerUserId = (int)($ownerRow->fetchColumn() ?: 0);
        $ownerProfile = $ownerUserId > 0 ? profile_user_get($ownerUserId) : [];
        $ownerDocuments = $ownerUserId > 0 ? profile_documents_list(['user' => $ownerUserId]) : [];
        $accountType = $orgProfile['account_type'] ?? 'individual';
        $reviewBadgeClass = ['pending' => 'badge-pending', 'approved' => 'badge-ok', 'rejected' => 'badge-off'];
        ?>
        <p><a href="/kyc-review.php">&rarr; بازگشت به فهرست</a></p>
        <div class="card">
          <h2><?= e((string)$organization['name']) ?>
            <span class="badge <?= e(['draft'=>'badge-off','rejected'=>'badge-off','submitted'=>'badge-pending','under_review'=>'badge-pending','needs_correction'=>'badge-pending','approved'=>'badge-ok'][$kycRequest['status']] ?? 'badge-off') ?>" style="margin-inline-start:8px">
              <?= e(kyc_status_label((string)$kycRequest['status'])) ?>
            </span>
          </h2>
          <div class="table-wrap">
          <table>
            <tr><th>نوع حساب</th><td><?= e(profile_account_type_label($accountType)) ?></td></tr>
            <tr><th>نام حقوقی</th><td><?= e((string)$orgProfile['legal_name']) ?: '—' ?></td></tr>
            <tr><th>ارسال درخواست</th><td><?= $kycRequest['submitted_at'] ? e(jdate((string)$kycRequest['submitted_at'])) : '—' ?></td></tr>
            <tr><th>شروع بررسی</th><td><?= $kycRequest['review_started_at'] ? e(jdate((string)$kycRequest['review_started_at'])) : '—' ?></td></tr>
            <tr><th>پایان بررسی</th><td><?= $kycRequest['reviewed_at'] ? e(jdate((string)$kycRequest['reviewed_at'])) : '—' ?></td></tr>
            <tr><th>یادداشت بازبین</th><td><?= e((string)$kycRequest['review_note']) ?: '—' ?></td></tr>
          </table>
          </div>

          <?php $nextStates = KYC_TRANSITIONS[$kycRequest['status']] ?? []; ?>
          <?php if ($nextStates): ?>
          <form method="post" style="margin-top:12px">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="transition">
            <input type="hidden" name="organization_id" value="<?= (int)$detailId ?>">
            <div class="grid grid-2">
              <label>وضعیت جدید
                <select name="to_status">
                  <?php foreach ($nextStates as $status): ?>
                    <option value="<?= e($status) ?>"><?= e(kyc_status_label($status)) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>یادداشت بازبینی (در صورت رد یا نیاز به اصلاح) <input type="text" name="review_note" maxlength="1000"></label>
            </div>
            <button class="btn btn-primary btn-sm">ثبت تغییر وضعیت</button>
          </form>
          <?php else: ?>
            <p class="hint">این درخواست در وضعیت نهایی است و تغییر بیشتری مجاز نیست.</p>
          <?php endif; ?>
        </div>

        <div class="card">
          <h2>اطلاعات پروفایل</h2>
          <?php if ($accountType === 'legal'): ?>
            <div class="table-wrap"><table>
              <tr><th>مدیرعامل/نماینده</th><td><?= e((string)$orgProfile['ceo_name']) ?: '—' ?></td></tr>
              <tr><th>کد ملی نماینده</th><td class="ltr"><?= e((string)$orgProfile['ceo_national_code']) ?: '—' ?></td></tr>
              <tr><th>موبایل نماینده</th><td class="ltr"><?= e((string)$orgProfile['ceo_mobile']) ?: '—' ?></td></tr>
              <tr><th>شناسه ملی شرکت</th><td class="ltr"><?= e((string)$orgProfile['national_id']) ?: '—' ?></td></tr>
              <tr><th>شماره ثبت</th><td class="ltr"><?= e((string)$orgProfile['registration_number']) ?: '—' ?></td></tr>
              <tr><th>آدرس</th><td><?= e(trim((string)$address['province'] . ' ' . (string)$address['city'] . ' ' . (string)$address['street'])) ?: '—' ?></td></tr>
              <tr><th>کد پستی</th><td class="ltr"><?= e((string)$address['postal_code']) ?: '—' ?></td></tr>
            </table></div>
          <?php else: ?>
            <div class="table-wrap"><table>
              <tr><th>نام پدر</th><td><?= e((string)($ownerProfile['father_name'] ?? '')) ?: '—' ?></td></tr>
              <tr><th>کد ملی</th><td class="ltr"><?= e((string)($ownerProfile['national_code'] ?? '')) ?: '—' ?></td></tr>
              <tr><th>شماره شناسنامه</th><td class="ltr"><?= e((string)($ownerProfile['birth_certificate_no'] ?? '')) ?: '—' ?></td></tr>
              <tr><th>آدرس</th><td><?= e(trim((string)$address['province'] . ' ' . (string)$address['city'] . ' ' . (string)$address['street'])) ?: '—' ?></td></tr>
              <tr><th>کد پستی</th><td class="ltr"><?= e((string)$address['postal_code']) ?: '—' ?></td></tr>
            </table></div>
          <?php endif; ?>
        </div>

        <?php
        $docSections = [
            ['title' => 'مدارک سازمان', 'documents' => $organizationDocuments, 'owner_user_id' => 0],
            ['title' => 'مدارک فردی', 'documents' => $ownerDocuments, 'owner_user_id' => $ownerUserId],
        ];
        foreach ($docSections as $section):
            if (!$section['documents']) continue;
        ?>
        <div class="card">
          <h2><?= e($section['title']) ?></h2>
          <div class="table-wrap">
          <table>
            <tr><th>نوع مدرک</th><th>وضعیت فایل</th><th>بررسی</th><th>تاریخ بارگذاری</th><th></th></tr>
            <?php foreach ($section['documents'] as $document): ?>
              <tr>
                <td><?= e(profile_document_type_label((string)$document['document_type'])) ?></td>
                <td><span class="badge badge-<?= $document['status'] === 'active' ? 'ok' : 'off' ?>"><?= $document['status'] === 'active' ? 'فعال' : 'بایگانی' ?></span></td>
                <td><span class="badge <?= e($reviewBadgeClass[$document['review_status']] ?? 'badge-pending') ?>"><?= e(kyc_document_review_status_label((string)$document['review_status'])) ?></span></td>
                <td><?= e(jdate((string)$document['created_at'])) ?></td>
                <td>
                  <div class="toolbar" style="margin:0">
                    <a class="btn btn-sm" href="/profile-document.php?id=<?= (int)$document['id'] ?>" target="_blank" rel="noopener">مشاهده</a>
                    <?php if ($document['status'] === 'active'): ?>
                    <form method="post" style="margin:0;display:inline-flex;gap:6px;align-items:center">
                      <?= csrf_field() ?>
                      <input type="hidden" name="do" value="document_review">
                      <input type="hidden" name="organization_id" value="<?= (int)$detailId ?>">
                      <input type="hidden" name="document_id" value="<?= (int)$document['id'] ?>">
                      <input type="hidden" name="owner_user_id" value="<?= (int)$section['owner_user_id'] ?>">
                      <input type="text" name="review_note" placeholder="یادداشت (اختیاری)" style="width:140px">
                      <button class="btn btn-sm" name="review_status" value="approved">تأیید</button>
                      <button class="btn btn-sm" name="review_status" value="rejected">رد</button>
                    </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </table>
          </div>
        </div>
        <?php endforeach; ?>
    <?php endif;
else:
    $statusFilter = (string)($_GET['status'] ?? '');
    $search = (string)($_GET['q'] ?? '');
    $requests = kyc_requests_list($statusFilter !== '' ? $statusFilter : null, $search);
    ?>
    <div class="card">
      <form method="get" class="toolbar">
        <label>وضعیت
          <select name="status">
            <option value="">همه</option>
            <?php foreach (KYC_STATUSES as $value => $label): ?>
              <option value="<?= e($value) ?>"<?= $statusFilter === $value ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>جستجو (نام سازمان/شرکت) <input type="text" name="q" value="<?= e($search) ?>"></label>
        <button class="btn btn-sm">فیلتر</button>
      </form>
    </div>
    <div class="card">
      <div class="table-wrap">
      <table>
        <tr><th>سازمان</th><th>نوع حساب</th><th>نام حقوقی</th><th>وضعیت</th><th>ارسال</th><th></th></tr>
        <?php foreach ($requests as $r): ?>
          <tr>
            <td><?= e((string)$r['organization_name']) ?></td>
            <td><?= e(profile_account_type_label((string)($r['account_type'] ?? 'individual'))) ?></td>
            <td><?= e((string)($r['legal_name'] ?? '')) ?: '—' ?></td>
            <td><span class="badge <?= e(['draft'=>'badge-off','rejected'=>'badge-off','submitted'=>'badge-pending','under_review'=>'badge-pending','needs_correction'=>'badge-pending','approved'=>'badge-ok'][$r['status']] ?? 'badge-off') ?>"><?= e(kyc_status_label((string)$r['status'])) ?></span></td>
            <td><?= $r['submitted_at'] ? e(jdate((string)$r['submitted_at'])) : '—' ?></td>
            <td><a class="btn btn-sm" href="/kyc-review.php?id=<?= (int)$r['organization_id'] ?>">مشاهده</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$requests): ?><tr><td colspan="6" class="empty">درخواستی یافت نشد.</td></tr><?php endif; ?>
      </table>
      </div>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../app/views/footer.php'; ?>
