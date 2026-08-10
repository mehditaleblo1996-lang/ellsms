</main>

<footer class="lp-footer">
  <img src="/assets/img/logo.png" alt="ELLSMS" class="lp-footer-logo">
  <p>ELLSMS نسخه <span class="ltr"><?= e(app_version()) ?></span> · پنل هوشمند پیامک</p>
</footer>

<script>
(function () {
  var slider = document.getElementById('lpSlider');
  if (!slider) return;
  var slides = slider.querySelectorAll('.lp-slide');
  var dots   = slider.querySelectorAll('.lp-dot');
  var n      = slides.length;
  var idx    = 0;
  var timer  = null;

  function show(i) {
    idx = (i + n) % n;
    slides.forEach(function (s, j) { s.classList.toggle('is-active', j === idx); });
    dots.forEach(function (d, j) { d.classList.toggle('is-active', j === idx); });
  }
  function resetTimer() {
    clearTimeout(timer);
    if (n > 1) timer = setTimeout(function () { show(idx + 1); resetTimer(); }, 5000);
  }

  var prev = slider.querySelector('.lp-slider-prev');
  var next = slider.querySelector('.lp-slider-next');
  if (prev) prev.addEventListener('click', function () { show(idx - 1); resetTimer(); });
  if (next) next.addEventListener('click', function () { show(idx + 1); resetTimer(); });
  dots.forEach(function (d, j) { d.addEventListener('click', function () { show(j); resetTimer(); }); });

  resetTimer();
})();
</script>
</body>
</html>
