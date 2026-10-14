<?php require_once __DIR__ . '/../Legal.php'; ?>
    </main>
    <footer class="site-footer" style="margin:24px 24px 18px;padding:18px 22px;border-radius:18px;background:#151a24;color:#d8deea;display:flex;align-items:center;justify-content:space-between;gap:24px;flex-wrap:wrap;box-shadow:0 10px 30px rgba(15,23,42,.08)">
      <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;font-size:13px;line-height:1.9">
        <strong style="color:#fff">ELLSMS</strong>
        <span style="opacity:.78">سامانه مدیریت و ارسال پیامک</span>
      </div>
      <?= legal_footer_links_html() ?>
      <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
        <?= legal_trust_badges_html(92) ?>
      </div>
    </footer>
  </div>
</div>
<script>
(function () {
  var toggle = document.getElementById('menuToggle');
  var sidebar = document.getElementById('sidebar');
  var backdrop = document.getElementById('sidebarBackdrop');
  if (toggle && sidebar && backdrop) {
    function open() {
      sidebar.classList.add('is-open');
      backdrop.classList.add('is-open');
      toggle.setAttribute('aria-expanded', 'true');
    }
    function close() {
      sidebar.classList.remove('is-open');
      backdrop.classList.remove('is-open');
      toggle.setAttribute('aria-expanded', 'false');
    }

    toggle.addEventListener('click', function () {
      sidebar.classList.contains('is-open') ? close() : open();
    });
    backdrop.addEventListener('click', close);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') close();
    });
    sidebar.querySelectorAll('a').forEach(function (a) {
      a.addEventListener('click', close);
    });
  }

  // Keep account security discoverable for every signed-in user without adding another long sidebar
  // item. The shortcut sits beside notifications/name and goes to the clean physical route.
  var userChip = document.querySelector('.user-chip');
  if (userChip && !document.getElementById('accountSecurityShortcut')) {
    var security = document.createElement('a');
    security.id = 'accountSecurityShortcut';
    security.className = 'btn btn-ghost';
    security.href = '/account/security/';
    security.title = 'امنیت حساب و MFA';
    security.setAttribute('aria-label', 'امنیت حساب و MFA');
    security.textContent = '🛡️';
    userChip.parentNode.insertBefore(security, userChip);
  }

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
