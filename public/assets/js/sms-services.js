/* «خدمات پیامک» (/sms-services) — the page's behaviour. docs/sms-services.md.
 * Everything here is illustrative motion: no request is made and nothing is sent. Packets run along
 * the SVG paths marked [data-track]; they pause off screen and in a hidden tab, and are not drawn at
 * all for prefers-reduced-motion (every drawing is then shown complete and still). */
(function () {
  'use strict';
  var root = document.querySelector('[data-ss]');
  if (!root) return;

  var SVGNS = 'http://www.w3.org/2000/svg';
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var hasIO = 'IntersectionObserver' in window;
  var FA = '۰۱۲۳۴۵۶۷۸۹';
  var fa = function (n, pad) { var s = String(n); if (pad && s.length < 2) s = '0' + s; return s.replace(/\d/g, function (d) { return FA[d]; }); };

  /** Calls fn(true/false) as el enters / leaves the viewport (true once at start without IO). */
  function watch(el, fn, margin) {
    if (!hasIO) { fn(true); return; }
    new IntersectionObserver(function (es) { es.forEach(function (e) { fn(e.isIntersecting); }); }, { rootMargin: margin || '0px' }).observe(el);
  }

  /* ------------------------------------------------------------------------------------------------
   * Packet runner: small squares travelling along an SVG's [data-track] paths.
   *   cfg.tracks  – the paths (default: all [data-track] in svg)
   *   cfg.count   – packets per track; cfg.duration – ms per lap; cfg.stagger – lap offset between tracks
   *   cfg.onLead(point, t) – called each frame with the first packet's position
   *   cfg.classFor(t) – optional extra class for a packet at lap position t
   * ---------------------------------------------------------------------------------------------- */
  function Runner(svg, cfg) {
    this.svg = svg; this.cfg = cfg; this.items = []; this.raf = 0; this.running = false;
    this.group = svg.querySelector('[data-packets]');
    if (reduce) return; // no packets at all for reduced motion
    var self = this, tracks = cfg.tracks || svg.querySelectorAll('[data-track]');
    Array.prototype.forEach.call(tracks, function (path, ti) {
      var len = path.getTotalLength();
      for (var k = 0; k < (cfg.count || 1); k++) {
        var el = document.createElementNS(SVGNS, 'rect');
        el.setAttribute('class', 'ss-pk'); el.setAttribute('width', '11'); el.setAttribute('height', '7');
        el.setAttribute('x', '-5.5'); el.setAttribute('y', '-3.5');
        el.style.opacity = '0';
        self.group.appendChild(el);
        self.items.push({ path: path, len: len, el: el, offset: k / (cfg.count || 1) + ti * (cfg.stagger || 0) });
      }
    });
    this.tick = this.tick.bind(this);
  }
  Runner.prototype.start = function () {
    if (reduce || this.running || !this.items.length) return;
    this.running = true; this.t0 = null; this.raf = requestAnimationFrame(this.tick);
  };
  Runner.prototype.stop = function () { this.running = false; cancelAnimationFrame(this.raf); };
  Runner.prototype.destroy = function () { this.stop(); this.items.forEach(function (it) { it.el.remove(); }); this.items = []; };
  Runner.prototype.tick = function (ts) {
    if (!this.running) return;
    if (this.t0 === null) this.t0 = ts;
    var base = (ts - this.t0) / this.cfg.duration, cfg = this.cfg;
    for (var i = 0; i < this.items.length; i++) {
      var it = this.items[i], t = ((base + 1 - it.offset) % 1 + 1) % 1;
      var p = it.path.getPointAtLength(t * it.len);
      it.el.setAttribute('transform', 'translate(' + p.x.toFixed(1) + ' ' + p.y.toFixed(1) + ')');
      // fade in/out at the ends of an open track, so a packet never pops into view
      it.el.style.opacity = cfg.closed ? '1' : String(Math.min(1, t * 14, (1 - t) * 14));
      if (cfg.classFor) it.el.setAttribute('class', 'ss-pk' + cfg.classFor(t));
      if (i === 0 && cfg.onLead) cfg.onLead(p, t);
    }
    this.raf = requestAnimationFrame(this.tick);
  };

  /** Which [data-node] box of svg a point is inside (−1 for none). */
  function nodeBoxes(svg) {
    return Array.prototype.map.call(svg.querySelectorAll('[data-node]'), function (g) {
      var b = g.querySelector('rect').getBBox();
      return { g: g, x0: b.x - 2, x1: b.x + b.width + 2, y0: b.y - 2, y1: b.y + b.height + 2 };
    });
  }
  function hit(boxes, p) {
    for (var i = 0; i < boxes.length; i++) { var b = boxes[i]; if (p.x >= b.x0 && p.x <= b.x1 && p.y >= b.y0 && p.y <= b.y1) return i; }
    return -1;
  }
  /** Keeps a runner going only while its element is on screen and the tab is visible. */
  function bindVisibility(el, getRunner) {
    var onScreen = false;
    var sync = function () { var r = getRunner(); if (!r) return; (onScreen && !document.hidden) ? r.start() : r.stop(); };
    watch(el, function (v) { onScreen = v; sync(); }, '80px');
    document.addEventListener('visibilitychange', sync);
    return sync;
  }

  /* ================================================================================ hero */
  if (!reduce) {
    root.classList.add('is-armed');
    requestAnimationFrame(function () { requestAnimationFrame(function () { root.classList.add('is-in'); }); });
  }
  var life = root.querySelector('[data-ss-life]');
  if (life) {
    var lsvg = life.querySelector('svg'), lboxes = nodeBoxes(lsvg);
    var legend = life.querySelectorAll('[data-legend]'), readout = lsvg.querySelector('[data-readout]');
    var titles = Array.prototype.map.call(legend, function (li) { return li.querySelector('b').textContent; });
    var lastHot = -2;
    var setHot = function (i) {
      if (i === lastHot) return; lastHot = i;
      lboxes.forEach(function (b, k) { b.g.classList.toggle('is-hot', k === i); });
      Array.prototype.forEach.call(legend, function (li, k) { li.classList.toggle('is-hot', k === i); });
      if (i >= 0 && readout) readout.textContent = titles[i];
    };
    watch(life, function (v) { if (v) life.classList.add('is-drawn'); });
    if (reduce) life.classList.add('is-drawn');
    // After the recipient (lap ≈ .70 on this loop) the packet is the delivery report coming back.
    var lifeRunner = new Runner(lsvg, {
      count: 2, duration: 12000, closed: true,
      classFor: function (t) { return t > 0.7 ? ' is-ack' : ''; },
      onLead: function (p) { var h = hit(lboxes, p); if (h >= 0) setHot(h); }
    });
    bindVisibility(life, function () { return lifeRunner; });
  }

  /* ================================================================================ 01 — explorer */
  var ex = root.querySelector('[data-ss-explorer]');
  if (ex) {
    var tabs = Array.prototype.slice.call(ex.querySelectorAll('[role="tab"]'));
    var panels = tabs.map(function (t) { return document.getElementById(t.getAttribute('aria-controls')); });
    var current = 0, flowRunner = null;

    var startFlow = function (panel) {
      if (flowRunner) flowRunner.destroy();
      flowRunner = null;
      var svg = panel.querySelector('.ss-flow-svg'), flow = panel.querySelector('.ss-x-flow');
      Array.prototype.forEach.call(svg.querySelectorAll('.ss-flow-line'), function (l, k) { l.style.setProperty('--i', k); });
      flow.classList.remove('is-drawn'); void flow.offsetWidth; flow.classList.add('is-drawn');
      if (reduce) return;
      var boxes = nodeBoxes(svg), states = svg.querySelectorAll('.ss-flow-state'), kind = panel.getAttribute('data-kind');
      var fan = kind === 'fanout';
      flowRunner = new Runner(svg, {
        count: 1, duration: fan ? 3600 : 4800, stagger: fan ? 0.015 : 0,
        onLead: function (p) {
          var h = hit(boxes, p);
          boxes.forEach(function (b, k) { b.g.classList.toggle('is-hot', k === h); });
          if (states.length && h >= 0 && h < 3) Array.prototype.forEach.call(states, function (s, k) { s.classList.toggle('is-hot', k === h); });
        }
      });
      syncFlow();
    };
    var syncFlow = bindVisibility(ex, function () { return flowRunner; });

    var select = function (i, focus) {
      if (i === current) { if (focus) tabs[i].focus(); return; }
      tabs[current].setAttribute('aria-selected', 'false'); tabs[current].setAttribute('tabindex', '-1');
      panels[current].hidden = true; panels[current].classList.remove('is-active', 'is-entering');
      current = i;
      tabs[i].setAttribute('aria-selected', 'true'); tabs[i].removeAttribute('tabindex');
      panels[i].hidden = false; panels[i].classList.add('is-active', 'is-entering');
      if (focus) tabs[i].focus();
      // on the phone the tab row scrolls sideways: keep the chosen tab in view
      if (tabs[i].scrollIntoView && ex.querySelector('.ss-x-nav').scrollWidth > ex.querySelector('.ss-x-nav').clientWidth) {
        tabs[i].scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: reduce ? 'auto' : 'smooth' });
      }
      startFlow(panels[i]);
    };
    tabs.forEach(function (t, i) {
      t.addEventListener('click', function () { select(i, false); });
      t.addEventListener('keydown', function (e) {
        var n = tabs.length, to = null;
        // vertical list on desktop, a row on small screens: both axes move; RTL: ArrowLeft = next
        if (e.key === 'ArrowDown' || e.key === 'ArrowLeft') to = (i + 1) % n;
        else if (e.key === 'ArrowUp' || e.key === 'ArrowRight') to = (i - 1 + n) % n;
        else if (e.key === 'Home') to = 0;
        else if (e.key === 'End') to = n - 1;
        if (to !== null) { e.preventDefault(); select(to, true); }
      });
    });
    startFlow(panels[0]);
  }

  /* ================================================================================ 02 — simulator */
  var sim = root.querySelector('[data-ss-sim]');
  if (sim) {
    var radios = sim.querySelectorAll('input[name="ssScenario"]');
    var pipe = sim.querySelector('[data-sim-pipe]'), packet = sim.querySelector('[data-sim-packet]');
    var fill = sim.querySelector('[data-sim-fill]').parentNode;
    var stages = sim.querySelectorAll('[data-sim-stage]'), log = sim.querySelector('[data-sim-log]');
    var elSender = sim.querySelector('[data-sim-sender]'), elText = sim.querySelector('[data-sim-text]');
    var elChars = sim.querySelector('[data-sim-chars]'), elParts = sim.querySelector('[data-sim-parts]');
    var timers = [], started = false;
    var later = function (fn, ms) { timers.push(setTimeout(fn, ms)); };
    var vertical = function () { return window.matchMedia('(max-width: 640px)').matches; };

    /** SMS parts of a text: Persian needs UCS-2 (70 per single part, 67 per part when split). */
    var parts = function (text) {
      var n = Array.from(text).length, ucs = /[^\x00-\x7F]/.test(text);
      var one = ucs ? 70 : 160, multi = ucs ? 67 : 153;
      return { chars: n, parts: n <= one ? 1 : Math.ceil(n / multi) };
    };
    var moveTo = function (i) {
      var dot = stages[i].querySelector('.ss-pipe-dot').getBoundingClientRect(), box = pipe.getBoundingClientRect();
      if (vertical()) {
        packet.style.setProperty('--y', (dot.top + dot.height / 2 - box.top - 5) + 'px');
      } else {
        packet.style.setProperty('--x', (dot.left + dot.width / 2 - box.right) + 'px');
      }
      fill.style.setProperty('--fill', String(i / (stages.length - 1)));
    };
    var addLog = function (i, text, final) {
      var li = document.createElement('li');
      if (final) li.className = 'is-final';
      var b = document.createElement('b'); b.textContent = fa(i + 1, true);
      li.appendChild(b); li.appendChild(document.createTextNode(text));
      log.appendChild(li);
    };
    var run = function () {
      timers.forEach(clearTimeout); timers = [];
      var r = sim.querySelector('input[name="ssScenario"]:checked');
      elText.classList.add('is-swap');
      later(function () {
        elSender.textContent = r.getAttribute('data-sender');
        elText.textContent = r.getAttribute('data-text');
        var p = parts(r.getAttribute('data-text'));
        elChars.textContent = fa(p.chars) + ' نویسه';
        elParts.textContent = fa(p.parts) + ' بخش پیامک';
        elText.classList.remove('is-swap');
      }, reduce ? 0 : 220);
      log.textContent = '';
      Array.prototype.forEach.call(stages, function (s) { s.classList.remove('is-active', 'is-done'); });
      fill.style.setProperty('--fill', '0');
      if (reduce) {
        Array.prototype.forEach.call(stages, function (s, i) { s.classList.add('is-done'); addLog(i, s.getAttribute('data-log'), i === stages.length - 1); });
        fill.style.setProperty('--fill', '1');
        return;
      }
      pipe.classList.add('is-running');
      packet.style.transition = 'none'; moveTo(0); void packet.offsetWidth; packet.style.transition = '';
      var step = 1250;
      Array.prototype.forEach.call(stages, function (s, i) {
        later(function () {
          if (i > 0) stages[i - 1].classList.replace('is-active', 'is-done');
          moveTo(i); s.classList.add('is-active');
        }, 400 + i * step);
        later(function () { addLog(i, s.getAttribute('data-log'), i === stages.length - 1); }, 400 + i * step + 650);
      });
      later(function () { stages[stages.length - 1].classList.replace('is-active', 'is-done'); pipe.classList.remove('is-running'); }, 400 + stages.length * step);
    };
    Array.prototype.forEach.call(radios, function (r) { r.addEventListener('change', function () { started = true; run(); }); });
    sim.querySelector('[data-ss-replay]').addEventListener('click', function () { started = true; run(); });
    watch(sim, function (v) { if (v && !started) { started = true; run(); } }, '-20% 0px');
    window.addEventListener('resize', function () { var a = sim.querySelector('.ss-pipe-stage.is-active'); if (a) moveTo(Array.prototype.indexOf.call(stages, a)); });
  }

  /* ================================================================================ 03 — scroll story */
  var story = root.querySelector('[data-ss-story]');
  if (story) {
    var steps = story.querySelectorAll('[data-step]'), layers = story.querySelectorAll('[data-layer]');
    var ssvg = story.querySelector('.ss-story-svg');
    var sNum = story.querySelector('[data-story-num]'), sName = story.querySelector('[data-story-name]');
    var storyRunner = null, stage = 0;
    var setStage = function (n) {
      if (n === stage) return; stage = n;
      story.setAttribute('data-stage', n);
      Array.prototype.forEach.call(steps, function (s) { s.classList.toggle('is-on', +s.getAttribute('data-step') === n); });
      Array.prototype.forEach.call(layers, function (l) {
        var k = +l.getAttribute('data-layer');
        l.classList.toggle('is-on', k <= n); l.classList.toggle('is-current', k === n);
      });
      sNum.textContent = fa(n, true);
      sName.textContent = steps[n - 1].querySelector('h3').textContent;
      if (storyRunner) storyRunner.destroy();
      storyRunner = null;
      if (reduce) return;
      storyRunner = new Runner(ssvg, { count: 1, duration: 2600, stagger: 0.18, tracks: story.querySelectorAll('[data-layer="' + n + '"] [data-track]') });
      syncStory();
    };
    var syncStory = bindVisibility(story, function () { return storyRunner; });
    if (hasIO) {
      var sio = new IntersectionObserver(function (es) {
        es.forEach(function (e) { if (e.isIntersecting) setStage(+e.target.getAttribute('data-step')); });
      }, { rootMargin: '-45% 0px -45% 0px' });
      Array.prototype.forEach.call(steps, function (s) { sio.observe(s); });
    }
    setStage(1);
    if (!hasIO) { Array.prototype.forEach.call(layers, function (l) { l.classList.add('is-on'); }); }
  }

  /* ================================================================================ 04 — industries rail */
  var rail = root.querySelector('[data-ind-rail]');
  if (rail) {
    var prev = root.querySelector('[data-ind-prev]'), next = root.querySelector('[data-ind-next]');
    var item = function () { var f = rail.querySelector('.ss-ind-item + .ss-ind-item'); return f ? f.getBoundingClientRect().width : 320; };
    var rtl = getComputedStyle(rail).direction === 'rtl';
    var edges = function () {
      var max = rail.scrollWidth - rail.clientWidth, pos = Math.abs(rail.scrollLeft);
      prev.disabled = pos < 4; next.disabled = pos > max - 4;
    };
    // In RTL the rail starts at the right edge and «next» scrolls toward the left.
    next.addEventListener('click', function () { rail.scrollBy({ left: (rtl ? -1 : 1) * item(), behavior: reduce ? 'auto' : 'smooth' }); });
    prev.addEventListener('click', function () { rail.scrollBy({ left: (rtl ? 1 : -1) * item(), behavior: reduce ? 'auto' : 'smooth' }); });
    rail.addEventListener('scroll', edges, { passive: true });
    window.addEventListener('resize', edges);
    edges();
  }

  /* ================================================================================ closing CTA */
  var fin = root.querySelector('.ss-final');
  if (fin) watch(fin, function (v) { if (v) fin.classList.add('is-drawn'); }, '-15% 0px');
})();
