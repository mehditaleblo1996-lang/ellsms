/*
 * ELLSMS — message detail drawer for the report lists (public/reports-v2.php, public/inbox.php).
 *
 * A row or button carrying data-detail="<ref>" opens a <dialog> and loads the page's own
 * ?_part=detail&m=<ref> fragment into it (app/LazyParts.php). ?m=<ref> in the address opens the
 * drawer on load, so a message's detail can be linked to. The fragment is HTML the server built and
 * escaped; nothing from the row itself is inserted.
 */
(function () {
  'use strict';
  var dialog = document.getElementById('reportDrawer');
  if (!dialog || typeof dialog.showModal !== 'function') return;
  var body = dialog.querySelector('.report-drawer-body');
  var opener = null, seq = 0;

  function setParam(ref) {
    try {
      var url = new URL(location.href);
      if (ref) url.searchParams.set('m', ref); else url.searchParams.delete('m');
      history.replaceState(null, '', url);
    } catch (_) {}
  }

  function open(ref, from) {
    opener = from || null;
    var mine = ++seq;
    body.setAttribute('aria-busy', 'true');
    body.innerHTML = '<div class="report-drawer-loading"><span class="skel skel-line is-title"></span><span class="skel skel-line"></span><span class="skel skel-line"></span><span class="skel skel-line is-half"></span><span class="sr-only">در حال بارگذاری جزئیات…</span></div>';
    if (!dialog.open) dialog.showModal();
    setParam(ref);
    var url = new URL(location.pathname, location.origin);
    url.searchParams.set('_part', 'detail');
    url.searchParams.set('m', ref);
    fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { 'X-Requested-With': 'lazy-part' } })
      .then(function (res) {
        if (res.headers.get('X-Lazy-Part') !== 'detail') throw new Error('session');
        return res.text();
      })
      .then(function (html) {
        if (mine !== seq) return;
        body.innerHTML = html;
        body.removeAttribute('aria-busy');
        var heading = body.querySelector('h2');
        if (heading) { heading.tabIndex = -1; heading.focus({ preventScroll: true }); }
      })
      .catch(function () {
        if (mine !== seq) return;
        body.removeAttribute('aria-busy');
        body.innerHTML = '<p class="flash flash-error">جزئیات پیام بارگذاری نشد (شاید نشست شما تمام شده باشد). صفحه را تازه کنید و دوباره امتحان کنید.</p>';
      });
  }

  dialog.addEventListener('close', function () {
    seq++;
    setParam(null);
    if (opener && document.contains(opener)) opener.focus({ preventScroll: true });
  });
  dialog.addEventListener('click', function (event) {
    if (event.target === dialog || event.target.closest('[data-drawer-close]')) dialog.close();
  });

  document.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-detail]');
    if (!trigger || event.target.closest('a, form, input, select, textarea') || dialog.contains(trigger)) return;
    event.preventDefault();
    open(trigger.getAttribute('data-detail'), trigger.matches('button') ? trigger : trigger.querySelector('button[data-detail]') || trigger);
  });
  document.addEventListener('keydown', function (event) {
    var row = event.target.closest && event.target.closest('tr[data-detail]');
    if (!row || event.target !== row || (event.key !== 'Enter' && event.key !== ' ')) return;
    event.preventDefault();
    open(row.getAttribute('data-detail'), row);
  });

  var initial = new URLSearchParams(location.search).get('m');
  if (initial) open(initial, null);
})();
