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

  function isImmediateSendForm(form) {
    if (!form) return false;
    var modeInput = form.querySelector('[name="mode"]:checked') || form.querySelector('[name="mode"]');
    var mode = modeInput ? modeInput.value : '';
    var path = window.location.pathname;
    return mode === 'direct' || mode === 'now' || (mode === '' && path === '/messages/send');
  }

  function routeImmediateToQueue(form) {
    if (isImmediateSendForm(form)) {
      form.setAttribute('action', '/send-queue.php');
      return true;
    }
    return false;
  }

  // Preview confirmation: enqueue immediately and let the worker perform the slow provider call.
  var confirmForm = document.getElementById('sendConfirmForm');
  if (confirmForm && routeImmediateToQueue(confirmForm)) {
    confirmForm.addEventListener('submit', function () {
      var submit = document.getElementById('sendConfirmSubmit');
      if (submit) submit.textContent = 'در حال ثبت در صف…';
    });
  }

  // "ارسال بدون پیش‌نمایش" must have the same non-blocking behavior. Only the explicit confirm
  // button is intercepted; preview, recurring, gradual and scheduling submissions stay untouched.
  document.querySelectorAll('button[name="do"][value="confirm"]').forEach(function (button) {
    if (confirmForm && button.form === confirmForm) return;
    button.addEventListener('click', function () {
      routeImmediateToQueue(button.form);
    });
  });
})();
</script>
</body>
</html>
