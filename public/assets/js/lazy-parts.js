/*
 * ELLSMS — lazily loaded page parts (app/LazyParts.php).
 *
 * Every [data-lazy-part] box fetches its section from the page's own URL with ?_part=<name>, all in
 * parallel, and is replaced by the answer. A redirected response without the X-Lazy-Part header is
 * the login page (the session expired), so the whole page reloads and the normal redirect takes
 * over. A failed or timed-out part shows a retry button in its own box only.
 */
(function () {
  'use strict';

  var refreshScheduled = false;

  function partUrl(name) {
    var url = new URL(location.href);
    url.hash = '';
    url.searchParams.set('_part', name);
    return url.toString();
  }

  function showError(box, name) {
    box.setAttribute('aria-busy', 'false');
    box.innerHTML = '';
    var status = document.createElement('div');
    status.className = 'lazy-part-status lazy-part-error';
    status.textContent = 'بارگذاری این بخش انجام نشد. ';
    var retry = document.createElement('button');
    retry.type = 'button';
    retry.className = 'btn btn-sm';
    retry.textContent = 'تلاش دوباره';
    retry.addEventListener('click', function () { load(box, name); });
    status.appendChild(retry);
    box.appendChild(status);
  }

  function applyMarkers(root) {
    root.querySelectorAll('[data-lazy-tab-count]').forEach(function (marker) {
      var tab = marker.getAttribute('data-lazy-tab-count');
      document.querySelectorAll('.tabs-nav [data-tab="' + tab + '"] .tab-count').forEach(function (count) {
        count.textContent = marker.textContent;
      });
      marker.remove();
    });
    var refresh = root.querySelector('[data-lazy-refresh]');
    if (refresh) {
      if (!refreshScheduled) {
        refreshScheduled = true;
        var ms = parseInt(refresh.getAttribute('data-lazy-refresh'), 10) * 1000;
        setInterval(function () { if (document.visibilityState === 'visible') location.reload(); }, ms);
      }
      refresh.remove();
    }
  }

  function load(box, name) {
    box.setAttribute('aria-busy', 'true');
    box.innerHTML = '<div class="lazy-part-status"><span class="lazy-spinner" aria-hidden="true"></span>در حال بارگذاری…</div>';
    fetch(partUrl(name), { credentials: 'same-origin', headers: { 'X-Requested-With': 'lazy-part' } })
      .then(function (response) {
        if (response.ok && response.headers.get('X-Lazy-Part') !== name) {
          // Redirected (to the login page): the page itself redirects there too. Anything else that
          // is not the part is an error, never a reload — that could loop.
          if (response.redirected) { location.reload(); return null; }
          throw new Error('not the part');
        }
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.text();
      })
      .then(function (html) {
        if (html === null) return;
        var holder = document.createElement('div');
        holder.innerHTML = html;
        applyMarkers(holder);
        var parent = box.parentNode;
        while (holder.firstChild) parent.insertBefore(holder.firstChild, box);
        parent.removeChild(box);
      })
      .catch(function () { showError(box, name); });
  }

  document.querySelectorAll('[data-lazy-part]').forEach(function (box) {
    load(box, box.getAttribute('data-lazy-part'));
  });
})();
