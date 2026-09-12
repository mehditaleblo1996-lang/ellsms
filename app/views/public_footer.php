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
  document.querySelectorAll('.lp-video-card').forEach(function (card) {
    var video = card.querySelector('video');
    if (!video) return;
    var showFallback = function () {
      if (!video.isConnected) return; // already swapped out
      var fallback = card.getAttribute('data-fallback');
      var btn = card.querySelector('.lp-video-mute');
      if (btn) btn.remove();
      if (fallback) {
        var img = document.createElement('img');
        img.src = fallback;
        img.alt = '';
        img.className = 'lp-video-fallback-img';
        video.replaceWith(img);
      } else {
        card.classList.add('lp-video-card--placeholder');
        video.remove();
      }
    };
    // The <video> error event doesn't bubble, so it's caught here directly;
    // a source-level error (unsupported format) fires on the <source> instead.
    video.addEventListener('error', showFallback);
    video.querySelectorAll('source').forEach(function (s) {
      s.addEventListener('error', showFallback);
    });
  });
})();

(function () {
  document.querySelectorAll('.lp-video-card').forEach(function (card) {
    var video = card.querySelector('video');
    var btn   = card.querySelector('.lp-video-mute');
    if (!video || !btn) return;
    btn.addEventListener('click', function () {
      video.muted = !video.muted;
      if (!video.muted) video.play().catch(function () {});
      btn.textContent = video.muted ? '🔇' : '🔊';
      btn.setAttribute('aria-pressed', video.muted ? 'false' : 'true');
    });
  });
})();

(function () {
  var bar = document.getElementById('lpScrollBar');
  if (bar) {
    var update = function () {
      var doc = document.documentElement;
      var max = doc.scrollHeight - doc.clientHeight;
      var ratio = max > 0 ? Math.min(Math.max(window.scrollY / max, 0), 1) : 0;
      bar.style.transform = 'scaleX(' + ratio + ')';
    };
    window.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);
    update();
  }

  var reveals = document.querySelectorAll('.lp-reveal');
  if (reveals.length) {
    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add('is-visible');
            io.unobserve(entry.target);
          }
        });
      }, { threshold: 0.15 });
      reveals.forEach(function (el) { io.observe(el); });
    } else {
      reveals.forEach(function (el) { el.classList.add('is-visible'); });
    }
  }
})();
</script>
</body>
</html>
