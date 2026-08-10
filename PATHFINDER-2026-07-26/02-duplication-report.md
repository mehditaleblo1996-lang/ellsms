# Duplication report

## 1. `ellsms_numbers` per-user lookup — 5 verbatim copies
`SELECT number, label FROM ellsms_numbers WHERE assigned_user_id = ? ORDER BY number` appears byte-identical in:
`public/send.php:13`, `public/new-send.php:9`, `public/p2p-send.php:10`, `public/smart-send.php:10`, `public/autoreply.php:11`.
Divergence reason: none — accidental copy-paste, not specialization. A schema change (e.g. a soft-delete flag on numbers) needs 5 coordinated edits.

## 2. Group / category lookup queries — near-identical pairs
- Groups query: `send.php:7` vs `new-send.php:14` — identical.
- Group-members query: `send.php:30` vs `new-send.php:36` — identical.
- Categories-with-count query: `send.php:18-21` vs `new-send.php:18-21` — literal copy-paste.
Divergence reason: none — `new-send.php` was built by copying `send.php`'s data-gathering block rather than factoring it out.

## 3. p2p-send.php / smart-send.php — near-total structural duplication
Numbers setup, cancel handler, originator resolution, file-presence checks, catch block, and results-table markup are byte-identical except for the `type='p2p'`/`type='smart'` literal and the row-parsing body (plain text vs. template+placeholders). This matches the DB schema's own comment ("both upload flows resolve to the SAME final per-row text... run_bulk_send_pass() doesn't know or care which type produced them") — i.e. the *engine* (`bulk_queue_job`/`run_bulk_send_pass`) is correctly unified already; only the *page wiring* around it is duplicated. Legitimate partial specialization (row-parsing differs genuinely) wrapped in illegitimate duplication (everything else doesn't need to differ).

## 4. "Parse a list of numbers" reinvented 4 times instead of reusing `parse_destinations()`
`app/bootstrap.php:206` (`parse_destinations()`) already splits on whitespace/comma/semicolon/Persian comma, dedupes, and normalizes via `normalize_msisdn()`. Independently reimplemented, each with slightly different semantics, in:
- `public/contacts.php:22-34` (also splits `name,mobile` per line)
- `public/blacklist.php:23-32`
- `public/number-categories.php:22-28` (dedupes via associative array instead)
Divergence is accidental, not principled — none of the three needed different splitting semantics; they just weren't written against the existing helper.

## 5. Worker "claim before send" pattern — inconsistent across the three job types, not merely duplicated
Three different concurrency-safety strategies coexist for logically the same problem ("don't process this row twice"):
- `run_autoreply_pass()` (`app/backend.php:276-287`): claims via `INSERT ... ON (rule_id, inbound_message_id)` protected by a UNIQUE key, catches the duplicate-key exception.
- `run_due_schedules()` (`app/backend.php:163-165`): claims via `UPDATE ... WHERE id=? AND status='active'`, checks `rowCount()`.
- `run_bulk_send_pass()` (`app/backend.php:574-627`): **no claim at all** — SELECTs pending items and sends them directly.
This is not code duplication to consolidate — it's three different (and inconsistent) answers to the identical design question "how does a worker pass avoid double-processing a row." One of the three (bulk) is missing the safeguard the other two have.

## 6. Credit check duplicated (staler) instead of deferred to the one real check
`dispatch_message()` (`app/backend.php:110-112`) is the authoritative, if racy, credit gate. `bulk_queue_job()` (`app/backend.php:503-505`) re-implements the identical formula (`sms_parts()`-based cost vs. `$user['credit']`) against an even-staler snapshot (checked once at queue time, not at each item's eventual send, which can be minutes/hours later via the worker). This isn't reuse of the same logic — it's two independent copies of a security-relevant check, one of which gives false assurance that a queued batch "fits" when only the per-item check deep in `dispatch_message()` (run later, asynchronously) can actually stop overspend.

## 7. Admin CRUD boilerplate — 3 near-identical page shapes
`public/pricing.php:43-56`, `public/guide-admin.php:40-53`, `public/slides.php:89-106` each hand-roll the same save/delete/toggle POST-dispatch and list-table-with-inline-forms rendering. `public/pricing.php:52` and `public/slides.php:102` have line-for-line identical `UPDATE ... SET active = 1 - active WHERE id = ?` toggle handlers. Genuine content differs (pricing has packages, guide has articles, slides has image upload) but the CRUD scaffolding around that content does not need to.

## 8. Raw string-interpolated SQL as an outlier style, not a systemic pattern
Most of the codebase consistently uses `prepare()->execute([...])`. A handful of spots depart from it (values are int-cast before interpolation so not currently exploitable, but inconsistent and a latent injection risk if ever edited without noticing the cast requirement):
`public/schedules.php:9,19`, `public/autoreply.php:50,56`, `public/p2p-send.php:22`, `public/smart-send.php:22`.

## 9. `ellsms_meta`/`user_` join re-implemented ad hoc instead of reusing `current_user()`'s shape
`current_user()` (`app/bootstrap.php:91-99`) already encodes "join `user_`+`ellsms_meta`, gate on active/deleted/panel_access." The same join shape, with *inconsistent gating*, is re-implemented in: `public/login.php:12-22` (two separate queries), `public/verify-2fa.php:9-11`, `public/bootstrap-admin.php:11-13`, `public/users.php:163-170` and `:179-186`, `app/backend.php:167-170` (`run_due_schedules`), `:304-309` (`autoreply_process_one`), `:533-537` (`bulk_send_one_item`). Concretely risky divergence: `bulk_send_one_item()` checks `active`/`deleted` but **not** `panel_access` — a revoked-access user's already-queued bulk items can still be sent by the worker after their access is revoked.

## 10. Magic-number timing/limit literals copied instead of named
`usleep(400000)` (anti-enumeration delay) appears independently in `public/login.php:17`, `public/verify-2fa.php:46`, `public/bootstrap-admin.php:16` — three copies of the same literal with no shared constant, unlike the sibling 2FA constants (`TWOFA_CODE_TTL_SECONDS`, `TWOFA_RESEND_COOLDOWN`) which *are* named in `bootstrap.php:463-464`. Similarly, the "max bulk rows" cap is `20000` in `p2p-send.php:40` vs `20001` in `smart-send.php:42` (same intent, divergent literal, no shared constant anywhere in `backend.php`).

---

**Not duplication (checked and rejected as false positives):**
- `autoreply.php:83-86` (admin rule listing) vs `backend.php:260-265` (runtime matching) — different purpose (display vs. match), correctly separate.
- `autoreply.php:88-90` vs `backend.php:354` (both query `ellsms_autoreply_variables`) — different projections (list vs. substitution map) for a genuinely different need; low-value to unify.
- `reports.php`'s `sender_user_id`-scoped queries vs `inbox.php`'s `destination`-scoped queries — these *should* be the same concept (owner scoping) but currently aren't, which is why `inbox.php`'s version is broken (see finding in 04-handoff-prompts.md) — not "duplication to remove" so much as "one of the two implementations is wrong and should be replaced by the other's pattern."
