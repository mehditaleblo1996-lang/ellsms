<?php /* «تلفن و ارتباطات سازمانی» scene: a ringing VoIP core connecting two offices and a mobile, a voice equaliser and an IVR menu. */ ?>
<svg viewBox="0 0 440 340" class="ps-svg" role="presentation">
  <defs>
    <linearGradient id="psVoipG" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#fbbf24"/><stop offset="1" stop-color="#f97316"/></linearGradient>
    <radialGradient id="psVoipGlow"><stop offset="0" stop-color="#f59e0b" stop-opacity=".38"/><stop offset="1" stop-color="#f59e0b" stop-opacity="0"/></radialGradient>
  </defs>
  <circle cx="220" cy="150" r="160" fill="url(#psVoipGlow)" class="ps-breathe"/>
  <!-- arcs -->
  <path d="M84 120 Q 150 40 220 120" class="ps-flow is-amber"/>
  <path d="M220 120 Q 290 40 356 120" class="ps-flow is-amber" style="--d:.5s"/>
  <path d="M220 150 Q 300 220 356 230" class="ps-flow is-amber" style="--d:.9s"/>
  <path d="M84 150 Q 150 220 220 150" class="ps-flow is-amber" style="--d:.3s"/>
  <!-- office A -->
  <g transform="translate(42 104)">
    <path d="M0 64V14L42 0l42 14v50Z" fill="#1c1408" stroke="url(#psVoipG)" stroke-width="2"/>
    <?php foreach ([[14,20],[34,20],[54,20],[14,38],[34,38],[54,38]] as $i => [$x,$y]): ?><rect x="<?= $x ?>" y="<?= $y ?>" width="12" height="10" rx="2" fill="#fbbf24" class="ps-blink" style="--d:<?= $i * .35 ?>s" opacity=".7"/><?php endforeach; ?>
    <text x="42" y="84" text-anchor="middle" class="ps-label ps-small" fill="#fde68a">دفتر مرکزی</text>
  </g>
  <!-- office B -->
  <g transform="translate(314 104)">
    <path d="M0 64V14L42 0l42 14v50Z" fill="#1c1408" stroke="url(#psVoipG)" stroke-width="2"/>
    <?php foreach ([[14,20],[34,20],[54,20],[14,38],[34,38],[54,38]] as $i => [$x,$y]): ?><rect x="<?= $x ?>" y="<?= $y ?>" width="12" height="10" rx="2" fill="#fbbf24" class="ps-blink" style="--d:<?= $i * .29 + .4 ?>s" opacity=".7"/><?php endforeach; ?>
    <text x="42" y="84" text-anchor="middle" class="ps-label ps-small" fill="#fde68a">شعبه</text>
  </g>
  <!-- core phone with rings -->
  <g transform="translate(220 140)">
    <circle r="40" class="ps-ring" style="--d:0s"/>
    <circle r="40" class="ps-ring" style="--d:.8s"/>
    <circle r="40" class="ps-ring" style="--d:1.6s"/>
    <circle r="40" fill="url(#psVoipG)"/>
    <g class="ps-shake"><path transform="translate(-14 -14) scale(1.17)" d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2Z" fill="#fff"/></g>
  </g>
  <!-- mobile -->
  <g transform="translate(344 200)">
    <rect width="40" height="70" rx="9" fill="#1c1408" stroke="url(#psVoipG)" stroke-width="2"/>
    <rect x="5" y="9" width="30" height="46" rx="4" fill="#2a1d0a"/>
    <circle cx="20" cy="32" r="8" fill="#34d399" class="ps-beat"/>
    <path d="M17 32h6" stroke="#06281b" stroke-width="2"/>
  </g>
  <!-- equaliser -->
  <g transform="translate(160 214)">
    <rect x="-8" y="-10" width="136" height="44" rx="12" fill="rgba(28,20,8,.9)" stroke="rgba(251,191,36,.35)"/>
    <?php for ($b = 0; $b < 12; $b++): ?><rect x="<?= $b * 10 ?>" y="2" width="5" height="20" rx="2.5" fill="url(#psVoipG)" class="ps-eq" style="--d:<?= ($b * 37 % 9) * .09 ?>s"/><?php endfor; ?>
  </g>
  <!-- IVR menu -->
  <g transform="translate(36 236)">
    <rect width="112" height="84" rx="12" fill="rgba(28,20,8,.92)" stroke="rgba(251,191,36,.4)"/>
    <text x="100" y="22" text-anchor="start" class="ps-label ps-small" fill="#fde68a">منوی گویا</text>
    <?php foreach (['۱  فروش', '۲  پشتیبانی', '۳  حسابداری'] as $i => $t): ?>
    <g class="ps-menu" style="--d:<?= $i * .9 ?>s">
      <rect x="8" y="<?= 30 + $i * 17 ?>" width="96" height="14" rx="5" fill="#fbbf24" opacity=".0" class="ps-menu-hl"/>
      <text x="96" y="<?= 41 + $i * 17 ?>" text-anchor="start" class="ps-label ps-small" fill="#fef3c7"><?= $t ?></text>
    </g>
    <?php endforeach; ?>
  </g>
</svg>
