<?php require_once __DIR__ . '/../Legal.php'; ?>
    </main>
    <footer class="site-footer panel-footer">
      <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;font-size:13px;line-height:1.9">
        <strong class="footer-brand">ELLSMS</strong>
        <span style="opacity:.78">سامانه مدیریت و ارسال پیامک</span>
      </div>
      <?= legal_footer_links_html() ?>
      <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
        <?= legal_trust_badges_html(52) ?>
      </div>
    </footer>
  </div>
</div>
<script>
(function () {
  // Chrome can visually decompose/repaint the panel badly when a fixed backdrop-filter modal is
  // removed in the same frame that a synchronous send starts navigating. Re-open it in a
  // lightweight, non-blurred "sending" state until the HTTP response/redirect arrives.
  var confirmForm = document.getElementById('sendConfirmForm');
  if (confirmForm) {
    confirmForm.addEventListener('submit', function () {
      var overlay = document.getElementById('sendConfirmOverlay');
      var submit = document.getElementById('sendConfirmSubmit');
      if (!overlay) return;

      overlay.classList.add('is-open', 'is-submitting');
      overlay.setAttribute('aria-busy', 'true');
      if (submit) {
        submit.disabled = true;
        submit.textContent = 'در حال ارسال…';
      }
    });
  }
})();
</script>
<script src="<?= e(asset_url('/assets/js/forms.js')) ?>"></script>
</body>
</html>
