</main>
<?php require_once __DIR__ . '/../Legal.php'; ?>

<footer class="lp-footer">
  <img src="<?= e(asset_url('/assets/img/brand/logo.svg')) ?>" alt="داتیس رایان گستر ایرانیان" class="lp-footer-logo">
  <?php $footerPhones = contact_lines('contact_phone'); $footerAddress = contact_lines('contact_address')[0] ?? ''; ?>
  <?php if ($footerPhones || $footerAddress !== ''): ?>
    <ul class="lp-footer-contact">
      <?php foreach ($footerPhones as $line): $tel = contact_tel_href($line); ?>
        <li>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/></svg>
          <?php if ($tel !== ''): ?><a class="ltr" href="<?= e($tel) ?>"><?= e($line) ?></a><?php else: ?><span class="ltr"><?= e($line) ?></span><?php endif; ?>
        </li>
      <?php endforeach; ?>
      <?php if ($footerAddress !== ''): ?>
        <li>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg>
          <a href="/contact#map"><?= e($footerAddress) ?></a>
        </li>
      <?php endif; ?>
    </ul>
  <?php endif; ?>
  <?= legal_footer_links_html() ?>
  <div class="lp-footer-badges" style="display:flex;align-items:center;justify-content:center;gap:18px;flex-wrap:wrap;margin:14px 0 10px">
    <?= legal_trust_badges_html(110) ?>
  </div>
  <p><?php if (legal_provider_name() !== 'داتیس رایان گستر ایرانیان'): ?><?= e(legal_provider_name()) ?> · <?php endif; ?>داتیس رایان گستر ایرانیان نسخه <span class="ltr"><?= e(app_version()) ?></span> · پنل هوشمند پیامک</p>
</footer>

<script>
(function () {
  document.querySelectorAll('#lpVideoSlider .lp-slide').forEach(function (slide) {
    var video = slide.querySelector('video');
    if (!video) return;
    var showFallback = function () {
      if (!video.isConnected) return; // already swapped out
      var fallback = slide.getAttribute('data-fallback');
      var btn = slide.querySelector('.lp-video-mute');
      if (btn) btn.remove();
      if (fallback) {
        var img = document.createElement('img');
        img.src = fallback;
        img.alt = '';
        img.className = 'lp-video-fallback-img';
        video.replaceWith(img);
      } else {
        slide.classList.add('lp-slide--placeholder');
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
  document.querySelectorAll('#lpVideoSlider .lp-slide').forEach(function (slide) {
    var video = slide.querySelector('video');
    var btn   = slide.querySelector('.lp-video-mute');
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
  var slider = document.getElementById('lpVideoSlider');
  if (!slider) return;
  var slides = slider.querySelectorAll('.lp-slide');
  var dots   = slider.querySelectorAll('.lp-dot');
  var n      = slides.length;
  var idx    = 0;
  var timer  = null;

  function show(i) {
    idx = (i + n) % n;
    slides.forEach(function (s, j) {
      var active = j === idx;
      s.classList.toggle('is-active', active);
      var video = s.querySelector('video');
      if (!video) return;
      if (active) {
        video.currentTime = 0;
        video.play().catch(function () {});
      } else {
        video.pause();
      }
    });
    dots.forEach(function (d, j) { d.classList.toggle('is-active', j === idx); });
  }
  function resetTimer() {
    clearTimeout(timer);
    if (n > 1) timer = setTimeout(function () { show(idx + 1); resetTimer(); }, 7000);
  }

  var prev = slider.querySelector('.lp-slider-prev');
  var next = slider.querySelector('.lp-slider-next');
  if (prev) prev.addEventListener('click', function () { show(idx - 1); resetTimer(); });
  if (next) next.addEventListener('click', function () { show(idx + 1); resetTimer(); });
  dots.forEach(function (d, j) { d.addEventListener('click', function () { show(j); resetTimer(); }); });

  resetTimer();
})();

(function () {
  var toggle = document.getElementById('lpNavToggle');
  var links  = document.getElementById('lpNavLinks');
  if (!toggle || !links) return;
  function close() {
    links.classList.remove('is-open');
    toggle.setAttribute('aria-expanded', 'false');
  }
  function open() {
    links.classList.add('is-open');
    toggle.setAttribute('aria-expanded', 'true');
  }
  toggle.addEventListener('click', function () {
    if (links.classList.contains('is-open')) close(); else open();
  });
  links.querySelectorAll('a').forEach(function (a) { a.addEventListener('click', close); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
  document.addEventListener('click', function (e) {
    if (!links.classList.contains('is-open')) return;
    if (links.contains(e.target) || toggle.contains(e.target)) return;
    close();
  });
})();

(function () {
  var nav = document.querySelector('.lp-nav');
  if (!nav) return;
  var update = function () { nav.classList.toggle('is-scrolled', window.scrollY > 8); };
  window.addEventListener('scroll', update, { passive: true });
  update();
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
