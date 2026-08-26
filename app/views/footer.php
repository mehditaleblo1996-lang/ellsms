    </main>
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
</body>
</html>
