/*
 * ELLSMS — shared form behaviour.
 *
 *  1. Jalali date pickers (jalali_date_select() in app/bootstrap.php): the day list follows the
 *     chosen month (31 / 30 / 29-or-30 for Esfand), and once any of the three boxes is picked the
 *     other two become required, so a half-filled date is caught before it is submitted.
 *  2. Country / province / city selects (app/IranGeo.php): choosing a province refills the city list
 *     with that province's cities; choosing a country other than Iran disables both.
 *  3. Tabs (.tabs > .tabs-nav + .tab-panel): one panel at a time. The open tab is remembered per page
 *     for the browser session, so the redirect after saving a form lands back on the same tab.
 */
(function () {
  'use strict';

  var FA_DIGITS = '۰۱۲۳۴۵۶۷۸۹';
  function fa(n) { return String(n).replace(/\d/g, function (d) { return FA_DIGITS[d]; }); }

  // Same 33-year arithmetic as gregorian_to_jalali()/jalali_to_gregorian(): Esfand has 30 days
  // exactly when the year is a leap year.
  function jalaliToDays(jy, jm, jd) {
    jy += 1595;
    return -355668 + 365 * jy + Math.floor(jy / 33) * 8 + Math.floor(((jy % 33) + 3) / 4)
      + jd + (jm < 7 ? (jm - 1) * 31 : (jm - 7) * 30 + 186);
  }
  function monthLength(jy, jm) {
    if (jm <= 6) return 31;
    if (jm <= 11) return 30;
    return jalaliToDays(jy + 1, 1, 1) - jalaliToDays(jy, 12, 1) === 30 ? 30 : 29;
  }

  function initDate(wrap) {
    var y = wrap.querySelector('.jdate-y');
    var m = wrap.querySelector('.jdate-m');
    var d = wrap.querySelector('.jdate-d');
    if (!y || !m || !d) return;
    var hasBlank = d.options.length && d.options[0].value === '';

    function syncDays() {
      var year = parseInt(y.value, 10);
      var month = parseInt(m.value, 10);
      var max = month ? monthLength(year || 1405, month) : 31;
      var current = parseInt(d.value, 10) || 0;
      var first = hasBlank ? 1 : 0;
      while (d.options.length - first > max) d.remove(d.options.length - 1);
      for (var day = d.options.length - first + 1; day <= max; day++) {
        d.add(new Option(fa(day), String(day)));
      }
      if (current > max) d.value = String(max);
    }

    function syncRequired() {
      if (!hasBlank) return;
      var any = y.value !== '' || m.value !== '' || d.value !== '';
      [y, m, d].forEach(function (s) { s.required = any; });
    }

    [y, m, d].forEach(function (s) {
      s.addEventListener('change', function () { syncDays(); syncRequired(); });
    });
    syncDays();
    syncRequired();
  }

  var geoData = null;
  var geoNode = document.getElementById('geoIranData');
  if (geoNode) {
    try { geoData = JSON.parse(geoNode.textContent); } catch (e) { geoData = null; }
  }

  function fillCities(citySelect, province) {
    var cities = (geoData && geoData[province]) || [];
    var keep = citySelect.value;
    while (citySelect.options.length) citySelect.remove(0);
    citySelect.add(new Option(cities.length ? 'انتخاب شهر' : 'ابتدا استان را انتخاب کنید', ''));
    cities.forEach(function (city) { citySelect.add(new Option(city, city)); });
    citySelect.value = cities.indexOf(keep) !== -1 ? keep : '';
  }

  function initGeo(form) {
    var country = form.querySelector('select[data-geo="country"]');
    var province = form.querySelector('select[data-geo="province"]');
    var city = form.querySelector('select[data-geo="city"]');
    // A field disabled server-side (read-only viewer) is never re-enabled from here.
    var locked = province && province.disabled;

    if (province && city && geoData) {
      province.addEventListener('change', function () { fillCities(city, province.value); });
    }
    if (country && province && !locked) {
      var syncCountry = function () {
        var abroad = country.value !== '' && country.value !== 'ایران';
        province.disabled = abroad;
        if (city) city.disabled = abroad;
        if (abroad) {
          province.value = '';
          if (city) fillCities(city, '');
        }
      };
      country.addEventListener('change', syncCountry);
      syncCountry();
    }
  }

  function initTabs(root) {
    var nav = root.querySelector('.tabs-nav');
    if (!nav) return;
    var buttons = Array.prototype.slice.call(nav.querySelectorAll('[data-tab]'));
    var panels = Array.prototype.filter.call(root.children, function (el) { return el.classList.contains('tab-panel'); });
    if (!buttons.length || !panels.length) return;
    var storeKey = 'ellsms-tab:' + location.pathname + location.search + ':' + (root.getAttribute('data-tabs') || '');

    function exists(id) { return buttons.some(function (b) { return b.getAttribute('data-tab') === id; }); }
    function show(id, focus) {
      buttons.forEach(function (b) {
        var on = b.getAttribute('data-tab') === id;
        b.setAttribute('aria-selected', on ? 'true' : 'false');
        b.tabIndex = on ? 0 : -1;
        if (on && focus) b.focus();
      });
      panels.forEach(function (p) { p.classList.toggle('is-active', p.getAttribute('data-panel') === id); });
      try { sessionStorage.setItem(storeKey, id); } catch (e) { /* private mode: tab just isn't remembered */ }
    }

    buttons.forEach(function (b, i) {
      b.addEventListener('click', function () {
        var id = b.getAttribute('data-tab');
        show(id, false);
        if (history.replaceState) history.replaceState(null, '', '#' + id);
      });
      b.addEventListener('keydown', function (e) {
        // RTL: the visually next tab is to the left.
        var step = e.key === 'ArrowLeft' ? 1 : e.key === 'ArrowRight' ? -1 : 0;
        if (!step) return;
        e.preventDefault();
        var next = buttons[(i + step + buttons.length) % buttons.length];
        next.click();
        next.focus();
      });
    });

    var initial = location.hash.slice(1);
    if (!exists(initial)) {
      try { initial = sessionStorage.getItem(storeKey) || ''; } catch (e) { initial = ''; }
    }
    if (!exists(initial)) initial = buttons[0].getAttribute('data-tab');
    root.classList.add('is-ready');
    show(initial, false);
  }

  document.querySelectorAll('.tabs').forEach(initTabs);
  document.querySelectorAll('[data-jdate]').forEach(initDate);
  document.querySelectorAll('form').forEach(function (form) {
    if (form.querySelector('select[data-geo]')) initGeo(form);
  });
})();
