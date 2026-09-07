</main>

<footer class="lp-footer">
  <img src="/assets/img/logo.png" alt="ELLSMS" class="lp-footer-logo">
  <div class="lp-footer-badges" style="display:flex;align-items:center;justify-content:center;gap:18px;flex-wrap:wrap;margin:14px 0 10px">
    <img src="/assets/img/footer-image-1.png" alt="نشان اعتماد" style="width:110px;max-width:30vw;height:auto;object-fit:contain">
    <img src="/assets/img/footer-image-2.png" alt="نشان اعتماد" style="width:110px;max-width:30vw;height:auto;object-fit:contain">
  </div>
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
