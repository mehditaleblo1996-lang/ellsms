/*
 * LBA polygon editor (public/lba.php, docs/lba.md): a small slippy map over OpenStreetMap-style tiles
 * with polygons drawn on it. No library: Web Mercator tiles, an SVG overlay, and pointer events.
 *
 *   <div data-lba-map data-input="polygonsInputId" data-tiles="https://…/{z}/{x}/{y}.png" [data-readonly]
 *        [data-lon="51.389" data-lat="35.689" data-zoom="11"]></div>
 *
 * The hidden input holds the polygons as the provider wants them: a JSON list of polygons, each a list
 * of [longitude, latitude] points, closed (first point repeated last). Click adds a point to the
 * polygon being drawn; drag pans; the wheel and the +/- buttons zoom.
 */
(function () {
  'use strict';
  var TILE = 256;

  function project(lon, lat, z) {
    var n = TILE * Math.pow(2, z);
    var s = Math.sin(lat * Math.PI / 180);
    s = Math.min(Math.max(s, -0.9999), 0.9999);
    return [(lon + 180) / 360 * n, (0.5 - Math.log((1 + s) / (1 - s)) / (4 * Math.PI)) * n];
  }
  function unproject(x, y, z) {
    var n = TILE * Math.pow(2, z);
    var lon = x / n * 360 - 180;
    var lat = Math.atan(Math.sinh(Math.PI * (1 - 2 * y / n))) * 180 / Math.PI;
    return [lon, lat];
  }
  function round(v) { return Math.round(v * 1e6) / 1e6; }
  function el(tag, attrs, parent, ns) {
    var e = ns ? document.createElementNS('http://www.w3.org/2000/svg', tag) : document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); });
    if (parent) parent.appendChild(e);
    return e;
  }

  function LbaMap(root) {
    var input = document.getElementById(root.getAttribute('data-input') || '');
    var readonly = root.hasAttribute('data-readonly');
    var tiles = root.getAttribute('data-tiles');
    var state = {
      lon: parseFloat(root.getAttribute('data-lon') || '51.389'),
      lat: parseFloat(root.getAttribute('data-lat') || '35.689'),
      zoom: parseInt(root.getAttribute('data-zoom') || '11', 10),
      polys: [[]]
    };
    root.classList.add('lba-map');
    var view = el('div', {'class': 'lba-map-view'}, root);
    var tileLayer = el('div', {'class': 'lba-map-tiles'}, view);
    var svg = el('svg', {'class': 'lba-map-svg'}, view, true);
    var credit = el('div', {'class': 'lba-map-credit'}, view);
    credit.innerHTML = '© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>';
    var bar = el('div', {'class': 'lba-map-bar'}, root);
    var tileCache = {};

    function load() {
      if (!input || !input.value) return;
      try {
        var data = JSON.parse(input.value);
        if (Array.isArray(data) && data.length && typeof data[0][0] === 'number') data = [data];
        var polys = (data || []).filter(Array.isArray).map(function (p) {
          var pts = p.filter(function (pt) { return Array.isArray(pt) && pt.length >= 2; }).map(function (pt) { return [+pt[0], +pt[1]]; });
          if (pts.length > 1 && pts[0][0] === pts[pts.length - 1][0] && pts[0][1] === pts[pts.length - 1][1]) pts.pop();
          return pts;
        }).filter(function (p) { return p.length; });
        if (polys.length) { state.polys = polys; if (!readonly) state.polys.push([]); fit(); }
      } catch (e) { /* a hand-typed value that is not JSON: leave the map empty */ }
    }
    function save() {
      if (!input || readonly) return;
      var out = state.polys.filter(function (p) { return p.length >= 3; }).map(function (p) {
        var pts = p.map(function (pt) { return [round(pt[0]), round(pt[1])]; });
        pts.push(pts[0].slice());
        return pts;
      });
      input.value = out.length ? JSON.stringify(out) : '';
      input.dispatchEvent(new Event('change', {bubbles: true}));
    }
    function fit() {
      var pts = [].concat.apply([], state.polys);
      if (!pts.length) return;
      var minLon = Infinity, maxLon = -Infinity, minLat = Infinity, maxLat = -Infinity;
      pts.forEach(function (p) { minLon = Math.min(minLon, p[0]); maxLon = Math.max(maxLon, p[0]); minLat = Math.min(minLat, p[1]); maxLat = Math.max(maxLat, p[1]); });
      state.lon = (minLon + maxLon) / 2;
      state.lat = (minLat + maxLat) / 2;
      var w = view.clientWidth || 600, h = view.clientHeight || 400;
      for (var z = 17; z >= 4; z--) {
        var a = project(minLon, maxLat, z), b = project(maxLon, minLat, z);
        if (b[0] - a[0] < w * 0.8 && b[1] - a[1] < h * 0.8) break;
      }
      state.zoom = z;
    }

    function render() {
      var w = view.clientWidth, h = view.clientHeight, z = state.zoom;
      var c = project(state.lon, state.lat, z);
      var left = c[0] - w / 2, top = c[1] - h / 2, n = Math.pow(2, z), used = {};
      for (var tx = Math.floor(left / TILE); tx <= Math.floor((left + w) / TILE); tx++) {
        for (var ty = Math.floor(top / TILE); ty <= Math.floor((top + h) / TILE); ty++) {
          if (ty < 0 || ty >= n) continue;
          var wx = ((tx % n) + n) % n, key = z + '/' + tx + '/' + ty;
          var img = tileCache[key];
          if (!img) {
            img = el('img', {alt: '', draggable: 'false', src: tiles.replace('{z}', z).replace('{x}', wx).replace('{y}', ty)});
            tileCache[key] = img;
          }
          img.style.left = Math.round(tx * TILE - left) + 'px';
          img.style.top = Math.round(ty * TILE - top) + 'px';
          if (img.parentNode !== tileLayer) tileLayer.appendChild(img);
          used[key] = true;
        }
      }
      Object.keys(tileCache).forEach(function (k) {
        if (!used[k]) { if (tileCache[k].parentNode) tileLayer.removeChild(tileCache[k]); if (k.split('/')[0] !== String(z)) delete tileCache[k]; }
      });
      while (svg.firstChild) svg.removeChild(svg.firstChild);
      state.polys.forEach(function (poly, i) {
        var last = !readonly && i === state.polys.length - 1;
        var pts = poly.map(function (p) { var q = project(p[0], p[1], z); return (q[0] - left).toFixed(1) + ',' + (q[1] - top).toFixed(1); });
        if (pts.length >= 2) el(last ? 'polyline' : 'polygon', {points: pts.join(' '), 'class': last ? 'lba-poly-draft' : 'lba-poly'}, svg, true);
        if (last) pts.forEach(function (p) { var xy = p.split(','); el('circle', {cx: xy[0], cy: xy[1], r: 4, 'class': 'lba-poly-point'}, svg, true); });
      });
      status();
    }
    function status() {
      if (readonly) return;
      var done = state.polys.filter(function (p) { return p.length >= 3; }).length;
      var cur = state.polys[state.polys.length - 1].length;
      info.textContent = 'محدوده‌های کامل: ' + done + ' — نقاط محدوده‌ی در حال رسم: ' + cur;
    }

    function zoomAt(delta, px, py) {
      var z = Math.min(18, Math.max(4, state.zoom + delta));
      if (z === state.zoom) return;
      var w = view.clientWidth, h = view.clientHeight;
      px = px === undefined ? w / 2 : px; py = py === undefined ? h / 2 : py;
      var c = project(state.lon, state.lat, state.zoom);
      var under = unproject(c[0] - w / 2 + px, c[1] - h / 2 + py, state.zoom);
      var u = project(under[0], under[1], z);
      var nc = unproject(u[0] - px + w / 2, u[1] - py + h / 2, z);
      state.zoom = z; state.lon = nc[0]; state.lat = nc[1];
      render();
    }
    function button(label, title, fn) {
      var b = el('button', {type: 'button', 'class': 'btn btn-sm', title: title}, bar);
      b.textContent = label;
      b.addEventListener('click', fn);
      return b;
    }
    button('+', 'بزرگ‌نمایی', function () { zoomAt(1); });
    button('−', 'کوچک‌نمایی', function () { zoomAt(-1); });
    var info = null;
    if (!readonly) {
      button('محدوده‌ی بعدی', 'پایان این محدوده و شروع محدوده‌ی تازه', function () {
        var cur = state.polys[state.polys.length - 1];
        if (cur.length >= 3) state.polys.push([]);
        else if (cur.length) { alert('هر محدوده دست‌کم سه نقطه لازم دارد.'); return; }
        save(); render();
      });
      button('حذف آخرین نقطه', 'برگرداندن آخرین کلیک', function () {
        var cur = state.polys[state.polys.length - 1];
        if (cur.length) cur.pop();
        else if (state.polys.length > 1) { state.polys.pop(); state.polys[state.polys.length - 1].pop(); }
        save(); render();
      });
      button('پاک کردن همه', 'حذف همه‌ی محدوده‌ها', function () {
        if (!confirm('همه‌ی محدوده‌ها پاک شوند؟')) return;
        state.polys = [[]]; save(); render();
      });
      info = el('span', {'class': 'muted lba-map-info'}, bar);
      if (input) input.addEventListener('lba:reload', function () { state.polys = [[]]; load(); render(); });
    }

    // Drag to pan; a press that does not move is a click (adds a point).
    var drag = null;
    view.addEventListener('pointerdown', function (ev) {
      if (ev.button !== 0) return;
      drag = {x: ev.clientX, y: ev.clientY, c: project(state.lon, state.lat, state.zoom), moved: false};
      view.setPointerCapture(ev.pointerId);
    });
    view.addEventListener('pointermove', function (ev) {
      if (!drag) return;
      var dx = ev.clientX - drag.x, dy = ev.clientY - drag.y;
      if (Math.abs(dx) + Math.abs(dy) > 4) drag.moved = true;
      if (!drag.moved) return;
      var ll = unproject(drag.c[0] - dx, drag.c[1] - dy, state.zoom);
      state.lon = ll[0]; state.lat = ll[1];
      render();
    });
    view.addEventListener('pointerup', function (ev) {
      if (!drag) return;
      var wasClick = !drag.moved;
      drag = null;
      if (!wasClick || readonly) return;
      var r = view.getBoundingClientRect();
      var c = project(state.lon, state.lat, state.zoom);
      var ll = unproject(c[0] - r.width / 2 + (ev.clientX - r.left), c[1] - r.height / 2 + (ev.clientY - r.top), state.zoom);
      state.polys[state.polys.length - 1].push([ll[0], ll[1]]);
      save(); render();
    });
    view.addEventListener('wheel', function (ev) {
      ev.preventDefault();
      var r = view.getBoundingClientRect();
      zoomAt(ev.deltaY < 0 ? 1 : -1, ev.clientX - r.left, ev.clientY - r.top);
    }, {passive: false});
    window.addEventListener('resize', render);

    load();
    render();
  }

  function init() { document.querySelectorAll('[data-lba-map]').forEach(function (n) { new LbaMap(n); }); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
