    </main>
  </div>
</div>
<script>
(function () {
  var toggle = document.getElementById('menuToggle');
  var sidebar = document.getElementById('sidebar');
  var backdrop = document.getElementById('sidebarBackdrop');
  if (!toggle || !sidebar || !backdrop) return;

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
})();
</script>
</body>
</html>
