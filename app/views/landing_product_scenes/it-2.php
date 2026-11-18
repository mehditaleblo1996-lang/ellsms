<?php /* IT slide 2 «سرور و مجازی‌سازی»: a rack with live LEDs and activity; three virtual machines (Windows, Linux, Mail) start up
   one after another on the virtualisation layer (each fills its boot bar, then shows a check) while the
   CPU and RAM gauges fill. 440×250, content above y 212. */
$vms = [['Windows', 214], ['Linux', 288], ['Mail', 362]]; ?>
<svg viewBox="0 0 440 250" class="ps-svg pc-svg" aria-hidden="true" focusable="false">
  <defs>
    <linearGradient id="pcIt2G" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#6fd6df"/><stop offset="1" stop-color="#11b8c5"/></linearGradient>
    <radialGradient id="pcIt2Glow"><stop offset="0" stop-color="#11b8c5" stop-opacity=".28"/><stop offset="1" stop-color="#11b8c5" stop-opacity="0"/></radialGradient>
    <clipPath id="pcIt2Rack"><rect x="34" y="22" width="110" height="170" rx="10"/></clipPath>
  </defs>
  <circle cx="290" cy="120" r="140" fill="url(#pcIt2Glow)" class="ps-breathe"/>
  <!-- rack -->
  <g class="pc-in" style="--d:.05s">
    <rect x="34" y="22" width="110" height="170" rx="10" fill="#0b1b1d" stroke="url(#pcIt2G)" stroke-width="2"/>
    <?php for ($u = 0; $u < 5; $u++): $y = 34 + $u * 30; ?>
    <rect x="44" y="<?= $y ?>" width="90" height="22" rx="4" fill="#102a2d"/>
    <circle cx="54" cy="<?= $y + 11 ?>" r="3" fill="#48cbd7" class="ps-blink" style="--d:<?= $u * .37 ?>s"/>
    <circle cx="64" cy="<?= $y + 11 ?>" r="3" fill="#f59e0b" class="ps-blink" style="--d:<?= $u * .23 + .5 ?>s" opacity=".85"/>
    <?php for ($b = 0; $b < 6; $b++): ?><rect x="<?= 76 + $b * 9 ?>" y="<?= $y + 6 ?>" width="5" height="10" rx="1.5" fill="#6fd6df" class="ps-eq" style="--d:<?= (($u * 5 + $b) * 37 % 11) * .08 ?>s"/><?php endfor; ?>
    <?php endfor; ?>
    <rect x="34" y="22" width="110" height="14" fill="url(#pcIt2G)" opacity=".3" clip-path="url(#pcIt2Rack)" class="pc-scan"/>
    <text x="89" y="208" text-anchor="middle" class="ps-label ps-small" fill="#c1edf1">سرور</text>
  </g>
  <!-- virtualisation layer -->
  <g class="pc-in" style="--d:.3s">
    <path d="M176 176h232l-14 14H190Z" fill="rgba(17,184,197,.14)" stroke="url(#pcIt2G)" stroke-width="1.6"/>
    <text x="292" y="207" text-anchor="middle" class="ps-label ps-small" fill="#9de2e8">لایه‌ی مجازی‌سازی</text>
  </g>
  <!-- links rack → VMs -->
  <?php foreach ($vms as $i => [, $x]): ?>
  <path d="M144 <?= 60 + $i * 50 ?> C170 <?= 60 + $i * 50 ?> <?= $x - 40 ?> 150 <?= $x ?> 150" class="pc-link" pathLength="100"/>
  <path d="M144 <?= 60 + $i * 50 ?> C170 <?= 60 + $i * 50 ?> <?= $x - 40 ?> 150 <?= $x ?> 150" class="pc-pkt" pathLength="100" style="--d:<?= 1 + $i * 1 ?>s"/>
  <?php endforeach; ?>
  <!-- VMs: rise, boot, check -->
  <?php foreach ($vms as $i => [$name, $x]): $d = .8 + $i * 1.05; ?>
  <g class="pc-rise" style="--d:<?= $d ?>s">
    <rect x="<?= $x - 32 ?>" y="104" width="64" height="64" rx="9" fill="#0b1b1d" stroke="url(#pcIt2G)" stroke-width="1.6"/>
    <rect x="<?= $x - 32 ?>" y="104" width="64" height="16" rx="8" fill="rgba(17,184,197,.2)"/>
    <circle cx="<?= $x - 21 ?>" cy="112" r="2" fill="#6fd6df"/><circle cx="<?= $x - 14 ?>" cy="112" r="2" fill="#6fd6df" opacity=".6"/>
    <text x="<?= $x ?>" y="140" text-anchor="middle" class="ps-small pc-latin" fill="#e6fbfd"><?= $name ?></text>
    <rect x="<?= $x - 22 ?>" y="150" width="44" height="5" rx="2.5" fill="#123236"/>
    <rect x="<?= $x - 22 ?>" y="150" width="44" height="5" rx="2.5" fill="#48cbd7" class="pc-fill" style="--d:<?= $d + .35 ?>s"/>
    <g class="pc-in" style="--d:<?= $d + 1.5 ?>s"><circle cx="<?= $x + 24 ?>" cy="104" r="8" fill="#11b8c5"/><path d="m<?= $x + 20 ?> 104 3 3 5-6" fill="none" stroke="#06282b" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></g>
  </g>
  <?php endforeach; ?>
  <!-- gauges -->
  <?php foreach ([['CPU', 266, 62], ['RAM', 338, 48]] as $i => [$lbl, $gx, $v]): ?>
  <g class="pc-in" style="--d:<?= .5 + $i * .2 ?>s">
    <circle cx="<?= $gx ?>" cy="52" r="24" fill="#0b1b1d" stroke="#123236" stroke-width="6"/>
    <circle cx="<?= $gx ?>" cy="52" r="24" fill="none" stroke="url(#pcIt2G)" stroke-width="6" stroke-linecap="round" pathLength="100" class="pc-gauge" style="--v:<?= 100 - $v ?>;--d:<?= 1.2 + $i * .4 ?>s"/>
    <text x="<?= $gx ?>" y="56" text-anchor="middle" class="ps-small pc-latin" fill="#c1edf1"><?= $lbl ?></text>
  </g>
  <?php endforeach; ?>
</svg>
