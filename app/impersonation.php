<?php
/**
 * ELLSMS — platform-admin support impersonation ("ورود به پنل مشتری").
 *
 * Lets a platform administrator open a customer's panel to reproduce a support issue, WITHOUT
 * knowing, resetting, or touching that customer's password or 2FA in any way. See
 * docs/admin-impersonation.md.
 *
 * THE CENTRAL DESIGN DECISION, and the reason the rest of this feature is small:
 *
 *   while impersonating, $_SESSION['uid'] IS the target user's id.
 *
 * Not the admin's, not a pair, not a flag consulted by every page. current_user(),
 * current_organization(), user_organization_memberships(), has_permission(), can_use_originator()
 * and every other authorization primitive therefore resolve EXACTLY as they would in the target's
 * own session — because as far as they are concerned, it IS the target's session. There is no
 * "platform admin plus target" hybrid identity that could leak an admin bypass into a customer
 * page, because no such identity exists at any point. That makes Invariant H (no privilege
 * escalation) and STEP 14/38's RBAC isolation true by construction rather than by remembering to
 * check something in every file.
 *
 * The REAL actor is kept beside it, in `$_SESSION['impersonation']`, and is used for exactly three
 * things: the banner, the audit trail, and the exit control. Nothing else in the codebase consults
 * it to make an authorization decision.
 *
 * Consequences worth stating explicitly:
 *   - require_admin() denies during impersonation (the effective user is an ordinary customer), so
 *     the whole platform-admin area is unreachable until the operator exits. That is deliberate.
 *   - Workers, cron and the public API are untouched: all of this lives in $_SESSION, which none of
 *     them has. A Bearer-authenticated API request can never be an impersonated one.
 *   - No password hash is read, no password is verified, set or reset, and no 2FA verifier is
 *     touched anywhere in this file. Identity is switched, never authenticated.
 *
 * FAIL CLOSED. impersonation_enforce() re-validates the whole state on every authenticated request:
 * a crafted session, an actor who has lost platform-admin, a target/actor mismatch or an expired
 * support session all end the session rather than degrading into something ambiguous.
 */

declare(strict_types=1);

/** The only mode this phase implements: read/navigate as the target, sensitive mutations blocked. */
const IMPERSONATION_MODE_SUPPORT = 'support';

/** Bounded so an operator cannot leave a support session open indefinitely (STEP 12). */
const IMPERSONATION_MAX_SECONDS = 3600;

/** Bounded so a reason can be stored and rendered safely; plain text only, never HTML. */
const IMPERSONATION_REASON_MAX_LENGTH = 200;

/* ==========================================================================
   The blocked-action catalog (STEP 7/23)
   ========================================================================== */

/**
 * Every action a support impersonation may NOT perform, with the reason shown to the operator.
 *
 * ONE list, in ONE place, deliberately: the alternative is `if (is_impersonating())` scattered
 * through thirty files, where the interesting question ("is this exhaustive?") becomes unanswerable.
 * Pages consult it through impersonation_guard_post()/impersonation_action_allowed(); hiding a
 * button is a courtesy, never the enforcement (STEP 23).
 *
 * The policy is deliberately conservative — a support session exists to LOOK at a customer's panel,
 * not to act for them. Anything that spends money, sends a message, changes credentials, or destroys
 * data is blocked. Reads, navigation and cost previews are not.
 */
function impersonation_blocked_actions(): array {
    return [
        // Sending — nothing may leave the gateway on a customer's behalf during support (STEP 8).
        'send.direct'          => 'ارسال واقعی در حالت پشتیبانی غیرفعال است.',
        'send.bulk'            => 'ارسال گروهی در حالت پشتیبانی غیرفعال است.',
        'send.campaign'        => 'اجرای کمپین در حالت پشتیبانی غیرفعال است.',
        'send.schedule'        => 'ایجاد یا فعال‌سازی ارسال زمان‌بندی‌شده در حالت پشتیبانی غیرفعال است.',
        'send.autoreply'       => 'فعال‌سازی منشی پیامک در حالت پشتیبانی غیرفعال است.',

        // Credentials — impersonation must never become account-takeover tooling (STEP 29).
        'account.password'     => 'تغییر رمز عبور در حالت پشتیبانی غیرفعال است.',
        'account.twofa'        => 'تغییر ورود دومرحله‌ای در حالت پشتیبانی غیرفعال است.',

        // Integration secrets (STEP 28).
        'apikey.create'        => 'ساخت کلید API در حالت پشتیبانی غیرفعال است.',
        'apikey.rotate'        => 'چرخش کلید API در حالت پشتیبانی غیرفعال است.',
        'apikey.revoke'        => 'ابطال کلید API در حالت پشتیبانی غیرفعال است.',
        'webhook.write'        => 'تغییر تنظیمات وب‌هوک در حالت پشتیبانی غیرفعال است.',
        'webhook.rotate'       => 'چرخش کلید وب‌هوک در حالت پشتیبانی غیرفعال است.',

        // Money and plan (STEP 27).
        'billing.subscription' => 'تغییر اشتراک در حالت پشتیبانی غیرفعال است.',
        'billing.payment'      => 'ثبت پرداخت در حالت پشتیبانی غیرفعال است.',
        'wallet.adjust'        => 'تغییر اعتبار در حالت پشتیبانی غیرفعال است.',

        // Organization structure.
        'org.members'          => 'تغییر اعضا یا نقش‌های سازمان در حالت پشتیبانی غیرفعال است.',
        'org.transfer_owner'   => 'انتقال مالکیت سازمان در حالت پشتیبانی غیرفعال است.',
        'org.delete'           => 'حذف سازمان در حالت پشتیبانی غیرفعال است.',

        // Destructive data operations — recoverable only from a backup, so not a support action.
        'contacts.delete'      => 'حذف مخاطب در حالت پشتیبانی غیرفعال است.',
        'blacklist.delete'     => 'حذف از لیست سیاه در حالت پشتیبانی غیرفعال است.',
    ];
}

/** True when $action may proceed. Unknown actions are ALLOWED — the catalog is a deny-list, and a typo must not silently disable an ordinary page. Blocking is only ever the result of an explicit entry. */
function impersonation_action_allowed(string $action): bool {
    if (!is_impersonating()) {
        return true;
    }
    return !array_key_exists($action, impersonation_blocked_actions());
}

/** The operator-facing reason a blocked action was refused. */
function impersonation_block_message(string $action): string {
    return impersonation_blocked_actions()[$action] ?? 'این عملیات در حالت پشتیبانی غیرفعال است.';
}

/**
 * Records a refused sensitive action. Called by every guard below so the audit trail contains the
 * ATTEMPT, not just the successes — an operator repeatedly probing a blocked action is exactly the
 * pattern a review needs to be able to see (STEP 20).
 */
function impersonation_record_block(string $action): void {
    $state = impersonation_state();
    if ($state === null) {
        return;
    }
    Logger::warning('impersonation.blocked_sensitive_action', [
        'action'                => $action,
        'impersonator_user_id'  => $state['actor_user_id'],
        'effective_user_id'     => $state['target_user_id'],
        'organization_id'       => $_SESSION['organization_id'] ?? null,
    ]);
    Metrics::increment('impersonation.blocked_action', 1, ['action' => $action]);
    audit($state['target_user_id'], 'impersonation.blocked_sensitive_action', $action);
}

/**
 * The form-page guard: returns true (and flashes + audits) when $action is blocked.
 *
 * Shaped to drop into the existing `csrf_check(); $do = ...;` idiom every page already has:
 *
 *     if (impersonation_guard_post('send.direct')) { redirect('/send.php'); }
 *
 * Server-side and authoritative — hiding the button in the template is a separate courtesy.
 */
function impersonation_guard_post(string $action): bool {
    if (impersonation_action_allowed($action)) {
        return false;
    }
    impersonation_record_block($action);
    flash('error', impersonation_block_message($action));
    return true;
}

/**
 * The hard guard, for choke points with no page to redirect to (the dispatch functions). Throws
 * rather than returning, so a caller cannot accidentally continue past it.
 */
function impersonation_assert_action_allowed(string $action): void {
    if (impersonation_action_allowed($action)) {
        return;
    }
    impersonation_record_block($action);
    throw new ImpersonationBlockedException(impersonation_block_message($action));
}

/** Raised when a support impersonation attempts a blocked action at a non-page choke point. */
class ImpersonationBlockedException extends RuntimeException {}

/* ==========================================================================
   State
   ========================================================================== */

/**
 * The validated impersonation state, or null when this session is an ordinary one.
 *
 * Every read of `$_SESSION['impersonation']` in the codebase goes through here, and here is where
 * a crafted session dies: the recorded target must BE the session's effective user, the actor must
 * be a different, existing, still-privileged platform administrator, and the session must not have
 * outlived its bound. A session that satisfies the shape but not the checks is not "partially
 * impersonating" — it is invalid, and impersonation_enforce() ends it.
 *
 * Deliberately does NOT self-heal by clearing the flag: silently dropping impersonation would leave
 * a platform admin browsing a customer's panel as that customer with no banner and no audit, which
 * is strictly worse than failing.
 */
function impersonation_state(): ?array {
    $raw = $_SESSION['impersonation'] ?? null;
    if (!is_array($raw)) {
        return null;
    }

    $actorId  = isset($raw['actor_user_id']) ? (int)$raw['actor_user_id'] : 0;
    $targetId = isset($raw['target_user_id']) ? (int)$raw['target_user_id'] : 0;
    $startedAt = isset($raw['started_at']) ? (int)$raw['started_at'] : 0;
    $sessionUid = isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : 0;

    if ($actorId <= 0 || $targetId <= 0 || $startedAt <= 0
        || $actorId === $targetId
        || $targetId !== $sessionUid                       // the session's effective user must be the recorded target
        || ($raw['mode'] ?? null) !== IMPERSONATION_MODE_SUPPORT) {
        return null;
    }

    return [
        'actor_user_id'            => $actorId,
        'target_user_id'           => $targetId,
        'started_at'               => $startedAt,
        'reason'                   => (string)($raw['reason'] ?? ''),
        'mode'                     => IMPERSONATION_MODE_SUPPORT,
        'original_organization_id' => isset($raw['original_organization_id']) ? (int)$raw['original_organization_id'] : 0,
        'return_to'                => (string)($raw['return_to'] ?? '/users.php'),
    ];
}

function is_impersonating(): bool {
    return impersonation_state() !== null;
}

/** The user id the session is ACTING AS (the customer) while impersonating, else null. */
function impersonated_user_id(): ?int {
    $state = impersonation_state();
    return $state === null ? null : $state['target_user_id'];
}

/**
 * The REAL human behind this session: the platform admin while impersonating, otherwise the
 * logged-in user. This is what the audit trail must never lose (Invariant C/D/E).
 */
function real_actor_user_id(): ?int {
    $state = impersonation_state();
    if ($state !== null) {
        return $state['actor_user_id'];
    }
    return isset($_SESSION['uid']) && (int)$_SESSION['uid'] > 0 ? (int)$_SESSION['uid'] : null;
}

/** The real actor's identity row, resolved through the identity provider. Null when unavailable. */
function real_actor_user(): ?array {
    $state = impersonation_state();
    if ($state === null) {
        return current_user();
    }
    return impersonation_resolve_admin($state['actor_user_id']);
}

/**
 * Resolves a user id and returns it ONLY if it is a currently-valid platform administrator.
 *
 * Used both to authorize a start and to re-validate the actor on every later request (STEP 33): a
 * session must not retain impersonation capability merely because the admin WAS an admin when it
 * began.
 */
function impersonation_resolve_admin(int $userId): ?array {
    if ($userId <= 0) {
        return null;
    }
    $row = backend_find_user_by_id($userId);
    if (!$row || !$row['active'] || $row['deleted'] || !$row['panel_access'] || !$row['is_admin']) {
        return null;
    }
    $row['role'] = 'admin';
    $row['full_name'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    return $row;
}

/* ==========================================================================
   Per-request enforcement
   ========================================================================== */

/**
 * Re-validates an impersonating session on every authenticated request. Called from require_login(),
 * which every authenticated page already funnels through.
 *
 * Three outcomes, all of them safe:
 *   - ordinary session, or a valid impersonation: nothing happens.
 *   - the support window has elapsed and the actor is still a valid admin: the session returns to
 *     the admin automatically and says so. Nothing is lost and nobody is stranded.
 *   - anything else (crafted state, actor no longer a platform admin, actor account gone): the
 *     WHOLE session is destroyed. Not "impersonation cleared" — clearing alone would leave an
 *     administrator silently browsing a customer's account as that customer.
 */
function impersonation_enforce(): void {
    if (!isset($_SESSION['impersonation'])) {
        return;
    }

    $state = impersonation_state();
    if ($state === null) {
        Logger::warning('impersonation.invalid_state_terminated', ['session_uid' => $_SESSION['uid'] ?? null]);
        impersonation_destroy_session();
    }

    // STEP 33: privilege is re-checked, never trusted from the session.
    if (impersonation_resolve_admin($state['actor_user_id']) === null) {
        Logger::warning('impersonation.actor_no_longer_admin', [
            'impersonator_user_id' => $state['actor_user_id'],
            'effective_user_id'    => $state['target_user_id'],
        ]);
        audit($state['target_user_id'], 'impersonation.ended', 'reason=actor_no_longer_platform_admin');
        impersonation_destroy_session();
    }

    if ((time() - $state['started_at']) > IMPERSONATION_MAX_SECONDS) {
        Logger::info('impersonation.session_expired', [
            'impersonator_user_id' => $state['actor_user_id'],
            'effective_user_id'    => $state['target_user_id'],
            'elapsed_seconds'      => time() - $state['started_at'],
        ]);
        audit($state['target_user_id'], 'impersonation.session_expired', 'actor=' . $state['actor_user_id']);
        impersonation_restore_actor($state, 'expired');
        flash('info', 'مدت مجاز حالت پشتیبانی به پایان رسید و به پنل مدیریت بازگشتید.');
        redirect($state['return_to']);
    }
}

/** Ends the entire session. Deliberately total: a half-valid impersonation has no safe interpretation. */
function impersonation_destroy_session(): never {
    $_SESSION = [];
    if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    redirect('/login.php');
}

/* ==========================================================================
   Start / exit
   ========================================================================== */

/**
 * Whether $target may be impersonated, as a machine-readable reason (STEP 5).
 *
 * Policy, deliberately restrictive for a first implementation:
 *   - the target must be an ELLSMS-managed account (resolve_ellsms_managed_user()'s own gate, the
 *     same one every other admin action on a user goes through),
 *   - another PLATFORM ADMIN is never impersonable — support does not need it, and an admin-to-admin
 *     switch is the single most dangerous shape this feature could take,
 *   - the admin cannot impersonate themselves (it would do nothing but confuse the audit trail),
 *   - a deleted account is refused outright; an INACTIVE-but-present account is allowed, because
 *     "the customer says they cannot log in" is a primary support case and refusing it would remove
 *     the feature's main use. Nothing is reactivated — the session is read-mostly and every
 *     sensitive action stays blocked.
 */
function impersonation_target_refusal(?array $target, int $actorUserId): ?string {
    if ($target === null) {
        return 'target_not_found';
    }
    if ((int)$target['id'] === $actorUserId) {
        return 'target_is_self';
    }
    if (!empty($target['deleted'])) {
        return 'target_deleted';
    }
    if (!empty($target['is_admin'])) {
        return 'target_is_platform_admin';
    }
    if (empty($target['panel_access'])) {
        return 'target_has_no_panel_access';
    }
    return null;
}

/** Persian explanation for a refusal code — operator-facing, on an admin-only page, so it may be specific. */
function impersonation_refusal_message(string $reason): string {
    return [
        'target_not_found'           => 'این حساب در محدوده‌ی مدیریت ELLSMS نیست یا یافت نشد.',
        'target_is_self'             => 'ورود به پنل خودتان معنایی ندارد.',
        'target_deleted'             => 'این حساب حذف شده است و امکان ورود پشتیبانی وجود ندارد.',
        'target_is_platform_admin'   => 'ورود به پنل یک مدیر سامانه مجاز نیست.',
        'target_has_no_panel_access' => 'این حساب دسترسی پنل ELLSMS ندارد.',
        'already_impersonating'      => 'هم‌اکنون در حالت پشتیبانی هستید. ابتدا از آن خارج شوید.',
        'not_platform_admin'         => 'این عملیات فقط برای مدیران سامانه مجاز است.',
        'rate_limited'               => 'تعداد تلاش‌های ورود پشتیبانی بیش از حد مجاز است. کمی بعد دوباره تلاش کنید.',
    ][$reason] ?? 'ورود به حالت پشتیبانی ممکن نشد.';
}

/**
 * Begins a support impersonation. $actor MUST be the authenticated platform admin resolved by
 * require_admin() — never a user id taken from the request (Invariant: the actor is who the session
 * says they are, not who the form says).
 *
 * Returns ['ok'=>true] or ['ok'=>false,'reason'=>code].
 */
function impersonation_start(array $actor, int $targetUserId, string $reason, string $returnTo): array {
    $actorUserId = (int)($actor['id'] ?? 0);

    if (impersonation_resolve_admin($actorUserId) === null) {
        return ['ok' => false, 'reason' => 'not_platform_admin'];
    }
    // Invariant G: nesting is impossible. Belt and braces — require_admin() already denies during an
    // impersonation, because the effective user is then an ordinary customer.
    if (isset($_SESSION['impersonation'])) {
        return ['ok' => false, 'reason' => 'already_impersonating'];
    }

    $target = resolve_ellsms_managed_user($targetUserId);
    $refusal = impersonation_target_refusal($target, $actorUserId);
    if ($refusal !== null) {
        Logger::warning('impersonation.start_refused', ['impersonator_user_id' => $actorUserId, 'target_user_id' => $targetUserId, 'reason' => $refusal]);
        audit($actorUserId, 'impersonation.start_refused', "target=#{$targetUserId} reason={$refusal}");
        return ['ok' => false, 'reason' => $refusal];
    }

    $originalOrganizationId = isset($_SESSION['organization_id']) ? (int)$_SESSION['organization_id'] : 0;

    // Invariant F: a brand-new session id, so a session fixed before the switch cannot be replayed
    // afterwards with elevated reach.
    impersonation_regenerate_session();

    // The identity switch itself. No password is read or verified anywhere in this function.
    $_SESSION['uid'] = (int)$target['id'];
    // STEP 13: the admin's organization selection must not follow them in. Dropping the key makes
    // current_organization() re-resolve from the TARGET's own memberships on the next call.
    unset($_SESSION['organization_id']);
    $_SESSION['impersonation'] = [
        'actor_user_id'            => $actorUserId,
        'target_user_id'           => (int)$target['id'],
        'started_at'               => time(),
        'reason'                   => impersonation_sanitize_reason($reason),
        'mode'                     => IMPERSONATION_MODE_SUPPORT,
        'original_organization_id' => $originalOrganizationId,
        'return_to'                => impersonation_safe_return_to($returnTo),
    ];

    $organizationId = user_default_organization_id((int)$target['id']);
    Logger::info('impersonation.started', [
        'impersonator_user_id' => $actorUserId,
        'effective_user_id'    => (int)$target['id'],
        'organization_id'      => $organizationId,
        'mode'                 => IMPERSONATION_MODE_SUPPORT,
    ]);
    Metrics::increment('impersonation.started');
    // Attributed to the ACTOR: starting a support session is an administrative act, performed by the
    // admin, before the identity switch has any meaning. Actions *inside* the session are attributed
    // to the target with the impersonator recorded alongside — see audit().
    audit($actorUserId, 'impersonation.started', "target=#{$target['id']} org=" . ($organizationId ?? '-') . ' reason=' . ($_SESSION['impersonation']['reason'] ?: '-'));

    return ['ok' => true, 'target_user_id' => (int)$target['id']];
}

/**
 * Ends a support impersonation and returns the session to the original administrator.
 *
 * Deliberately does not require the target to still exist or be valid (STEP 32): the exit path is
 * the operator's way out of a session that has gone wrong, so it must not depend on the thing that
 * went wrong.
 */
function impersonation_exit(): array {
    $state = impersonation_state();
    if ($state === null) {
        return ['ok' => false, 'reason' => 'not_impersonating'];
    }

    // The actor must still be a platform admin to be restored. If they are not, there is nothing
    // safe to return to.
    if (impersonation_resolve_admin($state['actor_user_id']) === null) {
        Logger::warning('impersonation.exit_actor_invalid', ['impersonator_user_id' => $state['actor_user_id']]);
        audit($state['target_user_id'], 'impersonation.ended', 'reason=actor_no_longer_platform_admin');
        impersonation_destroy_session();
    }

    impersonation_restore_actor($state, 'manual');
    return ['ok' => true, 'return_to' => $state['return_to']];
}

/** Puts the admin back: new session id, admin identity, their original organization, no residue. */
function impersonation_restore_actor(array $state, string $how): void {
    Logger::info('impersonation.ended', [
        'impersonator_user_id' => $state['actor_user_id'],
        'effective_user_id'    => $state['target_user_id'],
        'elapsed_seconds'      => time() - $state['started_at'],
        'how'                  => $how,
    ]);
    Metrics::increment('impersonation.ended', 1, ['how' => $how]);
    audit($state['actor_user_id'], 'impersonation.ended', "target=#{$state['target_user_id']} how={$how} seconds=" . (time() - $state['started_at']));

    // Cleared BEFORE the identity moves back, so nothing can observe an admin session that still
    // carries impersonation metadata.
    unset($_SESSION['impersonation']);

    impersonation_regenerate_session();
    $_SESSION['uid'] = $state['actor_user_id'];
    unset($_SESSION['organization_id']);
    if ($state['original_organization_id'] > 0) {
        // Restored, then re-validated by current_organization() against the ADMIN's own memberships
        // — which drops it if it is no longer usable, exactly as a normal admin session would.
        $_SESSION['organization_id'] = $state['original_organization_id'];
    }
}

/**
 * New session id on every identity switch (Invariant F), keeping the authentication clock honest.
 *
 * session_mark_authenticated() is called deliberately: the absolute-timeout clock measures "time
 * since this identity was established", and an impersonation IS a new authenticated identity. It
 * does NOT extend anything — IMPERSONATION_MAX_SECONDS is independently enforced and shorter than
 * the absolute session timeout's default.
 */
function impersonation_regenerate_session(): void {
    if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    session_regenerate_id(true);
    session_mark_authenticated();
}

/** Plain text, bounded, no markup — it is rendered back to operators and stored in the audit trail. */
function impersonation_sanitize_reason(string $reason): string {
    $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim($reason)) ?? '';
    $clean = strip_tags($clean);
    return mb_substr(trim($clean), 0, IMPERSONATION_REASON_MAX_LENGTH);
}

/**
 * Where "بازگشت به پنل مدیریت" lands. Only a same-site absolute path is accepted, so a crafted
 * return_to cannot turn the exit button into an open redirect.
 */
function impersonation_safe_return_to(string $candidate): string {
    $candidate = trim($candidate);
    if ($candidate === '' || !str_starts_with($candidate, '/') || str_starts_with($candidate, '//')) {
        return '/users.php';
    }
    return preg_match('#^/[A-Za-z0-9_\-/.]*(\?[A-Za-z0-9_\-=&%.]*)?$#', $candidate) === 1 ? $candidate : '/users.php';
}

/** Display context for the banner — resolved fresh, never cached in the session beyond ids. */
function impersonation_banner_context(): ?array {
    $state = impersonation_state();
    if ($state === null) {
        return null;
    }
    $target = current_user();
    $organization = current_organization();
    return [
        'target_username'   => (string)($target['username'] ?? ('#' . $state['target_user_id'])),
        'target_full_name'  => trim((string)($target['full_name'] ?? '')),
        'organization_name' => (string)($organization['name'] ?? ''),
        'started_at'        => $state['started_at'],
        'expires_in'        => max(0, IMPERSONATION_MAX_SECONDS - (time() - $state['started_at'])),
        'return_to'         => $state['return_to'],
    ];
}
