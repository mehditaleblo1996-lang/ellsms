/*
 * ELLSMS — «گزارش وضعیت‌ها» (public/status-report.php).
 *
 * Print, the two chart toggles (remembered per browser), the «شماره‌های اختصاصی» multi-select with
 * its search box, the clear buttons beside «ماه» / «تاریخ شروع» / «تاریخ پایان», and re-submitting
 * the form when the organization or user changes (their line and user lists depend on it). The
 * table and charts arrive in a lazily loaded part (assets/js/lazy-parts.js), so everything here
 * is delegated from the document.
 */
(function () {
  'use strict';

  var KEY = 'ellsms.statusReport.hiddenCharts';
  var fa = function (n) { return String(n).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); };

  function hiddenCharts() {
    try {
      var v = JSON.parse(localStorage.getItem(KEY) || '[]');
      return Array.isArray(v) ? v : [];
    } catch (e) {
      return [];
    }
  }

  function saveHidden(list) {
    try { localStorage.setItem(KEY, JSON.stringify(list)); } catch (e) { /* private mode: not remembered */ }
  }

  function applyCharts() {
    var hidden = hiddenCharts();
    document.querySelectorAll('[data-sr-chart]').forEach(function (box) {
      box.hidden = hidden.indexOf(box.getAttribute('data-sr-chart')) >= 0;
    });
    document.querySelectorAll('[data-sr-toggle]').forEach(function (btn) {
      btn.setAttribute('aria-pressed', hidden.indexOf(btn.getAttribute('data-sr-toggle')) >= 0 ? 'false' : 'true');
    });
  }

  function multiText(details) {
    var checked = details.querySelectorAll('input[type=checkbox]:checked').length;
    var label = details.querySelector('[data-sr-multi-text]');
    if (label) label.textContent = checked ? fa(checked) + ' شماره انتخاب شده' : 'همه‌ی شماره‌ها';
  }

  document.addEventListener('click', function (event) {
    var target = event.target.closest('[data-sr-print], [data-sr-toggle], [data-sr-clear], [data-sr-multi-clear]');
    if (!target) {
      // A click outside an open multi-select closes it.
      document.querySelectorAll('[data-sr-multi][open]').forEach(function (d) {
        if (!d.contains(event.target)) d.open = false;
      });
      return;
    }
    if (target.hasAttribute('data-sr-print')) {
      window.print();
    } else if (target.hasAttribute('data-sr-toggle')) {
      var name = target.getAttribute('data-sr-toggle');
      var hidden = hiddenCharts();
      var at = hidden.indexOf(name);
      if (at >= 0) hidden.splice(at, 1); else hidden.push(name);
      saveHidden(hidden);
      applyCharts();
    } else if (target.hasAttribute('data-sr-clear')) {
      var form = target.closest('form');
      var field = target.getAttribute('data-sr-clear');
      form.querySelectorAll('[name="' + field + '"], [name="' + field + '_y"], [name="' + field + '_m"], [name="' + field + '_d"]').forEach(function (el) {
        el.value = '';
      });
    } else if (target.hasAttribute('data-sr-multi-clear')) {
      var details = target.closest('[data-sr-multi]');
      details.querySelectorAll('input[type=checkbox]').forEach(function (box) { box.checked = false; });
      multiText(details);
    }
  });

  document.addEventListener('change', function (event) {
    var details = event.target.closest('[data-sr-multi]');
    if (details) multiText(details);
    if (event.target.matches('[data-sr-resubmit]')) {
      // The user and line lists follow the organization / user: reload them with the new choice,
      // dropping a user or lines that belonged to the previous one.
      var form = event.target.form;
      if (event.target.name === 'org_id' && form.user_id) form.user_id.value = '';
      form.querySelectorAll('[data-sr-multi] input[type=checkbox]').forEach(function (box) { box.checked = false; });
      form.submit();
    }
  });

  document.addEventListener('input', function (event) {
    if (!event.target.matches('[data-sr-multi-search]')) return;
    var q = event.target.value.replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); }).trim();
    event.target.closest('[data-sr-multi]').querySelectorAll('.sr-multi-list li').forEach(function (li) {
      li.hidden = q !== '' && li.textContent.indexOf(q) < 0;
    });
  });

  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;
    document.querySelectorAll('[data-sr-multi][open]').forEach(function (d) {
      d.open = false;
      var summary = d.querySelector('summary');
      if (summary) summary.focus();
    });
  });

  document.addEventListener('lazy-part:loaded', applyCharts);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', applyCharts); else applyCharts();
})();
