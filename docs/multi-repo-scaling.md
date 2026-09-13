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

| Piece | Path / command | Role |
| --- | --- | --- |
| Cron | `deploy/repo-watch-api.cron` → `/etc/cron.d/repo-watch-api` | `* * * * * schedule:run` |
| Schedule | `api/routes/console.php` | `repo-watch:refresh` hourly; `repo-watch:scan-repositories` every 15m; `repo-watch:refresh-advisories` hourly; `repo-watch:prune-snapshots` daily — all `withoutOverlapping` |
| Worker | `deploy/supervisor-repo-watch-api.conf` → `repo-watch-api-worker` | `queue:work redis --queue=repo-watch --timeout=180` (matches scan/advisory job timeouts) |
| Failed jobs | `failed_jobs` table | `QUEUE_FAILED_DRIVER=database-uuids` (default) |
| Doctor | `php artisan repo-watch:doctor` | Token / queue / schedule source / migrations sanity |

Stuck scans: repositories left in `scanning` longer than `GITHUB_REPO_WATCH_SCANNING_STALE_MINUTES` (default 20) are recovered to `pending` on the next scheduler tick.

Egress: API workers must reach GitHub, npm/Packagist, and `api.osv.dev`.

Same-repo multi-user hardening (not full snapshot sharing): webhook and scheduler stagger jobs for the same `owner/repo`, and scans share a **120s** `repo-watch:scan-preview:{owner}/{repo}` cache behind a fetch lock so Contents API bursts collapse.

## Stacked migration order

Run `php artisan migrate` once on the tip of the stack (or after each merge — timestamps are ordered). Do not cherry-pick later migrations onto an earlier PR without the prior tables.

| Migration | Introduced | Purpose |
| --- | --- | --- |
| `2026_03_09_000002_create_watched_packages_table` | pre-stack | Legacy package watch |
| `2026_07_26_000001` / `000002` | pre-stack | Shared registry packages |
| `2026_09_13_000001_create_watched_repositories_and_dependency_tracking` | #1 | Watched repos + snapshots + changes |
| `2026_09_13_000002_create_repo_watch_notifications_table` | #5 | High-signal notifications |
| `2026_09_13_000003_create_package_advisories_tables` | #6 | OSV advisory catalog + findings |
| `2026_09_13_000004_create_failed_jobs_table` | #7 | Queue failure recording |

Merge order for the stack: **#1 → #2 → #3 → #4 → #5 → #6 → #7 → CI/mergeability follow-up**.

## Go-live checklist (20–30 repos)

Use this path on `https://repo-watch.dogeow.com` after merging the stacked PRs (`#1`→`#7`→CI fix).

### 1. Infrastructure

- [ ] Central DogeOW SSO client for Repo Watch is deployed (`REPO_WATCH_SSO_*` on dogeow-api).
- [ ] `sudo scripts/migrate-production-database.sh` (creates `repo_watch` DB if needed).
- [ ] `sudo scripts/provision-production.sh` (nginx, supervisor, cron, shared `.env` template).
- [ ] Confirm `/etc/cron.d/repo-watch-api` and supervisor programs `repo-watch-api` + `repo-watch-api-worker` are running.
- [ ] Deploy via `main` workflow (or `workflow_dispatch`) so `migrate --force` runs.

### 2. Secrets & env (`/var/www/repo-watch-api/shared/.env`)

| Variable | Required? | Notes |
| --- | --- | --- |
| `GITHUB_TOKEN` | **Yes** for 20–30 repos | PAT with public repo read; `GITHUB_PAT` also accepted |
| `GITHUB_WEBHOOK_SECRET` | Recommended | Enables push/release → scan fan-out |
| `REPO_WATCH_NOTIFY_WEBHOOK_URL` | Optional | Slack-compatible outbound; in-app works without it |
| `REPO_WATCH_NOTIFY_*` / `REPO_WATCH_ADVISORY_*` | Optional | Defaults are production-safe (high-signal only) |
| Scan/retention `GITHUB_REPO_WATCH_*` | Optional | Defaults sized for ~30 repos (batch 5, 6h interval, floor 50) |
| `REDIS_QUEUE=repo-watch` | Yes | Must match supervisor `--queue=repo-watch` |
| `QUEUE_FAILED_DRIVER=database-uuids` | Default | Needs `failed_jobs` migration |

Then:

```bash
cd /var/www/repo-watch-api/current
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan repo-watch:doctor
sudo -n supervisorctl restart repo-watch-api-worker
```

### 3. Enable jobs smoke

```bash
sudo -u www-data php artisan schedule:list   # expect scan / advisories / prune / refresh
sudo -u www-data php artisan repo-watch:scan-repositories --limit=1
sudo -u www-data php artisan repo-watch:refresh-advisories --dry-run
sudo -u www-data php artisan repo-watch:prune-snapshots --dry-run
sudo -u www-data php artisan queue:failed     # should be empty / table exists
```

### 4. Product path

1. Sign in via DogeOW SSO on `repo-watch.dogeow.com`.
2. **Bulk import** 20–30 repos (UI paste or `POST /api/repo-watch/repositories/bulk`).
3. Open **扫描健康** — expect repos moving off never-scanned; use scan-unhealthy if needed.
4. Confirm **最近依赖变更** after a second scan with real lockfile drift (or force rescan).
5. Confirm **高信号通知** for major/removed/scan failure (set webhook if you want outbound).
6. Run `repo-watch:refresh-advisories --sync` once; confirm **包安全公告** (needs egress to `api.osv.dev`).

### 5. Ops watch (first 24h)

- GitHub rate limit remaining stays above `GITHUB_REPO_WATCH_RATE_LIMIT_FLOOR` (50).
- Worker logs: `/var/log/supervisor/repo-watch-api-worker.log`
- Scheduler log: `/var/log/repo-watch-api-scheduler.log`
- `php artisan queue:failed` stays empty; investigate exhausted scan/advisory/webhook jobs.

## Follow-ups (not in this slice)

- Auto-select / suggest packages to watch from the latest snapshot.
- Per-user or org-level GitHub App installation instead of a single PAT.
- Full cross-user snapshot reuse (beyond the 120s GitHub fetch cache).
- Richer notification channels (email via DogeOW identity) once a durable Notifiable user exists.
- Optional GHSA GraphQL enrichment (would share the GitHub rate-limit budget — keep secondary).
- PR CI runs on GitHub-hosted runners; production deploy remains self-hosted on `main`.
