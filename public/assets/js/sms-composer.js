/*
 * ELLSMS — send composer (public/send.php, public/new-send.php).
 *
 *  1. Segment meter: characters, parts, and how many characters are left before the next part,
 *     counted exactly as sms_parts() counts the submitted text (trimmed, CRLF newlines).
 *  2. Recipient stats: valid / invalid / duplicate counts as the numbers are typed, with
 *     normalize_msisdn()'s rules; Persian/Arabic digits are turned into Latin ones in place.
 *  3. Phone preview of the message under the chosen sender line.
 *  4. Live cost from /send-estimate.php (the same estimator «محاسبه‌ی هزینه» uses), debounced.
 *
 * Works on the form marked [data-composer]; fields are found by name, so the page's own markup and
 * submit flow are unchanged. Nothing here is trusted: the server re-parses and re-prices on submit.
 */
(function () {
  'use strict';
  var form = document.querySelector('form[data-composer]');
  if (!form) return;

  var FA = '۰۱۲۳۴۵۶۷۸۹', AR = '٠١٢٣٤٥٦٧٨٩';
  var ORDINALS = ['اول', 'دوم', 'سوم', 'چهارم', 'پنجم', 'ششم', 'هفتم', 'هشتم', 'نهم', 'دهم'];
  function fa(n) { return String(n).replace(/\d/g, function (d) { return FA[d]; }); }
  function faNum(n) { return fa(Number(n).toLocaleString('en-US')).replace(/,/g, '٬'); }
  function ordinal(n) { return ORDINALS[n - 1] || fa(n) + 'ام'; }
  function toLatinDigits(s) {
    return s.replace(/[۰-۹٠-٩]/g, function (d) { var i = FA.indexOf(d); return String(i === -1 ? AR.indexOf(d) : i); });
  }
  function field(name) { return form.elements.namedItem(name); }
  function byId(id) { return document.getElementById(id); }

  var content = field('content');
  var dest = field('destinations');
  var digitToggle = byId('digitConvert');
  if (!content) return;

  /* ---------- 1. Segments (mirror of sms_parts() / cost_estimate_segments()) ---------- */
  function analyzeText(raw) {
    // Browsers submit textarea newlines as CRLF and the server trims before counting.
    var text = raw.replace(/\r\n/g, '\n').replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '');
    var len = 0, odd = null, chars = 0, ascii = 0;
    for (var ch of text) {
      len += ch === '\n' ? 2 : 1;
      chars++;
      if (/[\x20-\x7E\r\n]/.test(ch)) ascii++;
      else if (odd === null) odd = ch;
    }
    var unicode = odd !== null;
    var single = unicode ? 70 : 160, multi = unicode ? 67 : 153;
    var parts = len === 0 ? 0 : (len <= single ? 1 : Math.ceil(len / multi));
    var left = parts <= 1 ? single - len : multi * parts - len;
    var used = parts <= 1 ? len : len - multi * (parts - 1);
    return {
      text: text, len: len, unicode: unicode, parts: parts, left: Math.max(0, left),
      fill: parts === 0 ? 0 : Math.min(1, used / (parts <= 1 ? single : multi)),
      perPart: parts > 1 ? multi : single,
      // A mostly-Latin message turned Unicode by one character (an emoji, a smart quote…).
      oddChar: unicode && ascii >= 20 && ascii / chars >= 0.9 ? odd : null,
    };
  }

  var meter = byId('composerMeter');
  function renderMeter(a) {
    if (!meter) return;
    var bar = meter.querySelector('.meter-fill');
    var count = meter.querySelector('.meter-count');
    var next = meter.querySelector('.meter-next');
    var enc = meter.querySelector('.meter-encoding');
    var note = meter.querySelector('.meter-note');
    bar.style.inlineSize = (a.fill * 100).toFixed(1) + '%';
    meter.classList.toggle('is-full', a.parts > 0 && a.left === 0);
    meter.classList.toggle('is-multi', a.parts > 1);
    count.textContent = a.parts === 0 ? 'پیام هنوز خالی است' : fa(a.len) + ' نویسه · ' + fa(a.parts) + ' بخش';
    if (a.parts === 0) next.textContent = 'تا ' + fa(a.unicode ? 70 : 160) + ' نویسه یک بخش است';
    else if (a.left === 0) next.textContent = 'بخش ' + ordinal(a.parts) + ' پر شد؛ نویسه‌ی بعدی بخش ' + ordinal(a.parts + 1) + ' را شروع می‌کند';
    else next.textContent = fa(a.left) + ' نویسه تا بخش ' + ordinal(a.parts + 1);
    enc.textContent = a.unicode ? 'فارسی / یونیکد · ' + fa(a.perPart) + ' نویسه در هر بخش' : 'لاتین · ' + fa(a.perPart) + ' نویسه در هر بخش';
    var notes = [];
    if (a.parts > 1) notes.push('هر گیرنده ' + fa(a.parts) + ' پیامک حساب می‌شود.');
    if (a.oddChar) notes.push('نویسه‌ی «' + a.oddChar + '» پیام لاتین را یونیکد کرده و ظرفیت هر بخش را از ۱۶۰ به ۷۰ رسانده است.');
    note.textContent = notes.join(' ');
    note.hidden = notes.length === 0;
  }

  /* ---------- 3. Phone preview ---------- */
  var bubble = byId('composerPhoneBubble');
  var phoneSender = byId('composerPhoneSender');
  var phoneMeta = byId('composerPhoneMeta');
  var URL_RE = /(https?:\/\/[^\s]+|www\.[^\s]+)/gi;
  function renderPhone(a) {
    if (!bubble) return;
    bubble.textContent = '';
    bubble.classList.toggle('is-empty', a.text === '');
    if (a.text === '') {
      bubble.textContent = 'متن پیام اینجا، همان‌طور که گیرنده می‌بیند، نمایش داده می‌شود.';
    } else {
      // Built from text nodes only, never innerHTML: the message is user input.
      a.text.split(URL_RE).forEach(function (piece, i) {
        if (piece === '') return;
        if (i % 2 === 1) {
          var link = document.createElement('span');
          link.className = 'phone-link';
          link.textContent = piece;
          bubble.appendChild(link);
        } else {
          bubble.appendChild(document.createTextNode(piece));
        }
      });
    }
    if (phoneMeta) phoneMeta.textContent = a.parts > 0 ? 'اکنون · ' + fa(a.parts) + ' بخش' : 'اکنون';
  }
  function renderSender() {
    if (!phoneSender) return;
    var o = field('originator');
    var value = o && o.value ? o.value.trim() : '';
    phoneSender.textContent = value || 'خط ارسال';
  }

  /* ---------- 2. Recipients (mirror of normalize_msisdn()) ---------- */
  function normalizeMsisdn(raw) {
    var n = raw.trim().replace(/[^\d+]/g, '');
    if (!n) return null;
    if (n[0] === '+') n = n.slice(1);
    if (n.indexOf('00') === 0) n = n.slice(2);
    if (n.indexOf('09') === 0 && n.length === 11) n = '98' + n.slice(1);
    if (n[0] === '9' && n.length === 10) n = '98' + n;
    return /^\d{10,15}$/.test(n) ? n : null;
  }
  function analyzeDest() {
    var tokens = dest ? dest.value.split(/[\s,;،]+/).filter(Boolean) : [];
    var seen = new Set(), valid = [], invalid = [], dup = 0;
    tokens.forEach(function (t) {
      var n = normalizeMsisdn(t);
      if (n === null) invalid.push(t);
      else if (seen.has(n)) dup++;
      else { seen.add(n); valid.push(n); }
    });
    return { tokens: tokens.length, valid: valid, invalid: invalid, dup: dup };
  }

  var stats = byId('composerDestStats');
  var invalidBox = byId('composerInvalid');
  var cleanButton = byId('composerClean');
  var undoButton = byId('composerUndo');
  var beforeClean = null;
  function chip(cls, text) {
    var s = document.createElement('span');
    s.className = 'composer-chip ' + cls;
    s.textContent = text;
    return s;
  }
  function selectedLabel(name) {
    var s = field(name);
    if (!s || !s.value || !s.selectedOptions || !s.selectedOptions[0]) return '';
    return s.selectedOptions[0].textContent.trim();
  }
  function renderDest(d) {
    if (!stats) return;
    var list = stats.querySelector('.composer-chips');
    list.textContent = '';
    if (d.tokens === 0) list.appendChild(chip('is-idle', 'هنوز شماره‌ای وارد نشده'));
    else list.appendChild(chip('is-ok', faNum(d.valid.length) + ' شماره'));
    if (d.invalid.length) list.appendChild(chip('is-err', faNum(d.invalid.length) + ' نامعتبر'));
    if (d.dup) list.appendChild(chip('is-warn', faNum(d.dup) + ' تکراری'));
    var g = selectedLabel('group'), c = selectedLabel('category');
    if (g) list.appendChild(chip('is-extra', '+ گروه «' + g + '»'));
    if (c) list.appendChild(chip('is-extra', '+ دسته‌ی ' + c));
    if (cleanButton) cleanButton.hidden = !(d.invalid.length || d.dup);
    if (undoButton) undoButton.hidden = beforeClean === null;
    if (invalidBox) {
      invalidBox.hidden = d.invalid.length === 0;
      invalidBox.querySelector('summary').textContent = 'نمایش ' + faNum(d.invalid.length) + ' شماره‌ی نامعتبر';
      var ul = invalidBox.querySelector('ul');
      ul.textContent = '';
      d.invalid.slice(0, 50).forEach(function (t) { var li = document.createElement('li'); li.textContent = t; ul.appendChild(li); });
      if (d.invalid.length > 50) { var more = document.createElement('li'); more.textContent = '… و ' + faNum(d.invalid.length - 50) + ' مورد دیگر'; ul.appendChild(more); }
    }
  }
  if (cleanButton) cleanButton.addEventListener('click', function () {
    var d = analyzeDest();
    beforeClean = dest.value;
    dest.value = d.valid.join('\n');
    refresh(true);
  });
  if (undoButton) undoButton.addEventListener('click', function () {
    if (beforeClean === null) return;
    dest.value = beforeClean;
    beforeClean = null;
    refresh(true);
    dest.focus();
  });

  /* ---------- 4. Live cost ---------- */
  var costBox = byId('composerCost');
  var estimateUrl = form.dataset.composerEstimate;
  var liveMax = parseInt(form.dataset.composerLiveMax || '5000', 10);
  var buyUrl = form.dataset.composerBuyUrl || '/buy-credit.php';
  var timer = null, seq = 0, controller = null, pausedUntil = 0;
  var inflightKey = null, cachedKey = null, cached = null; // the request on its way, and the last answer

  function costState(state, build) {
    if (!costBox) return;
    costBox.dataset.state = state;
    costBox.setAttribute('aria-busy', state === 'loading' ? 'true' : 'false');
    var body = costBox.querySelector('.cost-body');
    if (build) { body.textContent = ''; build(body); }
  }
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  }
  function row(body, label, value, cls) {
    var r = el('div', 'cost-row' + (cls ? ' ' + cls : ''));
    r.appendChild(el('span', '', label));
    r.appendChild(el('strong', 'ltr-num', value));
    body.appendChild(r);
  }
  function message(body, text, cls) { body.appendChild(el('p', 'cost-message' + (cls ? ' ' + cls : ''), text)); }
  var announcer = byId('composerAnnounce');
  function announce(text) { if (announcer) announcer.textContent = text; }

  function renderEstimate(r) {
    if (r.ok) {
      costState('ok', function (body) {
        var total = el('div', 'cost-total');
        total.appendChild(el('span', 'cost-total-label', 'هزینه‌ی تقریبی'));
        total.appendChild(el('strong', 'cost-total-value', r.exempt ? 'رایگان' : faNum(r.cost)));
        if (!r.exempt) total.appendChild(el('span', 'cost-total-unit', 'ریال'));
        body.appendChild(total);
        row(body, 'گیرنده‌ی نهایی', faNum(r.recipients.eligible));
        row(body, 'بخش هر پیام', faNum(r.segments.per_recipient));
        row(body, 'کل پیامک‌ها', faNum(r.segments.total));
        if (!r.exempt) row(body, 'موجودی پس از ارسال', faNum(r.wallet.remaining) + ' ریال', r.wallet.sufficient ? '' : 'is-err');
        var removed = [];
        if (r.recipients.blacklisted) removed.push(faNum(r.recipients.blacklisted) + ' شماره‌ی لیست سیاه');
        if (r.recipients.invalid) removed.push(faNum(r.recipients.invalid) + ' نامعتبر');
        if (r.recipients.duplicate) removed.push(faNum(r.recipients.duplicate) + ' تکراری');
        if (removed.length) message(body, 'کنار گذاشته شد: ' + removed.join('، ') + '.');
        if (r.exempt) message(body, 'ارسال مدیر سامانه از اعتبار کسر نمی‌شود.');
        if (!r.exempt && !r.wallet.sufficient) {
          var warn = el('p', 'cost-message is-err', 'اعتبار کافی نیست. ');
          var a = el('a', '', 'خرید اعتبار ←');
          a.href = buyUrl;
          warn.appendChild(a);
          body.appendChild(warn);
        }
        if (!r.quota_sufficient) message(body, 'این ارسال از سقف پیامک پلن شما بیشتر است.', 'is-err');
      });
      announce('هزینه‌ی تقریبی ' + (r.exempt ? 'رایگان' : faNum(r.cost) + ' ریال') + ' برای ' + faNum(r.recipients.eligible) + ' گیرنده');
      return;
    }
    if (r.reason === 'too_many_for_live') {
      costState('info', function (body) { message(body, 'فهرست بیش از ' + faNum(r.limit) + ' شماره دارد؛ هزینه‌ی دقیق را «محاسبه‌ی هزینه» نشان می‌دهد.'); });
      return;
    }
    costState('error', function (body) { message(body, r.message || 'محاسبه‌ی هزینه ممکن نشد.', 'is-warn'); });
  }

  function stop() {
    clearTimeout(timer);
    if (controller) controller.abort();
    seq++;
    inflightKey = null;
  }
  function idle(text) {
    stop();
    costState('idle', function (body) { message(body, text); });
  }

  function estimate(a, d) {
    if (!costBox || !estimateUrl) return;
    var extra = !!((field('group') || {}).value || (field('category') || {}).value);
    if (a.parts === 0 && d.valid.length === 0 && !extra) { idle('برای دیدن هزینه، متن پیام و دست‌کم یک گیرنده وارد کنید.'); return; }
    if (a.parts === 0) { idle('متن پیام را بنویسید تا هزینه محاسبه شود.'); return; }
    if (d.valid.length === 0 && !extra) { idle('دست‌کم یک شماره‌ی معتبر وارد کنید تا هزینه محاسبه شود.'); return; }
    if (d.tokens > liveMax) { stop(); renderEstimate({ ok: false, reason: 'too_many_for_live', limit: liveMax }); return; }

    var data = new FormData();
    data.append('_csrf', (field('_csrf') || {}).value || '');
    data.append('originator', (field('originator') || {}).value || '');
    data.append('content', content.value);
    data.append('destinations', dest ? dest.value : '');
    data.append('group', (field('group') || {}).value || '');
    data.append('category', (field('category') || {}).value || '');
    // new-send.php asks with a checkbox; send.php always filters the blacklist.
    var bl = field('use_blacklist');
    data.append('apply_blacklist', !bl || bl.checked ? '1' : '');
    var key = Array.from(data.entries()).map(function (e) { return e[0] + '=' + e[1]; }).join('&');
    if (key === inflightKey) { clearTimeout(timer); return; }
    if (key === cachedKey) { stop(); renderEstimate(cached); return; }

    stop();
    costState('loading');
    timer = setTimeout(function () {
      var wait = pausedUntil - Date.now();
      if (wait > 0) { timer = setTimeout(function () { estimate(analyzeText(content.value), analyzeDest()); }, wait); return; }
      controller = typeof AbortController === 'function' ? new AbortController() : null;
      var mine = ++seq;
      inflightKey = key;
      fetch(estimateUrl, { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller ? controller.signal : undefined })
        .then(function (res) {
          if (res.status === 429) { pausedUntil = Date.now() + 15000; throw new Error('rate'); }
          var type = res.headers.get('Content-Type') || '';
          if (type.indexOf('application/json') === -1) throw new Error('session');
          return res.json();
        })
        .then(function (r) {
          if (mine !== seq) return;
          inflightKey = null;
          cachedKey = key;
          cached = r;
          renderEstimate(r);
        })
        .catch(function (err) {
          if (mine !== seq || (err && err.name === 'AbortError')) return;
          inflightKey = null;
          costState('error', function (body) {
            message(body, err && err.message === 'rate'
              ? 'به‌روزرسانی زنده‌ی هزینه چند ثانیه متوقف شد؛ با ویرایش بعدی دوباره محاسبه می‌شود.'
              : 'هزینه‌ی زنده در دسترس نیست (شاید نشست شما تمام شده باشد). «محاسبه‌ی هزینه» همچنان کار می‌کند.', 'is-warn');
          });
        });
    }, 650);
  }

  /* ---------- Wiring ---------- */
  function convertDigits(input) {
    var v = input.value, latin = toLatinDigits(v);
    if (latin === v) return;
    var s = input.selectionStart, e = input.selectionEnd; // one character for one, so the caret stays put
    input.value = latin;
    input.setSelectionRange(s, e);
  }
  var destFrame = null;
  function refresh(destChanged) {
    var a = analyzeText(content.value);
    renderMeter(a);
    renderPhone(a);
    var d = analyzeDest();
    if (destChanged) renderDest(d);
    estimate(a, d);
  }
  content.addEventListener('input', function () {
    if (digitToggle && digitToggle.checked) convertDigits(content);
    refresh(false);
  });
  if (digitToggle) digitToggle.addEventListener('change', function () { if (digitToggle.checked) convertDigits(content); refresh(false); });
  if (dest) dest.addEventListener('input', function () {
    convertDigits(dest);
    beforeClean = null;
    cancelAnimationFrame(destFrame);
    destFrame = requestAnimationFrame(function () { refresh(true); });
  });
  ['originator', 'group', 'category', 'use_blacklist'].forEach(function (name) {
    var f = field(name);
    if (!f) return;
    f.addEventListener('change', function () { renderSender(); refresh(true); });
    f.addEventListener('input', function () { renderSender(); });
  });

  // Page scripts that rewrite the fields (campaign loader, emoji, file upload, clipboard) call this.
  window.smsComposerRefresh = function () { renderSender(); refresh(true); };

  renderSender();
  refresh(true);
})();
