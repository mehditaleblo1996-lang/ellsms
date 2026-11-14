/* «مشتریان و همکاران» rails on /landing.php (docs/landing.md#customers).
 *
 * Without this script the rails still run on a plain CSS animation. With it (.is-live) the rails are
 * driven frame by frame so they can:
 *   - speed up while the page scrolls, and run backwards while it scrolls up, easing back afterwards;
 *   - glide to a stop under the pointer instead of freezing, and start again smoothly;
 *   - be dragged sideways (mouse or touch) and keep the throw's momentum;
 *   - grow the tiles near the middle and shrink/fade the ones near the edges.
 * It only runs while the section is on screen and the tab is visible, and does nothing at all for
 * prefers-reduced-motion (the CSS then shows one still list).
 */
(function () {
  'use strict';
  var root = document.querySelector('[data-lp-marquee]');
  if (!root || !window.requestAnimationFrame) return;
  if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  var BASE = [38, -32];          // px per second for each rail; + moves right, - moves left
  var rows = Array.prototype.map.call(root.querySelectorAll('.lp-marquee-row'), function (row, i) {
    return {
      el: row,
      track: row.querySelector('.lp-marquee-track'),
      items: Array.prototype.slice.call(row.querySelectorAll('.lp-bank')),
      base: BASE[i % BASE.length],
      x: 0, setW: 1, rowW: 1, geo: [],
      hover: 0, hoverTarget: 1,  // 1 = running, 0 = stopped under the pointer
      drag: null, fling: 0
    };
  });
  if (!rows.length) return;
  root.classList.add('is-live');

  function measure() {
    rows.forEach(function (r) {
      var set = r.track.firstElementChild;
      r.setW = set.offsetWidth || 1;
      r.rowW = r.el.clientWidth || 1;
      r.geo = r.items.map(function (it) { return { l: it.offsetLeft, w: it.offsetWidth }; });
      wrap(r);
    });
  }

  function wrap(r) {
    while (r.x > 0) r.x -= r.setW;
    while (r.x <= -r.setW) r.x += r.setW;
  }

  // Scroll: velocity in px/s, turned into an extra speed multiplier that decays back to zero.
  var boost = 0, lastY = window.scrollY, lastT = performance.now();
  window.addEventListener('scroll', function () {
    var now = performance.now(), dt = Math.max(16, now - lastT), dy = window.scrollY - lastY;
    var v = dy / dt * 1000;
    var target = Math.max(-7, Math.min(7, v / 260));
    if (Math.abs(target) > Math.abs(boost) || (target * boost < 0)) boost = target;
    lastY = window.scrollY; lastT = now;
  }, { passive: true });

  rows.forEach(function (r) {
    r.el.addEventListener('pointerenter', function (e) { if (e.pointerType === 'mouse') r.hoverTarget = 0; });
    r.el.addEventListener('pointerleave', function () { if (!r.drag) r.hoverTarget = 1; });
    r.el.addEventListener('focusin', function () { r.hoverTarget = 0; });
    r.el.addEventListener('focusout', function () { r.hoverTarget = 1; });

    r.el.addEventListener('pointerdown', function (e) {
      if (e.button !== 0) return;
      r.drag = { id: e.pointerId, x: e.clientX, y: e.clientY, last: e.clientX, t: performance.now(), v: 0, moved: false };
      r.fling = 0;
    });
    r.el.addEventListener('pointermove', function (e) {
      var d = r.drag;
      if (!d || d.id !== e.pointerId) return;
      if (!d.moved) {
        if (Math.abs(e.clientX - d.x) < 6) return;
        if (Math.abs(e.clientY - d.y) > Math.abs(e.clientX - d.x)) { r.drag = null; return; } // a vertical swipe scrolls the page
        d.moved = true;
        r.el.classList.add('is-dragging');
        try { r.el.setPointerCapture(e.pointerId); } catch (_) {}
      }
      var now = performance.now(), dx = e.clientX - d.last;
      r.x += dx; wrap(r);
      d.v = d.v * 0.6 + (dx / Math.max(8, now - d.t) * 1000) * 0.4;
      d.last = e.clientX; d.t = now;
    });
    function end(e) {
      var d = r.drag;
      if (!d || d.id !== e.pointerId) return;
      if (d.moved) r.fling = Math.max(-2500, Math.min(2500, d.v));
      r.drag = null;
      r.el.classList.remove('is-dragging');
      if (e.pointerType !== 'mouse') r.hoverTarget = 1;
    }
    r.el.addEventListener('pointerup', end);
    r.el.addEventListener('pointercancel', end);
    // A drag must not follow a link or select text.
    r.el.addEventListener('click', function (e) { if (r.el.classList.contains('is-dragging')) e.preventDefault(); }, true);
    r.el.addEventListener('dragstart', function (e) { e.preventDefault(); });
  });

  var running = false, visible = false, prev = 0;
  function frame(now) {
    if (!running) return;
    var dt = Math.min(0.05, (now - prev) / 1000 || 0);
    prev = now;
    boost *= Math.pow(0.04, dt);           // scroll boost fades out in about a second
    rows.forEach(function (r) {
      r.hover += (r.hoverTarget - r.hover) * (1 - Math.pow(0.002, dt)); // ease into/out of a hover stop
      if (!r.drag) {
        // Scrolling down pushes each rail faster its own way; scrolling up runs it backwards.
        var speed = r.base * r.hover + r.base * boost;
        r.x += (speed + r.fling) * dt;
        r.fling *= Math.pow(0.03, dt);
        if (Math.abs(r.fling) < 2) r.fling = 0;
        wrap(r);
      }
      r.track.style.transform = 'translate3d(' + r.x.toFixed(2) + 'px,0,0)';
      // Tiles near the middle of the rail are full size; towards the ends they shrink and fade.
      var half = r.rowW / 2;
      for (var i = 0; i < r.items.length; i++) {
        var g = r.geo[i], c = r.x + g.l + g.w / 2;
        if (c < -g.w || c > r.rowW + g.w) continue;
        var d = Math.min(1, Math.abs(c - half) / half);
        var k = 1 - d * d;
        r.items[i].style.setProperty('--s', (0.86 + 0.16 * k).toFixed(3));
        r.items[i].style.setProperty('--o', (0.45 + 0.55 * k).toFixed(3));
      }
    });
    requestAnimationFrame(frame);
  }
  function start() {
    if (running || !visible || document.hidden) return;
    running = true; prev = performance.now();
    requestAnimationFrame(frame);
  }
  function stop() { running = false; }

  measure();
  window.addEventListener('resize', function () { measure(); rows.forEach(wrap); });
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(measure);
  window.addEventListener('load', measure);
  document.addEventListener('visibilitychange', function () { document.hidden ? stop() : start(); });
  if ('IntersectionObserver' in window) {
    new IntersectionObserver(function (entries) {
      visible = entries[0].isIntersecting;
      visible ? start() : stop();
    }, { rootMargin: '100px 0px' }).observe(root);
  } else {
    visible = true; start();
  }

  // Pointer spotlight on the hovered tile.
  root.addEventListener('pointermove', function (e) {
    var t = e.target.closest && e.target.closest('.lp-bank');
    if (!t) return;
    var b = t.getBoundingClientRect();
    t.style.setProperty('--mx', (e.clientX - b.left) + 'px');
    t.style.setProperty('--my', (e.clientY - b.top) + 'px');
  });
})();
