<?php /* «سامانه پیامکی و بازاریابی» scene: a phone sends campaign SMS to recipients, delivery ticks and a rising chart. */ ?>
<svg viewBox="0 0 440 340" class="ps-svg" role="presentation">
  <defs>
    <linearGradient id="psSmsG" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#0b7374"/><stop offset="1" stop-color="#0d7c7e"/></linearGradient>
    <radialGradient id="psSmsGlow"><stop offset="0" stop-color="#0b7374" stop-opacity=".45"/><stop offset="1" stop-color="#0b7374" stop-opacity="0"/></radialGradient>
  </defs>
  <circle cx="220" cy="170" r="160" fill="url(#psSmsGlow)" class="ps-breathe"/>
  <!-- flow lines phone -> recipients -->
  <path d="M168 120 C 120 100, 100 70, 70 62" class="ps-flow"/>
  <path d="M168 170 C 120 170, 100 170, 64 170" class="ps-flow" style="--d:.4s"/>
  <path d="M168 220 C 120 240, 100 270, 70 280" class="ps-flow" style="--d:.8s"/>
  <path d="M272 120 C 320 100, 340 70, 370 62" class="ps-flow" style="--d:.2s"/>
  <path d="M272 170 C 320 170, 340 170, 372 170" class="ps-flow" style="--d:.6s"/>
  <!-- recipients -->
  <?php foreach ([[52,62,0],[46,170,.4],[52,280,.8],[388,62,.2],[394,170,.6]] as [$x,$y,$d]): ?>
  <g transform="translate(<?= $x ?> <?= $y ?>)">
    <circle r="22" class="ps-ping" style="--d:<?= $d + 1.2 ?>s"/>
    <circle r="20" class="ps-node"/>
    <circle cy="-5" r="6" class="ps-glyph"/><path d="M-10 11a10 8 0 0 1 20 0" class="ps-glyph"/>
    <g class="ps-tick" style="--d:<?= $d + 1.2 ?>s"><circle cx="14" cy="-14" r="8" fill="#2dced1"/><path d="m10 -14 3 3 5-5" stroke="#06281b" stroke-width="2" fill="none" stroke-linecap="round"/></g>
  </g>
  <?php endforeach; ?>
  <!-- phone -->
  <g class="ps-float">
    <rect x="160" y="40" width="120" height="240" rx="22" fill="#131517" stroke="url(#psSmsG)" stroke-width="3"/>
    <rect x="170" y="56" width="100" height="208" rx="12" fill="#1a1d20"/>
    <rect x="200" y="46" width="40" height="5" rx="2.5" fill="#32383d"/>
    <rect x="178" y="66" width="60" height="8" rx="4" fill="#9de7e8" opacity=".8"/>
    <g class="ps-pop" style="--d:0s"><rect x="178" y="86" width="84" height="34" rx="10" fill="url(#psSmsG)"/><rect x="186" y="95" width="56" height="5" rx="2.5" fill="#fff" opacity=".9"/><rect x="186" y="105" width="38" height="5" rx="2.5" fill="#fff" opacity=".6"/></g>
    <g class="ps-pop" style="--d:.7s"><rect x="178" y="128" width="84" height="34" rx="10" fill="#292e32"/><rect x="186" y="137" width="64" height="5" rx="2.5" fill="#cfd5d8"/><rect x="186" y="147" width="30" height="5" rx="2.5" fill="#cfd5d8" opacity=".6"/></g>
    <g class="ps-pop" style="--d:1.4s"><rect x="178" y="170" width="84" height="34" rx="10" fill="url(#psSmsG)"/><rect x="186" y="179" width="48" height="5" rx="2.5" fill="#fff" opacity=".9"/><rect x="186" y="189" width="60" height="5" rx="2.5" fill="#fff" opacity=".6"/></g>
    <!-- send button pulse -->
    <circle cx="246" cy="240" r="13" fill="url(#psSmsG)" class="ps-beat"/>
    <path d="M241 240h10m-4-4 4 4-4 4" stroke="#fff" stroke-width="2" fill="none" stroke-linecap="round"/>
    <rect x="178" y="233" width="56" height="14" rx="7" fill="#292e32"/>
  </g>
  <!-- mini chart -->
  <g transform="translate(300 300)">
    <rect x="-6" y="-58" width="120" height="66" rx="12" fill="rgba(15, 17, 19,.85)" stroke="rgba(157, 231, 232,.35)"/>
    <?php foreach ([8,26,44,62,80] as $i => $x): ?>
    <rect x="<?= $x ?>" y="-44" width="12" height="44" rx="3" fill="url(#psSmsG)" class="ps-bar" style="--d:<?= $i * .15 ?>s;--h:<?= [.35,.55,.45,.75,1][$i] ?>"/>
    <?php endforeach; ?>
  </g>
</svg>
