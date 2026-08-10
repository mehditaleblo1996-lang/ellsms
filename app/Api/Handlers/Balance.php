<?php
/**
 * ELLSMS public API — GET /api/v1/balance (Phase 12, STEP 24).
 *
 * Reads through wallet_balance() (app/wallet.php) — the same source of truth every other page in
 * this codebase uses — never user_.currentcredit directly. An API key acts on behalf of its
 * creating user for wallet purposes (see app/ApiKeys.php's docblock: this codebase's wallet model
 * is strictly user-keyed, Phase 3, and this phase deliberately does not invent a new
 * organization-level wallet on top of it).
 */

declare(strict_types=1);

function api_handle_balance(array $ctx): void {
    $principal = $ctx['principal'];
    $balance = wallet_balance($principal['created_by_user_id']);
    ApiResponse::success(200, [
        'available' => $balance['available'],
        'reserved'  => $balance['reserved'],
        'total'     => $balance['total'],
        'unit'      => 'credits',
    ]);
}
