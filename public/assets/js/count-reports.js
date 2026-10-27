/*
 * ELLSMS — the per-customer count reports (public/received-count-report.php, sent-count-report.php).
 *
 * The page sends every customer's row at once (a few hundred at most); this sorts them by any column
 * marked th[data-sort], filters them by the search box and shows one page of them at a time. The
 * total rows in <tfoot> stay put. The table arrives in a lazily loaded part (assets/js/lazy-parts.js).
 */
(function () {
  'use strict';

  var SIZES = [10, 25, 50, 100, 0]; // 0 = all
  var SIZE_KEY = 'ellsms.countReport.pageSize';
  var fa = function (n) { return String(n).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); };

  function storedSize() {
    try {
      var v = parseInt(localStorage.getItem(SIZE_KEY), 10);
      return SIZES.indexOf(v) >= 0 ? v : 10;
    } catch (e) {
      return 10;
    }
  }

  function init(table) {
    if (table.__countReport) return;
    table.__countReport = true;
    var body = table.tBodies[0];
    var rows = Array.from(body.rows).filter(function (row) { return !row.querySelector('.empty-cell'); });
    if (!rows.length) return;
    var wrap = table.closest('.table-wrap') || table;
    var state = { size: storedSize(), page: 0, query: '', col: -1, dir: 1 };

    // Toolbar: rows per page and search.
    var bar = document.createElement('div');
    bar.className = 'cc-toolbar';
    var sizeLabel = document.createElement('label');
    sizeLabel.className = 'cc-size';
    sizeLabel.append('نمایش ');
    var select = document.createElement('select');
    SIZES.forEach(function (n) {
      var opt = document.createElement('option');
      opt.value = String(n);
      opt.textContent = n ? fa(n) : 'همه';
      if (n === state.size) opt.selected = true;
      select.appendChild(opt);
    });
    sizeLabel.append(select, ' ردیف');
    var search = document.createElement('input');
    search.type = 'search';
    search.className = 'cc-search';
    search.placeholder = 'جست‌وجو در نام، شرکت یا شماره…';
    search.setAttribute('aria-label', 'جست‌وجو در ردیف‌های گزارش');
    bar.append(sizeLabel, search);
    wrap.before(bar);

    // Pager under the table.
    var pager = document.createElement('div');
    pager.className = 'report-pager cc-pager';
    var info = document.createElement('p');
    info.className = 'hint';
    info.setAttribute('role', 'status');
    var links = document.createElement('div');
    links.className = 'report-pager-links';
    var prev = document.createElement('button');
    prev.type = 'button';
    prev.className = 'btn btn-sm btn-ghost';
    prev.textContent = 'قبلی';
    var next = prev.cloneNode();
    next.textContent = 'بعدی';
    links.append(prev, next);
    pager.append(info, links);
    wrap.after(pager);

    // Sortable headings.
    var heads = Array.from(table.tHead.rows[0].cells);
    heads.forEach(function (th, index) {
      var type = th.getAttribute('data-sort');
      if (!type) return;
      if (th.getAttribute('aria-sort') === 'descending') { state.col = index; state.dir = -1; }
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'cc-sort';
      while (th.firstChild) button.appendChild(th.firstChild);
      th.appendChild(button);
      button.addEventListener('click', function () {
        state.dir = state.col === index ? -state.dir : (type === 'num' ? -1 : 1);
        state.col = index;
        heads.forEach(function (h) { if (h.hasAttribute('data-sort')) h.setAttribute('aria-sort', 'none'); });
        th.setAttribute('aria-sort', state.dir === 1 ? 'ascending' : 'descending');
        sort(type);
        state.page = 0;
        render();
      });
    });

    function cellValue(row, type) {
      var v = row.cells[state.col].getAttribute('data-v') || '';
      return type === 'num' ? parseFloat(v) || 0 : v;
    }
    function sort(type) {
      rows.sort(function (a, b) {
        var x = cellValue(a, type), y = cellValue(b, type);
        if (type === 'num') return (x - y) * state.dir;
        if (x === '' || y === '') return (x === '') - (y === ''); // no company / name: last either way
        return x.localeCompare(y, 'fa') * state.dir;
      });
      rows.forEach(function (row) { body.appendChild(row); });
    }

    function render() {
      var q = state.query;
      var shown = rows.filter(function (row) { return !q || (row.getAttribute('data-search') || '').indexOf(q) >= 0; });
      var size = state.size || shown.length || 1;
      var pages = Math.max(1, Math.ceil(shown.length / size));
      if (state.page >= pages) state.page = pages - 1;
      var start = state.page * size;
      var end = Math.min(shown.length, start + size);
      rows.forEach(function (row) { row.hidden = true; });
      shown.slice(start, end).forEach(function (row) { row.hidden = false; });
      info.textContent = shown.length
        ? 'نمایش ' + fa(start + 1) + ' تا ' + fa(end) + ' از ' + fa(shown.length) + ' مشتری' + (q ? ' (از ' + fa(rows.length) + ')' : '')
        : 'مشتری‌ای با این عبارت پیدا نشد.';
      prev.disabled = state.page === 0;
      next.disabled = state.page >= pages - 1;
      links.hidden = pages <= 1;
    }

    select.addEventListener('change', function () {
      state.size = parseInt(select.value, 10) || 0;
      state.page = 0;
      try { localStorage.setItem(SIZE_KEY, String(state.size)); } catch (e) { /* private mode: not remembered */ }
      render();
    });
    search.addEventListener('input', function () {
      // Persian and Latin digits match alike: the rows carry the numbers as typed (Latin).
      state.query = search.value.trim().toLowerCase().replace(/[۰-۹]/g, function (d) { return String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)); });
      state.page = 0;
      render();
    });
    prev.addEventListener('click', function () { state.page--; render(); });
    next.addEventListener('click', function () { state.page++; render(); });
    render();
  }

  function scan() { document.querySelectorAll('table[data-count-table]').forEach(init); }
  document.addEventListener('lazy-part:inserted', scan);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', scan); else scan();
})();
