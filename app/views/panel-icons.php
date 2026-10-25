<?php
/** Small, local SVGs for the panel chrome. No font, script or remote icon dependency. */
function panel_icon(string $name): string {
    $paths = [
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
        'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
        'send' => '<path d="m21 3-7 18-4-7-7-4L21 3ZM10 14l5-5"/>',
        'spark' => '<path d="m12 3 2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5L12 3Z"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'message' => '<path d="M21 11a8 8 0 0 1-8 8H7l-4 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4v4Z"/><path d="M7 8h10M7 12h7"/>',
        'chart' => '<path d="M4 3v17h17M9 15v-4M14 15V7M19 15V4"/>',
        'inbox' => '<path d="m3 14 3-9h12l3 9v6H3v-6ZM3 14h5l2 3h4l2-3h5"/>',
        'users' => '<circle cx="9" cy="7" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 4a3 3 0 0 1 0 6M21 21v-3a6 6 0 0 0-4-5"/>',
        'user' => '<circle cx="12" cy="7" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>',
        'shield' => '<path d="M12 3 3 7v5c0 5 9 9 9 9s9-4 9-9V7l-9-4Z"/><path d="m8 12 3 3 5-6"/>',
        'wallet' => '<path d="M20 7H5a2 2 0 0 1 0-4h13v4M4 7v13h17V7M21 11h-6v5h6"/><path d="M17 13.5h.01"/>',
        'receipt' => '<path d="M5 3h14v19l-3-2-4 2-4-2-3 2V3ZM9 7h6M9 11h6M9 15h3"/>',
        'key' => '<circle cx="8" cy="8" r="5"/><path d="m12 12 9 9M16 16l3-3M19 19l3-3"/>',
        'link' => '<path d="m9 15 6-6M7 14l-3 3a3.5 3.5 0 0 0 5 5l4-4M17 10l3-3a3.5 3.5 0 0 0-5-5l-4 4"/>',
        'database' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 4 16 4 16 0V5M4 12c0 4 16 4 16 0"/>',
        'book' => '<path d="M12 5C9 2 5 2 2 3v16c3-1 7-1 10 2 3-3 7-3 10-2V3c-3-1-7-1-10 2v16"/>',
        'settings' => '<path d="M4 7h16M4 17h16"/><circle cx="9" cy="7" r="3"/><circle cx="15" cy="17" r="3"/>',
        'phone' => '<path d="m6 3 4 5-3 3a15 15 0 0 0 6 6l3-3 5 4c-1 4-4 4-7 3A20 20 0 0 1 3 10C2 7 2 4 6 3Z"/>',
        'folder' => '<path d="M3 6V4h6l3 3h9v13H3V6Z"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><ellipse cx="12" cy="12" rx="4" ry="9"/><path d="M3 12h18"/>',
        'ban' => '<circle cx="12" cy="12" r="9"/><path d="m6 6 12 12"/>',
        'copy' => '<rect x="8" y="8" width="13" height="13" rx="2"/><path d="M16 4H4v12"/>',
        'help' => '<circle cx="12" cy="12" r="9"/><path d="M9 9a3 3 0 0 1 6 0c0 2-3 2-3 4M12 17h.01"/>',
        'logout' => '<path d="M9 4H3v16h6M9 12h12m-4-4 4 4-4 4"/>',
        'search' => '<circle cx="10.5" cy="10.5" r="7.5"/><path d="m16 16 5 5"/>',
        'close' => '<path d="m6 6 12 12M6 18 18 6"/>',
        'check' => '<path d="m5 12 4 4L20 5"/>',
        'speaker' => '<path d="M4 9v6h4l5 4V5L8 9H4Z"/><path d="M16.5 8.5a5 5 0 0 1 0 7M19 6a8.5 8.5 0 0 1 0 12"/>',
        'mic' => '<rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3M8 21h8"/>',
        'refresh' => '<path d="M20 11a8 8 0 1 0-2.3 5.7M20 4v7h-7"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'bookmark' => '<path d="M6 3h12v18l-6-4-6 4V3Z"/>',
        'lock' => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 15v2"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'headset' => '<path d="M4 15v-3a8 8 0 0 1 16 0v3"/><rect x="3" y="14" width="4" height="6" rx="1.5"/><rect x="17" y="14" width="4" height="6" rx="1.5"/><path d="M19 20c0 1.5-1.5 2-3 2h-3"/>',
        'more' => '<circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/>',
    ];
    $names = [
        'dashboard'=>'grid', 'notifications'=>'bell', 'guide'=>'book', 'onboarding'=>'check',
        'new_send'=>'spark', 'send'=>'send', 'p2p'=>'users', 'smart'=>'spark', 'schedules'=>'clock',
        'autoreply'=>'message', 'reports'=>'chart', 'reports_bulk'=>'chart', 'inbox'=>'inbox',
        'contacts'=>'users', 'blacklist'=>'ban', 'line_optouts'=>'ban', 'tickets'=>'help',
        'buy_credit'=>'wallet', 'invoices'=>'receipt', 'financial_log'=>'receipt', 'bale'=>'message', 'voice'=>'speaker', 'voice_files'=>'mic', 'voice_settings'=>'speaker', 'regional_bulk'=>'globe',
        'api_keys'=>'key', 'integrations'=>'link', 'remote_db'=>'database', 'webhooks'=>'link',
        'number_forwards'=>'send', 'billing'=>'wallet', 'subaccounts'=>'users', 'menu_roles'=>'shield',
        'reseller_profits'=>'chart', 'user_send_policies'=>'shield', 'registration_requests'=>'user',
        'kyc_review'=>'shield', 'kyc_gates'=>'key', 'analytics'=>'chart', 'logs'=>'receipt',
        'numbers'=>'phone', 'number_categories'=>'folder', 'landing_videos'=>'folder', 'pricing'=>'wallet',
        'price_quote'=>'receipt', 'sms_pricing'=>'wallet', 'sms_gateways'=>'link', 'prohibited_words'=>'ban',
        'queue_cancellation'=>'ban', 'queue_monitor'=>'chart', 'alerts'=>'bell', 'bulk_archive'=>'folder', 'sms_gateway_clone'=>'copy',
        'guide_admin'=>'book', 'billing_admin'=>'wallet', 'financial_admin'=>'receipt',
    ];
    $path = $paths[$names[$name] ?? $name] ?? $paths['grid'];
    return '<svg class="ui-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $path . '</svg>';
}

/**
 * An empty list or table: an icon, what is missing, an optional hint and an optional next step
 * ($action = [href, label]). Inside a table it goes in a full-width <td class="empty-cell">.
 */
function panel_empty_state(string $title, string $text = '', ?array $action = null, string $icon = 'inbox'): string {
    $html = '<div class="empty-state"><span class="empty-state-icon">' . panel_icon($icon) . '</span>'
        . '<strong class="empty-state-title">' . e($title) . '</strong>';
    if ($text !== '') $html .= '<p class="empty-state-text">' . e($text) . '</p>';
    if ($action !== null) $html .= '<a class="btn btn-sm btn-primary" href="' . e($action[0]) . '">' . e($action[1]) . '</a>';
    return $html . '</div>';
}
