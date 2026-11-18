<?php /* Telephony slide 2 «منشی گویا و صف تماس»: the welcome message types out, the IVR menu highlights
   its options and «پشتیبانی» is chosen («انتقال به پشتیبانی»), while callers move up a queue to the
   agents' headsets. 440×250, content above y 212. */
$menu = ['۱  فروش', '۲  پشتیبانی', '۳  حسابداری']; ?>
<svg viewBox="0 0 440 250" class="ps-svg pc-svg" aria-hidden="true" focusable="false">
  <defs>
    <linearGradient id="pcVo2G" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#fbbf24"/><stop offset="1" stop-color="#f97316"/></linearGradient>
    <radialGradient id="pcVo2Glow"><stop offset="0" stop-color="#f59e0b" stop-opacity=".26"/><stop offset="1" stop-color="#f59e0b" stop-opacity="0"/></radialGradient>
    <clipPath id="pcVo2Type"><rect x="250" y="18" width="170" height="34" class="pc-type"/></clipPath>
  </defs>
  <circle cx="320" cy="110" r="130" fill="url(#pcVo2Glow)" class="ps-breathe"/>
  <!-- welcome message (right, typed in RTL) -->
  <g class="pc-in" style="--d:.05s">
    <path d="M420 22a8 8 0 0 0-8-8H262a8 8 0 0 0-8 8v22a8 8 0 0 0 8 8h136l14 10v-10a8 8 0 0 0 8-8Z" fill="rgba(251,191,36,.14)" stroke="rgba(251,191,36,.5)"/>
    <g clip-path="url(#pcVo2Type)"><text x="408" y="38" text-anchor="start" class="ps-label ps-small" fill="#fef3c7">سلام، به داتیس خوش آمدید</text></g>
  </g>
  <!-- IVR menu -->
  <g class="pc-in" style="--d:.3s">
    <rect x="270" y="76" width="150" height="104" rx="14" fill="rgba(28,20,8,.94)" stroke="rgba(251,191,36,.4)"/>
    <text x="406" y="98" text-anchor="start" class="ps-label ps-small" fill="#fde68a">منوی گویا</text>
    <?php foreach ($menu as $i => $t): $y = 108 + $i * 22; ?>
    <rect x="282" y="<?= $y ?>" width="126" height="18" rx="6" fill="#fbbf24" class="pc-menu-hl is-<?= $i + 1 ?>"/>
    <text x="398" y="<?= $y + 13 ?>" text-anchor="start" class="ps-label ps-small pc-menu-t is-<?= $i + 1 ?>" fill="#fef3c7"><?= $t ?></text>
    <?php endforeach; ?>
  </g>
  <!-- transfer -->
  <g class="pc-transfer">
    <path d="M270 128 C240 128 236 104 206 104" fill="none" stroke="#fbbf24" stroke-width="2" stroke-dasharray="4 4" class="pc-dash-run"/>
    <rect x="276" y="188" width="132" height="22" rx="11" fill="rgba(251,191,36,.18)" stroke="rgba(251,191,36,.55)"/>
    <text x="398" y="203" text-anchor="start" class="ps-label ps-small" fill="#fde68a">انتقال به پشتیبانی ←</text>
  </g>
  <!-- queue (left): callers move up to the agents -->
  <g class="pc-in" style="--d:.45s">
    <text x="200" y="66" text-anchor="start" class="ps-label ps-small" fill="#fde68a">صف تماس</text>
    <path d="M206 104 H58" stroke="rgba(251,191,36,.25)" stroke-width="16" stroke-linecap="round"/>
    <?php foreach ([0, 1, 2, 3] as $q): ?>
    <g class="pc-queue" style="--d:<?= $q * -1.1 ?>s"><circle cx="196" cy="104" r="6" fill="#fbbf24"/><circle cx="196" cy="104" r="10" fill="none" stroke="rgba(251,191,36,.4)"/></g>
    <?php endforeach; ?>
    <?php foreach ([[40, 70], [40, 138]] as $k => [$hx, $hy]): ?>
    <g>
      <circle cx="<?= $hx ?>" cy="<?= $hy ?>" r="20" fill="#1c1408" stroke="url(#pcVo2G)" stroke-width="1.8"/>
      <path d="M<?= $hx - 9 ?> <?= $hy + 3 ?>v-3a9 9 0 0 1 18 0v3" fill="none" stroke="#fbbf24" stroke-width="2.2" stroke-linecap="round"/>
      <rect x="<?= $hx - 11 ?>" y="<?= $hy + 1 ?>" width="5" height="8" rx="2" fill="#fbbf24"/><rect x="<?= $hx + 6 ?>" y="<?= $hy + 1 ?>" width="5" height="8" rx="2" fill="#fbbf24"/>
      <circle cx="<?= $hx + 14 ?>" cy="<?= $hy - 14 ?>" r="4" fill="#6ee7b7" class="ps-blink" style="--d:<?= $k * .6 ?>s"/>
    </g>
    <?php endforeach; ?>
    <path d="M58 104 C50 104 50 80 46 88M58 104 C50 104 50 128 46 120" fill="none" stroke="rgba(251,191,36,.35)" stroke-width="1.5"/>
    <text x="40" y="186" text-anchor="middle" class="ps-label ps-small" fill="#fde68a">کارشناسان</text>
  </g>
</svg>
