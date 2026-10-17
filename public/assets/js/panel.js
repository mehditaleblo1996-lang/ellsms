/* Local, dependency-free navigation. Never changes form actions or account permissions. */
(function () {
  'use strict';
  var sidebar = document.getElementById('sidebar');
  var toggle = document.getElementById('menuToggle');
  var closeButton = document.getElementById('sidebarClose');
  var backdrop = document.getElementById('sidebarBackdrop');
  var main = document.querySelector('.panel .main');
  if (!sidebar || !toggle || !main) return;
  var mobile = window.matchMedia('(max-width: 1100px)');
  var search = document.getElementById('navSearch');
  var searchEmpty = document.getElementById('navSearchEmpty');
  var groups = Array.from(sidebar.querySelectorAll('.nav-group'));
  var state = {};
  var filtering = false;
  try { state = JSON.parse(localStorage.getItem('ellsms.navGroups') || '{}') || {}; } catch (_) {}
  if (typeof state !== 'object' || Array.isArray(state)) state = {};

  groups.forEach(function (group) {
    var id = group.dataset.group;
    if (!group.classList.contains('has-active') && typeof state[id] === 'boolean') group.open = state[id];
    group.addEventListener('toggle', function () {
      if (filtering) return;
      state[id] = group.open;
      try { localStorage.setItem('ellsms.navGroups', JSON.stringify(state)); } catch (_) {}
    });
  });

  function setOpen(open, restoreFocus) {
    open = mobile.matches && open;
    sidebar.inert = mobile.matches && !open;
    main.inert = open;
    sidebar.classList.toggle('is-open', open);
    backdrop.classList.toggle('is-open', open);
    document.body.classList.toggle('panel-menu-open', open);
    toggle.setAttribute('aria-expanded', String(open));
    if (open) {
      sidebar.setAttribute('role', 'dialog');
      sidebar.setAttribute('aria-modal', 'true');
      closeButton.focus({ preventScroll: true });
    } else {
      sidebar.removeAttribute('role');
      sidebar.removeAttribute('aria-modal');
      if (restoreFocus) toggle.focus({ preventScroll: true });
    }
  }
  toggle.addEventListener('click', function () { setOpen(!sidebar.classList.contains('is-open'), true); });
  closeButton.addEventListener('click', function () { setOpen(false, true); });
  backdrop.addEventListener('click', function () { setOpen(false, true); });
  sidebar.querySelectorAll('a').forEach(function (link) {
    link.addEventListener('click', function () { if (mobile.matches) setOpen(false, false); });
  });
  document.addEventListener('keydown', function (event) {
    if (!sidebar.classList.contains('is-open')) return;
    if (event.key === 'Escape') { event.preventDefault(); setOpen(false, true); }
    if (event.key !== 'Tab') return;
    var focusable = Array.from(sidebar.querySelectorAll('a,button,input,summary,[tabindex="0"]')).filter(function (el) {
      return !el.disabled && el.getClientRects().length && getComputedStyle(el).visibility !== 'hidden';
    });
    var first = focusable[0], last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  });
  mobile.addEventListener('change', function () {
    var focusWasInside = sidebar.contains(document.activeElement);
    setOpen(false, mobile.matches && focusWasInside);
  });
  setOpen(false, false);

  // Persian/Arabic forms of ی and ک, and optional spaces, should find the same menu.
  function normalize(value) {
    return value.toLocaleLowerCase('fa').replace(/ي/g, 'ی').replace(/ك/g, 'ک').replace(/[\s\u200c\u200f]+/g, '');
  }
  if (search) {
    search.closest('.nav-search').hidden = false;
    var beforeSearch = new Map();
    search.addEventListener('input', function () {
      var query = normalize(search.value);
      if (query && !filtering) groups.forEach(function (g) { beforeSearch.set(g, g.open); });
      filtering = !!query;
      var matches = 0;
      groups.forEach(function (group) {
        var groupMatch = query && normalize(group.querySelector('summary').textContent).includes(query);
        var count = 0;
        group.querySelectorAll('.nav-item').forEach(function (link) {
          link.hidden = !!query && !groupMatch && !normalize(link.textContent).includes(query);
          if (!link.hidden) count++;
        });
        group.hidden = count === 0;
        if (query) group.open = count > 0;
        else if (beforeSearch.has(group)) group.open = beforeSearch.get(group);
        matches += count;
      });
      searchEmpty.hidden = !query || matches > 0;
    });
  }

  // Contain wide data tables on every page, including older pages without a table wrapper.
  document.querySelectorAll('.content table').forEach(function (table) {
    if (table.closest('.table-wrap')) return;
    var wrap = document.createElement('div');
    wrap.className = 'table-wrap';
    table.parentNode.insertBefore(wrap, table);
    wrap.appendChild(table);
  });
  function labelScrollableTables() {
    document.querySelectorAll('.content .table-wrap').forEach(function (wrap) {
      var hint = wrap.previousElementSibling;
      if (!hint || !hint.classList.contains('table-scroll-hint')) {
        hint = document.createElement('p');
        hint.className = 'table-scroll-hint';
        hint.textContent = 'برای دیدن همهٔ ستون‌ها، جدول را افقی پیمایش کنید ←';
        wrap.before(hint);
      }
      hint.hidden = wrap.scrollWidth <= wrap.clientWidth + 1;
      if (wrap.scrollWidth > wrap.clientWidth + 1) {
        wrap.tabIndex = 0;
        wrap.setAttribute('role', 'region');
        var title = wrap.closest('.card');
        title = title && title.querySelector('h2');
        wrap.setAttribute('aria-label', 'جدول ' + (title ? title.textContent.trim() : document.title.split('—')[0].trim()) + '؛ برای دیدن ستون‌ها افقی پیمایش کنید');
      } else {
        wrap.removeAttribute('tabindex'); wrap.removeAttribute('role'); wrap.removeAttribute('aria-label');
      }
    });
  }
  labelScrollableTables();
  var resizeFrame;
  window.addEventListener('resize', function () { cancelAnimationFrame(resizeFrame); resizeFrame = requestAnimationFrame(labelScrollableTables); });
  if (document.fonts) document.fonts.ready.then(labelScrollableTables);
})();
