/*
 * ELLSMS — login page.
 *
 *  1. Show / hide the password (the button stays hidden without script, so the form never depends on it).
 *  2. The feature slider on the stage (app/views/partials/login_slider.php). The active progress tick
 *     fills over one loop in CSS and its animationend moves to the next slide, so pausing the
 *     animation (pointer or focus on the slider, a hidden tab, the pause button) also pauses the timer.
 *     With reduced motion nothing advances by itself; the buttons and tabs still work.
 */
(function () {
  'use strict';

  var FA_DIGITS = '۰۱۲۳۴۵۶۷۸۹';
  function fa(n) { return String(n).replace(/\d/g, function (d) { return FA_DIGITS[d]; }); }

  // 1. Password visibility.
  var pass = document.getElementById('authPassword');
  var reveal = document.getElementById('authReveal');
  if (pass && reveal) {
    reveal.hidden = false;
    reveal.addEventListener('click', function () {
      var show = pass.type === 'password';
      pass.type = show ? 'text' : 'password';
      reveal.textContent = show ? 'پنهان' : 'نمایش';
      reveal.setAttribute('aria-pressed', show ? 'true' : 'false');
      pass.focus();
    });
  }

  // 2. Feature slider.
  var root = document.getElementById('loginSlider');
  if (!root) return;
  var slides = root.querySelectorAll('.ls-slide');
  var ticks = root.querySelectorAll('.ls-tick');
  var counter = document.getElementById('lsCurrent');
  var playBtn = document.getElementById('lsPlay');
  var reduce = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : { matches: false };
  var current = 0;
  var leavingTimer = null;
  var paused = false;

  function show(i, focusTab) {
    var next = (i + slides.length) % slides.length;
    if (next === current) {
      // Same slide (only one, or a click on the active tab): restart its tick and scene.
      restart(slides[next], ticks[next]);
    } else {
      var prev = slides[current];
      clearTimeout(leavingTimer);
      slides.forEach(function (s) { s.classList.remove('is-leaving'); });
      prev.classList.add('is-leaving');
      leavingTimer = setTimeout(function () { prev.classList.remove('is-leaving'); }, 800);
      current = next;
    }
    slides.forEach(function (s, k) {
      var on = k === current;
      s.classList.toggle('is-active', on);
      if (on) s.removeAttribute('aria-hidden'); else s.setAttribute('aria-hidden', 'true');
    });
    ticks.forEach(function (t, k) {
      var on = k === current;
      t.classList.toggle('is-active', on);
      t.classList.toggle('is-done', k < current);
      t.setAttribute('aria-selected', on ? 'true' : 'false');
      t.tabIndex = on ? 0 : -1;
    });
    if (counter) counter.textContent = fa(current + 1);
    if (focusTab) ticks[current].focus();
  }

  function restart(slide, tick) {
    [slide, tick].forEach(function (el) {
      el.classList.remove('is-active');
      void el.offsetWidth;
      el.classList.add('is-active');
    });
  }

  ticks.forEach(function (t, k) {
    t.addEventListener('click', function () { show(k); });
    // The tick bar's fill ending is the slide's timer running out.
    t.addEventListener('animationend', function (e) {
      if (e.animationName === 'ls-fill' && k === current) show(current + 1);
    });
  });

  // Right-to-left: the left arrow moves forward.
  root.querySelector('.ls-progress').addEventListener('keydown', function (e) {
    var to = null;
    if (e.key === 'ArrowLeft') to = current + 1;
    else if (e.key === 'ArrowRight') to = current - 1;
    else if (e.key === 'Home') to = 0;
    else if (e.key === 'End') to = slides.length - 1;
    if (to === null) return;
    e.preventDefault();
    show(to, true);
  });
  document.getElementById('lsNext').addEventListener('click', function () { show(current + 1); });
  document.getElementById('lsPrev').addEventListener('click', function () { show(current - 1); });

  // Autoplay, unless the viewer asked for less motion.
  function setAuto() {
    var auto = !reduce.matches;
    root.classList.toggle('is-auto', auto);
    if (playBtn) playBtn.hidden = !auto;
  }
  setAuto();
  if (reduce.addEventListener) reduce.addEventListener('change', setAuto);

  function setPaused(p) {
    paused = p;
    root.classList.toggle('is-paused', p);
    if (!playBtn) return;
    playBtn.setAttribute('aria-pressed', p ? 'true' : 'false');
    playBtn.setAttribute('aria-label', p ? 'ادامه‌ی نمایش خودکار' : 'توقف نمایش خودکار');
  }
  if (playBtn) playBtn.addEventListener('click', function () { setPaused(!paused); });

  // Hold the timer (not the scene) while the mouse or keyboard focus is on the slider. A mouse click
  // also focuses a button, but that focus does not hold: leaving the slider resumes it.
  var hovering = false, focused = false;
  function hold() { root.classList.toggle('is-hold', hovering || focused || document.hidden); }
  root.addEventListener('pointerenter', function (e) { if (e.pointerType === 'mouse') { hovering = true; hold(); } });
  root.addEventListener('pointerleave', function () { hovering = false; hold(); });
  root.addEventListener('focusin', function (e) {
    var keyboard = true;
    try { keyboard = e.target.matches(':focus-visible'); } catch (err) { /* older engines: treat as keyboard */ }
    focused = keyboard; hold();
  });
  root.addEventListener('focusout', function (e) { if (!root.contains(e.relatedTarget)) { focused = false; hold(); } });
  document.addEventListener('visibilitychange', hold);

  // Touch screens wide enough to show the slider: a horizontal swipe changes slides. Right to left,
  // the next slide waits on the left, so a swipe to the right brings it in.
  var startX = null;
  root.addEventListener('touchstart', function (e) { startX = e.touches.length === 1 ? e.touches[0].clientX : null; }, { passive: true });
  root.addEventListener('touchend', function (e) {
    if (startX === null) return;
    var dx = e.changedTouches[0].clientX - startX;
    startX = null;
    if (Math.abs(dx) > 40) show(dx > 0 ? current + 1 : current - 1);
  }, { passive: true });
})();
