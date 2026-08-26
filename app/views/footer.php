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

  // Chrome can visually decompose/repaint the panel badly when a fixed backdrop-filter modal is
  // removed in the same frame that a synchronous send starts navigating. The shared cost-preview
  // script intentionally disables the submit button, but it also hides the modal immediately.
  // Re-open it in a lightweight, non-blurred "sending" state until the HTTP response/redirect
  // arrives. This keeps every send page visually stable while preserving the existing synchronous
  // gateway flow (no queue semantics and no change to what is actually sent).
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
