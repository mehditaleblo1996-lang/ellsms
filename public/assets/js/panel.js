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

  // Topbar dropdowns are <details> and open without script; here only one stays open at a time, and a
  // click elsewhere or Escape closes it.
  var menus = Array.from(document.querySelectorAll('.topbar-menu'));
  function closeMenus(except) {
    menus.forEach(function (menu) { if (menu !== except) menu.open = false; });
  }
  menus.forEach(function (menu) {
    menu.addEventListener('toggle', function () { if (menu.open) closeMenus(menu); });
  });
  document.addEventListener('click', function (event) {
    if (!event.target.closest('.topbar-menu')) closeMenus(null);
  });
  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;
    var open = menus.filter(function (menu) { return menu.open; })[0];
    if (!open) return;
    open.open = false;
    open.querySelector('summary').focus();
  });

  // Date and live clock, in Tehran time, kept to the server's clock rather than the device's.
  var clock = document.getElementById('topbarClock');
  if (clock && window.Intl) {
    var clockOffset = Number(clock.dataset.now) - Date.now();
    var todayEl = document.getElementById('topbarToday');
    var timeEl = document.getElementById('topbarTime');
    var dayKey = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Tehran' });
    var dayLabel = new Intl.DateTimeFormat('fa-IR-u-ca-persian', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'Asia/Tehran' });
    var timeLabel = new Intl.DateTimeFormat('fa-IR', { hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23', timeZone: 'Asia/Tehran' });
    var shownDay = dayKey.format(new Date(Date.now() + clockOffset));
    (function tick() {
      var now = new Date(Date.now() + clockOffset);
      timeEl.textContent = timeLabel.format(now);
      // The server wrote today's date; it is rewritten only once the day turns over.
      if (dayKey.format(now) !== shownDay) { shownDay = dayKey.format(now); todayEl.textContent = dayLabel.format(now); }
      setTimeout(tick, 1000 - (now.getTime() % 1000));
    })();
  }

  // Credit refresh without reloading the page.
  var creditBox = document.getElementById('topbarCredit');
  var creditRefresh = document.getElementById('topbarCreditRefresh');
  if (creditBox && creditRefresh && window.fetch) {
    creditRefresh.hidden = false;
    creditRefresh.addEventListener('click', function () {
      if (creditRefresh.classList.contains('is-spinning')) return;
      creditRefresh.classList.add('is-spinning');
      creditRefresh.setAttribute('aria-busy', 'true');
      fetch('/credit-status.php', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (response) { return response.ok ? response.json() : Promise.reject(response.status); })
        .then(function (data) {
          if (!data || !data.ok) return Promise.reject('bad');
          document.getElementById('topbarCreditValue').textContent = data.credit_text;
          creditBox.classList.remove('is-low', 'is-warn');
          if (data.level) creditBox.classList.add('is-' + data.level);
          document.getElementById('topbarCreditLow').hidden = data.level !== 'low';
          creditBox.title = data.level === 'low' ? 'اعتبار شما کم است' : 'اعتبار باقی‌مانده';
          creditRefresh.title = 'به‌روزرسانی اعتبار';
        })
        .catch(function () { creditRefresh.title = 'به‌روزرسانی نشد؛ دوباره تلاش کنید'; })
        .then(function () {
          creditRefresh.classList.remove('is-spinning');
          creditRefresh.removeAttribute('aria-busy');
        });
    });
  }

  // Bookmarks and open-page tabs live in this browser only, one list per account (a support session
  // looking at a customer's panel gets its own).
  var topbar = document.querySelector('.topbar');
  var scope = topbar ? topbar.dataset.scope : '';
  var here = location.pathname + location.search;
  var titleEl = document.querySelector('.content > .page-title');
  var hereTitle = (titleEl && titleEl.textContent.trim()) || document.title.split('—')[0].trim();
  function loadList(key) {
    var list;
    try { list = JSON.parse(localStorage.getItem(key + scope) || '[]'); } catch (_) { list = []; }
    return Array.isArray(list) ? list.filter(function (item) {
      return item && typeof item.url === 'string' && /^\/(?!\/)/.test(item.url) && typeof item.title === 'string';
    }) : [];
  }
  function saveList(key, list) {
    try { localStorage.setItem(key + scope, JSON.stringify(list)); } catch (_) {}
  }
  function iconButton(className, icon, label) {
    var button = document.createElement('button');
    button.type = 'button';
    button.className = className;
    button.setAttribute('aria-label', label);
    button.title = label;
    button.innerHTML = icon;
    return button;
  }
  var icons = document.getElementById('topbarIcons');
  function icon(name) {
    var el = icons && icons.content.querySelector('[data-icon="' + name + '"]');
    return el ? el.innerHTML : '';
  }
  var closeIcon = icon('close');

  var bookmarksMenu = document.getElementById('bookmarksMenu');
  if (bookmarksMenu && hereTitle) {
    var BOOKMARKS = 'ellsms.bookmarks.', MAX_BOOKMARKS = 20;
    var bookmarkList = document.getElementById('bookmarkList');
    var bookmarkEmpty = document.getElementById('bookmarkEmpty');
    var bookmarkToggle = document.getElementById('bookmarkToggle');
    var renderBookmarks = function () {
      var list = loadList(BOOKMARKS);
      var marked = list.some(function (item) { return item.url === here; });
      bookmarksMenu.classList.toggle('is-marked', marked);
      bookmarkToggle.textContent = marked ? 'حذف نشانک این صفحه' : 'نشانک کردن این صفحه';
      bookmarkToggle.setAttribute('aria-pressed', String(marked));
      bookmarkToggle.disabled = !marked && list.length >= MAX_BOOKMARKS;
      bookmarkList.replaceChildren();
      list.forEach(function (item) {
        var li = document.createElement('li');
        li.className = 'bookmark-item';
        var link = document.createElement('a');
        link.href = item.url;
        link.textContent = item.title;
        if (item.url === here) link.setAttribute('aria-current', 'page');
        var remove = iconButton('page-tab-btn', closeIcon, 'حذف نشانک «' + item.title + '»');
        remove.addEventListener('click', function () {
          saveList(BOOKMARKS, loadList(BOOKMARKS).filter(function (b) { return b.url !== item.url; }));
          renderBookmarks();
        });
        li.append(link, remove);
        bookmarkList.appendChild(li);
      });
      bookmarkEmpty.hidden = list.length > 0;
    };
    bookmarkToggle.addEventListener('click', function () {
      var list = loadList(BOOKMARKS);
      var without = list.filter(function (item) { return item.url !== here; });
      saveList(BOOKMARKS, without.length < list.length ? without : list.concat([{ url: here, title: hereTitle }]).slice(0, MAX_BOOKMARKS));
      renderBookmarks();
    });
    bookmarksMenu.hidden = false;
    renderBookmarks();
    window.addEventListener('storage', function (event) {
      if (event.key === BOOKMARKS + scope) renderBookmarks();
    });
  }

  var tabsBar = document.getElementById('pageTabs');
  if (tabsBar && hereTitle) {
    var TABS = 'ellsms.pageTabs.', MAX_TABS = 10;
    var tabsList = document.getElementById('pageTabsList');
    // One tab per page: a page opened again with other filters or another record reuses its tab.
    var isHere = function (tab) { return tab.url.split('?')[0] === location.pathname; };
    var goTo = function (list, index) {
      var next = list[Math.min(index, list.length - 1)];
      location.href = next ? next.url : '/index.php';
    };
    var closeTab = function (url) {
      var list = loadList(TABS);
      var index = list.findIndex(function (tab) { return tab.url === url; });
      if (index === -1) return;
      var wasHere = isHere(list[index]);
      list.splice(index, 1);
      saveList(TABS, list);
      if (wasHere) goTo(list, index); else renderTabs();
    };
    var renderTabs = function () {
      tabsList.replaceChildren();
      loadList(TABS).forEach(function (tab) {
        var active = isHere(tab);
        var li = document.createElement('li');
        li.className = 'page-tab' + (active ? ' is-active' : '');
        var link = document.createElement('a');
        link.className = 'page-tab-link';
        link.href = tab.url;
        link.textContent = tab.title;
        link.title = tab.title;
        if (active) link.setAttribute('aria-current', 'page');
        li.appendChild(link);
        if (active) {
          var reload = iconButton('page-tab-btn', icon('refresh'), 'بارگذاری دوباره‌ی «' + tab.title + '»');
          reload.addEventListener('click', function () { location.reload(); });
          li.appendChild(reload);
        }
        var close = iconButton('page-tab-btn', closeIcon, 'بستن تب «' + tab.title + '»');
        close.addEventListener('click', function () { closeTab(tab.url); });
        li.appendChild(close);
        tabsList.appendChild(li);
        if (active) requestAnimationFrame(function () { li.scrollIntoView({ block: 'nearest', inline: 'nearest' }); });
      });
    };
    // This page joins the tabs (or refreshes its title); past the limit the oldest other tab goes.
    var tabs = loadList(TABS);
    var current = tabs.find(isHere);
    if (current) { current.url = here; current.title = hereTitle; } else tabs.push({ url: here, title: hereTitle });
    while (tabs.length > MAX_TABS) tabs.splice(tabs.findIndex(function (tab) { return !isHere(tab); }), 1);
    saveList(TABS, tabs);
    tabsBar.querySelectorAll('[data-tabs-close]').forEach(function (button) {
      button.addEventListener('click', function () {
        closeMenus(null);
        if (button.dataset.tabsClose === 'others') {
          saveList(TABS, loadList(TABS).filter(isHere));
          renderTabs();
        } else {
          saveList(TABS, []);
          location.href = '/index.php';
        }
      });
    });
    tabsBar.hidden = false;
    renderTabs();
    window.addEventListener('storage', function (event) {
      if (event.key === TABS + scope) renderTabs();
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
