<?php /* IT slide 1 «پشتیبانی و پایش شبکه» (docs/landing.md#it-and-telephony-carousels): a core switch with
   live traffic to the office, laptops, Wi-Fi and a branch. At 2 s the branch link fails (amber, «!»), a
   technician's wrench works on it, and at 4.6 s it comes back with a check. 440×250, content above y 212. */
$nodes = [
    ['M188 92 C150 92 130 58 96 58', 'دفتر مرکزی'],
    ['M188 118 C150 118 130 160 96 160', 'لپ‌تاپ‌ها'],
    ['M252 92 C290 92 312 58 344 58', 'وای‌فای'],
]; ?>
<svg viewBox="0 0 440 250" class="ps-svg pc-svg" aria-hidden="true" focusable="false">
  <defs>
    <linearGradient id="pcIt1G" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#6fd6df"/><stop offset="1" stop-color="#11b8c5"/></linearGradient>
    <radialGradient id="pcIt1Glow"><stop offset="0" stop-color="#11b8c5" stop-opacity=".32"/><stop offset="1" stop-color="#11b8c5" stop-opacity="0"/></radialGradient>
  </defs>
  <circle cx="220" cy="106" r="130" fill="url(#pcIt1Glow)" class="ps-breathe"/>
  <!-- grid dots -->
  <g fill="rgba(157,226,232,.12)"><?php for ($gx = 20; $gx < 440; $gx += 30) for ($gy = 16; $gy < 214; $gy += 30) echo '<circle cx="' . $gx . '" cy="' . $gy . '" r="1"/>'; ?></g>
  <!-- links with packets both ways -->
  <?php foreach ($nodes as $i => [$d]): ?>
  <path d="<?= $d ?>" class="pc-link" pathLength="100"/>
  <path d="<?= $d ?>" class="pc-pkt" pathLength="100" style="--d:<?= $i * .5 ?>s"/>
  <path d="<?= $d ?>" class="pc-pkt is-rev is-dim" pathLength="100" style="--d:<?= $i * .5 + .9 ?>s"/>
  <?php endforeach; ?>
  <!-- the branch link: fails, is fixed -->
  <path d="M252 118 C290 118 312 160 344 160" class="pc-link pc-fault-link" pathLength="100"/>
  <path d="M252 118 C290 118 312 160 344 160" class="pc-pkt pc-fault-pkt" pathLength="100" style="--d:.3s"/>
  <path d="M252 118 C290 118 312 160 344 160" class="pc-pkt is-rev is-dim pc-fault-pkt" pathLength="100" style="--d:1.1s"/>
  <!-- core switch -->
  <g class="pc-in" style="--d:.1s">
    <rect x="172" y="88" width="96" height="36" rx="9" fill="#0b1b1d" stroke="url(#pcIt1G)" stroke-width="2"/>
    <?php for ($p = 0; $p < 7; $p++): ?><rect x="<?= 182 + $p * 11 ?>" y="101" width="7" height="10" rx="1.5" fill="#123236" stroke="#48cbd7" stroke-width=".8" class="ps-blink" style="--d:<?= ($p * 37 % 7) * .17 ?>s"/><?php endfor; ?>
    <text x="220" y="140" text-anchor="middle" class="ps-label ps-small" fill="#9de2e8">سوئیچ مرکزی</text>
  </g>
  <!-- office -->
  <g class="pc-in" style="--d:.3s">
    <rect x="40" y="34" width="56" height="48" rx="8" fill="#0b1b1d" stroke="url(#pcIt1G)" stroke-width="1.6"/>
    <?php foreach ([[50, 44], [64, 44], [78, 44], [50, 58], [64, 58], [78, 58]] as $k => [$x, $y]): ?><rect x="<?= $x ?>" y="<?= $y ?>" width="9" height="8" rx="1.5" fill="#48cbd7" opacity=".75" class="ps-blink" style="--d:<?= $k * .3 ?>s"/><?php endforeach; ?>
    <text x="68" y="98" text-anchor="middle" class="ps-label ps-small" fill="#c1edf1">دفتر مرکزی</text>
  </g>
  <!-- laptops -->
  <g class="pc-in" style="--d:.45s">
    <rect x="44" y="140" width="48" height="30" rx="4" fill="#0b1b1d" stroke="url(#pcIt1G)" stroke-width="1.6"/>
    <path d="M52 150h22M52 158h30" stroke="#7ce5ee" stroke-width="2.4" stroke-linecap="round" opacity=".7"/>
    <path d="M36 174h64l-6 6H42Z" fill="#123236" stroke="#48cbd7" stroke-width="1"/>
    <text x="68" y="198" text-anchor="middle" class="ps-label ps-small" fill="#c1edf1">لپ‌تاپ‌ها</text>
  </g>
  <!-- wifi -->
  <g class="pc-in" style="--d:.6s">
    <rect x="344" y="48" width="56" height="22" rx="6" fill="#0b1b1d" stroke="url(#pcIt1G)" stroke-width="1.6"/>
    <?php foreach ([356, 366, 376] as $k => $x): ?><circle cx="<?= $x ?>" cy="59" r="2.4" fill="#48cbd7" class="ps-blink" style="--d:<?= $k * .25 ?>s"/><?php endforeach; ?>
    <?php foreach ([10, 17, 24] as $k => $r): ?><path d="M<?= 372 - $r ?> <?= 44 - $r * .2 ?>a<?= $r ?> <?= $r ?> 0 0 1 <?= 2 * $r ?> 0" fill="none" stroke="#7ce5ee" stroke-width="1.8" stroke-linecap="round" class="pc-wave" style="--d:<?= $k * .25 ?>s"/><?php endforeach; ?>
    <text x="372" y="88" text-anchor="middle" class="ps-label ps-small" fill="#c1edf1">وای‌فای</text>
  </g>
  <!-- branch -->
  <g class="pc-in" style="--d:.75s">
    <path d="M344 186V146l28-12 28 12v40Z" fill="#0b1b1d" stroke="url(#pcIt1G)" stroke-width="1.6"/>
    <?php foreach ([[356, 154], [368, 154], [380, 154], [356, 168], [368, 168], [380, 168]] as $k => [$x, $y]): ?><rect x="<?= $x ?>" y="<?= $y ?>" width="8" height="7" rx="1.5" fill="#48cbd7" opacity=".7"/><?php endforeach; ?>
    <text x="372" y="204" text-anchor="middle" class="ps-label ps-small" fill="#c1edf1">شعبه</text>
  </g>
  <!-- fault, fix, ok -->
  <g class="pc-fault-badge"><circle cx="300" cy="139" r="11" fill="#f59e0b"/><path d="M300 133v7M300 144v.5" stroke="#1c1408" stroke-width="2.6" stroke-linecap="round"/></g>
  <g class="pc-fix"><circle cx="300" cy="139" r="14" fill="#0b1b1d" stroke="#6fd6df" stroke-width="1.6"/><path transform="translate(288 127) scale(.75)" d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76Z" fill="none" stroke="#9de9ef" stroke-width="2.2" stroke-linejoin="round"/></g>
  <g class="pc-ok"><circle cx="300" cy="139" r="11" fill="#11b8c5"/><path d="m295 139 3.5 3.5 6.5-7" fill="none" stroke="#06282b" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></g>
  <!-- status chip -->
  <g class="pc-in" style="--d:.9s">
    <rect x="172" y="16" width="96" height="24" rx="12" fill="rgba(17,184,197,.12)" stroke="rgba(111,214,223,.4)"/>
    <circle cx="256" cy="28" r="3.5" fill="#48cbd7" class="ps-blink"/>
    <text x="248" y="32" text-anchor="start" class="ps-label ps-small" fill="#c1edf1">پایش شبانه‌روزی</text>
  </g>
</svg>
