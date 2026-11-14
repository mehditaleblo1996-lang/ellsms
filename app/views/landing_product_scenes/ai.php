<?php /* «هوش مصنوعی و اتوماسیون» scene: an AI core with a neural net, a document writing itself and an automated workflow with a turning gear. */ ?>
<svg viewBox="0 0 440 340" class="ps-svg" role="presentation">
  <defs>
    <linearGradient id="psAiG" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#c084fc"/><stop offset="1" stop-color="#ec4899"/></linearGradient>
    <radialGradient id="psAiGlow"><stop offset="0" stop-color="#a855f7" stop-opacity=".45"/><stop offset="1" stop-color="#a855f7" stop-opacity="0"/></radialGradient>
  </defs>
  <circle cx="220" cy="150" r="150" fill="url(#psAiGlow)" class="ps-breathe"/>
  <!-- neural links -->
  <?php $nodes = [[120,60],[320,60],[90,150],[350,150],[140,240],[300,240],[220,30]];
  foreach ($nodes as $i => [$x,$y]): ?>
  <path d="M220 150 L<?= $x ?> <?= $y ?>" class="ps-flow is-pink" style="--d:<?= $i * .25 ?>s"/>
  <?php endforeach; ?>
  <path d="M120 60 L220 30 L320 60 M90 150 L120 60 M350 150 L320 60 M140 240 L90 150 M300 240 L350 150" class="ps-wire"/>
  <?php foreach ($nodes as $i => [$x,$y]): ?>
  <circle cx="<?= $x ?>" cy="<?= $y ?>" r="7" fill="#1a1036" stroke="url(#psAiG)" stroke-width="2"/>
  <circle cx="<?= $x ?>" cy="<?= $y ?>" r="3" fill="#f0abfc" class="ps-blink" style="--d:<?= $i * .3 ?>s"/>
  <?php endforeach; ?>
  <!-- AI core -->
  <g class="ps-float">
    <circle cx="220" cy="150" r="54" fill="none" stroke="url(#psAiG)" stroke-width="1.5" stroke-dasharray="4 8" class="ps-spin-slow"/>
    <rect x="182" y="112" width="76" height="76" rx="20" fill="url(#psAiG)" class="ps-beat"/>
    <?php foreach ([126, 150, 174] as $y): ?><path d="M174 <?= $y ?>h8M258 <?= $y ?>h8" stroke="#f0abfc" stroke-width="3" stroke-linecap="round"/><?php endforeach; ?>
    <text x="220" y="160" text-anchor="middle" class="ps-label ps-big" fill="#fff">AI</text>
  </g>
  <!-- document typing itself -->
  <g transform="translate(24 252)">
    <rect width="130" height="76" rx="12" fill="rgba(26,16,54,.92)" stroke="rgba(240,171,252,.4)"/>
    <path d="m14 18 4-8 4 8m-6.5-3h5" stroke="#f0abfc" stroke-width="1.6" fill="none"/>
    <?php foreach ([[30,18,80],[14,34,100],[14,48,86],[14,62,60]] as $i => [$x,$y,$w]): ?>
    <rect x="<?= $x ?>" y="<?= $y - 3 ?>" width="<?= $w ?>" height="6" rx="3" fill="#e9d5ff" class="ps-type" style="--d:<?= $i * .6 ?>s"/>
    <?php endforeach; ?>
  </g>
  <!-- workflow -->
  <g transform="translate(270 262)">
    <rect width="146" height="66" rx="12" fill="rgba(26,16,54,.92)" stroke="rgba(240,171,252,.4)"/>
    <?php foreach ([12, 58, 104] as $i => $x): ?>
    <rect x="<?= $x ?>" y="20" width="30" height="26" rx="7" fill="#2a1a52" stroke="url(#psAiG)" stroke-width="1.5" class="ps-step" style="--d:<?= $i * .6 ?>s"/>
    <?php endforeach; ?>
    <path d="M42 33h16M88 33h16" class="ps-flow is-pink"/>
    <g transform="translate(119 33)"><circle r="7" fill="none" stroke="#f0abfc" stroke-width="4" stroke-dasharray="3 2.5" class="ps-spin"/><circle r="2.5" fill="#f0abfc"/></g>
    <path d="m21 33 4 4 6-7" stroke="#f0abfc" stroke-width="2" fill="none" stroke-linecap="round"/>
    <path d="M67 28h12M67 33h12M67 38h8" stroke="#f0abfc" stroke-width="1.6"/>
  </g>
</svg>
