# Customer database connector (Remote DB) — #45

A customer inserts messages into a table in **their own database**. ELLSMS reads the pending rows,
sends them from that customer's account, and writes the result back into the same rows. It also
inserts the messages received on the customer's lines into an inbound table in their database.
Everything is configured on **Admin → اتصال به دیتابیس مشتری** (`/admin/remote-db`).

Supported customer databases: **MySQL / MariaDB**, **PostgreSQL**, **Microsoft SQL Server**
(PDO drivers `pdo_mysql`, `pdo_pgsql`, `pdo_sqlsrv`; the last two are installed by `docker/Dockerfile`).

## Setup

1. Apply the migration: `make db-migrations-apply` (`db/migrations/2026_10_06_remote_db_connections.sql`).
2. Rebuild the image so the PostgreSQL / SQL Server drivers are present: `docker compose build app`.
   For SQL Server support the image needs `INSTALL_SQLSRV=1` (the default; amd64 only).
3. `SMS_GATEWAY_MASTER_KEY` must be set (≥ 32 chars). The database password is stored encrypted under
   a key derived from it (HKDF purpose `ellsms.remote_db.password.v1`, AES-256-GCM). It is never shown again.
4. `docker compose up -d remote-db-worker`.
5. On the panel, create a connection:
   - Pick the user whose account sends the messages, and a default sender line (needed if the table
     has no sender column).
   - Enter the database connection details, the table and column mapping, and the status words to
     write back.
   - Press **تست اتصال با همین مقادیر** to check it, then enable the connection.
   - If the customer has no table yet, give them the generated `CREATE TABLE` script (under the form).

## The customer's table

The standard layout, which every column name can be remapped from:

| role | default column | notes |
|---|---|---|
| id | `id` | primary key; any type |
| destination | `destination` | `0912…`, `98912…`, `+98912…` all accepted |
| content | `message` | ≤ 2000 characters |
| status | `status` | written by ELLSMS |
| originator (opt.) | `sender` | must be a line this user may send from; empty → default line |
| due_at (opt.) | `send_at` | the row waits until this time (server time, Asia/Tehran) |
| priority (opt.) | `priority` | lower = earlier |
| reference / provider_id / parts / error / sent_at / delivered_at / updated_at (opt.) | … | written by ELLSMS |

A row is **pending** when its status is NULL, equal to the pending value, or either one
(configurable). The customer only inserts the destination and the text.

Status words (configurable; text or numbers):

`IN_PROGRESS` → `SENT` → `DELIVERED` / `NOT_DELIVERED` / `REJECTED` / `EXPIRED`, or `FAILED` (not sent, with the reason in the error column), or `UNKNOWN`.

## How it works (app/RemoteDb/Sync.php)

The worker processes each due connection, holding a lease so only one worker works on a connection at a time:

1. **Ingest.** Reads up to `batch_size` pending rows. Each row is validated: destination, text,
   sender line, and the prohibited-words list.
   - Each row is recorded in `ellsms_remote_db_rows`. `UNIQUE(connection_id, remote_row_id)` means a
     customer row is never sent twice, even if the customer resets its status.
   - The row is then marked `IN_PROGRESS` on the customer's side.
   - The rows are queued with `bulk_queue_job()` as the connection's user, so the wallet, pricing,
     plan quota, opt-outs and the normal bulk send pipeline all apply.
   - Each bulk item carries `source_ref`, written in the same transaction, which links it back to its row.
2. **Recover.** Rows that could not be queued (no credit, quota used up, pricing missing) stay
   taken and are retried with backoff. If they still cannot be queued after `retry_hours`, they
   become `FAILED`. If a crash happened after queueing but before linking, the row is linked to the
   existing item and is not sent again.
3. **Status write-back.** Every open row whose ELLSMS state changed is written in one transaction
   on the customer's database. A row is closed once delivery is final, or after `delivery_wait_hours`.
4. **Inbound.** Messages received on the user's **own** lines (the same ones the user's inbox shows) are inserted
   from the moment the option is enabled. A cursor tracks progress, and the optional external-id column
   prevents duplicate inserts after a crash.

If the customer's database cannot be reached, the cycle fails without changing anything. The error
is shown on the panel and the connection is retried with backoff (10 s up to 5 min). ELLSMS advances
its own state only after the customer's database has committed.

## Panel

- **List:** health of each connection and today's counts (taken / sent / delivered / failed).
- **Detail:** last run and last success, last error, today and 7-day statistics, the latest rows
  with their status, and the event log. Actions: test, run now, pause/resume, rewrite statuses, and
  delete (only when no message was ever sent).
- **Customer view:** a user who has a connection sees a read-only health card under «اتصال به دیتابیس».

## Security notes

- Table and column names must match `^[A-Za-z_][A-Za-z0-9_]{0,63}$` (a table may have one schema
  prefix). They are quoted per dialect, and every value is a bound parameter. Host and database name
  are validated before they are put into the DSN.
- Only platform admins can create or edit connections. The worker connects to whatever host the
  admin enters, including private addresses, because customer databases are usually on a VPN.
- Driver error messages are stored with any password removed.

## Tests

- `tests/Unit/RemoteDbConfigTest.php`: validation, dialect SQL, state mapping, vault.
- `tests/Integration/RemoteDbSyncTest.php`: end to end against a real customer database. MySQL
  always; PostgreSQL when `ELLSMS_TEST_PG_DSN` is set.
