// Minimal syntax colouring for the guide's code blocks (runs in the browser before printing).
// Persian text inside a string literal is wrapped in an RTL-isolated span: inside an LTR code line the
// bidi algorithm would otherwise move the sentence's trailing punctuation to the wrong end. A single-quoted
// run that contains double quotes (curl's -d '{"a": "b"}') is not taken as one string, so the JSON inside
// it is coloured and isolated piece by piece.
(function () {
  var TOKEN = /(\s\/\/ [^\n]*)|("(?:[^"\\\n]|\\.)*"|'(?:[^'"\\\n]|\\.)*')(\s*:(?!:))?|((?:^|(?<=\s))-[A-Za-z](?=\s))|((?<![\w.-])\d+(?![\w.-]))/gm;
  var ARABIC = /[\u0600-\u06FF]/;

  function esc(s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }
  function str(s) {
    var q = s[0], inner = s.slice(1, -1);
    var body = ARABIC.test(inner) ? '<span class="fa" dir="rtl">' + esc(inner) + '</span>' : esc(inner);
    return esc(q) + body + esc(q);
  }

  document.querySelectorAll('.code pre').forEach(function (pre) {
    var src = pre.textContent.replace(/^\n/, '').replace(/\s+$/, '');
    var out = '', last = 0, m;
    TOKEN.lastIndex = 0;
    while ((m = TOKEN.exec(src)) !== null) {
      if (m[0] === '') { TOKEN.lastIndex++; continue; }
      out += esc(src.slice(last, m.index));
      if (m[1]) out += '<span class="tc">' + esc(m[1]) + '</span>';
      else if (m[2] && m[3]) out += '<span class="tk">' + str(m[2]) + '</span>' + esc(m[3]);
      else if (m[2]) out += '<span class="ts">' + str(m[2]) + '</span>';
      else if (m[4]) out += '<span class="tf">' + esc(m[4]) + '</span>';
      else if (m[5]) out += '<span class="tn">' + m[5] + '</span>';
      last = TOKEN.lastIndex;
    }
    pre.innerHTML = out + esc(src.slice(last));
  });
})();
