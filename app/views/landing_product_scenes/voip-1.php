<?php /* Telephony slide 1 «سانترال تحت شبکه»: an incoming call rings, the IP PBX routes it (the route
   scans past 101 and lights 102), extension 102 rings, then the call connects: «در حال مکالمه» with a live
   voice wave. 440×250, content above y 212. */
$phone = 'M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2Z';
$exts = [['۱۰۱', 40], ['۱۰۲', 104], ['۱۰۳', 168]]; ?>
<svg viewBox="0 0 440 250" class="ps-svg pc-svg" aria-hidden="true" focusable="false">
  <defs>
    <linearGradient id="pcVo1G" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#fbbf24"/><stop offset="1" stop-color="#f97316"/></linearGradient>
    <radialGradient id="pcVo1Glow"><stop offset="0" stop-color="#f59e0b" stop-opacity=".3"/><stop offset="1" stop-color="#f59e0b" stop-opacity="0"/></radialGradient>
  </defs>
  <circle cx="200" cy="104" r="130" fill="url(#pcVo1Glow)" class="ps-breathe"/>
  <!-- caller → PBX -->
  <path d="M86 104 H164" class="pc-link is-amber" pathLength="100"/>
  <path d="M86 104 H164" class="pc-pkt is-amber" pathLength="100" style="--t:1.2s"/>
  <!-- PBX → extensions -->
  <?php foreach ($exts as $i => [$n, $y]): ?>
  <path d="M236 104 C272 104 280 <?= $y ?> 314 <?= $y ?>" class="pc-link is-amber<?= $i === 1 ? ' pc-route' : '' ?><?= $i === 0 ? ' pc-route-scan' : '' ?>" pathLength="100"/>
  <?php if ($i === 1): ?><path d="M236 104 C272 104 280 <?= $y ?> 314 <?= $y ?>" class="pc-pkt is-amber pc-route-pkt" pathLength="100" style="--t:1s"/><?php endif; ?>
  <?php endforeach; ?>
  <!-- caller -->
  <g class="pc-in" style="--d:.05s">
    <g class="pc-ringing">
      <circle cx="60" cy="104" r="22" class="ps-ring" style="--d:0s"/><circle cx="60" cy="104" r="22" class="ps-ring" style="--d:.8s"/>
    </g>
    <circle cx="60" cy="104" r="22" fill="url(#pcVo1G)"/>
    <g class="pc-shake-call"><path transform="translate(48 92)" d="<?= $phone ?>" fill="#fff"/></g>
    <text x="60" y="146" text-anchor="middle" class="ps-label ps-small" fill="#fde68a">مشتری</text>
  </g>
  <!-- PBX -->
  <g class="pc-in" style="--d:.2s">
    <rect x="164" y="78" width="72" height="52" rx="10" fill="#1c1408" stroke="url(#pcVo1G)" stroke-width="2"/>
    <?php for ($r = 0; $r < 3; $r++): ?><rect x="174" y="<?= 88 + $r * 12 ?>" width="40" height="6" rx="3" fill="#2d200c"/><circle cx="224" cy="<?= 91 + $r * 12 ?>" r="2.6" fill="#fbbf24" class="ps-blink" style="--d:<?= $r * .3 ?>s"/><?php endfor; ?>
    <text x="200" y="148" text-anchor="middle" class="ps-label ps-small" fill="#fde68a">سانترال IP</text>
  </g>
  <!-- extensions -->
  <?php foreach ($exts as $i => [$n, $y]): ?>
  <g class="pc-in" style="--d:<?= .35 + $i * .12 ?>s">
    <g class="<?= $i === 1 ? 'pc-ext-on' : '' ?>">
      <rect x="314" y="<?= $y - 18 ?>" width="96" height="36" rx="9" fill="#1c1408" stroke="rgba(251,191,36,.45)" stroke-width="1.4" class="<?= $i === 1 ? 'pc-ext-frame' : '' ?>"/>
      <g class="<?= $i === 1 ? 'pc-shake-ext' : '' ?>"><path transform="translate(380 <?= $y - 9 ?>) scale(.75)" d="<?= $phone ?>" fill="none" stroke="#fbbf24" stroke-width="2"/></g>
      <text x="374" y="<?= $y + 5 ?>" text-anchor="start" class="ps-label ps-small" fill="#fef3c7">داخلی <?= $n ?></text>
    </g>
  </g>
  <?php endforeach; ?>
  <!-- status: ringing → talking -->
  <g class="pc-status-a">
    <rect x="22" y="18" width="104" height="24" rx="12" fill="rgba(251,191,36,.14)" stroke="rgba(251,191,36,.45)"/>
    <circle cx="114" cy="30" r="3.5" fill="#fbbf24" class="ps-blink"/>
    <text x="106" y="34" text-anchor="start" class="ps-label ps-small" fill="#fde68a">تماس ورودی…</text>
  </g>
  <g class="pc-status-b">
    <rect x="22" y="18" width="104" height="24" rx="12" fill="rgba(52,211,153,.14)" stroke="rgba(110,231,183,.5)"/>
    <circle cx="114" cy="30" r="3.5" fill="#6ee7b7" class="ps-blink"/>
    <text x="106" y="34" text-anchor="start" class="ps-label ps-small" fill="#d1fae5">در حال مکالمه</text>
  </g>
  <!-- voice wave once connected -->
  <g class="pc-talk">
    <rect x="150" y="164" width="140" height="36" rx="12" fill="rgba(28,20,8,.92)" stroke="rgba(251,191,36,.35)"/>
    <?php for ($b = 0; $b < 13; $b++): ?><rect x="<?= 160 + $b * 10 ?>" y="172" width="5" height="20" rx="2.5" fill="url(#pcVo1G)" class="ps-eq" style="--d:<?= ($b * 37 % 9) * .09 ?>s"/><?php endfor; ?>
  </g>
</svg>
