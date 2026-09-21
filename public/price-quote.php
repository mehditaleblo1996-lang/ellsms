<?php
require_once __DIR__ . '/../app/bootstrap.php';
$me = require_admin();
$pageTitle = 'فاکتور / لیست قیمت پیامک';
$active = 'price_quote';

const PRICE_QUOTE_SETTING_KEY = 'price_quote_document';

function price_quote_default(): array {
    [$jy] = gregorian_to_jalali((int)date('Y'), (int)date('n'), (int)date('j'));
    return [
        'invoice_no'     => 'ELL-' . $jy . '-001',
        'date'           => jdate(date('Y-m-d H:i:s'), false),
        'validity_days'  => '7',
        'price_unit'     => 'تومان',
        'title'          => 'فاکتور / لیست قیمت خدمات پیامک',
        'subtitle'       => 'ELLSMS Smart SMS Panel',
        'rows' => [
            ['title' => 'پیامک حقوقی',              'desc' => 'مناسب فاکتور رسمی / شرکتی',            'unit' => 'هر پیامک', 'price' => '115'],
            ['title' => 'پیامک غیررسمی',             'desc' => 'مناسب فاکتور شخصی / غیررسمی',          'unit' => 'هر پیامک', 'price' => '110'],
            ['title' => 'پیامک بالک منطقه‌ای',        'desc' => 'ارسال هدفمند بر اساس مناطق دارای دیتا (برای مناطقی که دیتا نداریم)', 'unit' => 'هر پیامک', 'price' => '145'],
            ['title' => 'LBA',                        'desc' => 'ارسال مبتنی بر موقعیت مکانی',          'unit' => 'هر پیامک', 'price' => '400'],
        ],
        'notes' => [
            'تمام قیمت‌ها به ازای هر پیامک محاسبه می‌شوند.',
            'تعرفه‌ها ممکن است بر اساس حجم ارسال با شرایط همکاری تغییر کنند.',
            'برای دریافت مشاوره و خرید پنل، با واحد فروش ELLSMS تماس بگیرید.',
        ],
        'features' => ['پایداری و امنیت', 'پشتیبانی حرفه‌ای', 'ارسال سریع', 'پوشش سراسری'],
        'phone1'   => '۰۹۱۲۳۳۴۸۴۱۷',
        'phone2'   => '۰۹۱۹۷۶۸۴۰۶۳',
        'website'  => 'www.ellsms.ir',
        'email'    => 'sales@ellsms.ir',
        'footer_tagline' => 'با ELLSMS بیشتر دیده شوید ...',
    ];
}

function price_quote_load(): array {
    $raw = setting(PRICE_QUOTE_SETTING_KEY);
    if (!$raw) return price_quote_default();
    $data = json_decode($raw, true);
    if (!is_array($data)) return price_quote_default();
    return array_replace(price_quote_default(), $data);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';

    if ($do === 'reset') {
        set_setting(PRICE_QUOTE_SETTING_KEY, json_encode(price_quote_default(), JSON_UNESCAPED_UNICODE));
        audit((int)$me['id'], 'price_quote.reset');
        flash('info', 'قالب فاکتور به حالت پیش‌فرض بازگردانده شد.');
        redirect('/price-quote.php');
    }

    if ($do === 'save') {
        $rowsIn = is_array($_POST['rows'] ?? null) ? $_POST['rows'] : [];
        $rows = [];
        foreach ($rowsIn as $r) {
            $title = trim((string)($r['title'] ?? ''));
            if ($title === '') continue;
            $rows[] = [
                'title' => $title,
                'desc'  => trim((string)($r['desc'] ?? '')),
                'unit'  => trim((string)($r['unit'] ?? '')),
                'price' => trim((string)($r['price'] ?? '')),
            ];
        }
        $notesIn = is_array($_POST['notes'] ?? null) ? $_POST['notes'] : [];
        $notes = [];
        foreach ($notesIn as $n) {
            $n = trim((string)$n);
            if ($n !== '') $notes[] = $n;
        }
        $featuresIn = is_array($_POST['features'] ?? null) ? $_POST['features'] : [];
        $features = [];
        foreach ($featuresIn as $f) {
            $f = trim((string)$f);
            if ($f !== '') $features[] = $f;
        }

        $data = [
            'invoice_no'     => trim((string)($_POST['invoice_no'] ?? '')),
            'date'           => trim((string)($_POST['date'] ?? '')),
            'validity_days'  => trim((string)($_POST['validity_days'] ?? '')),
            'price_unit'     => trim((string)($_POST['price_unit'] ?? '')),
            'title'          => trim((string)($_POST['title'] ?? '')),
            'subtitle'       => trim((string)($_POST['subtitle'] ?? '')),
            'rows'           => $rows,
            'notes'          => $notes,
            'features'       => $features,
            'phone1'         => trim((string)($_POST['phone1'] ?? '')),
            'phone2'         => trim((string)($_POST['phone2'] ?? '')),
            'website'        => trim((string)($_POST['website'] ?? '')),
            'email'          => trim((string)($_POST['email'] ?? '')),
            'footer_tagline' => trim((string)($_POST['footer_tagline'] ?? '')),
        ];

        set_setting(PRICE_QUOTE_SETTING_KEY, json_encode($data, JSON_UNESCAPED_UNICODE));
        audit((int)$me['id'], 'price_quote.save');
        flash('success', 'فاکتور ذخیره شد.');
        redirect('/price-quote.php');
    }
}

$doc = price_quote_load();
require __DIR__ . '/../app/views/header.php';
?>
<style>
.pq-toolbar { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:16px; }
.pq-wrap { display:flex; justify-content:center; }
.pq-sheet {
  width: 210mm; max-width: 100%; min-height: 297mm; background:#fff; color:#1a2036;
  box-shadow: 0 10px 40px rgba(15,23,42,.15); border-radius: 14px; overflow:hidden;
  font-family: 'Vazirmatn', Tahoma, sans-serif; position:relative;
}
.pq-band {
  background: linear-gradient(120deg,#1e3fd6 0%,#5b3df0 55%,#8b2fe0 100%);
  color:#fff; padding: 28px 32px 22px; position:relative; overflow:hidden;
}
.pq-band::after {
  content:''; position:absolute; inset-inline-start:-40px; top:-60px; width:220px; height:220px;
  background: rgba(255,255,255,.08); border-radius: 40%; transform: rotate(20deg);
}
.pq-band-top { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; }
.pq-brand { display:flex; align-items:center; gap:10px; }
.pq-brand img { height:38px; width:auto; filter: brightness(0) invert(1); }
.pq-tagline { font-size:12px; opacity:.85; text-align:left; line-height:1.7; }
.pq-headline { margin-top:22px; }
.pq-headline input.pq-title-input {
  font-size:22px; font-weight:800; background:transparent; border:none; color:#fff; width:100%;
  padding:2px 0;
}
.pq-headline input.pq-subtitle-input {
  font-size:12px; background:transparent; border:none; color:#e6e9ff; opacity:.9; width:100%; padding:2px 0;
}
.pq-meta { display:flex; gap:14px; flex-wrap:wrap; padding: 16px 32px; background:#f6f8ff; border-bottom:1px solid #e5e8f5; }
.pq-meta-item { font-size:12px; color:#4a5170; display:flex; align-items:center; gap:6px; }
.pq-meta-item label { color:#8890ad; }
.pq-meta-item input { border:none; background:transparent; font-family:inherit; font-size:12px; color:#1a2036; width:120px; }
.pq-body { padding: 22px 32px; }
.pq-table { width:100%; border-collapse:collapse; border-radius:10px; overflow:hidden; border:1px solid #e5e8f5; }
.pq-table thead th { background: linear-gradient(120deg,#1e3fd6,#8b2fe0); color:#fff; font-size:12.5px; padding:10px 12px; text-align:right; }
.pq-table thead th.pq-col-num { text-align:center; width:44px; }
.pq-table thead th.pq-col-price { text-align:center; width:110px; }
.pq-table thead th.pq-col-unit { text-align:center; width:90px; }
.pq-table thead th.pq-col-actions { width:36px; }
.pq-table tbody td { border-top:1px solid #eef0fa; padding:8px 10px; font-size:12.5px; vertical-align:middle; }
.pq-table tbody tr:nth-child(even) { background:#fafbff; }
.pq-num { text-align:center; color:#8890ad; font-size:12px; }
.pq-table input, .pq-table textarea {
  width:100%; border:none; background:transparent; font-family:inherit; font-size:12.5px; color:#1a2036; resize:none;
}
.pq-table input.pq-price-input, .pq-table input.pq-unit-input { text-align:center; }
.pq-row-title { font-weight:700; }
.pq-row-desc { color:#6b7290; font-size:11.5px; margin-top:2px; }
.pq-rm-btn { border:none; background:#fdeaea; color:#c0392b; width:22px; height:22px; border-radius:6px; cursor:pointer; font-size:12px; line-height:1; }
.pq-notes { margin-top:18px; background:#f6f8ff; border:1px solid #e5e8f5; border-radius:10px; padding:14px 16px; }
.pq-notes h4 { margin:0 0 8px; font-size:13px; color:#3a3f66; }
.pq-note-line { display:flex; align-items:center; gap:6px; margin-bottom:5px; }
.pq-note-line input { flex:1; border:none; background:transparent; font-family:inherit; font-size:12px; color:#454a6b; }
.pq-features { display:flex; gap:10px; margin-top:18px; flex-wrap:wrap; }
.pq-feature { flex:1 1 130px; background:#f6f8ff; border:1px solid #e5e8f5; border-radius:10px; padding:10px; text-align:center; }
.pq-feature input { border:none; background:transparent; text-align:center; font-family:inherit; font-size:11.5px; width:100%; color:#3a3f66; }
.pq-footer { margin-top:20px; padding:16px 32px; background:#151a24; color:#d8deea; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px; }
.pq-footer-brand { display:flex; align-items:center; gap:8px; font-size:12px; }
.pq-footer-brand img { height:26px; filter: brightness(0) invert(1); }
.pq-footer-contact { display:flex; gap:16px; flex-wrap:wrap; font-size:11.5px; }
.pq-footer-contact input { border:none; background:transparent; color:#d8deea; font-family:inherit; font-size:11.5px; width:130px; }
.pq-footer-tagline input { border:none; background:transparent; color:#9aa2c7; font-family:inherit; font-size:11px; width:220px; text-align:left; }
.pq-add-row-wrap { margin-top:10px; text-align:center; }
@media print {
  .sidebar, .topbar, .site-footer, .pq-toolbar, .no-export, .flash, .menu-toggle { display:none !important; }
  body, html, .content, .main { background:#fff !important; margin:0; padding:0; }
  .pq-wrap { display:block; }
  .pq-sheet { box-shadow:none; border-radius:0; width:auto; margin:0; }
  @page { size:A4; margin:10mm; }
}
</style>

<div class="pq-toolbar no-export">
  <button type="button" class="btn btn-primary" id="pqSaveBtn">ذخیره‌ی تغییرات</button>
  <button type="button" class="btn btn-ghost" id="pqPdfBtn">📄 خروجی PDF (چاپ)</button>
  <button type="button" class="btn btn-ghost" id="pqImgBtn">🖼 خروجی تصویر (PNG)</button>
  <button type="button" class="btn btn-ghost" id="pqAddRowBtn">➕ افزودن ردیف</button>
  <button type="button" class="btn btn-ghost" id="pqAddNoteBtn">➕ افزودن نکته</button>
  <form method="post" onsubmit="return confirm('فاکتور به حالت پیش‌فرض بازگردانده شود؟ تغییرات ذخیره‌نشده از بین می‌رود.')" style="display:inline">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="reset">
    <button type="submit" class="btn btn-danger">بازگشت به پیش‌فرض</button>
  </form>
</div>
<p class="hint no-export">مقادیر را مستقیم روی خود فاکتور ویرایش کنید، سپس «ذخیره‌ی تغییرات» را بزنید تا برای بازدیدهای بعدی نگه‌داشته شود. دکمه‌های PDF و تصویر همیشه از روی همان محتوایی که الان می‌بینید خروجی می‌گیرند، حتی اگر هنوز ذخیره نکرده باشید.</p>

<form method="post" id="pqForm">
<?= csrf_field() ?>
<input type="hidden" name="do" value="save">

<div class="pq-wrap">
  <div class="pq-sheet" id="pqSheet">

    <div class="pq-band">
      <div class="pq-band-top">
        <div class="pq-brand">
          <img src="/assets/img/logo.png" alt="ELLSMS">
        </div>
        <div class="pq-tagline">پیامک<br>قدرت<br>ارتباط است</div>
      </div>
      <div class="pq-headline">
        <input class="pq-title-input" name="title" value="<?= e($doc['title']) ?>">
        <input class="pq-subtitle-input" name="subtitle" value="<?= e($doc['subtitle']) ?>">
      </div>
    </div>

    <div class="pq-meta">
      <div class="pq-meta-item"><label>شماره فاکتور:</label><input name="invoice_no" value="<?= e($doc['invoice_no']) ?>"></div>
      <div class="pq-meta-item"><label>تاریخ:</label><input name="date" value="<?= e($doc['date']) ?>"></div>
      <div class="pq-meta-item"><label>اعتبار قیمت:</label><input name="validity_days" style="width:40px" value="<?= e($doc['validity_days']) ?>"> روز</div>
      <div class="pq-meta-item"><label>واحد قیمت:</label><input name="price_unit" style="width:70px" value="<?= e($doc['price_unit']) ?>"></div>
    </div>

    <div class="pq-body">
      <table class="pq-table" id="pqTable">
        <thead>
          <tr>
            <th class="pq-col-num">ردیف</th>
            <th>شرح خدمت</th>
            <th class="pq-col-unit">واحد</th>
            <th class="pq-col-price">قیمت</th>
            <th class="pq-col-actions no-export"></th>
          </tr>
        </thead>
        <tbody id="pqRows">
          <?php foreach ($doc['rows'] as $i => $row): ?>
          <tr>
            <td class="pq-num"><?= to_persian_digits((string)($i + 1)) ?></td>
            <td>
              <input class="pq-row-title" name="rows[<?= $i ?>][title]" value="<?= e($row['title']) ?>" placeholder="نام خدمت">
              <input class="pq-row-desc" name="rows[<?= $i ?>][desc]" value="<?= e($row['desc']) ?>" placeholder="توضیح کوتاه">
            </td>
            <td><input class="pq-unit-input" name="rows[<?= $i ?>][unit]" value="<?= e($row['unit']) ?>"></td>
            <td><input class="pq-price-input" name="rows[<?= $i ?>][price]" value="<?= e($row['price']) ?>"></td>
            <td class="no-export"><button type="button" class="pq-rm-btn pq-rm-row" title="حذف ردیف">✕</button></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <div class="pq-notes">
        <h4>نکات مهم</h4>
        <div id="pqNotes">
          <?php foreach ($doc['notes'] as $note): ?>
          <div class="pq-note-line">
            <span class="no-export">●</span>
            <input name="notes[]" value="<?= e($note) ?>">
            <button type="button" class="pq-rm-btn pq-rm-note no-export" title="حذف نکته">✕</button>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="pq-features">
        <?php foreach ($doc['features'] as $f): ?>
        <div class="pq-feature"><input name="features[]" value="<?= e($f) ?>"></div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="pq-footer">
      <div class="pq-footer-brand">
        <img src="/assets/img/logo.png" alt="ELLSMS">
      </div>
      <div class="pq-footer-contact">
        <input name="phone1" value="<?= e($doc['phone1']) ?>">
        <input name="phone2" value="<?= e($doc['phone2']) ?>">
        <input name="website" value="<?= e($doc['website']) ?>">
        <input name="email" value="<?= e($doc['email']) ?>">
      </div>
      <div class="pq-footer-tagline">
        <input name="footer_tagline" value="<?= e($doc['footer_tagline']) ?>">
      </div>
    </div>

  </div>
</div>
</form>

<script>
(function () {
  var rowsBody   = document.getElementById('pqRows');
  var notesBody  = document.getElementById('pqNotes');
  var rowIndex   = <?= count($doc['rows']) ?>;
  var form       = document.getElementById('pqForm');
  var sheet      = document.getElementById('pqSheet');

  function renumberRows() {
    rowsBody.querySelectorAll('tr').forEach(function (tr, i) {
      tr.querySelector('.pq-num').textContent = toPersianDigits(String(i + 1));
    });
  }
  function toPersianDigits(s) {
    var en = '0123456789', fa = '۰۱۲۳۴۵۶۷۸۹';
    return s.replace(/[0-9]/g, function (d) { return fa[en.indexOf(d)]; });
  }

  document.getElementById('pqAddRowBtn').addEventListener('click', function () {
    var i = rowIndex++;
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td class="pq-num"></td>' +
      '<td>' +
        '<input class="pq-row-title" name="rows[' + i + '][title]" placeholder="نام خدمت">' +
        '<input class="pq-row-desc" name="rows[' + i + '][desc]" placeholder="توضیح کوتاه">' +
      '</td>' +
      '<td><input class="pq-unit-input" name="rows[' + i + '][unit]" value="هر پیامک"></td>' +
      '<td><input class="pq-price-input" name="rows[' + i + '][price]"></td>' +
      '<td class="no-export"><button type="button" class="pq-rm-btn pq-rm-row" title="حذف ردیف">✕</button></td>';
    rowsBody.appendChild(tr);
    renumberRows();
    tr.querySelector('.pq-row-title').focus();
  });

  rowsBody.addEventListener('click', function (e) {
    if (e.target.classList.contains('pq-rm-row')) {
      e.target.closest('tr').remove();
      renumberRows();
    }
  });

  document.getElementById('pqAddNoteBtn').addEventListener('click', function () {
    var div = document.createElement('div');
    div.className = 'pq-note-line';
    div.innerHTML =
      '<span class="no-export">●</span>' +
      '<input name="notes[]">' +
      '<button type="button" class="pq-rm-btn pq-rm-note no-export" title="حذف نکته">✕</button>';
    notesBody.appendChild(div);
    div.querySelector('input').focus();
  });

  notesBody.addEventListener('click', function (e) {
    if (e.target.classList.contains('pq-rm-note')) {
      e.target.closest('.pq-note-line').remove();
    }
  });

  document.getElementById('pqSaveBtn').addEventListener('click', function () {
    form.requestSubmit();
  });

  document.getElementById('pqPdfBtn').addEventListener('click', function () {
    window.print();
  });

  document.getElementById('pqImgBtn').addEventListener('click', function () {
    exportSheetAsPng(sheet, (document.querySelector('input[name="invoice_no"]').value || 'ellsms-price-quote') + '.png');
  });

  /* Rasterizes the invoice sheet to a PNG without any third-party library
     (no outbound network access is available to fetch one at build time):
     serialize a styled clone into an SVG <foreignObject>, draw it onto a
     canvas, then export via toDataURL. Falls back to a plain message if the
     browser blocks canvas export for cross-origin/security reasons. */
  function exportSheetAsPng(node, filename) {
    try {
      var rect = node.getBoundingClientRect();
      var scale = 2;
      var width = Math.ceil(rect.width);
      var height = Math.ceil(rect.height);

      var clone = node.cloneNode(true);
      clone.querySelectorAll('.no-export').forEach(function (el) { el.remove(); });
      clone.querySelectorAll('input, textarea').forEach(function (el) {
        var replacement = document.createElement('span');
        replacement.textContent = el.value;
        replacement.setAttribute('style', el.getAttribute('style') || '');
        replacement.className = el.className;
        var cs = window.getComputedStyle(el);
        replacement.style.display = 'block';
        replacement.style.font = cs.font;
        replacement.style.color = cs.color;
        replacement.style.textAlign = cs.textAlign;
        replacement.style.width = cs.width;
        replacement.style.whiteSpace = 'pre-wrap';
        el.parentNode.replaceChild(replacement, el);
      });
      clone.style.margin = '0';
      clone.style.boxShadow = 'none';

      var fontCss =
        "@font-face{font-family:'Vazirmatn';src:url('/assets/fonts/Vazirmatn-Regular.woff2') format('woff2');font-weight:400;}" +
        "@font-face{font-family:'Vazirmatn';src:url('/assets/fonts/Vazirmatn-Medium.woff2') format('woff2');font-weight:500;}" +
        "@font-face{font-family:'Vazirmatn';src:url('/assets/fonts/Vazirmatn-SemiBold.woff2') format('woff2');font-weight:600;}" +
        "@font-face{font-family:'Vazirmatn';src:url('/assets/fonts/Vazirmatn-Bold.woff2') format('woff2');font-weight:700;}" +
        "@font-face{font-family:'Vazirmatn';src:url('/assets/fonts/Vazirmatn-ExtraBold.woff2') format('woff2');font-weight:800;}";

      var serialized = new XMLSerializer().serializeToString(clone);
      var svgMarkup =
        '<svg xmlns="http://www.w3.org/2000/svg" width="' + width + '" height="' + height + '">' +
        '<foreignObject width="100%" height="100%">' +
        '<div xmlns="http://www.w3.org/1999/xhtml" style="direction:rtl">' +
        '<style>' + fontCss + '</style>' +
        serialized +
        '</div></foreignObject></svg>';

      var svgBlob = new Blob([svgMarkup], { type: 'image/svg+xml;charset=utf-8' });
      var url = URL.createObjectURL(svgBlob);
      var img = new Image();
      img.onload = function () {
        document.fonts.ready.finally(function () {
          var canvas = document.createElement('canvas');
          canvas.width = width * scale;
          canvas.height = height * scale;
          var ctx = canvas.getContext('2d');
          ctx.scale(scale, scale);
          ctx.fillStyle = '#ffffff';
          ctx.fillRect(0, 0, width, height);
          ctx.drawImage(img, 0, 0, width, height);
          URL.revokeObjectURL(url);
          try {
            var pngUrl = canvas.toDataURL('image/png');
            var a = document.createElement('a');
            a.href = pngUrl;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            a.remove();
          } catch (err) {
            alert('مرورگر اجازه‌ی خروجی گرفتن مستقیم تصویر را نداد. به‌جای آن از «خروجی PDF (چاپ)» استفاده کنید یا از صفحه اسکرین‌شات بگیرید.');
          }
        });
      };
      img.onerror = function () {
        URL.revokeObjectURL(url);
        alert('ساخت تصویر با خطا مواجه شد. از «خروجی PDF (چاپ)» استفاده کنید یا از صفحه اسکرین‌شات بگیرید.');
      };
      img.src = url;
    } catch (err) {
      alert('ساخت تصویر با خطا مواجه شد. از «خروجی PDF (چاپ)» استفاده کنید یا از صفحه اسکرین‌شات بگیرید.');
    }
  }
})();
</script>

<?php require __DIR__ . '/../app/views/footer.php'; ?>
