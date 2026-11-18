<?php /* Telephony slide 3 «شعب، دورکاری و ضبط مکالمات»: head office, a branch and a colleague on mobile
   talk over the VoIP network (calls flowing both ways on the arcs); a REC badge blinks over a recording
   waveform and the calls report's bars grow. 440×250, content above y 212. */
$hub = [220, 96];
$ends = [
    ['M196 104 C150 130 120 150 92 150', 'دفتر مرکزی'],
    ['M244 86 C290 60 320 48 350 48', 'شعبه'],
    ['M244 106 C290 130 320 150 352 152', 'موبایل همکار'],
]; ?>
<svg viewBox="0 0 440 250" class="ps-svg pc-svg" aria-hidden="true" focusable="false">
  <defs>
    <linearGradient id="pcVo3G" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#fbbf24"/><stop offset="1" stop-color="#f97316"/></linearGradient>
    <radialGradient id="pcVo3Glow"><stop offset="0" stop-color="#f59e0b" stop-opacity=".28"/><stop offset="1" stop-color="#f59e0b" stop-opacity="0"/></radialGradient>
  </defs>
  <circle cx="220" cy="100" r="130" fill="url(#pcVo3Glow)" class="ps-breathe"/>
  <!-- calls on the arcs -->
  <?php foreach ($ends as $i => [$d]): ?>
  <path d="<?= $d ?>" class="pc-link is-amber pc-draw-link" pathLength="100" style="--d:<?= .3 + $i * .25 ?>s"/>
  <path d="<?= $d ?>" class="pc-pkt is-amber" pathLength="100" style="--d:<?= 1 + $i * .4 ?>s;--t:1.6s"/>
  <path d="<?= $d ?>" class="pc-pkt is-amber is-rev is-dim" pathLength="100" style="--d:<?= 1.6 + $i * .4 ?>s;--t:1.6s"/>
  <?php endforeach; ?>
  <!-- VoIP hub -->
  <g class="pc-in" style="--d:.05s">
    <circle cx="<?= $hub[0] ?>" cy="<?= $hub[1] ?>" r="26" class="ps-ring" style="--d:0s"/>
    <circle cx="<?= $hub[0] ?>" cy="<?= $hub[1] ?>" r="26" class="ps-ring" style="--d:1.2s"/>
    <circle cx="<?= $hub[0] ?>" cy="<?= $hub[1] ?>" r="26" fill="url(#pcVo3G)"/>
    <circle cx="<?= $hub[0] ?>" cy="<?= $hub[1] ?>" r="10" fill="none" stroke="#fff" stroke-width="2"/>
    <path d="M211 96h18M220 87c3 3 3 15 0 18M220 87c-3 3-3 15 0 18" fill="none" stroke="#fff" stroke-width="1.6"/>
    <text x="220" y="140" text-anchor="middle" class="ps-label ps-small" fill="#fde68a">شبکه‌ی VoIP</text>
  </g>
  <!-- head office -->
  <g class="pc-in" style="--d:.4s">
    <path d="M44 176V134l30-12 30 12v42Z" fill="#1c1408" stroke="url(#pcVo3G)" stroke-width="1.6"/>
    <?php foreach ([[56, 142], [69, 142], [82, 142], [56, 157], [69, 157], [82, 157]] as $k => [$x, $y]): ?><rect x="<?= $x ?>" y="<?= $y ?>" width="9" height="8" rx="1.5" fill="#fbbf24" opacity=".75" class="ps-blink" style="--d:<?= $k * .3 ?>s"/><?php endforeach; ?>
    <text x="74" y="194" text-anchor="middle" class="ps-label ps-small" fill="#fde68a">دفتر مرکزی</text>
  </g>
  <!-- branch -->
  <g class="pc-in" style="--d:.55s">
    <path d="M350 66V34l24-10 24 10v32Z" fill="#1c1408" stroke="url(#pcVo3G)" stroke-width="1.6"/>
    <?php foreach ([[360, 40], [371, 40], [382, 40], [360, 52], [371, 52], [382, 52]] as $k => [$x, $y]): ?><rect x="<?= $x ?>" y="<?= $y ?>" width="8" height="7" rx="1.5" fill="#fbbf24" opacity=".7"/><?php endforeach; ?>
    <text x="374" y="84" text-anchor="middle" class="ps-label ps-small" fill="#fde68a">شعبه</text>
  </g>
  <!-- mobile colleague -->
  <g class="pc-in" style="--d:.7s">
    <rect x="352" y="128" width="30" height="52" rx="7" fill="#1c1408" stroke="url(#pcVo3G)" stroke-width="1.6"/>
    <rect x="356" y="135" width="22" height="34" rx="3" fill="#2a1d0a"/>
    <circle cx="367" cy="152" r="6" fill="#fbbf24" class="ps-beat"/>
    <text x="367" y="198" text-anchor="middle" class="ps-label ps-small" fill="#fde68a">دورکاری</text>
  </g>
  <!-- recording -->
  <g class="pc-in" style="--d:.9s">
    <rect x="150" y="160" width="140" height="40" rx="12" fill="rgba(28,20,8,.94)" stroke="rgba(251,191,36,.35)"/>
    <circle cx="276" cy="180" r="5" fill="#ef4444" class="pc-rec"/>
    <text x="266" y="184" text-anchor="start" class="ps-small pc-latin" fill="#fecaca">REC</text>
    <?php for ($b = 0; $b < 9; $b++): ?><rect x="<?= 160 + $b * 9 ?>" y="170" width="4" height="20" rx="2" fill="url(#pcVo3G)" class="ps-eq" style="--d:<?= ($b * 37 % 9) * .09 ?>s"/><?php endfor; ?>
  </g>
  <!-- calls report -->
  <g class="pc-in" style="--d:1.1s">
    <rect x="22" y="18" width="116" height="78" rx="12" fill="rgba(28,20,8,.94)" stroke="rgba(251,191,36,.35)"/>
    <text x="126" y="36" text-anchor="start" class="ps-label ps-small" fill="#fde68a">گزارش تماس‌ها</text>
    <?php foreach ([.55, .8, .45, .95, .7, .6] as $k => $h): ?>
    <rect x="<?= 34 + $k * 16 ?>" y="<?= 86 - 38 * $h ?>" width="9" height="<?= round(38 * $h, 1) ?>" rx="2.5" fill="url(#pcVo3G)" class="pc-grow" style="--d:<?= 1.4 + $k * .12 ?>s"/>
    <?php endforeach; ?>
  </g>
</svg>
