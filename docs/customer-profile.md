# Customer / organization profile

Personal profile, company legal profile, address, low-credit alert settings, and private profile
documents.

Code: `app/Profile.php` · Pages: `public/profile.php` (self-service), `public/users.php` (platform
admin) · Downloads: `public/profile-document.php` · Schema:
`db/migrations/2026_08_12_customer_profile.sql`

---

## 1. Ownership — the rule everything else follows from

**Personal identity belongs to the USER. Company and legal data belongs to the ORGANIZATION.**

| Domain | Owner | Table |
|---|---|---|
| Personal identity (father's name, national code, birth certificate, birth date, gender, personal address) | user | `ellsms_user_profiles` |
| Company / legal record | organization | `ellsms_organization_profiles` |
| Company address | organization | `ellsms_organization_addresses` |
| Low-credit alert settings | organization | `ellsms_organization_notification_preferences` |
| Documents | exactly one of the two | `ellsms_profile_documents` |

Keying company data by `user_id` would make two things impossible that this product genuinely needs:

- a **second member** of an organization seeing the same company profile (Invariant C), and
- one user in **two organizations** seeing two different company profiles (Invariant D).

Both are asserted directly in `tests/Integration/CustomerProfileTest.php`.

## 2. Source of truth

This is a hard architecture rule: every field has exactly one owner, and nothing is written in two
places.

| Field | Authoritative source | Editable from ELLSMS? |
|---|---|---|
| username, first/last name, email, mobile | **backend `user_`** (via Phase 8 adapters) | No — rendered read-only, labelled as central-system data |
| `currentcredit` / balance | wallet ledger (`app/wallet.php`) | Only through wallet operations |
| `panel_access`, `is_admin`, `originator`, `twofa_enabled` | `ellsms_meta` | Platform admin only |
| father's name, national code, birth certificate no., birth date, gender, personal address | `ellsms_user_profiles` | User, and platform admin |
| legal name, company type, registration no., company national id, economic code, CEO fields, company dates | `ellsms_organization_profiles` | `settings.manage`, and platform admin |
| address, postal code | `ellsms_organization_addresses` | `settings.manage`, and platform admin |
| low-credit threshold and alert channels | `ellsms_organization_notification_preferences` | `settings.manage`, and platform admin |
| documents | `ellsms_profile_documents` | owner (personal) / `settings.manage` (organization) / platform admin |

`app/Profile.php` never writes to `user_`; a test asserts the file contains no `user_` SQL at all, so
Phase 8's boundary is not quietly reopened.

**`legal_name` is not a duplicate of `ellsms_organizations.name`.** The organization name is its
display name inside ELLSMS; `legal_name` is the registered entity name that appears on an invoice,
and the two are frequently different. The UI falls back to the organization name when `legal_name` is
empty, so nothing has to be typed twice.

## 3. Permissions

Deliberately no new permissions (STEP 22's "smallest clean model"):

| Actor | Personal profile | Company profile / address / alerts / org documents |
|---|---|---|
| The user themselves | edit | — |
| Member | edit own | **view only** |
| Admin / Owner (`settings.manage`) | edit own | edit |
| Platform admin | edit any, via `users.php` | edit any |

`settings.manage` is already the organization-configuration permission, already granted to owner and
admin and withheld from member. A new `profile.manage` would have been granted to exactly the same
roles and would only have added a second thing to keep in sync.

## 4. Documents

- Stored **outside the web root**, under `storage/profile-documents/`.
- Filenames are **40 hex characters of random** plus a validated extension. Nothing the uploader
  supplies ever reaches the filesystem, so path traversal and extension smuggling have no surface.
- Accepted by **real content inspection** (`mime_content_type`): JPEG, PNG, WEBP, PDF. The browser's
  declared type and the filename are both ignored for the decision. SVG is excluded — it is
  script-bearing markup, not an image.
- **8MB** limit, matching the existing KYC limit rather than inventing a second number.
- **Exactly one owner**, enforced by a database `CHECK` constraint, not by convention — an
  ambiguously-owned document is the direct road to a cross-tenant read.
- **One active document per owner per type**, enforced by a `UNIQUE` index on an application-
  maintained `active_slot` column. (Not a generated column — see TD-070.)
- **Replacement archives, never overwrites.** Uploading a new national card archives the previous
  one; the old row and the old file both survive, and remain downloadable by id.
- Every read goes through `public/profile-document.php`, which authorizes per request and responds
  **404** (not 403) when refused, so document ids cannot be enumerated.

### Document types

| User | Organization |
|---|---|
| `national_card`, `birth_certificate` | `incorporation_notice`, `latest_changes_notice`, `registration_document`, `introduction_letter`, `postal_certificate` |

A user type filed against an organization (or the reverse) is refused.

## 5. Validation

- Persian/Arabic digits are normalized to ASCII everywhere (`profile_normalize_digits()`).
- National codes and postal codes are stored as **exactly 10 digits or empty** — never truncated into
  a wrong value. An empty value is "not provided yet", not an error.
- **No national-code checksum and no government lookup.** The product does not verify identity, so it
  never claims to. Shape is validated; genuineness is not asserted.
- Company expiry may not precede company start.
- A linked legal representative must be an **active member of that organization**.
- Unknown company types and genders fall back to `unspecified`. **Gender is never inferred** — not
  from a name, a phone number, or anything else.
- Markup is stripped at the write boundary *and* every value is escaped at render.

## 6. Impersonation

A support session may **read** a profile, address, alert settings and any document the target could
normally see. It may **not** change any of them: `profile.personal`, `profile.organization` and
`profile.documents` are on the blocked-action list in `app/impersonation.php`.

The real actor's platform-admin document reach is deliberately **not** combined with the target's
view context (STEP 27): while impersonating, an administrator sees exactly what the customer sees. An
admin who needs unrestricted document access exits impersonation first. This is asserted directly —
the same admin can read a foreign organization's document as themselves and gets a 404 for it while
impersonating.

## 7. Privacy

National codes, birth dates, postal addresses and documents are treated as sensitive:

- audit rows record **that** identity data changed and for whom, never a second copy of the value —
  national codes are masked (`12******90`) and addresses are not written to the trail at all;
- `make profile-status` prints **presence**, never the value, of sensitive fields;
- documents are never cacheable (`no-store`), never publicly addressable, and the download response
  filename is rebuilt from the document type rather than the uploaded name;
- nothing sensitive appears in metrics labels, URLs or query strings.

## 8. Legacy migration

`ellsms_user_kyc` already held `father_name`, `address` and two identity-photo slots. The migration
is **schema-only**; moving the data is an explicit operator command:

```bash
make profile-backfill-dry-run     # report only
make profile-backfill             # move personal fields, COPY document files
```

- Personal fields move only for users with **no** profile row yet, so newer data is never overwritten.
- Document files are **copied**, not moved — `public/kyc-photo.php` keeps serving existing links, and
  `storage/kyc` is never modified or deleted. `legacy_source` makes re-running a no-op.

**Read-through fallback (STEP 52).** Until the backfill runs, `profile_user_get()` reads
`father_name`/`personal_address` from `ellsms_user_kyc` so a deploy without a backfill does not appear
to lose customer data. It is read-only and there is exactly **one write path** — the first save writes
the new table and the legacy one is never consulted for that user again, so the two cannot diverge.
`make profile-integrity-check` reports how many users still depend on it; that number reaching zero is
the precondition for ever retiring the legacy columns.

## 9. Backup and restore — read this

**Database:** profile tables and document *metadata* are ordinary ELLSMS tables and are included in
`make backup` like everything else. Restore brings them back intact.

**Files: NOT included.** Phase 11's backup is a `mysqldump` of the database. Document *files* under
`storage/profile-documents/` (and the pre-existing `storage/kyc/`) are **not** in that artifact, and
this feature does not change that.

Restoring a database without the corresponding filesystem therefore produces rows whose files are
missing — downloads 404 and `make profile-integrity-check` reports each one as CRITICAL, which is how
you find out. **Backing up `storage/` is an operational prerequisite**, not something the application
performs. This is stated plainly rather than claimed as covered; see
`docs/backup-and-disaster-recovery.md` and TD-071 in `docs/technical-debt.md`.

## 10. Operations

```bash
make profile-integrity-check              # ownership, checksums, missing files, invalid identifiers
make profile-status ORG=<id>              # completeness and what is missing, for support
make profile-status USER=<id>
make profile-status-json
make profile-backfill-dry-run             # legacy migration preview
```

The integrity check verifies every document's **sha256 against the file on disk**, reports metadata
without a file, files without metadata, documents owned by both or neither party, duplicate active
documents, representatives who are not members, malformed identifiers, and expiry-before-start.
It never auto-fixes: identity and legal data is exactly where a silent "correction" is worse than the
inconsistency.

## 11. Limitations

- **The low-credit alert is stored, not sent.** This phase owns the preference; the notification
  sender needs a scheduled job with its own dedup/idempotency design and is deliberately not invented
  here. Nothing currently reads the threshold to send anything.
- **One address per organization.** Every screen in this product represents a single company address;
  a billing/shipping split would add a "which one?" question to every read for no current benefit.
- **No province/city catalog.** Validated free text, because no such catalog exists in the product
  today and building geographic master data is a different project.
- **No KYC approval workflow.** Documents are stored, replaced and archived; nothing reviews or
  approves them, and completeness is informational — it never blocks product use.
- **Document files are not in the database backup** (§9).
- **`user_.national_id` / `gender` are not read.** The backend platform writes them at account
  creation but its identity adapter does not expose them, so ELLSMS keeps its own extended fields
  rather than creating a second writer for backend-owned columns.
