# ELLSMS — notes for Claude

## Documentation must always be kept up to date (standing rule)

Whenever a new service or feature is added — or an existing one changes behaviour — update the
documentation **in the same commit/PR**, never as a later follow-up:

1. **Its own page in `docs/`** — create `docs/<feature>.md` for a new service, or edit the existing
   one so nothing in it describes removed or old behaviour (fields, pages, rules, prices).
2. **`README.md`** — the service's short section (what it does, which pages/URLs, API scope, link to
   its doc page) **and** the «Documentation index» at the end; every file in `docs/` must be linked
   there.
3. **API** — new or changed `/api/v1/*` routes go into `docs/openapi-v1.yaml` and `docs/public-api.md`
   (scopes too).
4. **Periodic jobs / workers** — `docs/scheduler.md` (and the cron/worker section of the doc page).
5. **Settings** — new env vars in `.env.example` and the README «Configuration» table.
6. **Migrations** — mention the `db/migrations/*.sql` file the feature needs in its doc page.
7. Search the docs for anything the change makes stale
   (`grep -rn '<old term>' docs README.md`) and fix it.

Before finishing any feature task, check: does every new page, route, table, job and setting appear
in the docs?

## Conventions

- UI text is Persian; docs are written in English with the Persian UI names in «…».
- Commit messages describe the feature in one line, then the details.
