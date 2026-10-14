/*
 * ELLSMS — login page.
 *
 *  1. Show / hide the password (the button stays hidden without script, so the form never depends on it).
 *  2. The composer on the stage: counts characters and parts exactly as sms_parts() in app/bootstrap.php
 *     bills them — any character outside printable ASCII makes the whole message Unicode, which is one
 *     part up to 70 characters and 67 per part after that; Latin-only is 160 / 153. Characters are
 *     counted as code points, the way mb_strlen() counts them. Nothing typed here leaves the page.
 *  3. The received bubble shows the same text, stamped with the current time in Tehran.
 */
(function () {
  'use strict';

  var FA_DIGITS = '۰۱۲۳۴۵۶۷۸۹';
  function fa(n) { return String(n).replace(/\d/g, function (d) { return FA_DIGITS[d]; }); }

  // 1. Password visibility.
  var pass = document.getElementById('authPassword');
  var reveal = document.getElementById('authReveal');
  if (pass && reveal) {
    reveal.hidden = false;
    reveal.addEventListener('click', function () {
      var show = pass.type === 'password';
      pass.type = show ? 'text' : 'password';
      reveal.textContent = show ? 'پنهان' : 'نمایش';
      reveal.setAttribute('aria-pressed', show ? 'true' : 'false');
      pass.focus();
    });
  }

  // 2. Composer and part counter.
  var box = document.getElementById('smsDemo');
  if (!box) return;
  var out = {
    chars: document.getElementById('smsChars'),
    parts: document.getElementById('smsParts'),
    kind: document.getElementById('smsKind'),
    left: document.getElementById('smsLeft'),
    meter: document.getElementById('smsMeterFill'),
    bubble: document.getElementById('smsBubble'),
    time: document.getElementById('smsTime')
  };
  // The meter is a row of cells, one per part; the fill element in the markup is replaced by them.
  var meter = out.meter ? out.meter.parentNode : null;

  function measure(text) {
    var unicode = /[^\x20-\x7E\r\n]/.test(text);
    var len = Array.from(text).length;
    var single = unicode ? 70 : 160;
    var multi = unicode ? 67 : 153;
    var parts = len === 0 ? 0 : (len <= single ? 1 : Math.ceil(len / multi));
    var capacity = parts <= 1 ? single : parts * multi;
    return { unicode: unicode, len: len, parts: parts, left: capacity - len, per: parts <= 1 ? single : multi };
  }

  function set(el, value) {
    if (!el || el.textContent === value) return;
    el.textContent = value;
    el.classList.remove('is-bump');
    void el.offsetWidth;
    el.classList.add('is-bump');
  }

  function drawMeter(m) {
    if (!meter) return;
    var cells = Math.max(1, m.parts);
    while (meter.children.length < cells) {
      var c = document.createElement('span');
      c.appendChild(document.createElement('i'));
      meter.appendChild(c);
    }
    while (meter.children.length > cells) meter.removeChild(meter.lastChild);
    var usedInLast = m.parts <= 1 ? m.len : m.len - (m.parts - 1) * m.per;
    for (var k = 0; k < cells; k++) {
      var f = k < cells - 1 ? 1 : usedInLast / m.per;
      meter.children[k].firstChild.style.setProperty('--f', String(Math.min(1, f)));
    }
  }

  function update() {
    var text = box.value;
    var m = measure(text);
    set(out.chars, fa(m.len));
    set(out.parts, fa(m.parts));
    set(out.kind, m.len === 0 ? '—' : (m.unicode ? 'فارسی' : 'لاتین'));
    set(out.left, fa(m.len === 0 ? m.per : m.left));
    drawMeter(m);
    if (out.bubble) out.bubble.textContent = text.trim() === '' ? '…' : text;
  }

  if (meter) meter.textContent = '';
  box.addEventListener('input', update);
  update();

  // 3. Timestamp in Tehran.
  if (out.time) {
    var stamp = function () {
      try {
        out.time.textContent = new Intl.DateTimeFormat('fa-IR', { hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Tehran' }).format(new Date());
      } catch (e) { out.time.textContent = ''; }
    };
    stamp();
    setInterval(stamp, 30000);
  }
})();
