/*
 * Live check of a new password against the panel's rules (app/PasswordSecurity.php), so the user
 * sees what is missing while typing instead of after submitting. The server checks the same rules
 * again — this file only saves a round trip.
 *
 *   <input type="password" data-pw-policy [data-pw-username="ali"] [data-pw-username-field="username"]>
 *   <span data-pw-rules><span data-rule="length">…</span>…</span>      (password_policy_hint_html())
 *   <input type="password" data-pw-repeat="new">                      (must equal the input named "new")
 */
(function () {
  'use strict';

  var MIN = 8, MAX = 128;
  var LETTER, DIGIT;
  try {
    LETTER = new RegExp('\\p{L}', 'u');
    DIGIT = new RegExp('\\p{N}', 'u');
  } catch (e) {
    // A browser without Unicode property escapes: Latin and Persian letters, Latin and Persian digits.
    LETTER = /[A-Za-z؀-ۿ]/;
    DIGIT = /[0-9۰-۹٠-٩]/;
  }

  function usernameFor(input) {
    if (input.dataset.pwUsername) return input.dataset.pwUsername;
    var field = input.dataset.pwUsernameField;
    if (field && input.form && input.form.elements[field]) return input.form.elements[field].value || '';
    return '';
  }

  function problems(value, username) {
    var out = [];
    var length = Array.from(value).length;
    if (length < MIN || length > MAX) out.push('length');
    if (!LETTER.test(value)) out.push('letter');
    if (!DIGIT.test(value)) out.push('digit');
    username = (username || '').trim();
    if (username !== '' && value.toLowerCase() === username.toLowerCase()) out.push('username');
    return out;
  }

  function rulesBoxFor(input) {
    var scope = input.closest('label') || input.parentNode;
    var box = scope ? scope.querySelector('[data-pw-rules]') : null;
    if (!box && input.form) box = input.form.querySelector('[data-pw-rules]');
    return box;
  }

  function check(input) {
    var value = input.value;
    var broken = problems(value, usernameFor(input));
    var box = rulesBoxFor(input);
    if (box) {
      box.querySelectorAll('[data-rule]').forEach(function (line) {
        var bad = broken.indexOf(line.dataset.rule) !== -1;
        line.classList.toggle('is-ok', value !== '' && !bad);
        line.classList.toggle('is-bad', value !== '' && bad);
      });
    }
    // Blocks the submit and lets the browser point at the field; the list above says what is missing.
    input.setCustomValidity(value !== '' && broken.length ? 'رمز عبور هنوز همه‌ی شرط‌ها را ندارد.' : '');
    if (input.form) {
      input.form.querySelectorAll('[data-pw-repeat="' + input.name + '"]').forEach(checkRepeat);
    }
  }

  function checkRepeat(repeat) {
    var first = repeat.form ? repeat.form.elements[repeat.dataset.pwRepeat] : null;
    var differs = first && repeat.value !== '' && repeat.value !== first.value;
    repeat.setCustomValidity(differs ? 'تکرار رمز عبور با رمز عبور یکسان نیست.' : '');
  }

  function init(root) {
    root.querySelectorAll('input[data-pw-policy]').forEach(function (input) {
      if (input.dataset.pwBound) return;
      input.dataset.pwBound = '1';
      input.addEventListener('input', function () { check(input); });
      var field = input.dataset.pwUsernameField;
      if (field && input.form && input.form.elements[field]) {
        input.form.elements[field].addEventListener('input', function () { check(input); });
      }
    });
    root.querySelectorAll('input[data-pw-repeat]').forEach(function (repeat) {
      if (repeat.dataset.pwBound) return;
      repeat.dataset.pwBound = '1';
      repeat.addEventListener('input', function () { checkRepeat(repeat); });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { init(document); });
  } else {
    init(document);
  }
})();
