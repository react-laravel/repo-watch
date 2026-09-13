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

## Production scan wiring

- Cron: `deploy/repo-watch-api.cron` runs `schedule:run` every minute.
- Schedule: `repo-watch:scan-repositories` every 15 minutes; `repo-watch:prune-snapshots` daily (`api/routes/console.php`).
- Worker: `deploy/supervisor-repo-watch-api.conf` uses `queue:work --timeout=180` (aligned with `ScanWatchedRepository` job timeout).
- Stuck scans: repositories left in `scanning` longer than `GITHUB_REPO_WATCH_SCANNING_STALE_MINUTES` (default 20) are recovered to `pending` on the next scheduler tick.

## Follow-ups (not in this slice)

- Notify (email/webhook) when high-signal dependency changes appear.
- Package advisories / vulnerability signals alongside version diffs.
- Auto-select / suggest packages to watch from the latest snapshot.
- Per-user or org-level GitHub App installation instead of a single PAT.
- Deduplicate scans when many users watch the same public repository.
