# Scaling Repo Watch to 20–30 repositories

This note covers the multi-repository foundation added for Sam’s goal of watching roughly 20–30 GitHub repos and surfacing recent dependency changes.

## What exists now

- First-class `watched_repositories` (CRUD + scan status).
- Per-manifest `dependency_snapshots` and diffed `dependency_changes` (added / updated / removed for npm + Composer).
- Background scans via `repo-watch:scan-repositories` (every 15 minutes) and `ScanWatchedRepository` jobs.
- Shared `registry_packages` refresh remains hourly for “latest registry version” watching.
- GitHub push/release webhooks enqueue both registry refresh and repository snapshot scans.
- UI: **仓库与变更** shows watched repos and a recent dependency-change feed.

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

## Tunables

Set in API `.env` (see `config/services.php` → `github`):

- `GITHUB_TOKEN` / `GITHUB_PAT` — strongly recommended before scaling past a handful of repos.
- `GITHUB_REPO_WATCH_SCAN_BATCH_SIZE` — raise toward 10 only with a token and headroom.
- `GITHUB_REPO_WATCH_SCAN_DELAY_MS` — increase if secondary rate limits appear.
- `GITHUB_REPO_WATCH_RATE_LIMIT_FLOOR` — stop enqueueing/scanning before hard 429s.
- `GITHUB_REPO_WATCH_SCAN_INTERVAL_HOURS` — lower only if webhook coverage is incomplete.

## Manual verification

```bash
cd api
php artisan migrate
php artisan test --filter=WatchedRepository
php artisan repo-watch:scan-repositories --limit=5
```

Or via API (authenticated session):

1. `POST /api/repo-watch/repositories` with `{ "url": "https://github.com/org/repo" }`
2. `POST /api/repo-watch/repositories/{id}/scan?sync=1` twice (second run with changed manifests produces changes)
3. `GET /api/repo-watch/dependency-changes`

## Follow-ups (not in this slice)

- Retain only the latest N snapshots per manifest to bound storage.
- Auto-select / suggest packages to watch from the latest snapshot.
- Per-user or org-level GitHub App installation instead of a single PAT.
- Deduplicate scans when many users watch the same public repository.
- Notify (email/webhook) when high-signal dependency changes appear.
