<?php
/**
 * One KYC document tile — shared by public/profile.php (self-service) and public/kyc-review.php
 * (admin), so the two screens can never visually drift apart for the same underlying data
 * (docs/profile-kyc.md).
 *
 * Expects, all already-escaped-at-render (nothing here trusts raw input):
 *   $tileType        string   the document_type key (e.g. 'national_card')
 *   $tileLabel       string   its Persian label
 *   $tileDocument    ?array   the active ellsms_profile_documents row for this type, or null
 *   $tileEditable    bool     whether an upload/replace control should render
 *   $tileOwnerField  string   'user' | 'organization' — value of the hidden "owner" field on upload
 *   $tileReviewBadge array    review_status => badge css class map
 *
 * Renders NOTHING sensitive: no storage path, no raw filename beyond what profile_document_type_label
 * already produces. The thumbnail <img>, when shown, points at the SAME authenticated
 * public/profile-document.php endpoint the "مشاهده" link already used — no new download surface.
 */
$tileDocument ??= null;
$tileHasFile = $tileDocument !== null;
$tileMime = $tileHasFile ? (string)$tileDocument['mime_type'] : '';
$tileIsImage = str_starts_with($tileMime, 'image/');
$tileReviewStatus = $tileHasFile ? (string)$tileDocument['review_status'] : null;
?>
<div class="doc-tile">
  <div class="doc-tile-thumb">
    <?php if ($tileHasFile && $tileIsImage): ?>
      <img src="/profile-document.php?id=<?= (int)$tileDocument['id'] ?>" alt="<?= e($tileLabel) ?>" loading="lazy">
    <?php elseif ($tileHasFile): ?>
      <span class="doc-tile-icon">📄</span>
    <?php else: ?>
      <span class="doc-tile-icon">🗂</span>
    <?php endif; ?>
  </div>
  <div class="doc-tile-body">
    <div class="doc-tile-title"><?= e($tileLabel) ?></div>
    <div>
      <?php if ($tileHasFile): ?>
        <span class="badge <?= e($tileReviewBadge[$tileReviewStatus] ?? 'badge-pending') ?>"><?= e(kyc_document_review_status_label($tileReviewStatus)) ?></span>
        <span class="hint" style="display:inline"><?= e(jdate((string)$tileDocument['created_at'])) ?></span>
      <?php else: ?>
        <span class="badge badge-off">بارگذاری نشده</span>
      <?php endif; ?>
    </div>
    <?php if ($tileHasFile && $tileReviewStatus !== 'pending' && (string)$tileDocument['review_note'] !== ''): ?>
      <div class="doc-tile-note"><?= e((string)$tileDocument['review_note']) ?></div>
    <?php endif; ?>
    <?php if ($tileHasFile): ?>
      <a class="btn btn-sm" href="/profile-document.php?id=<?= (int)$tileDocument['id'] ?>" target="_blank" rel="noopener">مشاهده فایل</a>
    <?php endif; ?>
  </div>
  <?php if ($tileEditable): ?>
    <div class="doc-tile-foot">
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="document_upload">
        <input type="hidden" name="owner" value="<?= e($tileOwnerField) ?>">
        <input type="hidden" name="document_type" value="<?= e($tileType) ?>">
        <span class="file-picker">
          <label class="file-picker-btn">
            انتخاب فایل
            <input type="file" name="document" accept=".jpg,.jpeg,.png,.webp,.pdf" required
                   onchange="var n=this.closest('.file-picker').querySelector('.file-picker-name'); n.textContent = this.files[0] ? this.files[0].name : '';">
          </label>
          <span class="file-picker-name"></span>
        </span>
        <button class="btn btn-sm btn-primary"><?= $tileHasFile ? 'جایگزینی' : 'بارگذاری' ?></button>
      </form>
    </div>
  <?php endif; ?>
</div>
