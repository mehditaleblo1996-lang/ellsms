<?php
/**
 * Inline notice for a page whose primary action is blocked during a support impersonation
 * (docs/admin-impersonation.md, STEP 24).
 *
 * Expects: $impersonationNoticeAction — a key from impersonation_blocked_actions().
 *
 * This is a COURTESY, never the enforcement. Every action named here is already refused server-side
 * by impersonation_guard_post()/impersonation_action_allowed() before anything happens; the point of
 * the notice is that an operator should learn the action is unavailable BEFORE filling in a form,
 * not after submitting it. Renders nothing at all outside an impersonation.
 */
if (is_impersonating() && !impersonation_action_allowed($impersonationNoticeAction ?? '')) : ?>
  <div class="flash flash-info">
    <?= e(impersonation_block_message($impersonationNoticeAction)) ?>
    مشاهده و بررسی اطلاعات این صفحه همچنان در دسترس است.
  </div>
<?php endif; ?>
