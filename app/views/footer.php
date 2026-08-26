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

  // Cost-preview confirmation used to POST back to send.php/new-send.php, where the provider HTTP
  // call ran synchronously and could leave the modal showing "در حال ارسال…" until gateway timeout.
  // For immediate sends only, post the already-CSRF-protected confirmation to the lightweight queue
  // endpoint. Recurring/gradual flows keep their original page action and semantics.
  var confirmForm = document.getElementById('sendConfirmForm');
  if (confirmForm) {
    var modeInput = confirmForm.querySelector('input[name="mode"]');
    var mode = modeInput ? modeInput.value : '';
    var path = window.location.pathname;
    var immediate = mode === 'direct' || mode === 'now' || (mode === '' && path === '/messages/send');
    if (immediate) {
      confirmForm.setAttribute('action', '/send-queue.php');
      confirmForm.addEventListener('submit', function () {
        var submit = document.getElementById('sendConfirmSubmit');
        if (submit) submit.textContent = 'در حال ثبت در صف…';
      });
    }
  }
})();
</script>
</body>
</html>
