# Customer / organization profile — final report

**Status: PASS**

Personal profile, company legal profile, organization address, low-credit alert settings and private
profile documents, with platform-admin and self-service views, cross-tenant isolation and
impersonation-safe read-only access.

Feature guide: [`docs/customer-profile.md`](customer-profile.md)

---

## 1. The design decision

**Personal identity belongs to the USER; company and legal data belongs to the ORGANIZATION.**

Keying company data by `user_id` would make two things impossible that this product needs: a second
member of an organization seeing the same company profile, and one user in two organizations seeing
two different ones. So every company read/write takes an `organization_id`, every personal one takes
a `user_id`, and no function takes both and guesses.

The backend platform stays authoritative for identity (username, name, email, mobile, balance). None
of it is copied, and `app/Profile.php` contains no `user_` SQL at all — asserted by a test, so Phase
8's boundary is not quietly reopened.

## 2. Validation

### PHP lint
`make lint` — **240 files, 0 parse errors.**

### Unit tests
`vendor/bin/phpunit` — **300 tests, 730 assertions, 0 failures/errors/skipped.**

### Integration tests (real MySQL 8, clean database)
`vendor/bin/phpunit -c phpunit.integration.xml` — **466 tests, 2502 assertions, 0 failures/errors/
skipped** (422 before this work; +44).

- `tests/Integration/CustomerProfileTest.php` — 31 tests (ownership, validation, documents, audit)
- `tests/Integration/CustomerProfileHttpTest.php` — 13 tests (real multipart uploads, download
  authorization, CSRF, permissions, impersonation)

### Profile results

| Area | Result |
|---|---|
| Personal profile | PASS — user-owned; a second user never sees it |
| Organization profile | PASS — organization-owned; every member sees the same one |
| Address | PASS — organization-scoped, postal code normalized to 10 digits or refused |
| Notification settings | PASS — organization-scoped; threshold is configuration and never touches the wallet (asserted against a real balance) |
| Source of truth | PASS — no field written in two places; `app/Profile.php` has no `user_` SQL |
| Legacy backfill | PASS — dry run, apply, and a second apply proven to be a no-op |
| Multi-membership | PASS — profile, address, alerts and documents all switch with the active organization while the personal profile stays the same person |
| Platform-admin detail | PASS — six new sections render on the real customer page |
| Platform-admin edit | PASS — saves company/address/alerts/documents; organization resolved from the target's own memberships, never from the request |
| Normal-user profile | PASS — self-service view and edit |
| User/org permissions | PASS — member reads the company profile and cannot write it; `settings.manage` can |
| Document model | PASS — exactly one owner, enforced by a database `CHECK` |
| Document upload | PASS — real browser multipart upload end-to-end |
| MIME/size validation | PASS — a PHP payload named `.png` is refused by content inspection; SVG refused; oversized refused |
| Document storage | PASS — outside the web root, 40 hex chars of random, nothing uploader-supplied reaches the filesystem |
| Download authorization | PASS — owner/member/platform admin only; **404** on refusal so ids cannot be enumerated |
| Replacement / archive | PASS — the previous version keeps its row *and* its file; one active document per type enforced by a UNIQUE index |
| Document backup/restore | **PARTIAL — honestly reported.** DB metadata is backed up; FILES are not. See §4. |
| profile-integrity-check | PASS — exit 0 on a consistent database, and it genuinely caught two real problems during this work (§5) |
| Cross-tenant | PASS — profile, documents, archive and download all denied across organizations |
| Admin impersonation | PASS — reads allowed, every mutation blocked, platform-admin document reach does not leak in |
| Audit | PASS — national codes masked (`12******90`), address values never written to the trail |

### Security

- **CSRF** — enforced on every profile mutation; a forged token yields 400 and zero mutation.
- **XSS** — a value written directly to the database still renders escaped; markup is also stripped
  at the write boundary.
- **IDOR** — crafted document ids, crafted `organization_id` on the admin page, and cross-owner
  archive attempts are all refused with zero mutation.
- **Platform-admin boundary** — an organization user POSTing to `users.php` gets 403.
- **Sensitive logs** — reviewed: no national code, address, or document content in any log line,
  metric label, or URL.

### Existing regressions
Admin impersonation, SMS pricing, Cost Preview, Phase 13 billing/quota, Phase 12 API/webhooks, Phase
11 backup/restore, Phase 10 security, Phase 8 backend boundary, Phase 7 RBAC, Phase 6 tenancy,
wallet/payment and the queue are all inside the 466-test clean-state run — **0 failures**.

### Live smoke
Real MySQL, real PHP server, real `public/`: admin opens the customer detail page → all six profile
sections present → saves company profile and address (Persian digits normalized) → uploads and views
an organization document → impersonates the customer → profile and document readable, edits and
uploads blocked, admin area 403 → exits → admin edit works again. Then the customer: opens their
profile, edits personal fields, uploads a personal document; a member sees the same company profile,
cannot change it, cannot read another user's personal document, and *can* read the organization's.
**39/39 checks pass.**

### Operational commands

| Command | Result |
|---|---|
| `profile-backfill` (dry run / apply / re-apply) | PASS — idempotent; `storage/kyc` never modified |
| `profile-integrity-check` | exit 0 on a consistent database |
| `profile-status ORG=/USER=` | PASS — completeness and gaps, no sensitive values printed |

## 3. Deliverables

### New files (9)

| Path | Purpose |
|---|---|
| `app/Profile.php` | the whole service: catalogs, validation, documents, authorization, completeness |
| `public/profile-document.php` | authenticated document download |
| `db/migrations/2026_08_12_customer_profile.sql` | five tables, schema only |
| `cron/profile-backfill.php` | legacy `ellsms_user_kyc` migration |
| `cron/profile-integrity-check.php` | ownership, checksums, missing files, identifiers, legacy dependency |
| `cron/profile-status.php` | support/ops view |
| `tests/Integration/CustomerProfileTest.php`, `CustomerProfileHttpTest.php` | 44 tests |
| `docs/customer-profile.md` + this report | documentation |

### Modified
`app/bootstrap.php` (loader), `app/impersonation.php` (three blocked actions), `public/profile.php`
(self-service rewrite), `public/users.php` (admin sections), `Makefile`, and
`docs/{architecture,rbac-architecture,admin-impersonation,backup-and-disaster-recovery,production-runbook,technical-debt}.md`.

### Migrations
One: `db/migrations/2026_08_12_customer_profile.sql`. Additive; creates
`ellsms_user_profiles`, `ellsms_organization_profiles`, `ellsms_organization_addresses`,
`ellsms_organization_notification_preferences`, `ellsms_profile_documents`. **No existing row is
modified**, `ellsms_user_kyc` keeps every column, and nothing backend-owned is touched. Data movement
is the separate `make profile-backfill`.

No generated columns — the "one active document per type" uniqueness uses an application-maintained
slot column, so TD-070 is not reintroduced.

### New environment variables
**None.** The document size limit and accepted formats are constants matching the existing KYC policy
rather than new configuration.

### Breaking changes
**None.** The legacy `kyc_save` write path on `public/profile.php` is replaced by the new personal
profile form, and a documented **read-only** fallback (`profile_user_get()`) keeps existing
`father_name`/`address` visible until `make profile-backfill` runs — so there is exactly one write
path and no window in which data appears lost. `public/kyc-photo.php` and the admin KYC card are
untouched and keep working.

## 4. Document files are not in the database backup

`make backup` is a `mysqldump`: it captures the profile tables and document **metadata**, and nothing
on the filesystem. Files under `storage/profile-documents/` (and the pre-existing `storage/kyc/`) are
**not** in that artifact.

Restoring a database without the matching filesystem leaves rows whose files are gone. That state is
detectable rather than silent — downloads 404 and `profile-integrity-check` reports each affected
document as CRITICAL, comparing every recorded sha256 against the file on disk.

This is stated as an **operational prerequisite** (back up `storage/` with the same volume snapshot
that protects the rest of the deployment) rather than claimed as covered, and recorded as **TD-071**
with a concrete closure path: extend `cron/backup.php` to archive+checksum the storage tree and teach
`cron/restore.php` to unpack it, with its own DR test. Bundling that into a profile feature would
have meant changing the backup path without the DR coverage it deserves.

## 5. Two real problems the tooling caught during this work

**Orphan files from the test suite.** `profile-integrity-check` reported 8 files in
`storage/profile-documents/` referenced by no row. The cause was mine: integration tests run inside a
transaction that rolls rows back, but files do not roll back. `CustomerProfileTest` now records the
directory contents in `setUp()` and removes anything new in `tearDown()` — verified to leave zero
files behind.

**Metadata without a file.** After I deleted the storage directory between runs, the check reported
both live-smoke documents as CRITICAL. That is precisely the TD-071 restore scenario, and it
confirmed the checksum/presence verification does what §4 claims.

## 6. Acceptance criteria

- [x] personal profile is user-owned
- [x] company/legal profile is organization-owned
- [x] address is organization-scoped
- [x] low-credit notification settings are organization-scoped
- [x] existing authoritative identity fields are not duplicated unsafely
- [x] platform admin can view/edit customer/company profile
- [x] normal users can view their profile
- [x] organization edit permissions are server-side enforced
- [x] multi-membership switches company profile correctly
- [x] profile documents are private
- [x] document download is authenticated/authorized
- [x] document storage is outside public root
- [x] file validation blocks executable/unsafe uploads
- [x] cross-tenant profile/document IDOR tests pass
- [x] support impersonation is read-only for profile mutations
- [x] sensitive profile changes are audited
- [x] migration/backfill is rerun-safe
- [x] profile-integrity-check exists
- [x] backup/restore behavior for document metadata/files is honestly validated (§4)
- [x] backend-boundary regression stays green
- [x] full regression suite stays green
