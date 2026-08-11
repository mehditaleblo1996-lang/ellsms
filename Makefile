\
# ELLSMS — developer command layer.
#
# This is a thin, intentionally small wrapper around commands a developer
# would otherwise have to remember/type by hand (docker compose, php -l
# loops, vendor/bin/phpunit). It introduces no build system, no new
# runtime mechanism, and no automatic database migration — every command
# here either runs a plain existing tool or shells out to docker compose
# using the project's existing docker-compose.yml. The Docker worker
# service remains the one authoritative way the worker runs continuously;
# nothing here creates a second one.
#
# Run `make help` (or just `make`) to list all commands.

.PHONY: help lint test test-integration check \
        docker-build up down logs worker-logs worker-once \
        composer-install db-schema-show db-tables db-schema-apply \
        db-migrations-show db-migrations-status db-migrations-apply \
        db-integrity-check db-cleanup db-cleanup-apply \
        wallet-backfill wallet-backfill-dry-run wallet-audit \
        payments-reconcile payments-reconcile-dry-run \
        jobs-status jobs-recover jobs-recover-force jobs-recover-force-dry-run \
        rbac-integrity-check rbac-status backend-boundary-check \
        jobs-status-json performance-snapshot performance-snapshot-json \
        load-test-small load-test-medium load-test-workers \
        config-check predeploy-check smoke-test production-integrity-check \
        release-check dependency-audit \
        backup backup-json backup-verify backup-prune-dry-run backup-prune backup-status \
        restore restore-test dr-drill \
        maintenance-on maintenance-off maintenance-status \
        release-preflight release-plan release-apply \
        api-keys-status webhooks-status webhook-retry-failed \
        webhook-prune-dry-run webhook-prune webhook-worker-once idempotency-prune-dry-run idempotency-prune \
        billing-backfill billing-backfill-dry-run billing-plans subscription-integrity-check \
        subscription-lifecycle subscription-lifecycle-dry-run usage-status usage-reconcile usage-reconcile-apply \
        sms-pricing-integrity-check sms-pricing-status sms-pricing-status-json sms-price-simulate \
        sms-gateway-backfill-dry-run sms-gateway-backfill sms-gateway-integrity-check \
        sms-gateway-status sms-gateway-status-json sms-gateway-simulate sms-status-poll \
        profile-backfill profile-backfill-dry-run profile-integrity-check profile-status profile-status-json

help:
	@echo "ELLSMS developer commands"
	@echo ""
	@echo "  make lint             PHP syntax check every .php file (fails on any parse error)"
	@echo "  make test             Run the PHPUnit unit suite (delegates to 'composer test')"
	@echo "  make test-integration Run tests/Integration against a real disposable MySQL DB"
	@echo "                        (needs ELLSMS_TEST_DB_HOST — see this target in the Makefile; skipped"
	@echo "                        entirely, not run, if unset — never run against a production database)"
	@echo "  make check            lint + test — stops at the first failure"
	@echo ""
	@echo "  make composer-install Install PHPUnit/dev dependencies (vendor/, dev-only)"
	@echo ""
	@echo "  make docker-build     docker compose build"
	@echo "  make up               docker compose up -d   (app + worker)"
	@echo "  make down             docker compose down     (stops containers, no data touched)"
	@echo "  make logs             tail the app container's logs"
	@echo "  make worker-logs      tail the worker container's logs"
	@echo ""
	@echo "  make worker-once      run one worker pass in a throwaway container (php cron/worker.php --once)"
	@echo "                        safe to run even while the persistent worker service ('make up') is also"
	@echo "                        running (Phase 4 -- atomic per-row claiming makes concurrent workers safe,"
	@echo "                        see docs/job-queue-architecture.md); redundant/wasted work, not unsafe"
	@echo ""
	@echo "  make db-schema-show   print db/ellsms_extra.sql (read-only, no DB connection)"
	@echo "  make db-tables        list ellsms_* tables actually present in the shared DB (read-only, needs .env + a running DB)"
	@echo "  make db-schema-apply  MUTATION: apply db/ellsms_extra.sql to the shared database (idempotent, needs .env)"
	@echo "                        never run automatically by any other target or by container startup — see"
	@echo "                        docs/database-audit.md before touching schema in the shared database"
	@echo ""
	@echo "  make db-migrations-show    print every db/migrations/*.sql file (read-only, no DB connection)"
	@echo "  make db-migrations-status read-only: which migrations are applied vs. pending (ledger-tracked)"
	@echo "  make db-migrations-apply  MUTATION: apply every pending migration in order (idempotent, needs .env)"
	@echo "                            see docs/database-migrations.md before running"
	@echo ""
	@echo "  make db-integrity-check   read-only: orphan/duplicate/drift audit across ELLSMS-owned tables"
	@echo "                            (also serves as migration preflight — run before db-migrations-apply)"
	@echo "  make db-cleanup           read-only: report expired 2FA codes / stale rate-limit rows (dry run)"
	@echo "  make db-cleanup-apply     MUTATION: actually delete them — never touches financial/audit rows"
	@echo ""
	@echo "  make wallet-backfill           MUTATION (additive/idempotent): seed wallet accounts from currentcredit"
	@echo "  make wallet-backfill-dry-run   same, but only reports what it would do"
	@echo "  make wallet-audit              read-only: report any drift between the wallet and currentcredit"
	@echo "  make payments-reconcile        recover payments ZarinPal completed but ELLSMS never finished crediting"
	@echo "  make payments-reconcile-dry-run  same, but only reports what it would do"
	@echo "                            see docs/wallet-architecture.md for the deployment order these fit into"
	@echo ""
	@echo "  make jobs-status               read-only: queue health across bulk items/jobs, schedules, auto-reply"
	@echo "  make jobs-recover              read-only: list rows whose claim lease has expired"
	@echo "  make jobs-recover-force        also clear those expired leases so the next tick reclaims them immediately"
	@echo "  make jobs-recover-force-dry-run  same as --force, but only reports what it would clear"
	@echo "                            see docs/job-queue-architecture.md for the claim/lease/retry model"
	@echo ""
	@echo "  make rbac-integrity-check read-only: zero-owner orgs / invalid role values / role-permission-map"
	@echo "                            consistency audit -- see docs/rbac-architecture.md"
	@echo "  make rbac-status          read-only: prints the built-in owner/admin/member permission matrix"
	@echo ""
	@echo "  make backend-boundary-check  read-only, no DB needed: static scan for direct SQL access to"
	@echo "                            a backend-owned table (user_/domain/inbound_message/outbound_message)"
	@echo "                            outside the approved adapter files -- see cron/backend-boundary-check.php"
	@echo ""
	@echo "  make backup               MUTATION (creates a file): full mysqldump backup with checksum +"
	@echo "                            manifest -- see docs/backup-and-disaster-recovery.md"
	@echo "  make backup-verify FILE=<backup_id>  read-only: checksum/decrypt/decompress integrity check"
	@echo "                            of an existing backup artifact (does not restore anything)"
	@echo "  make backup-prune-dry-run  read-only: reports which backups retention would delete"
	@echo "  make backup-prune         MUTATION: actually deletes backups older than BACKUP_RETENTION_DAYS,"
	@echo "                            never deletes the newest valid backup"
	@echo "  make backup-status        read-only: latest backup age/size/verification status, no secrets/paths"
	@echo "  make restore BACKUP=<id>  restore a backup -- disposable/new-DB by default; production overwrite"
	@echo "                            requires ALLOW_DESTRUCTIVE_RESTORE=1 + explicit target -- CLI-only,"
	@echo "                            see docs/backup-and-disaster-recovery.md before running"
	@echo "  make restore-test BACKUP=<id>  REAL disposable-MySQL restore + migration/integrity checks"
	@echo "                            (hard acceptance criterion -- never targets production)"
	@echo "  make dr-drill             full disaster-recovery drill on disposable infrastructure:"
	@echo "                            seed -> backup -> simulate loss -> restore -> verify -> report timing"
	@echo ""
	@echo "  make maintenance-on [MSG=...]  MUTATION: enable maintenance mode (503 for most pages;"
	@echo "                            health/readiness and payment callbacks stay reachable)"
	@echo "  make maintenance-off      MUTATION: disable maintenance mode"
	@echo "  make maintenance-status   read-only: is maintenance mode currently on"
	@echo ""
	@echo "  make release-preflight    read-only: config-check + predeploy-check + boundary + sms-pricing-integrity + backup-status"
	@echo "  make release-plan         read-only: prints the 15-step release sequence, nothing executes"
	@echo "  make release-apply OPERATOR=<id>  MUTATION: backup+verify+integrity-check+record metadata"
	@echo "                            (does NOT deploy code or apply migrations -- see docs/production-runbook.md)"
	@echo ""
	@echo "  make api-keys-status      read-only: API key inventory -- active/revoked/expired/unused counts"
	@echo "  make webhooks-status      read-only: webhook delivery queue depth + disabled endpoints"
	@echo "  make webhook-retry-failed ID=<delivery_id>  MUTATION: requeue one failed/dead_letter delivery"
	@echo "                            (same event identity -- never mints a new event, see STEP 54)"
	@echo "  make webhook-prune-dry-run  read-only: reports which delivery/event rows retention would delete"
	@echo "  make webhook-prune        MUTATION: deletes them (dead_letter rows kept unless DEAD_LETTER=1)"
	@echo "  make webhook-worker-once  run one webhook-delivery pass in a throwaway container"
	@echo "  make idempotency-prune-dry-run  read-only: reports which COMPLETED idempotency records retention would delete"
	@echo "  make idempotency-prune    MUTATION: deletes them -- see docs/public-api.md"
	@echo ""
	@echo "  make billing-backfill-dry-run  read-only: what plan seeding + legacy backfill would do"
	@echo "  make billing-backfill     MUTATION: seed built-in plans and assign every organization"
	@echo "                            without a subscription to the grandfathered 'legacy' plan."
	@echo "                            Idempotent; NEVER downgrades or overwrites an existing"
	@echo "                            subscription. Run this BEFORE setting BILLING_ENABLED=1."
	@echo "  make billing-plans        read-only: the seeded plan catalog with prices/limits"
	@echo "  make subscription-integrity-check  read-only: overlapping/missing subscriptions, unknown"
	@echo "                            entitlement or limit keys, paid-but-unactivated billing records"
	@echo "  make subscription-lifecycle-dry-run  read-only: which lifecycle transitions are due"
	@echo "  make subscription-lifecycle  MUTATION: apply trial/grace expiry, period rollover,"
	@echo "                            scheduled downgrades/cancellations, stale-reservation release."
	@echo "                            Schedule this (cron/systemd); serialized by a MySQL named lock."
	@echo "  make usage-status [ORG=<id>]  read-only: plan, limits, used/remaining for one org or all"
	@echo "  make usage-reconcile      read-only: reports usage-counter drift"
	@echo "  make usage-reconcile-apply  MUTATION: repairs the derivable 'reserved' column only"
	@echo ""
	@echo "  make sms-pricing-integrity-check  read-only: ambiguous prefixes, active routes under an"
	@echo "                            archived provider, overlapping/zero/foreign-currency price periods,"
	@echo "                            routes with no usable rate -- see docs/sms-pricing.md. Exits non-zero"
	@echo "                            on critical findings and NEVER auto-fixes financial configuration."
	@echo "  make sms-pricing-status [OPERATOR=|PROVIDER=|ROUTE=|SENDER=]  read-only: the operator/provider/"
	@echo "                            route/rate configuration actually in effect right now, resolved"
	@echo "                            through the same functions the send path uses (no secrets exist here)"
	@echo "  make sms-pricing-status-json  same, machine-readable"
	@echo "  make sms-price-simulate PHONE=<number> [SENDER=|TYPE=|SEGMENTS=|CONTENT=|AT=]"
	@echo "                            read-only: what one hypothetical message would cost and WHY --"
	@echo "                            operator, provider, route, pricing rule id, unit price, total."
	@echo "                            Sends nothing, reserves nothing, records nothing."
	@echo ""
	@echo "  make sms-gateway-backfill-dry-run  read-only: what registering the CURRENT REST integration"
	@echo "                            as a configured gateway would create -- see docs/sms-gateway-connectors.md"
	@echo "  make sms-gateway-backfill  MUTATION: registers it. Idempotent; copies no credential into the"
	@echo "                            database (HMAC keys stay in the environment). Routes nothing yet."
	@echo "  make sms-gateway-integrity-check  read-only: gateways that do not compile, secrets encrypted"
	@echo "                            with a different master key, endpoints production would refuse,"
	@echo "                            routes pointing at a missing gateway. Exits non-zero on critical"
	@echo "                            findings and NEVER auto-fixes a connector."
	@echo "  make sms-gateway-status [GATEWAY=<code>]  read-only: the gateway configuration in effect,"
	@echo "                            resolved through the engine itself. Never prints a secret VALUE."
	@echo "  make sms-gateway-status-json  same, machine-readable"
	@echo "  make sms-gateway-simulate TO=<msisdn> [GATEWAY=|SENDER=|TEXT=|COMPARE=1]"
	@echo "                            read-only: the EXACT request a gateway would send, built by the real"
	@echo "                            builder and NOT transmitted. COMPARE=1 also prints the legacy"
	@echo "                            client's request and exits non-zero if the two bodies differ --"
	@echo "                            run this before enabling SMS_GATEWAY_TRANSPORT."
	@echo "  make sms-status-poll      one delivery-status polling pass against configured status"
	@echo "                            connectors. Safe to run concurrently; each row is claimed atomically."
	@echo ""
	@echo "  make profile-backfill-dry-run  read-only: what the legacy ellsms_user_kyc -> profile"
	@echo "                            migration would move (personal fields + identity documents)"
	@echo "  make profile-backfill     MUTATION (additive/idempotent): moves them. COPIES document"
	@echo "                            files -- storage/kyc is never modified, so legacy links keep working."
	@echo "  make profile-integrity-check  read-only: document ownership/checksums/missing files,"
	@echo "                            orphan profiles, invalid national/postal codes, legacy dependency."
	@echo "                            Exits non-zero on critical findings; never auto-fixes identity data."
	@echo "  make profile-status [ORG=<id>] [USER=<id>]  read-only: profile completeness, missing fields"
	@echo "                            and document status. Never prints national codes or addresses."
	@echo "  make profile-status-json  same, machine-readable"

## ---------- Lint ----------

lint:
	@fail=0; \
	count=0; \
	for f in $$(find app public cron tests -name '*.php' 2>/dev/null); do \
		count=$$((count+1)); \
		out=$$(php -l "$$f" 2>&1); \
		if [ $$? -ne 0 ]; then \
			echo "$$out"; \
			fail=1; \
		fi; \
	done; \
	if [ $$fail -eq 0 ]; then \
		echo "Lint OK — $$count PHP file(s) parse cleanly."; \
	else \
		echo "Lint FAILED — see errors above."; \
		exit 1; \
	fi

## ---------- Tests ----------

test:
	@if [ ! -f vendor/bin/phpunit ]; then \
		echo "vendor/bin/phpunit not found — run 'make composer-install' (or 'composer install') first."; \
		exit 1; \
	fi
	composer test

# Runs tests/Integration/*Test.php against a REAL, disposable MySQL
# instance — the DB-touching half of app/authorization.php,
# app/rate_limit.php, and 2FA storage (app/backend.php), which a PDO mock
# can't meaningfully prove correct (real joins, real sliding windows, real
# constraints). NEVER point this at a production database — start a
# throwaway container instead, e.g.:
#
#   docker run -d --name ellsms-test-mysql \
#     -e MYSQL_ROOT_PASSWORD=testroot -e MYSQL_DATABASE=ellsms_test \
#     -e MYSQL_USER=ellsms_test -e MYSQL_PASSWORD=ellsms_test \
#     -p 33061:3306 mysql:8.0
#
# Skips every test (not a failure) if ELLSMS_TEST_DB_HOST isn't set, so
# 'make check'/'make test' never depend on it and CI without a test DB
# available still passes cleanly.
#
# Phase 11's RestoreDisasterRecoveryTest (the STEP 42 hard-acceptance-criterion real restore
# test) additionally needs the test DB user to CREATE/DROP databases matching its own name's
# prefix (it works inside its own throwaway "<name>_e2edr_<random>" database, never the shared
# fixture) -- grant this once against the disposable container above:
#   GRANT CREATE, DROP, ALTER, INDEX, INSERT, SELECT, UPDATE, DELETE, CREATE ROUTINE, ALTER
#   ROUTINE, TRIGGER, EVENT, REFERENCES, LOCK TABLES ON `ellsms\_test%`.* TO 'ellsms_test'@'%';
# Without it, that one test class skips itself with this same message rather than failing.
test-integration:
	@if [ ! -f vendor/bin/phpunit ]; then \
		echo "vendor/bin/phpunit not found — run 'make composer-install' (or 'composer install') first."; \
		exit 1; \
	fi
	@if [ -z "$$ELLSMS_TEST_DB_HOST" ]; then \
		echo "ELLSMS_TEST_DB_HOST not set — see this target's comment in the Makefile for how to start a disposable test database."; \
	fi
	vendor/bin/phpunit -c phpunit.integration.xml

## ---------- Backend boundary (Phase 8 -- see docs/service-boundaries.md) ----------

# Read-only, no DB connection needed -- static regex scan of every tracked *.php file for direct
# SQL access to a backend-owned table outside the approved adapter files. Exits non-zero on any
# unapproved reference -- see cron/backend-boundary-check.php for the allowlist.
backend-boundary-check:
	php cron/backend-boundary-check.php

## ---------- Combined validation ----------

check: lint test backend-boundary-check
	@echo "check: lint + test + backend-boundary-check all passed."

## ---------- Composer ----------

composer-install:
	composer install

## ---------- Docker ----------

docker-build:
	docker compose build

up:
	docker compose up -d

down:
	docker compose down

logs:
	docker compose logs -f app

worker-logs:
	docker compose logs -f worker

## ---------- Worker ----------

worker-once:
	docker compose run --rm worker php cron/worker.php --once

## ---------- Database (see docs/database-audit.md before using db-schema-apply) ----------

db-schema-show:
	@cat db/ellsms_extra.sql

# Read-only — lists ellsms_* tables that actually exist in the shared
# database right now. Requires .env (for BACKEND_DB_* / BACKEND_DB_HOST)
# and that database container to already be reachable.
db-tables:
	@set -a; [ -f .env ] && . ./.env; set +a; \
	docker exec -i "$${BACKEND_DB_HOST}" \
	  mysql -u"$${BACKEND_DB_USER}" -p"$${BACKEND_DB_PASS}" "$${BACKEND_DB_NAME}" \
	  -e "SHOW TABLES LIKE 'ellsms\_%';"

# MUTATION. Every statement in db/ellsms_extra.sql is CREATE TABLE IF NOT
# EXISTS / guarded ALTER TABLE / ON DUPLICATE KEY UPDATE, so this is safe
# to re-run against an install that already has the schema — but it is
# still a real write against the shared production database if pointed
# at one, so it is never invoked by any other target, by 'make up', or by
# container startup (see docker/entrypoint.sh, which only touches the
# filesystem, never the database).
db-schema-apply:
	@set -a; [ -f .env ] && . ./.env; set +a; \
	docker exec -i "$${BACKEND_DB_HOST}" \
	  mysql -u"$${BACKEND_DB_USER}" -p"$${BACKEND_DB_PASS}" "$${BACKEND_DB_NAME}" \
	  < db/ellsms_extra.sql
	@echo "Schema applied (or already up to date)."

# Read-only — prints every migration file so you can review exactly
# what db-migrations-apply would run before running it.
db-migrations-show:
	@for f in db/migrations/*.sql; do echo "=== $$f ==="; cat "$$f"; echo; done

# Read-only (Phase 5). Reports which migrations are recorded as applied in
# ellsms_schema_migrations vs. still pending, in order — see
# cron/db-migrate.php and docs/database-migrations.md.
db-migrations-status:
	docker compose run --rm worker php cron/db-migrate.php --status

# MUTATION. Applies every not-yet-recorded db/migrations/*.sql file, in
# filename (timestamp) order, against the shared database, via the
# deterministic ledger runner (cron/db-migrate.php, Phase 5) — records each
# one in ellsms_schema_migrations as it succeeds, stops at the first
# failure without recording it. Every file remains independently
# idempotent/guarded on its own (see db/migrations/README.md) — the ledger
# adds bookkeeping on top of that, it doesn't replace it. Still a real
# write against a production database if pointed at one, so — same as
# db-schema-apply — never invoked by any other target, by 'make up', or by
# container startup. Run 'make db-integrity-check' first — see
# docs/database-migrations.md for the full recommended sequence.
db-migrations-apply:
	docker compose run --rm worker php cron/db-migrate.php --apply

## ---------- Database integrity & retention (Phase 5 -- see docs/database-migrations.md) ----------

# Read-only. Orphan/duplicate/drift audit across ELLSMS-owned tables --
# doubles as migration preflight (reports the same counts
# db/migrations/2026_07_28_data_integrity.sql's own guards compute) and as
# an ongoing integrity monitor. Exits non-zero only for CRITICAL findings.
db-integrity-check:
	docker compose run --rm worker php cron/db-integrity-check.php

# Read-only (default). Reports expired ellsms_2fa_codes / stale
# ellsms_rate_limits rows that would be deleted -- deletes nothing.
# Never targets financial, payment, audit, or ticket records.
db-cleanup:
	docker compose run --rm worker php cron/db-cleanup.php

# MUTATION. Same targets as db-cleanup, actually deletes them.
db-cleanup-apply:
	docker compose run --rm worker php cron/db-cleanup.php --apply

## ---------- Wallet / payments (Phase 3 -- see docs/wallet-architecture.md) ----------

# MUTATION (but additive/idempotent -- see cron/wallet-backfill.php). Seeds
# an ellsms_wallet_accounts row for every ELLSMS-managed user who doesn't
# have one yet, from their CURRENT user_.currentcredit. Run this once
# after applying db-migrations-apply (specifically
# 2026_07_28_wallet_ledger.sql) and before relying on wallet-gated flows
# in production -- never automatic, never run by any other target.
wallet-backfill:
	docker compose run --rm worker php cron/wallet-backfill.php

# Same as above but prints what it WOULD do without writing anything.
wallet-backfill-dry-run:
	docker compose run --rm worker php cron/wallet-backfill.php --dry-run

# Read-only. Compares every wallet account's available_balance against
# user_.currentcredit and reports any mismatch -- see cron/wallet-audit.php.
# Never auto-corrects anything. Exits non-zero if drift is found.
wallet-audit:
	docker compose run --rm worker php cron/wallet-audit.php

# Recovers payments where ZarinPal succeeded but local processing was
# interrupted (verification_failed) or the browser never returned
# (stale pending) -- see cron/payments-reconcile.php. Idempotent: safe to
# run repeatedly, and can never double-credit a payment another run (or a
# live callback) already processed. Manual/on-demand in this phase, not a
# scheduled job.
payments-reconcile:
	docker compose run --rm worker php cron/payments-reconcile.php

payments-reconcile-dry-run:
	docker compose run --rm worker php cron/payments-reconcile.php --dry-run

## ---------- Job queue (Phase 4 -- see docs/job-queue-architecture.md) ----------

# Read-only. Status/lease/retry counts across bulk items, bulk jobs,
# schedules, and the auto-reply log -- see cron/jobs-status.php.
jobs-status:
	docker compose run --rm worker php cron/jobs-status.php

# Read-only. Lists rows whose claim lease has expired -- every one of
# these is already self-healing (the next normal worker tick reclaims it
# automatically); this is for visibility, not a required step.
jobs-recover:
	docker compose run --rm worker php cron/jobs-recover.php

# Same rows as above, but also clears their expired lease immediately so
# the very next tick reclaims them rather than waiting on worker timing.
# Never touches a row whose lease is still valid.
jobs-recover-force:
	docker compose run --rm worker php cron/jobs-recover.php --force

jobs-recover-force-dry-run:
	docker compose run --rm worker php cron/jobs-recover.php --force --dry-run

## ---------- RBAC (Phase 7 -- see docs/rbac-architecture.md) ----------

# Read-only. Zero-active-owner organizations, invalid membership role values, and role_permissions()
# map consistency against the Permissions catalog -- see cron/rbac-integrity-check.php. Exits
# non-zero only for CRITICAL findings; never auto-fixes ownership or role state.
rbac-integrity-check:
	docker compose run --rm worker php cron/rbac-integrity-check.php

# Read-only. Prints the built-in owner/admin/member -> permission matrix app/rbac.php actually
# enforces, straight from role_permissions() -- not hand-copied documentation that can drift from
# the code (see cron/rbac-status.php).
rbac-status:
	docker compose run --rm worker php cron/rbac-status.php

## ---------- Observability & performance (Phase 9 -- see docs/observability-and-performance.md) ----------

jobs-status-json:
	docker compose run --rm worker php cron/jobs-status.php --json

# Read-only. Queue counts, oldest backlog age, stale reservations, expired leases, recent backend
# failure counts -- all cheap, indexed queries (see cron/performance-snapshot.php). Safe to run on
# demand; never a full-table scan of message content.
performance-snapshot:
	docker compose run --rm worker php cron/performance-snapshot.php

performance-snapshot-json:
	docker compose run --rm worker php cron/performance-snapshot.php --json

# Reproducible bulk-queue load tests (see docs/observability-and-performance.md) -- all three
# target the disposable ELLSMS_TEST_DB_* database (cron/load-test.php refuses to run against
# anything whose name doesn't contain "test", or without ELLSMS_ALLOW_LOAD_TEST=1), never
# production. Run directly with `php cron/load-test.php` (not through `docker compose run`) since
# it spawns real local OS worker processes and a local fake-backend server that need to reach the
# SAME database connection details this shell already has, not a fresh container's.
load-test-small:
	LOAD_TEST_LABEL=small LOAD_TEST_ITEMS=500 LOAD_TEST_WORKERS=1 LOAD_TEST_BACKEND_LATENCY_MS=50 php cron/load-test.php

load-test-medium:
	LOAD_TEST_LABEL=medium LOAD_TEST_ITEMS=500 LOAD_TEST_WORKERS=2 LOAD_TEST_BACKEND_LATENCY_MS=50 php cron/load-test.php

# Worker-count scaling comparison -- runs 1/2/4 workers back to back against the same item count,
# each writing its own storage/benchmarks/phase-9-*.json artifact.
load-test-workers:
	LOAD_TEST_LABEL=workers_1 LOAD_TEST_ITEMS=500 LOAD_TEST_WORKERS=1 LOAD_TEST_BACKEND_LATENCY_MS=50 php cron/load-test.php
	LOAD_TEST_LABEL=workers_2 LOAD_TEST_ITEMS=500 LOAD_TEST_WORKERS=2 LOAD_TEST_BACKEND_LATENCY_MS=50 php cron/load-test.php
	LOAD_TEST_LABEL=workers_4 LOAD_TEST_ITEMS=500 LOAD_TEST_WORKERS=4 LOAD_TEST_BACKEND_LATENCY_MS=50 php cron/load-test.php

## ---------- Production hardening & release safety (Phase 10 -- see docs/production-hardening.md) ----------

# Read-only, no DB connection strictly required (validates shape/presence of config, not
# connectivity). Exits non-zero on any blocking (FAIL-level) misconfiguration -- see
# cron/config-check.php for the full list of what's checked.
config-check:
	php cron/config-check.php

config-check-json:
	php cron/config-check.php --json

# Read-only. Composes config-check, DB reachability, migration status, writable-directory checks,
# production/test-mode guards, and backend-boundary-check into one pre-deploy gate. Run this with
# the DEPLOY TARGET's actual environment already in place, right before deploying -- see
# cron/predeploy-check.php and docs/production-hardening.md's deployment procedure.
predeploy-check:
	php cron/predeploy-check.php

# Non-destructive HTTP checks against a RUNNING deployment -- liveness, readiness, login page,
# protected-route denial, internal-file exposure, security headers. Never sends a real SMS or
# creates a real payment. Usage: make smoke-test URL=https://sms.example.com
smoke-test:
	php cron/smoke-test.php $(URL)

# Read-only aggregate of every existing integrity/status tool (db/tenant/rbac integrity,
# wallet-audit, jobs-status, performance-snapshot, migration-status, config-check,
# backend-boundary-check) -- one PASS/WARN/FAIL per tool plus an overall verdict. Never auto-fixes
# anything -- see cron/production-integrity-check.php.
production-integrity-check:
	php cron/production-integrity-check.php

# Composes lint + unit + integration (if ELLSMS_TEST_DB_HOST is set) + backend-boundary-check +
# config-check (against safe fixture values, not real secrets) into one reproducible go/no-go
# command for cutting a release -- see docs/production-hardening.md. Does not require real
# provider credentials.
release-check: lint test backend-boundary-check
	@echo "--- config-check (safe fixture values) ---"
	@BACKEND_DB_HOST=fixture-host BACKEND_DB_NAME=fixture_db BACKEND_DB_USER=fixture_user BACKEND_DB_PASS=fixture_pass \
	  API_BASE_URL=https://api.fixture.example APP_ENV=production \
	  php cron/config-check.php
	@if [ -n "$$ELLSMS_TEST_DB_HOST" ]; then \
		$(MAKE) test-integration; \
	else \
		echo "ELLSMS_TEST_DB_HOST not set -- skipping integration tests (see test-integration target)"; \
	fi
	@echo "release-check: all checks passed."

# Read-only. Reports installed PHP extensions/version and composer.lock contents (phpunit + its
# own dependencies only -- this project ships no runtime Composer dependencies, see docker/Dockerfile,
# which never runs `composer install`) for manual review against the PHP/CVE advisories of the day.
# This project has no network-dependent audit tool wired in (composer audit needs a
# packagist.org-reachable environment this container may not have) -- this command reports what's
# present and exits 0; it does NOT claim to have checked anything against a live vulnerability feed.
## ---------- Backup, restore & disaster recovery (Phase 11 -- see docs/backup-and-disaster-recovery.md) ----------

# MUTATION (creates a file under BACKUP_DIR, default storage/backups). Full mysqldump
# --single-transaction backup of BACKEND_DB_NAME, gzip-compressed, checksummed, manifested.
# BACKUP_ENCRYPTION_ENABLED=1 + BACKUP_ENCRYPTION_KEY_FILE=<path> encrypts with gpg AES-256.
# Serialized via the ellsms_backup MySQL named lock -- safe to schedule, never overlaps itself.
backup:
	docker compose run --rm worker php cron/backup.php

backup-json:
	docker compose run --rm worker php cron/backup.php --json

# Read-only. Verifies an existing backup artifact's checksum and (if it can access the key/gzip)
# that it decrypts/decompresses into a structurally complete mysqldump -- does not touch any
# database. Usage: make backup-verify FILE=20260802-105135-d0c4514b
backup-verify:
	docker compose run --rm worker php cron/backup-verify.php $(FILE)

# Restore BACKUP=<id> into a fresh disposable database by default -- SAFE, never overwrites
# anything. A destructive replacement restore (--target-db=<existing db with data>) additionally
# needs ALLOW_DESTRUCTIVE_RESTORE=1 and CONFIRM=<same db name> -- see docs/backup-and-disaster-recovery.md.
# CLI-only (STEP 36): there is no web/admin-panel equivalent of this command.
restore:
	docker compose run --rm worker php cron/restore.php $(BACKUP) $(if $(TARGET_DB),--target-db=$(TARGET_DB)) $(if $(CONFIRM),--confirm=$(CONFIRM))

# Read-only. Reports which backups BACKUP_RETENTION_DAYS/BACKUP_RETENTION_MIN_COUNT would delete
# -- deletes nothing. Never touches a corrupt/unreadable-manifest entry (reported, not deleted).
backup-prune-dry-run:
	docker compose run --rm worker php cron/backup-prune.php --dry-run

# MUTATION. Same policy as above, actually deletes. Always keeps the newest valid backup
# regardless of age (Invariant G) -- see docs/backup-and-disaster-recovery.md.
backup-prune:
	docker compose run --rm worker php cron/backup-prune.php

# Read-only. Latest backup age/size/verification status -- no filesystem paths or secrets.
backup-status:
	docker compose run --rm worker php cron/backup-status.php

# Hard acceptance criterion (Phase 11, STEP 42): a REAL restore into a disposable database,
# followed by migration-status + every read-only integrity tool + a representative cross-table
# query, all run against the RESTORED data (never the source). Disposable database is dropped
# afterward unless KEEP=1. Never targets production.
restore-test:
	docker compose run --rm worker php cron/restore-test.php $(BACKUP) $(if $(KEEP),--keep)

# Full timed disaster-recovery drill: seed/snapshot -> real backup -> simulate total loss (DROPs
# the configured database) -> real restore -> integrity checks -> live throwaway app server +
# smoke test -> worker pass -> exact record verification. Refuses to run unless BACKEND_DB_NAME
# looks disposable ("test" in the name) or ELLSMS_ALLOW_DR_DRILL=1 is explicitly set -- see
# docs/backup-and-disaster-recovery.md. Never targets production automatically.
dr-drill:
	docker compose run --rm worker php cron/dr-drill.php

## ---------- Maintenance mode (Phase 11, STEP 22/23 -- see app/maintenance.php) ----------

# MUTATION. Creates storage/maintenance.flag directly on the host -- app and worker both
# bind-mount this same directory (docker-compose.yml), so this takes effect immediately in both
# containers with no restart. Health/readiness and the ZarinPal payment callback stay reachable;
# everything else gets a 503. Pass a custom message: make maintenance-on MSG="reason here"
maintenance-on:
	@echo "$(if $(MSG),$(MSG),سامانه در حال به‌روزرسانی است. لطفاً چند دقیقه‌ی دیگر تلاش کنید.)" > storage/maintenance.flag
	@echo "Maintenance mode ON (storage/maintenance.flag created)."

# MUTATION. Removes the flag -- normal traffic resumes immediately.
maintenance-off:
	@rm -f storage/maintenance.flag
	@echo "Maintenance mode OFF (storage/maintenance.flag removed)."

maintenance-status:
	@if [ -f storage/maintenance.flag ]; then \
		echo "Maintenance mode: ON"; \
		echo "Message: $$(cat storage/maintenance.flag)"; \
	else \
		echo "Maintenance mode: OFF"; \
	fi

## ---------- Release orchestration (Phase 11, STEP 26/27 -- see cron/release.php) ----------
## NOTE: distinct from the existing `release-check` target above (Phase 10's lint+test+config-check
## go/no-go gate) -- these compose the DB-adjacent operational tools instead.

# Read-only: config-check + predeploy-check + backend-boundary-check + backup-status.
release-preflight:
	docker compose run --rm worker php cron/release.php --check

# Read-only: prints the full 15-step release sequence annotated with current git commit/version/
# migration head -- nothing executes.
release-plan:
	docker compose run --rm worker php cron/release.php --plan

# MUTATION: real backup + verify + migration-status report + production-integrity-check + release
# metadata recorded to storage/releases/ -- does NOT apply migrations or deploy new code (see
# docs/production-runbook.md for the manual steps around this). Requires OPERATOR=<id>.
release-apply:
	docker compose run --rm worker php cron/release.php --apply --confirm=RELEASE --operator=$(OPERATOR)

## ---------- Public API / webhooks (Phase 12 -- see docs/public-api.md, docs/webhooks.md) ----------

# Read-only. API key inventory across every organization -- counts only, never a raw secret (which
# is never stored anywhere to begin with -- see app/ApiKeys.php).
api-keys-status:
	docker compose run --rm worker php cron/api-keys-status.php

# Read-only. Webhook delivery queue depth by status + which endpoints are currently disabled and why.
webhooks-status:
	docker compose run --rm worker php cron/webhooks-status.php

# MUTATION. Requeues ONE failed/dead_letter delivery row for the next webhook-worker pass --
# preserves the original event identity (STEP 54), never creates a new logical event.
webhook-retry-failed:
	docker compose run --rm worker php cron/webhook-retry-failed.php --id=$(ID)

# Read-only. Reports which terminal delivery rows (and now-orphaned event rows) retention would
# delete -- deletes nothing. dead_letter rows are excluded unless DEAD_LETTER=1 (STEP 55: preserve
# unresolved dead-letter items long enough for operations).
webhook-prune-dry-run:
	docker compose run --rm worker php cron/webhook-prune.php --dry-run $(if $(DEAD_LETTER),--include-dead-letter)

# MUTATION. Same policy as above, actually deletes.
webhook-prune:
	docker compose run --rm worker php cron/webhook-prune.php $(if $(DEAD_LETTER),--include-dead-letter)

# Runs one webhook-delivery claim/attempt pass in a throwaway container -- safe alongside the
# persistent webhook-worker service (same atomic-claim safety as worker-once/bulk items, Phase 4).
webhook-worker-once:
	docker compose run --rm worker php cron/webhook-worker.php --once

# Read-only. Reports how many COMPLETED Idempotency-Key records are older than API_IDEMPOTENCY_TTL_HOURS.
idempotency-prune-dry-run:
	docker compose run --rm worker php cron/idempotency-prune.php --dry-run

# MUTATION. Deletes them. Never touches an in_progress record regardless of age (see
# app/Idempotency.php's own staleness-reclaim mechanism for that case).
idempotency-prune:
	docker compose run --rm worker php cron/idempotency-prune.php

## ---------- Plans, subscriptions & quotas (Phase 13 -- see docs/plans-and-entitlements.md) ----------

# Read-only. Shows exactly which plans would be seeded and which organizations would be assigned the
# grandfathered 'legacy' plan -- changes nothing.
billing-backfill-dry-run:
	docker compose run --rm worker php cron/billing-backfill.php --dry-run

# MUTATION (additive/idempotent). Seeds the built-in plan catalog, then assigns every organization
# that has NO effective subscription to the grandfathered 'legacy' plan (unlimited -- preserving
# exactly what those customers already had). Never downgrades, never overwrites, safe to re-run.
# ALWAYS run this before switching BILLING_ENABLED=1 -- see docs/billing-operations.md.
billing-backfill:
	docker compose run --rm worker php cron/billing-backfill.php

# Read-only. The seeded plan catalog with prices, entitlements and limits.
billing-plans:
	docker compose run --rm worker php cron/usage-status.php --json

# Read-only audit: overlapping/missing subscriptions, unknown entitlement/limit keys, invalid period
# or status/date combinations, negative usage, orphaned reservations, billing/payment organization
# mismatches, and paid-but-unactivated billing records. Never auto-repairs anything ambiguous.
# Exits non-zero on CRITICAL findings only.
subscription-integrity-check:
	docker compose run --rm worker php cron/subscription-integrity-check.php

# Read-only: which lifecycle transitions are currently due.
subscription-lifecycle-dry-run:
	docker compose run --rm worker php cron/subscription-lifecycle.php --dry-run

# MUTATION. Trial/grace expiry, billing-period rollover, scheduled downgrades, cancel-at-period-end,
# and stale usage-reservation release. Idempotent and serialized by the ellsms_subscription_lifecycle
# MySQL named lock -- safe to schedule and safe if two copies overlap.
subscription-lifecycle:
	docker compose run --rm worker php cron/subscription-lifecycle.php

# Read-only. ORG=<id> for one organization's full plan/limits/usage report; omit for a summary.
usage-status:
	docker compose run --rm worker php cron/usage-status.php $(if $(ORG),--org=$(ORG))

# Read-only. Reports usage-counter drift between `reserved` and the reservations that actually exist.
usage-reconcile:
	docker compose run --rm worker php cron/usage-reconcile.php

# MUTATION, narrowly scoped: repairs ONLY the `reserved` column (independently derivable from active
# reservations). `used` is never auto-rewritten -- it has no independent source and a wrong
# correction would either refund or steal real consumption. See cron/usage-reconcile.php.
usage-reconcile-apply:
	docker compose run --rm worker php cron/usage-reconcile.php --apply

## ---------- Dependency audit ----------

dependency-audit:
	@echo "PHP version: $$(php -v | head -1)"
	@echo "Loaded extensions:"; php -m
	@if [ -f composer.lock ]; then \
		echo ""; echo "composer.lock packages (dev-only -- never shipped to the production image):"; \
		php -r '$$l=json_decode(file_get_contents("composer.lock"),true); foreach(array_merge($$l["packages"]??[],$$l["packages-dev"]??[]) as $$p) echo "  {$$p["name"]} {$$p["version"]}\n";'; \
	else \
		echo "composer.lock not present."; \
	fi
	@echo ""
	@echo "NOTE: no network-based vulnerability feed was queried -- review the above manually against your source of choice (e.g. https://github.com/advisories) if this environment has no outbound network access to do so automatically."

## ---------- SMS pricing (see docs/sms-pricing.md) ----------

# Read-only. Configuration audit over the admin-managed operator/prefix/provider/route/price
# catalog: ambiguous prefixes, ambiguous default routes, active routes under archived providers,
# overlapping effective price periods, zero/foreign-currency prices, routes with no usable rate.
# Exits non-zero on CRITICAL findings. Never auto-fixes -- a tool quietly "correcting" a tariff
# would be worse than the misconfiguration it found.
sms-pricing-integrity-check:
	docker compose run --rm worker php cron/sms-pricing-integrity-check.php

# Read-only. The pricing configuration in effect right now, resolved through the engine's own
# functions rather than a hand-maintained summary. Optional filters:
#   make sms-pricing-status SENDER=5000435800
sms-pricing-status:
	docker compose run --rm worker php cron/sms-pricing-status.php \
	  $(if $(OPERATOR),--operator=$(OPERATOR)) $(if $(PROVIDER),--provider=$(PROVIDER)) \
	  $(if $(ROUTE),--route=$(ROUTE)) $(if $(SENDER),--sender=$(SENDER))

sms-pricing-status-json:
	docker compose run --rm worker php cron/sms-pricing-status.php --json \
	  $(if $(OPERATOR),--operator=$(OPERATOR)) $(if $(PROVIDER),--provider=$(PROVIDER)) \
	  $(if $(ROUTE),--route=$(ROUTE)) $(if $(SENDER),--sender=$(SENDER))

# Read-only troubleshooting: prices ONE hypothetical message through the real engine and prints the
# whole resolution chain (operator -> provider -> route -> rule -> unit price -> cost). Dispatches
# nothing and writes nothing.
#   make sms-price-simulate PHONE=09121234567 SENDER=5000435800 SEGMENTS=2
sms-price-simulate:
	@if [ -z "$(PHONE)" ]; then echo "PHONE=<number> is required, e.g. make sms-price-simulate PHONE=09121234567"; exit 2; fi
	docker compose run --rm worker php cron/sms-price-simulate.php --phone=$(PHONE) \
	  $(if $(SENDER),--sender=$(SENDER)) $(if $(TYPE),--type=$(TYPE)) \
	  $(if $(SEGMENTS),--segments=$(SEGMENTS)) $(if $(CONTENT),--content=$(CONTENT)) $(if $(AT),--at=$(AT))

## ---------- SMS gateway connectors (see docs/sms-gateway-connectors.md) ----------

# Read-only. What registering the CURRENT REST integration as a configured gateway would create.
sms-gateway-backfill-dry-run:
	docker compose run --rm worker php cron/sms-gateway-backfill.php

# MUTATION, idempotent. Registers the existing integration as the `legacy_rest` gateway using the
# values transcribed from app/Backend/ApiClient.php -- it invents nothing. Copies NO credential into
# the database: the HMAC keys stay in the environment and are referenced by name. Routes nothing yet;
# enabling the new transport is a separate, explicit operator decision.
sms-gateway-backfill:
	docker compose run --rm worker php cron/sms-gateway-backfill.php --apply

# Read-only. Gateways that do not compile, secrets encrypted under a different master key (the
# signature of a restore onto the wrong host), endpoints production would refuse, routes pointing at
# a missing gateway, messages stuck without a delivery state. Exits non-zero on CRITICAL findings.
# NEVER auto-fixes: a tool that silently "corrected" a connector would change where customer messages
# are sent.
sms-gateway-integrity-check:
	docker compose run --rm worker php cron/sms-gateway-integrity-check.php

# Read-only. The gateway configuration in effect, resolved through gateway_compiled() itself rather
# than a hand-maintained summary. Secret VALUES are never printed -- only which keys exist.
sms-gateway-status:
	docker compose run --rm worker php cron/sms-gateway-status.php $(if $(GATEWAY),--gateway=$(GATEWAY))

sms-gateway-status-json:
	docker compose run --rm worker php cron/sms-gateway-status.php --json $(if $(GATEWAY),--gateway=$(GATEWAY))

# Read-only. Builds the EXACT request a gateway would send, with the same builder the send path uses,
# and does not transmit it. COMPARE=1 also prints the legacy client's request for the same input and
# exits non-zero if the two bodies differ -- this is the check to run before setting
# SMS_GATEWAY_TRANSPORT=1.
#   make sms-gateway-simulate TO=989121234567 SENDER=5000435800 COMPARE=1
sms-gateway-simulate:
	@if [ -z "$(TO)" ]; then echo "TO=<msisdn> is required, e.g. make sms-gateway-simulate TO=989121234567"; exit 2; fi
	docker compose run --rm worker php cron/sms-gateway-simulate.php --to=$(TO) \
	  $(if $(GATEWAY),--gateway=$(GATEWAY)) $(if $(SENDER),--sender=$(SENDER)) \
	  $(if $(TEXT),--text=$(TEXT)) $(if $(COMPARE),--compare)

# One bounded delivery-status polling pass. Schedule this like any other worker command; it is safe
# to run concurrently with itself and with the send worker (each row is claimed with a
# compare-and-swap).
sms-status-poll:
	docker compose run --rm worker php cron/sms-status-poll.php

## ---------- Customer / organization profile (see docs/customer-profile.md) ----------

# Read-only. Reports what the legacy ellsms_user_kyc -> profile migration would move.
profile-backfill-dry-run:
	docker compose run --rm worker php cron/profile-backfill.php

# MUTATION (additive/idempotent). Moves legacy personal fields into ellsms_user_profiles and COPIES
# legacy identity documents into the new document store. Never modifies or deletes anything under
# storage/kyc, so public/kyc-photo.php keeps serving existing links. Safe to re-run.
profile-backfill:
	docker compose run --rm worker php cron/profile-backfill.php --apply

# Read-only. Document ownership/checksums/missing files, orphan profiles, invalid identifiers,
# and how many users still depend on the legacy read-through. Never auto-fixes identity or legal data.
profile-integrity-check:
	docker compose run --rm worker php cron/profile-integrity-check.php

# Read-only support/ops view. Deliberately prints presence, never the value, of sensitive fields.
profile-status:
	docker compose run --rm worker php cron/profile-status.php \
	  $(if $(ORG),--org=$(ORG)) $(if $(USER),--user=$(USER))

profile-status-json:
	docker compose run --rm worker php cron/profile-status.php --json \
	  $(if $(ORG),--org=$(ORG)) $(if $(USER),--user=$(USER))
