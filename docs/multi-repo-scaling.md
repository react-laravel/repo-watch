# Scaling Repo Watch to 20–30 repositories

This note covers the multi-repository foundation added for Sam’s goal of watching roughly 20–30 GitHub repos and surfacing recent dependency changes.

## What exists now

- First-class `watched_repositories` (CRUD + scan status).
- Per-manifest `dependency_snapshots` and diffed `dependency_changes` (added / updated / removed for npm + Composer).
- Background scans via `repo-watch:scan-repositories` (every 15 minutes) and `ScanWatchedRepository` jobs.
- Shared `registry_packages` refresh remains hourly for “latest registry version” watching.
- GitHub push/release webhooks enqueue both registry refresh and repository snapshot scans.
- UI: **仓库与变更** shows fleet scan health, watched repos, and a filterable dependency-change feed.
- Snapshot retention: `repo-watch:prune-snapshots` keeps newest N snapshots per manifest and ages out old changes.

## Rate-limit budget (20–30 repos)

Each repository scan typically costs about **3–5 GitHub Contents/API calls** (repo metadata + `package.json` / `composer.json` + lockfiles when present).

| Scale | Calls per full cycle | Notes |
| --- | --- | --- |
| 30 repos × ~5 calls | ~150 / cycle | Comfortable under authenticated 5,000/hour |
| Batch size default | 5 repos / scheduler tick | `GITHUB_REPO_WATCH_SCAN_BATCH_SIZE` |
| Inter-job delay | 750ms | `GITHUB_REPO_WATCH_SCAN_DELAY_MS` |
| Scan interval | 6 hours | `GITHUB_REPO_WATCH_SCAN_INTERVAL_HOURS` |
| Rate-limit floor | 50 remaining | Defer scans when below floor |

At 30 repos, a 6-hour cadence is ~600 GitHub calls/day for snapshots alone—well within a PAT budget. Registry refreshes are shared across users/repos, so duplicate packages (e.g. `react`) are fetched once.

## Scan health

`GET /api/repo-watch/repositories` returns:

- `repositories` — per-repo `scan_status`, `last_scanned_at`, `next_scan_at`, `last_scan_error` (errors sorted first)
- `health` — fleet totals: `by_status`, `never_scanned`, `overdue`, `failing`
- `retention` — active prune policy (`snapshot_keep`, `change_retention_days`)

Manual recovery:

- Per repo: `POST /api/repo-watch/repositories/{id}/scan`
- Fleet: `POST /api/repo-watch/repositories/scan-unhealthy` (queues `error` + never-scanned)

## Snapshot retention policy

Default policy (configurable):

| Knob | Env | Default | Behavior |
| --- | --- | --- | --- |
| Keep-N | `GITHUB_REPO_WATCH_SNAPSHOT_KEEP` | 10 | Newest N snapshots per `(repository, ecosystem, manifest_path)` |
| Change window | `GITHUB_REPO_WATCH_CHANGE_RETENTION_DAYS` | 90 | Delete `dependency_changes` older than N days |

Command:

```bash
php artisan repo-watch:prune-snapshots
php artisan repo-watch:prune-snapshots --keep=10 --changes-days=90 --dry-run
```

Scheduled daily (`api/routes/console.php`). Deleting snapshots nulls `dependency_changes.dependency_snapshot_id` (FK `nullOnDelete`); aged change rows are removed separately so the feed stays bounded.

At 30 repos × ~2 manifests × keep-10 ≈ **600 snapshot rows** steady-state, independent of scan history length.

## Tunables

Set in API `.env` (see `config/services.php` → `github`):

- `GITHUB_TOKEN` / `GITHUB_PAT` — strongly recommended before scaling past a handful of repos.
- `GITHUB_REPO_WATCH_SCAN_BATCH_SIZE` — raise toward 10 only with a token and headroom.
- `GITHUB_REPO_WATCH_SCAN_DELAY_MS` — increase if secondary rate limits appear.
- `GITHUB_REPO_WATCH_RATE_LIMIT_FLOOR` — stop enqueueing/scanning before hard 429s.
- `GITHUB_REPO_WATCH_SCAN_INTERVAL_HOURS` — lower only if webhook coverage is incomplete.
- `GITHUB_REPO_WATCH_SNAPSHOT_KEEP` / `GITHUB_REPO_WATCH_CHANGE_RETENTION_DAYS` — storage bounds.

## Manual verification

```bash
cd api
php artisan migrate
php artisan test --filter=WatchedRepository
php artisan test --filter=PruneDependencySnapshots
php artisan repo-watch:scan-repositories --limit=5
php artisan repo-watch:prune-snapshots --dry-run
```

Or via API (authenticated session):

1. Bulk import: `POST /api/repo-watch/repositories/bulk` with `{ "repositories": ["org/a", "org/b", "https://github.com/org/c"] }`
2. Or single: `POST /api/repo-watch/repositories` with `{ "url": "https://github.com/org/repo" }`
3. `GET /api/repo-watch/repositories` — confirm `health` + per-repo scan fields
4. `POST /api/repo-watch/repositories/scan-unhealthy` when failing/never-scanned
5. `POST /api/repo-watch/repositories/{id}/scan?sync=1` twice (second run with changed manifests produces changes)
6. `GET /api/repo-watch/dependency-changes` (optional filters: `repository_id`, `ecosystem=npm|composer`, `change_type=added|updated|removed`, `limit`)

UI: **仓库与变更** → **扫描健康** strip, paste import, filter **最近依赖变更**.

## High-signal notifications

After each successful scan that records dependency changes, Repo Watch filters for **high-signal** events and writes in-app `repo_watch_notifications` rows (SSO `user_id` scoped). Optional outbound delivery posts JSON to a webhook URL (Slack-compatible `text` field included).

Default signal set (low noise at 20–30 repos):

| Signal | Default | Toggle |
| --- | --- | --- |
| Major version bumps (`updated` + semver major) | on | `REPO_WATCH_NOTIFY_ON_MAJOR` |
| Removals | on | `REPO_WATCH_NOTIFY_ON_REMOVED` |
| Failed scans | on | `REPO_WATCH_NOTIFY_ON_SCAN_FAILURE` |
| Critical/high package advisories | on | `REPO_WATCH_NOTIFY_ON_ADVISORY` |
| Adds / minor / patch | off | — |

Enablement / destination:

- `REPO_WATCH_NOTIFY_ENABLED` — master switch (default `true`; in-app records)
- `REPO_WATCH_NOTIFY_WEBHOOK_URL` — optional outbound hook (empty = in-app only)

API:

- `GET /api/repo-watch/notifications`
- `POST /api/repo-watch/notifications/{id}/read`
- `POST /api/repo-watch/notifications/read-all`

UI: **仓库与变更** → **高信号通知** strip.

## Package advisories (OSV)

Repo Watch queries **[OSV](https://osv.dev)** (`https://api.osv.dev`) against **lock-sourced** versions from the latest dependency snapshot per manifest. This path does **not** use the GitHub API budget (important at 20–30 repos).

| Knob | Env | Default |
| --- | --- | --- |
| Enable | `REPO_WATCH_ADVISORY_ENABLED` | `true` |
| Min severity stored | `REPO_WATCH_ADVISORY_MIN_SEVERITY` | `high` |
| OSV batch size | `REPO_WATCH_ADVISORY_QUERY_BATCH_SIZE` | `80` |
| OSV base URL | `REPO_WATCH_OSV_BASE_URL` | `https://api.osv.dev` |

Behavior:

- Shared catalog `package_advisories` + per-repo `package_advisory_findings` (`open` / `resolved`)
- Ecosystems: `npm` → OSV `npm`, `composer` → OSV `Packagist`
- Skips manifest-only versions (constraint-derived) to avoid false matches
- Schedule: `repo-watch:refresh-advisories` hourly; also queued after each successful repo scan
- Critical/high newly-opened findings feed `HighSignalNotificationService` (`package_advisory`)

API / UI:

- `GET /api/repo-watch/advisories?repository_id=&ecosystem=&severity=&status=open&limit=`
- UI: **仓库与变更** → **包安全公告**

Manual:

```bash
cd api
php artisan repo-watch:refresh-advisories --dry-run
php artisan repo-watch:refresh-advisories --sync
php artisan repo-watch:refresh-advisories --repository=123 --sync
```

## Production scan wiring

- Cron: `deploy/repo-watch-api.cron` runs `schedule:run` every minute.
- Schedule: `repo-watch:scan-repositories` every 15 minutes; `repo-watch:refresh-advisories` hourly; `repo-watch:prune-snapshots` daily (`api/routes/console.php`).
- Worker: `deploy/supervisor-repo-watch-api.conf` uses `queue:work --timeout=180` (aligned with `ScanWatchedRepository` job timeout).
- Stuck scans: repositories left in `scanning` longer than `GITHUB_REPO_WATCH_SCANNING_STALE_MINUTES` (default 20) are recovered to `pending` on the next scheduler tick.
- Egress: API workers must reach `api.osv.dev` (same class of outbound access as npm/Packagist).

## Follow-ups (not in this slice)

- Auto-select / suggest packages to watch from the latest snapshot.
- Per-user or org-level GitHub App installation instead of a single PAT.
- Deduplicate scans when many users watch the same public repository.
- Richer notification channels (email via DogeOW identity) once a durable Notifiable user exists.
- Optional GHSA GraphQL enrichment (would share the GitHub rate-limit budget — keep secondary).
