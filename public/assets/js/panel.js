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
    if (icon) button.innerHTML = icon; else button.textContent = '×';
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

  // Flash messages each get a close button. A success also leaves the page flow as a toast that goes
  // away by itself (held while hovered or focused); errors and notices stay until closed.
  var toastStack = null;
  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  function dismissFlash(flash) {
    if (flash.classList.contains('is-leaving')) return;
    flash.classList.add('is-leaving');
    setTimeout(function () { flash.remove(); }, reduceMotion ? 0 : 200);
  }
  document.querySelectorAll('[data-flash]').forEach(function (flash) {
    var close = iconButton('flash-close', closeIcon, 'بستن پیام');
    close.addEventListener('click', function () { dismissFlash(flash); });
    flash.appendChild(close);
    if (flash.dataset.flash !== 'success') return;
    if (!toastStack) {
      toastStack = document.createElement('div');
      toastStack.className = 'toast-stack';
      toastStack.setAttribute('role', 'status');
      toastStack.setAttribute('aria-live', 'polite');
      document.body.appendChild(toastStack);
    }
    flash.classList.add('toast');
    // Moved in a moment later, so the live region already exists and the message is read out.
    setTimeout(function () { toastStack.appendChild(flash); }, 60);
    var timer = null, left = 6000, started = 0;
    var run = function () { started = Date.now(); timer = setTimeout(function () { dismissFlash(flash); }, left); };
    var hold = function () { if (timer === null) return; clearTimeout(timer); timer = null; left = Math.max(1500, left - (Date.now() - started)); };
    flash.addEventListener('mouseenter', hold);
    flash.addEventListener('focusin', hold);
    flash.addEventListener('mouseleave', function () { if (timer === null && !flash.contains(document.activeElement)) run(); });
    flash.addEventListener('focusout', function (event) { if (timer === null && !flash.contains(event.relatedTarget)) run(); });
    run();
  });

  // Tables marked data-cards become one card per row on phones (panel.css): every cell carries its
  // column's heading as a label, and its content is wrapped so label and value sit side by side.
  function cardTable(table) {
    if (table.classList.contains('has-cards')) return;
    var rows = Array.from(table.rows);
    var head = table.tHead ? table.tHead.rows[0] : rows.find(function (row) { return row.querySelector('th'); });
    if (!head) return;
    var labels = [];
    Array.from(head.cells).forEach(function (cell) {
      for (var i = 0; i < (cell.colSpan || 1); i++) labels.push(cell.textContent.trim());
    });
    head.classList.add('cards-head');
    rows.forEach(function (row) {
      if (row === head || row.parentNode === table.tHead) return;
      var column = 0;
      Array.from(row.cells).forEach(function (cell) {
        if ((cell.colSpan || 1) === 1 && labels[column]) cell.setAttribute('data-label', labels[column]);
        if (cell.colSpan === 1 && cell.tagName === 'TD') {
          var value = document.createElement('span');
          value.className = 'cell-value';
          while (cell.firstChild) value.appendChild(cell.firstChild);
          cell.appendChild(value);
        }
        column += cell.colSpan || 1;
      });
    });
    table.classList.add('has-cards');
  }

  // Contain wide data tables on every page, including older pages without a table wrapper, and those
  // a lazily loaded part brings in later (assets/js/lazy-parts.js).
  function dressTables(root) {
    root.querySelectorAll('table').forEach(function (table) {
      if (!table.closest('.content')) return;
      if (table.hasAttribute('data-cards')) cardTable(table);
      if (table.closest('.table-wrap')) return;
      var wrap = document.createElement('div');
      wrap.className = 'table-wrap';
      table.parentNode.insertBefore(wrap, table);
      wrap.appendChild(table);
    });
  }
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
  dressTables(document);
  labelScrollableTables();
  document.addEventListener('lazy-part:inserted', function (event) {
    dressTables(event.target);
    labelScrollableTables();
  });
  var resizeFrame;
  window.addEventListener('resize', function () { cancelAnimationFrame(resizeFrame); resizeFrame = requestAnimationFrame(labelScrollableTables); });
  if (document.fonts) document.fonts.ready.then(labelScrollableTables);
})();

// Runs on every panel page, sidebar or not.
(function () {
  'use strict';
  // File inputs become drop zones. The native <input type=file> is kept in place (same id, name,
  // required, listeners) and stretched invisibly over the zone, so clicking and dropping a file are
  // handled by the browser itself; the script only mirrors the chosen file into the zone's text.
  var FILE_ICON_UP = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 18a4.5 4.5 0 0 1-.6-8.96A6 6 0 0 1 18 8.5a4 4 0 0 1-.5 9.5"/><path d="M12 12v8"/><path d="m8.5 15.5 3.5-3.5 3.5 3.5"/></svg>';
  var FILE_ICON_DOC = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h4"/></svg>';
  function faDigits(value) { return String(value).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }
  function fileSize(bytes) {
    if (bytes < 1024) return faDigits(bytes) + ' بایت';
    if (bytes < 1048576) return faDigits(Math.round(bytes / 1024)) + ' کیلوبایت';
    return faDigits((bytes / 1048576).toFixed(1)) + ' مگابایت';
  }
  function acceptedFormats(accept) {
    var seen = [];
    (accept || '').split(',').forEach(function (part) {
      part = part.trim().toLowerCase();
      if (!part) return;
      var name = part.charAt(0) === '.' ? part.slice(1) : (part.split('/')[1] || part);
      name = ({ '*': part.split('/')[0], plain: 'txt', mpeg: 'mp3', jpg: 'jpeg' })[name] || name;
      name = name === 'image' ? 'تصویر' : name === 'video' ? 'ویدیو' : name === 'audio' ? 'صوت' : name.toUpperCase();
      if (seen.indexOf(name) < 0) seen.push(name);
    });
    return seen;
  }
  function dressFileInput(input) {
    if (input.dataset.fdrop || input.closest('.file-picker, .fdrop')) return;
    input.dataset.fdrop = '1';
    var compact = !!input.closest('.lba-actions, .toolbar, td, [data-file-compact]');
    var zone = document.createElement('span');
    zone.className = 'fdrop' + (compact ? ' fdrop-compact' : '');
    var formats = acceptedFormats(input.getAttribute('accept'));
    zone.innerHTML =
      '<span class="fdrop-icon">' + FILE_ICON_UP + '</span>' +
      '<span class="fdrop-text">' +
        '<span class="fdrop-title">' + (compact ? 'انتخاب فایل' : 'فایل را اینجا رها کنید یا <u>انتخاب کنید</u>') + '</span>' +
        '<span class="fdrop-sub">' + (formats.length ? formats.map(function (f) { return '<span class="fdrop-chip">' + f + '</span>'; }).join('') : 'همه‌ی فرمت‌ها') + '</span>' +
      '</span>' +
      '<button type="button" class="fdrop-clear" aria-label="حذف فایل انتخاب‌شده" hidden>' +
        '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button>';
    input.parentNode.insertBefore(zone, input);
    zone.appendChild(input);
    var icon = zone.querySelector('.fdrop-icon');
    var title = zone.querySelector('.fdrop-title');
    var sub = zone.querySelector('.fdrop-sub');
    var clear = zone.querySelector('.fdrop-clear');
    var emptyIcon = icon.innerHTML, emptyTitle = title.innerHTML, emptySub = sub.innerHTML, preview = null;
    function sync() {
      var files = input.files ? Array.prototype.slice.call(input.files) : [];
      if (preview) { URL.revokeObjectURL(preview); preview = null; }
      zone.classList.toggle('has-file', files.length > 0);
      clear.hidden = files.length === 0 || input.disabled;
      if (!files.length) { icon.innerHTML = emptyIcon; title.innerHTML = emptyTitle; sub.innerHTML = emptySub; return; }
      var first = files[0];
      if (/^image\//.test(first.type) && window.URL && URL.createObjectURL) {
        preview = URL.createObjectURL(first);
        icon.innerHTML = '<img alt="" src="' + preview + '">';
      } else {
        var ext = (first.name.split('.').pop() || '').slice(0, 4).toLowerCase();
        var kind = /^(xlsx?|csv|ods)$/.test(ext) ? 'sheet' : /^pdf$/.test(ext) ? 'pdf' : /^(docx?|odt|rtf|md|txt)$/.test(ext) ? 'doc'
          : /^audio\//.test(first.type) ? 'audio' : /^video\//.test(first.type) ? 'video' : 'other';
        icon.innerHTML = ext && first.name.indexOf('.') > 0
          ? '<span class="fdrop-ext" data-kind="' + kind + '">' + ext.toUpperCase().replace(/[^A-Z0-9]/g, '') + '</span>'
          : FILE_ICON_DOC;
      }
      title.textContent = files.length > 1 ? faDigits(files.length) + ' فایل انتخاب شد' : first.name;
      title.title = first.name;
      var total = files.reduce(function (sum, f) { return sum + f.size; }, 0);
      sub.textContent = fileSize(total) + ' · آماده‌ی بارگذاری';
    }
    input.addEventListener('change', sync);
    clear.addEventListener('click', function (event) {
      event.preventDefault();
      input.value = '';
      input.dispatchEvent(new Event('change', { bubbles: true }));
      input.focus();
    });
    ['dragenter', 'dragover'].forEach(function (type) {
      input.addEventListener(type, function () { zone.classList.add('is-dragover'); });
    });
    ['dragleave', 'drop'].forEach(function (type) {
      input.addEventListener(type, function () { zone.classList.remove('is-dragover'); });
    });
    if (input.form) input.form.addEventListener('reset', function () { setTimeout(sync, 0); });
    sync();
  }
  function dressFileInputs(root) {
    Array.prototype.forEach.call((root || document).querySelectorAll('input[type=file]'), dressFileInput);
  }
  dressFileInputs(document);
  document.addEventListener('lazy-part:inserted', function (event) { dressFileInputs(event.target); });
  window.panelDressFileInputs = dressFileInputs;
})();

// Dropdowns: on a mouse/trackpad device a <select> opens a styled list instead of the OS one. The
// <select> stays the real control (value, name, form submission, change listeners, keyboard arrows);
// the list only sets its value and fires input/change. Touch devices keep the native picker.
(function () {
  'use strict';
  var fine = window.matchMedia('(pointer: fine)');
  var CHECK = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>';
  var open = null; // { select, pop, list, search, items, active }
  var uid = 0;

  function eligible(select) {
    return select && select.tagName === 'SELECT' && !select.multiple && !(select.size > 1) && !select.disabled
      && fine.matches && select.closest('.panel') && !select.closest('[data-native-select]');
  }
  function norm(text) {
    return String(text || '').replace(/[يى]/g, 'ی').replace(/ك/g, 'ک').replace(/[‌\s]+/g, ' ')
      .replace(/[۰-۹]/g, function (d) { return String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)); }).trim().toLowerCase();
  }
  function close(restoreFocus) {
    if (!open) return;
    var o = open; open = null;
    o.select.setAttribute('aria-expanded', 'false');
    o.select.classList.remove('is-open');
    o.pop.classList.add('is-leaving');
    setTimeout(function () { o.pop.remove(); }, 120);
    if (restoreFocus) o.select.focus({ preventScroll: true });
  }
  function setActive(index, scroll) {
    var items = open.items.filter(function (it) { return !it.el.hidden && !it.disabled; });
    if (!items.length) { open.active = null; return; }
    var cur = open.active ? items.indexOf(open.active) : -1;
    var next = index === 'first' ? 0 : index === 'last' ? items.length - 1 : Math.max(0, Math.min(items.length - 1, cur + index));
    if (open.active) open.active.el.classList.remove('is-active');
    open.active = items[next];
    open.active.el.classList.add('is-active');
    open.list.setAttribute('aria-activedescendant', open.active.el.id);
    if (scroll) open.active.el.scrollIntoView({ block: 'nearest' });
  }
  function choose(item) {
    var select = open.select;
    var changed = select.selectedIndex !== item.index;
    select.selectedIndex = item.index;
    close(true);
    if (changed) {
      select.dispatchEvent(new Event('input', { bubbles: true }));
      select.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }
  function place(select, pop) {
    var r = select.getBoundingClientRect();
    var vw = document.documentElement.clientWidth, vh = window.innerHeight;
    var width = Math.min(Math.max(r.width, 200), 440, vw - 16);
    pop.style.width = width + 'px';
    var rtl = getComputedStyle(select).direction === 'rtl';
    var left = rtl ? r.right - width : r.left;
    pop.style.left = Math.max(8, Math.min(left, vw - width - 8)) + 'px';
    var below = vh - r.bottom - 12, above = r.top - 12;
    var up = below < 220 && above > below;
    var max = Math.max(140, Math.min(340, up ? above : below));
    pop.style.maxHeight = max + 'px';
    pop.classList.toggle('is-up', up);
    if (up) { pop.style.top = ''; pop.style.bottom = (vh - r.top + 6) + 'px'; }
    else { pop.style.bottom = ''; pop.style.top = (r.bottom + 6) + 'px'; }
  }
  function filter(query) {
    var q = norm(query), shown = 0;
    open.items.forEach(function (it) { it.el.hidden = q !== '' && it.key.indexOf(q) < 0; if (!it.el.hidden) shown++; });
    open.groups.forEach(function (g) { g.hidden = !g.nextItems.some(function (it) { return !it.el.hidden; }); });
    open.empty.hidden = shown > 0;
    if (open.active && open.active.el.hidden) open.active = null;
    if (!open.active) setActive('first', true);
  }
  function build(select) {
    close(false);
    var pop = document.createElement('div');
    pop.className = 'csel-pop';
    pop.dir = getComputedStyle(select).direction;
    var list = document.createElement('div');
    list.className = 'csel-list';
    list.setAttribute('role', 'listbox');
    list.tabIndex = -1;
    var label = select.getAttribute('aria-label') || (select.labels && select.labels[0] ? select.labels[0].textContent.trim().split('\n')[0] : '');
    if (label) list.setAttribute('aria-label', label);
    var items = [], groups = [], lastGroup = null, base = 'csel-' + (++uid);
    Array.prototype.forEach.call(select.options, function (opt, index) {
      if (opt.hidden) return;
      var parent = opt.parentNode;
      if (parent.tagName === 'OPTGROUP' && parent !== lastGroup) {
        lastGroup = parent;
        var g = document.createElement('div');
        g.className = 'csel-group'; g.textContent = parent.label; g.nextItems = [];
        list.appendChild(g); groups.push(g);
      }
      var el = document.createElement('div');
      el.className = 'csel-opt';
      el.id = base + '-' + index;
      el.setAttribute('role', 'option');
      var selected = index === select.selectedIndex;
      el.setAttribute('aria-selected', selected ? 'true' : 'false');
      var disabled = opt.disabled || (parent.tagName === 'OPTGROUP' && parent.disabled);
      if (disabled) el.setAttribute('aria-disabled', 'true');
      var text = document.createElement('span');
      text.className = 'csel-text';
      text.textContent = opt.text.trim() || ' ';
      el.appendChild(text);
      var mark = document.createElement('span');
      mark.className = 'csel-check';
      if (selected) mark.innerHTML = CHECK;
      el.appendChild(mark);
      var item = { el: el, index: index, disabled: disabled, key: norm(opt.text) };
      if (parent.tagName === 'OPTGROUP') lastGroup.nextItems.push(item);
      items.push(item);
      list.appendChild(el);
    });
    var search = null;
    if (items.length > 9) {
      var box = document.createElement('div');
      box.className = 'csel-search';
      search = document.createElement('input');
      search.type = 'search';
      search.placeholder = 'جست‌وجو…';
      search.setAttribute('aria-label', 'جست‌وجو در گزینه‌ها');
      search.autocomplete = 'off';
      box.appendChild(search);
      pop.appendChild(box);
    }
    var empty = document.createElement('div');
    empty.className = 'csel-empty'; empty.textContent = 'گزینه‌ای پیدا نشد'; empty.hidden = true;
    list.appendChild(empty);
    pop.appendChild(list);
    document.body.appendChild(pop);
    open = { select: select, pop: pop, list: list, search: search, items: items, groups: groups, empty: empty, active: null };
    select.setAttribute('aria-expanded', 'true');
    select.classList.add('is-open');
    place(select, pop);
    var current = items.filter(function (it) { return it.index === select.selectedIndex; })[0];
    if (current && !current.disabled) { open.active = current; current.el.classList.add('is-active'); list.setAttribute('aria-activedescendant', current.el.id); }
    else setActive('first', false);
    if (open.active) open.active.el.scrollIntoView({ block: 'center' });
    list.addEventListener('mousemove', function (event) {
      var el = event.target.closest('.csel-opt');
      if (!el || !open || (open.active && open.active.el === el)) return;
      var it = open.items.filter(function (x) { return x.el === el; })[0];
      if (!it || it.disabled) return;
      if (open.active) open.active.el.classList.remove('is-active');
      open.active = it; el.classList.add('is-active');
    });
    list.addEventListener('click', function (event) {
      var el = event.target.closest('.csel-opt');
      var it = el && open && open.items.filter(function (x) { return x.el === el; })[0];
      if (it && !it.disabled) choose(it);
    });
    pop.addEventListener('keydown', onKey);
    if (search) {
      search.addEventListener('input', function () { filter(search.value); });
      search.focus({ preventScroll: true });
    } else {
      list.focus({ preventScroll: true });
    }
  }
  var typed = '', typedAt = 0;
  function onKey(event) {
    if (!open) return;
    var k = event.key;
    if (k === 'Escape') { event.preventDefault(); close(true); }
    else if (k === 'Tab') { close(true); }
    else if (k === 'ArrowDown') { event.preventDefault(); setActive(1, true); }
    else if (k === 'ArrowUp') { event.preventDefault(); setActive(-1, true); }
    else if (k === 'Home' && !open.search) { event.preventDefault(); setActive('first', true); }
    else if (k === 'End' && !open.search) { event.preventDefault(); setActive('last', true); }
    else if (k === 'PageDown') { event.preventDefault(); setActive(8, true); }
    else if (k === 'PageUp') { event.preventDefault(); setActive(-8, true); }
    else if (k === 'Enter' || (k === ' ' && !open.search)) { event.preventDefault(); if (open.active) choose(open.active); }
    else if (!open.search && k.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
      var now = Date.now();
      typed = (now - typedAt > 700 ? '' : typed) + norm(k); typedAt = now;
      var hit = open.items.filter(function (it) { return !it.disabled && it.key.indexOf(typed) === 0; })[0];
      if (hit) { if (open.active) open.active.el.classList.remove('is-active'); open.active = hit; hit.el.classList.add('is-active'); hit.el.scrollIntoView({ block: 'nearest' }); }
    }
  }

  document.addEventListener('mousedown', function (event) {
    if (open && open.pop.contains(event.target)) return;
    var select = event.target.closest && event.target.closest('select');
    if (event.button === 0 && eligible(select)) {
      event.preventDefault();
      if (open && open.select === select) { close(true); return; }
      select.focus({ preventScroll: true });
      build(select);
      return;
    }
    close(false);
  }, true);
  document.addEventListener('keydown', function (event) {
    var select = event.target;
    if (open || !eligible(select)) return;
    if (event.key === ' ' || event.key === 'F4' || (event.altKey && (event.key === 'ArrowDown' || event.key === 'ArrowUp'))) {
      event.preventDefault();
      build(select);
    }
  }, true);
  window.addEventListener('scroll', function (event) {
    if (open && !open.pop.contains(event.target)) close(false);
  }, true);
  window.addEventListener('resize', function () { close(false); });
  window.addEventListener('blur', function () { close(false); });
})();

