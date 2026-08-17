# Profile + KYC management

Account type (حقیقی/حقوقی), the KYC review workflow, per-document review, admin review UI, allowed-IP
management, and configurable KYC feature gating.

Code: `app/Kyc.php` (workflow), `app/Profile.php` (extended), `app/AllowedIps.php` · Pages:
`public/profile.php` (self-service), `public/kyc-review.php` (platform admin) · Schema:
`db/migrations/2026_08_17_kyc_workflow.sql`

This continues `docs/customer-profile.md`, which deliberately shipped without a review workflow
(§11: "No KYC approval workflow — documents are stored, replaced and archived; nothing reviews or
approves them"). Read that document first — it owns the underlying data model (personal profile,
company profile, address, documents) that this phase builds a workflow on top of, without changing
it.

---

## 1. Account type

Every organization has exactly one durable `account_type`, stored on `ellsms_organization_profiles`
(the organization/account profile level, never scattered as a per-feature flag):

| Value | Persian label |
|---|---|
| `individual` | حقیقی |
| `legal` | حقوقی |

**Migration safety.** Every existing organization backfills to `individual` unless its existing data
already looks like a company (a non-`unspecified` `company_type` or a non-empty `legal_name`), in
which case it backfills to `legal`. No organization is forced into an incomplete KYC flow by the
migration — a KYC request row is created lazily, on first read, so an organization that never touches
KYC has no row and is completely unaffected.

**Switching is non-destructive.** `profile_organization_save()` merges its input against the current
row before writing (any field not explicitly present in the caller's input falls back to what is
already stored), so toggling `account_type` alone — which is exactly what the profile page's "نوع
حساب" control does — can never blank out company data, representative data, or documents. Both
sides' data and documents simply stay stored; only which UI sections are shown, and which document
set is required for submission, changes. A field explicitly submitted as empty is still a real,
deliberate clear — this only protects against fields the caller never mentioned.

## 2. Field ownership

Unchanged from `docs/customer-profile.md` §1/§2, extended additively:

| Field | Table | Notes |
|---|---|---|
| `account_type` | `ellsms_organization_profiles` | new |
| Representative contact (`ceo_birth_city`, `ceo_mobile`, `ceo_email`) | `ellsms_organization_profiles` | new — additive to the existing `ceo_name`/`ceo_father_name`/`ceo_national_code`/`ceo_birth_date`, which already modeled the legal representative. No second, parallel `representative_*` naming scheme was introduced for the same person. |
| `company_type` | `ellsms_organization_profiles` | ENUM widened additively with the Iranian legal-entity types (`private_joint_stock`, `public_joint_stock`, `limited_liability`, `cooperative`, `institution`, `governmental`), the original 5 values kept for backward compatibility |
| KYC request state | `ellsms_kyc_requests` | new, one row per organization |
| Document review state | `ellsms_profile_documents.review_status`/`reviewed_at`/`reviewed_by_user_id`/`review_note` | new, additive columns |
| Allowed IPs | `ellsms_organization_allowed_ips` | new |

`app/Kyc.php` never writes to `user_`, matching Profile.php's own boundary rule.

## 3. Document catalog

Additive to `docs/customer-profile.md`'s catalog:

| User | Organization |
|---|---|
| `national_card`, `birth_certificate`, `selfie_with_national_card`, `address_proof` | `incorporation_notice`, `latest_changes_notice`, `registration_document`, `introduction_letter`, `postal_certificate`, `representative_national_card` |

**Minimum required for submission** (`PROFILE_REQUIRED_DOCUMENTS_INDIVIDUAL` /
`_LEGAL` in `app/Profile.php`), a deliberately small, product-defined subset of the full catalog:

- individual: `national_card`, `selfie_with_national_card`
- legal: `incorporation_notice`, `representative_national_card`

Every catalog entry is always uploadable; only these are *blocking* for submission.

## 4. KYC state machine

```
draft -> submitted
submitted -> under_review
under_review -> approved | needs_correction | rejected
needs_correction -> submitted
rejected -> submitted
approved -> (terminal)
```

`KYC_TRANSITIONS` (`app/Kyc.php`) is the *only* table of allowed transitions, and `kyc_transition()`
is the *only* function that writes `ellsms_kyc_requests.status` — every write locks the row
(`SELECT ... FOR UPDATE`), re-checks the transition against the table, and refuses (`invalid_transition`)
anything not explicitly listed. `submitted_at`, `review_started_at`, `reviewed_at`,
`reviewed_by_user_id` and `review_note` are set by the same function, keyed off which transition ran,
never by a separate write path.

**Submission eligibility** (`kyc_can_submit()` / `kyc_submit()`) is centralized and shared —
`public/profile.php` calls the exact same function `public/kyc-review.php` would use to sanity-check,
so there is no separate, potentially-drifting "the UI thinks this is complete" rule. A user may not
submit unless their account type's minimum profile fields, address, and required documents are all
present.

## 5. Document review

Independent of the overall KYC request status. Each document carries its own
`pending` / `approved` / `rejected` review state, settable only by a platform admin
(`kyc_document_review()`, called from `public/kyc-review.php`). Replacing a document (uploading a new
one of the same type) archives the old row *and* starts the new one at `pending` — a rejection never
survives onto a replacement upload (`ellsms_profile_documents.review_status` is a plain column on
each row, not carried through `profile_document_archive_active()`).

## 6. Admin review flow

`public/kyc-review.php` — platform-admin-only:

- **List** (`kyc_requests_list()`): filter by status, search by organization/legal name or numeric
  organization id.
- **Detail** (`?id=<organization_id>`): profile data (branch by `account_type`), every document with
  its review state, the request's own timestamps, and the set of transitions currently legal from
  `KYC_TRANSITIONS`.
- **Actions**: transition the overall request (with an optional review note, required in practice for
  `needs_correction`/`rejected`); approve/reject an individual document with its own note.
- Every document link routes through the existing `public/profile-document.php` — nothing here
  renders a document inline or exposes a second, unauthenticated path to one.
- A document review POST re-validates that the document actually belongs to the organization (or its
  owning user) the screen is scoped to before accepting the action — a cross-organization
  `document_id` is refused, matching `profile_document_belongs_to()`'s existing ownership check.

## 7. RBAC

`Permissions::KYC_VIEW` / `KYC_MANAGE` stay exactly as `app/rbac.php` already documents: **reserved**,
granted to no organization role by default, not even owner. This phase does not reopen that decision.
Every admin-facing KYC action lives on `public/kyc-review.php` and is gated by `require_admin()`, the
same platform-admin-only model the pre-existing `public/users.php` KYC fields and
`public/kyc-photo.php` already use. Broadening identity-document review to an organization role
"merely because RBAC exists" is exactly what `docs/customer-profile.md` §3 already warned against, and
this phase agrees with that reasoning rather than relitigating it.

Self-service actions on `public/profile.php` (submitting a KYC request, uploading a document,
managing allowed IPs) use the existing `settings.manage` permission for anything organization-scoped,
identical to the rest of that page.

## 8. Audit

Every action in the phase brief's list is audited via the existing `audit()` (`app/bootstrap.php`):
`profile.account_type_changed`, `profile.updated`, `kyc.document_uploaded` (already
`profile.document_upload` from Phase — see note below), `kyc.document_approved`,
`kyc.document_rejected`, `kyc.submitted`, `kyc.review_started`, `kyc.approved`,
`kyc.needs_correction`, `kyc.rejected`, `allowed_ip.created`, `allowed_ip.deleted`.

Document *upload* itself keeps `docs/customer-profile.md`'s original audit action name
(`profile.document_upload`) rather than introducing a second, differently-named event for the same
write — grepping the audit log for either name finds the same rows. Review notes are **never** written
into the audit trail (only that a review happened, and by whom) — a review note can legitimately
reference sensitive specifics ("national code photo is blurry"), matching
`profile_mask_identifier()`'s existing precedent of never duplicating sensitive text into the trail.

## 9. Feature gating

`kyc_feature_allowed(int $organizationId, string $gate): bool` (`app/Kyc.php`) is the **one** place any
KYC-gated feature is decided. Catalog (`KYC_FEATURE_GATES`): `credit_purchase`,
`dedicated_number_request`, `production_api`, `high_volume_send`.

Each gate's on/off switch is an ordinary `ellsms_settings` row (`kyc_gate.<gate>`, via the existing
`setting()`/`set_setting()`), **defaulting to off** — §22's explicit "default migration behavior must
preserve current production behavior; do not unexpectedly block current customers." An operator turns
a gate on with `kyc_gate_set_required()`; nothing in this phase turns one on automatically.

**Not wired into any existing feature's code path.** Billing, wallet, and numbers are explicitly out
of scope for this phase except for the gating *mechanism* itself — no gate defaults to required, so no
existing call site needs to change today. Integrating a real feature means, at that feature's own
entry point: `if (!kyc_feature_allowed($organizationId, 'credit_purchase')) { …refuse with a clear
Persian message… }` — one line, never a bare `if ($kyc === 'approved')` scattered through unrelated
files.

The yes/no decision itself (`kyc_feature_allowed_for_status()`) is a pure function, separated from the
`ellsms_settings` lookup specifically so it is unit-testable without the settings cache's known
process-lifetime caching behavior (see `tests/Integration/IntegrationTestCase.php`'s own note on
`default_originator` — the same limitation applies to any `ellsms_settings`-backed value read more
than once per process).

## 10. Allowed IP management

`app/AllowedIps.php` + `ellsms_organization_allowed_ips`. Validates IPv4, IPv6, and CIDR
(`allowed_ip_normalize()`), normalizes Persian/Arabic digits first, enforces uniqueness per
organization, and audits create/delete.

**Enforcement status: NOT implemented.** This is management only. ELLSMS's login and API-key paths
(`app/authorization.php`, `app/rate_limit.php`) have no existing "check the caller's IP against a
per-organization allowlist" hook, and the phase brief is explicit that enforcement is added only "if
the project already has a clear safe hook" — wiring one in blind risks locking an administrator out
mid-migration, which the brief explicitly forbids. An organization can record and manage its expected
IPs today; nothing currently refuses a request based on them. Building real enforcement is future work
and would need its own design (which paths it applies to, what happens to an admin locked out by their
own configuration, whether platform-admin/impersonation is exempt) — deliberately not invented here.

## 11. Low-credit preferences

Unchanged from `docs/customer-profile.md` §11: `ellsms_organization_notification_preferences` already
stores `low_credit_alert_enabled`/`low_credit_threshold`/`email_alert_enabled`/`sms_alert_enabled` in
the wallet's own CREDIT unit. This phase does not touch that table or invent a second one — see that
document for the (unchanged) statement that the preference is stored, not automatically sent.

## 12. Profile completion

`profile_account_completeness()` (`app/Profile.php`) is the single, centralized, deterministic
completion figure shown on the profile page — which underlying score it reports depends only on
`account_type`:

- `individual`: personal identity fields (`profile_user_completeness()`) plus address city/postal
  code.
- `legal`: the existing company completeness score (`profile_organization_completeness()`), which
  already folds the address in.

Optional fields are never counted as completion blockers, matching
`docs/customer-profile.md`'s existing "half-filled and saveable" philosophy.

## 13. Tenant isolation

Every new query is organization-scoped or ownership-checked, following the exact patterns
`docs/customer-profile.md` already established:

- `ellsms_kyc_requests` is keyed by `organization_id`; `kyc_transition()`/`kyc_request_get()` never
  accept a bare id without it being the caller's own resolved organization.
- Document review (`kyc_document_review()`) operates on a document row already resolved by id;
  `public/kyc-review.php` additionally re-checks `profile_document_belongs_to()` against the
  organization the admin screen is scoped to before accepting a review action.
- `ellsms_organization_allowed_ips` operations always take `(organizationId, id)` together — deleting
  or toggling an id that belongs to a *different* organization is a no-op (`not_found`), never a
  cross-tenant mutation.
- Covered directly by `tests/Integration/KycWorkflowIntegrationTest.php` (a transition/account-type
  change on one organization never affects another; an allowed IP cannot be deleted through a
  different organization's scope; a document's ownership check is exercised against both the
  correct and the wrong organization id).

## 14. Migration / backfill

`db/migrations/2026_08_17_kyc_workflow.sql` — additive only, idempotent (same guarded-ALTER pattern as
every migration since `2026_08_13`): widens `company_type`, adds `account_type` (+ backfill), adds
representative contact columns, adds document review columns (existing documents backfill to
`pending` — correct, since none of them have actually been reviewed), creates
`ellsms_kyc_requests` and `ellsms_organization_allowed_ips`. Nothing existing is dropped, renamed, or
rewritten. Apply with the existing `make db-migrations-apply` — no new operator command was
introduced, unlike Phase 12's profile backfill, because there is no legacy data to migrate here.

## 15. API

No new public API surface. `/api/v1/*` endpoints are untouched by this phase — the brief's own
guidance ("do not expose national ID, document storage keys, document contents, reviewer-only notes")
is satisfied by not adding any of this to the API at all rather than adding it and then filtering it.

## 15a. UI completion pass (2026-08-18)

`public/profile.php` was reworked to match the reference profile/KYC screens structurally, without
touching the data-ownership model in §1–§4: a segmented حقیقی/حقوقی switcher; a read-only account-info
summary card (deliberately never a second edit path for a field already editable elsewhere — the
prior layout's "confusing overlap between personal profile and KYC information" was exactly that); a
KYC status card with submit/review timestamps; one section for individual profile fields, one combined
company+representative section for legal accounts; a restyled address card; and a document **tile
grid** (thumbnail preview through the existing protected download endpoint, per-document review
status/note, a styled upload/replace control) replacing the old table+raw-file-input layout.
`public/kyc-review.php` got the same tile treatment for consistency; its review workflow is unchanged.

Five fields the reference screens expect had no column anywhere and were added additively
(`db/migrations/2026_08_18_profile_ui_completion.sql`): `national_id_expiry_at` (user),
`landline_phone`/`fax_number`/`customer_code`/`ceo_birth_certificate_no`/`ceo_last_name`
(organization). `ceo_last_name` is additive next to the pre-existing `ceo_name` rather than a rename,
so nothing that already reads `ceo_name` needed to change. `profile_user_save()` gained the same
merge-safe (no-silent-data-loss) contract `profile_organization_save()` already had. "حداکثر تعداد
زیرمجموعه" (a sub-organization hierarchy) was deliberately NOT added — no such hierarchy exists
anywhere in ELLSMS, and inventing one is a different, much larger feature than a display field.

## 16. Known limitations / follow-up

- **Allowed-IP enforcement is not implemented** (§10) — management only, by design, until a safe
  enforcement hook exists.
- **No feature gate defaults to required** — the mechanism exists and is tested; turning one on for a
  specific product feature (credit purchase, dedicated numbers, …) is a follow-up integration at that
  feature's own call site, deliberately not done here to avoid touching pricing/wallet/billing
  behavior outside what this phase asked for.
- **No automatic KYC-status notification** (email/SMS on approval/rejection) — out of scope, same as
  the pre-existing low-credit-alert sender.
- **`ellsms_settings`-backed gate toggles are cached per-process** (see §9) — correct across real web
  requests (one process each), a known limitation only inside a single long-lived PHPUnit process,
  documented rather than worked around.
