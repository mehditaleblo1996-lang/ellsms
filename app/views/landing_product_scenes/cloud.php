<?php /* «خدمات ابری و زیرساختی» scene: a cloud above blinking server racks, data flowing both ways, a globe (domain) and a storage cylinder filling. */ ?>
<svg viewBox="0 0 440 340" class="ps-svg" role="presentation">
  <defs>
    <linearGradient id="psCloudG" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#38bdf8"/><stop offset="1" stop-color="#2e63f6"/></linearGradient>
    <radialGradient id="psCloudGlow"><stop offset="0" stop-color="#0ea5e9" stop-opacity=".4"/><stop offset="1" stop-color="#0ea5e9" stop-opacity="0"/></radialGradient>
    <clipPath id="psCloudDb"><path d="M318 236v52c0 8 18 14 40 14s40-6 40-14v-52"/></clipPath>
  </defs>
  <circle cx="220" cy="150" r="160" fill="url(#psCloudGlow)" class="ps-breathe"/>
  <!-- cloud -->
  <g class="ps-float">
    <path d="M150 112c-22 0-38-15-38-34s16-33 36-33c6-22 26-36 50-36 26 0 47 17 52 40 3-1 7-1 10-1 22 0 39 15 39 32s-17 32-39 32Z" fill="rgba(14,165,233,.14)" stroke="url(#psCloudG)" stroke-width="3"/>
    <text x="214" y="86" text-anchor="middle" class="ps-label" fill="#bae6fd">Cloud</text>
  </g>
  <!-- data lines -->
  <?php foreach ([150, 220, 290] as $i => $x): ?>
  <path d="M<?= $x ?> 120 V 176" class="ps-flow is-cyan" style="--d:<?= $i * .3 ?>s"/>
  <path d="M<?= $x + 10 ?> 176 V 120" class="ps-flow is-cyan is-rev" style="--d:<?= $i * .3 + .5 ?>s"/>
  <?php endforeach; ?>
  <!-- racks -->
  <?php foreach ([118, 188, 258] as $i => $x): ?>
  <g transform="translate(<?= $x ?> 176)">
    <rect width="64" height="112" rx="8" fill="#0b1430" stroke="rgba(56,189,248,.45)" stroke-width="1.5"/>
    <?php for ($r = 0; $r < 4; $r++): $y = 10 + $r * 25; ?>
    <rect x="7" y="<?= $y ?>" width="50" height="18" rx="4" fill="#12204a"/>
    <circle cx="16" cy="<?= $y + 9 ?>" r="3" fill="#34d399" class="ps-blink" style="--d:<?= ($i * 4 + $r) * .23 ?>s"/>
    <circle cx="26" cy="<?= $y + 9 ?>" r="3" fill="#38bdf8" class="ps-blink" style="--d:<?= ($i * 4 + $r) * .37 + .2 ?>s"/>
    <rect x="34" y="<?= $y + 7 ?>" width="18" height="4" rx="2" fill="#2a3a78"/>
    <?php endfor; ?>
  </g>
  <?php endforeach; ?>
  <!-- globe (domain) -->
  <g transform="translate(70 230)">
    <circle r="34" fill="rgba(14,165,233,.1)" stroke="url(#psCloudG)" stroke-width="2.5"/>
    <ellipse rx="14" ry="34" fill="none" stroke="#7dd3fc" stroke-width="1.5" class="ps-globe"/>
    <path d="M-34 0h68M-30 -16h60M-30 16h60" stroke="#7dd3fc" stroke-width="1.2" opacity=".7"/>
    <rect x="-30" y="42" width="60" height="18" rx="9" fill="#0b1430" stroke="rgba(56,189,248,.45)"/>
    <text y="55" text-anchor="middle" class="ps-label ps-small" fill="#7dd3fc">www.ir</text>
  </g>
  <!-- storage -->
  <g>
    <rect x="318" y="236" width="80" height="66" fill="#0b1430" clip-path="url(#psCloudDb)"/>
    <rect x="318" y="236" width="80" height="66" fill="url(#psCloudG)" opacity=".55" clip-path="url(#psCloudDb)" class="ps-fill"/>
    <path d="M318 236v52c0 8 18 14 40 14s40-6 40-14v-52" fill="none" stroke="url(#psCloudG)" stroke-width="2.5"/>
    <ellipse cx="358" cy="236" rx="40" ry="13" fill="#12204a" stroke="url(#psCloudG)" stroke-width="2.5"/>
    <path d="M330 262c8 6 48 6 56 0" stroke="#bae6fd" stroke-width="1.2" fill="none" opacity=".5"/>
  </g>
</svg>
