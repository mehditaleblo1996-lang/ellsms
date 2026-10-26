/*
 * ELLSMS — lazily loaded page parts (app/LazyParts.php).
 *
 * Every [data-lazy-part] box fetches its section from the page's own URL with ?_part=<name>, all in
 * parallel, and is replaced by the answer. A redirected response without the X-Lazy-Part header is
 * the login page (the session expired), so the whole page reloads and the normal redirect takes
 * over. A failed or timed-out part shows a retry button in its own box only.
 *
 * A live box (data-lazy-poll="N", lazy_part_live_placeholder()) is kept and refilled in place every
 * N seconds while the tab is visible, one request at a time; a failed refresh keeps the last content.
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
    box.classList.add('lazy-part-failed');
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

  // Next refresh of a live box. Each load() bumps the box's generation, so a timer or an answer from
  // an older load (superseded by a `lazy-part:refresh`) is dropped instead of running a second chain.
  function schedule(box, name, gen) {
    var seconds = parseInt(box.getAttribute('data-lazy-poll'), 10) || 3;
    setTimeout(function () {
      if (box.__lazyGen !== gen) return;
      if (document.visibilityState === 'visible') { load(box, name); return; }
      var resume = function () {
        if (document.visibilityState !== 'visible') return;
        document.removeEventListener('visibilitychange', resume);
        if (box.__lazyGen === gen) load(box, name);
      };
      document.addEventListener('visibilitychange', resume);
    }, seconds * 1000);
  }

  function load(box, name) {
    var live = box.hasAttribute('data-lazy-poll');
    var hasContent = live && box.classList.contains('lazy-part-ready');
    var gen = box.__lazyGen = (box.__lazyGen || 0) + 1;
    if (!hasContent) {
      // Back to the skeleton the page printed (it is already there on the first load).
      box.setAttribute('aria-busy', 'true');
      box.classList.remove('lazy-part-failed');
      if (box.__skeleton !== undefined && box.innerHTML !== box.__skeleton) box.innerHTML = box.__skeleton;
    }
    fetch(partUrl(name), { credentials: 'same-origin', cache: 'no-store', headers: { 'X-Requested-With': 'lazy-part' } })
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
        if (html === null || box.__lazyGen !== gen) return;
        var holder = document.createElement('div');
        holder.innerHTML = html;
        applyMarkers(holder);
        if (live) {
          box.innerHTML = '';
          while (holder.firstChild) box.appendChild(holder.firstChild);
          box.style.minHeight = '';
          box.setAttribute('aria-busy', 'false');
          box.classList.add('lazy-part-ready');
          box.classList.remove('lazy-part-stale');
          box.dispatchEvent(new CustomEvent('lazy-part:loaded', { bubbles: true }));
          box.dispatchEvent(new CustomEvent('lazy-part:inserted', { bubbles: true }));
          schedule(box, name, gen);
          return;
        }
        var parent = box.parentNode;
        while (holder.firstChild) parent.insertBefore(holder.firstChild, box);
        parent.removeChild(box);
        // New tables and messages for assets/js/panel.js to dress (scroll hints, mobile cards).
        parent.dispatchEvent(new CustomEvent('lazy-part:inserted', { bubbles: true }));
      })
      .catch(function () {
        if (box.__lazyGen !== gen) return;
        if (!live) { showError(box, name); return; }
        if (hasContent) box.classList.add('lazy-part-stale');
        else showError(box, name);
        schedule(box, name, gen);
      });
  }

  document.querySelectorAll('[data-lazy-part]').forEach(function (box) {
    var name = box.getAttribute('data-lazy-part');
    box.__skeleton = box.innerHTML;
    if (box.hasAttribute('data-lazy-poll')) {
      box.addEventListener('lazy-part:refresh', function () { load(box, name); });
    }
    load(box, name);
  });
})();
