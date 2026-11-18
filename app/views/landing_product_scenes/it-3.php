<?php /* IT slide 3 «امنیت و نگهداری»: threats fly in from outside and are stopped at a turning firewall
   ring (a burst where each one hits), the shield draws its check; beside it a maintenance checklist ticks
   off while the update bar fills. 440×250, content above y 212. */
$cx = 136; $cy = 106;
$threats = [];
foreach ([[150, 0], [196, .55], [238, 1.1], [118, 1.6], [214, 2.1], [262, 2.5], [172, 2.9]] as [$deg, $d]) {
    $a = deg2rad($deg);
    $threats[] = [
        'x' => round($cx + 64 * cos($a), 1), 'y' => round($cy + 64 * sin($a), 1),
        'tx' => round(96 * cos($a), 1), 'ty' => round(96 * sin($a), 1), 'd' => $d,
    ];
}
$tasks = ['وصله‌های امنیتی', 'پشتیبان‌گیری', 'بررسی آسیب‌پذیری']; ?>
<svg viewBox="0 0 440 250" class="ps-svg pc-svg" aria-hidden="true" focusable="false">
  <defs>
    <linearGradient id="pcIt3G" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#6fd6df"/><stop offset="1" stop-color="#11b8c5"/></linearGradient>
    <radialGradient id="pcIt3Glow"><stop offset="0" stop-color="#11b8c5" stop-opacity=".34"/><stop offset="1" stop-color="#11b8c5" stop-opacity="0"/></radialGradient>
  </defs>
  <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="120" fill="url(#pcIt3Glow)" class="ps-breathe"/>
  <!-- firewall rings -->
  <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="64" fill="rgba(17,184,197,.05)" stroke="rgba(111,214,223,.5)" stroke-width="2" stroke-dasharray="10 6" class="ps-spin-slow"/>
  <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="76" fill="none" stroke="rgba(111,214,223,.18)" stroke-width="1" stroke-dasharray="2 6" class="ps-spin-slow is-rev"/>
  <!-- threats: fly in, burst on the ring -->
  <?php foreach ($threats as $t): ?>
  <g style="--d:<?= $t['d'] ?>s">
    <circle cx="<?= $t['x'] ?>" cy="<?= $t['y'] ?>" r="4.5" fill="#f87171" class="pc-threat" style="--tx:<?= $t['tx'] ?>px;--ty:<?= $t['ty'] ?>px;--d:<?= $t['d'] ?>s"/>
    <circle cx="<?= $t['x'] ?>" cy="<?= $t['y'] ?>" r="7" fill="none" stroke="#9de9ef" stroke-width="2" class="pc-burst" style="--d:<?= $t['d'] ?>s"/>
  </g>
  <?php endforeach; ?>
  <!-- shield -->
  <g class="pc-in" style="--d:.1s">
    <g class="ps-float">
      <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="48" fill="none" stroke="url(#pcIt3G)" stroke-width="1.5" class="ps-ping-soft"/>
      <path d="M<?= $cx ?> <?= $cy - 40 ?> <?= $cx - 34 ?> <?= $cy - 27 ?>v24c0 22 14 37 34 47 20-10 34-25 34-47v-24Z" fill="rgba(6,39,42,.96)" stroke="url(#pcIt3G)" stroke-width="2.6"/>
      <path d="m<?= $cx - 15 ?> <?= $cy + 1 ?> 11 11 21-22" fill="none" stroke="#48cbd7" stroke-width="5.5" stroke-linecap="round" stroke-linejoin="round" pathLength="100" class="pc-draw" style="--d:.7s"/>
    </g>
  </g>
  <!-- maintenance card -->
  <g class="pc-in" style="--d:.35s">
    <rect x="262" y="30" width="158" height="150" rx="14" fill="rgba(11,27,29,.94)" stroke="rgba(111,214,223,.35)"/>
    <text x="406" y="54" text-anchor="start" class="ps-label ps-small" fill="#e6fbfd">نگهداری و به‌روزرسانی</text>
    <?php foreach ($tasks as $i => $task): $y = 74 + $i * 28; $d = 1.2 + $i * 1.1; ?>
    <rect x="276" y="<?= $y ?>" width="130" height="22" rx="7" fill="#102a2d"/>
    <text x="388" y="<?= $y + 15 ?>" text-anchor="start" class="ps-label ps-small" fill="#c1edf1"><?= $task ?></text>
    <g class="pc-in" style="--d:<?= $d ?>s"><circle cx="288" cy="<?= $y + 11 ?>" r="7" fill="#11b8c5"/><path d="m284.5 <?= $y + 11 ?> 2.5 2.5 4.5-5" fill="none" stroke="#06282b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></g>
    <circle cx="288" cy="<?= $y + 11 ?>" r="6" fill="none" stroke="#48cbd7" stroke-width="1.6" stroke-dasharray="8 30" class="pc-wait" style="--d:<?= $d ?>s"/>
    <?php endforeach; ?>
    <rect x="276" y="160" width="130" height="6" rx="3" fill="#123236"/>
    <rect x="276" y="160" width="130" height="6" rx="3" fill="url(#pcIt3G)" class="pc-fill is-slow" style="--d:.8s"/>
  </g>
</svg>
