<?php /* «خدمات فناوری اطلاعات» scene: a network topology with live traffic, a server being scanned, a shield drawing its check and turning gears. */ ?>
<svg viewBox="0 0 440 340" class="ps-svg" role="presentation">
  <defs>
    <linearGradient id="psItG" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#34d399"/><stop offset="1" stop-color="#14b8a6"/></linearGradient>
    <radialGradient id="psItGlow"><stop offset="0" stop-color="#10b981" stop-opacity=".38"/><stop offset="1" stop-color="#10b981" stop-opacity="0"/></radialGradient>
    <clipPath id="psItSrv"><rect x="40" y="40" width="96" height="120" rx="10"/></clipPath>
  </defs>
  <circle cx="220" cy="170" r="160" fill="url(#psItGlow)" class="ps-breathe"/>
  <!-- links -->
  <?php $ends = [[88,100],[88,250],[352,90],[360,250],[220,300]];
  foreach ($ends as $i => [$x,$y]): ?>
  <path d="M220 170 L<?= $x ?> <?= $y ?>" class="ps-flow is-green" style="--d:<?= $i * .3 ?>s"/>
  <?php endforeach; ?>
  <!-- server with scan -->
  <g>
    <rect x="40" y="40" width="96" height="120" rx="10" fill="#081a1a" stroke="url(#psItG)" stroke-width="2"/>
    <?php for ($r = 0; $r < 4; $r++): $y = 52 + $r * 26; ?>
    <rect x="50" y="<?= $y ?>" width="76" height="18" rx="4" fill="#0d2a28"/>
    <circle cx="60" cy="<?= $y + 9 ?>" r="3" fill="#34d399" class="ps-blink" style="--d:<?= $r * .4 ?>s"/>
    <rect x="70" y="<?= $y + 7 ?>" width="46" height="4" rx="2" fill="#1d4d48"/>
    <?php endfor; ?>
    <rect x="40" y="40" width="96" height="16" fill="url(#psItG)" opacity=".35" clip-path="url(#psItSrv)" class="ps-scan"/>
  </g>
  <!-- laptop -->
  <g transform="translate(46 222)">
    <rect width="84" height="54" rx="6" fill="#081a1a" stroke="url(#psItG)" stroke-width="2"/>
    <path d="M12 18h30M12 28h50M12 38h22" stroke="#5eead4" stroke-width="3" stroke-linecap="round" opacity=".7"/>
    <path d="M-8 60h100l-8 8H0Z" fill="#0d2a28" stroke="url(#psItG)" stroke-width="1.5"/>
  </g>
  <!-- router -->
  <g transform="translate(320 64)">
    <rect width="72" height="36" rx="8" fill="#081a1a" stroke="url(#psItG)" stroke-width="2"/>
    <?php foreach ([14, 26, 38] as $i => $x): ?><circle cx="<?= $x ?>" cy="18" r="3" fill="#34d399" class="ps-blink" style="--d:<?= $i * .25 ?>s"/><?php endforeach; ?>
    <path d="M58 0v-16M16 0v-12" stroke="#5eead4" stroke-width="2" stroke-linecap="round"/>
    <path d="M50 -24a12 12 0 0 1 16 0M46 -30a20 20 0 0 1 24 0" stroke="#5eead4" stroke-width="1.6" fill="none" class="ps-blink"/>
  </g>
  <!-- gears -->
  <g transform="translate(360 250)">
    <circle r="22" fill="none" stroke="#5eead4" stroke-width="9" stroke-dasharray="6 5.5" class="ps-spin"/>
    <circle r="12" fill="#081a1a" stroke="#5eead4" stroke-width="2"/>
    <g transform="translate(34 -26)"><circle r="12" fill="none" stroke="#34d399" stroke-width="6" stroke-dasharray="4 3.85" class="ps-spin is-rev"/><circle r="5" fill="#081a1a" stroke="#34d399" stroke-width="1.5"/></g>
  </g>
  <!-- switch -->
  <g transform="translate(220 300)">
    <rect x="-46" y="-14" width="92" height="28" rx="7" fill="#081a1a" stroke="url(#psItG)" stroke-width="2"/>
    <?php for ($p = 0; $p < 6; $p++): ?><rect x="<?= -36 + $p * 12 ?>" y="-5" width="8" height="10" rx="1.5" fill="#0d2a28" stroke="#34d399" stroke-width="1" class="ps-blink" style="--d:<?= $p * .2 ?>s"/><?php endfor; ?>
  </g>
  <!-- shield -->
  <g class="ps-float">
    <circle cx="220" cy="166" r="66" fill="none" stroke="url(#psItG)" stroke-width="1.5" opacity=".5" class="ps-ping-soft"/>
    <path d="M220 104 172 122v34c0 30 20 52 48 66 28-14 48-36 48-66v-34Z" fill="rgba(6,40,30,.95)" stroke="url(#psItG)" stroke-width="3"/>
    <path d="m198 162 16 16 30-32" stroke="#34d399" stroke-width="7" fill="none" stroke-linecap="round" stroke-linejoin="round" class="ps-draw"/>
  </g>
</svg>
