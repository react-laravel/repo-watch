<?php

namespace App\Http\Controllers\Api\Tools;

use App\Http\Controllers\Controller;
use App\Models\Repo\DependencyChange;
use App\Models\Repo\WatchedRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class DependencyChangeController extends Controller
{
    private const EXPORT_MAX_ROWS = 500;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'repository_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'ecosystem' => ['sometimes', 'nullable', 'string', Rule::in(['npm', 'composer'])],
            'change_type' => ['sometimes', 'nullable', 'string', Rule::in([
                DependencyChange::TYPE_ADDED,
                DependencyChange::TYPE_UPDATED,
                DependencyChange::TYPE_REMOVED,
            ])],
            'include_muted' => ['sometimes', 'boolean'],
        ]);

        $limit = (int) ($validated['limit'] ?? 50);
        $changes = $this->filteredChanges($request, $validated)
            ->limit($limit)
            ->get()
            ->map(fn (DependencyChange $change) => $this->transformChange($change));

        return $this->success($changes);
    }

    /**
     * Export recent dependency changes as CSV or a copyable plain-text summary.
     * Reuses the same ownership/filter rules as the list endpoint; no GitHub PAT.
     */
    public function export(Request $request): JsonResponse
    {
        $maxHours = max(1, (int) config('services.repo_watch.digest_max_hours', 48));
        $defaultHours = max(1, (int) config('services.repo_watch.digest_default_hours', 24));

        $validated = $request->validate([
            'format' => ['sometimes', 'string', Rule::in(['csv', 'summary'])],
            'since' => ['sometimes', 'nullable', 'date'],
            'hours' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:168'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::EXPORT_MAX_ROWS],
            'repository_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'ecosystem' => ['sometimes', 'nullable', 'string', Rule::in(['npm', 'composer'])],
            'change_type' => ['sometimes', 'nullable', 'string', Rule::in([
                DependencyChange::TYPE_ADDED,
                DependencyChange::TYPE_UPDATED,
                DependencyChange::TYPE_REMOVED,
            ])],
            'include_muted' => ['sometimes', 'boolean'],
        ]);

        $format = $validated['format'] ?? 'csv';
        $limit = (int) ($validated['limit'] ?? self::EXPORT_MAX_ROWS);
        [$since, $until, $windowSource] = $this->resolveExportWindow(
            isset($validated['since']) && is_string($validated['since']) ? $validated['since'] : null,
            isset($validated['hours']) ? (int) $validated['hours'] : null,
            $defaultHours,
            $maxHours,
        );

        $changes = $this->filteredChanges($request, $validated)
            ->where('detected_at', '>=', $since)
            ->where('detected_at', '<=', $until)
            ->limit($limit)
            ->get();

        $rows = $changes->map(fn (DependencyChange $change) => $this->transformChange($change))->values();
        $content = $format === 'summary'
            ? $this->buildSummary($rows, $since, $until)
            : $this->buildCsv($rows);

        $filename = sprintf(
            'repo-watch-dependency-changes-%s.%s',
            $until->format('Y-m-d'),
            $format === 'summary' ? 'txt' : 'csv'
        );

        return $this->success([
            'format' => $format,
            'filename' => $filename,
            'content' => $content,
            'row_count' => $rows->count(),
            'truncated' => $rows->count() >= $limit,
            'since' => $since->toIso8601String(),
            'until' => $until->toIso8601String(),
            'window_source' => $windowSource,
            'filters' => [
                'repository_id' => isset($validated['repository_id']) ? (int) $validated['repository_id'] : null,
                'ecosystem' => $validated['ecosystem'] ?? null,
                'change_type' => $validated['change_type'] ?? null,
                'include_muted' => $this->shouldIncludeMuted($validated),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return Builder<DependencyChange>
     */
    private function filteredChanges(Request $request, array $validated): Builder
    {
        $repositoryId = isset($validated['repository_id']) ? (int) $validated['repository_id'] : null;
        $ecosystem = $validated['ecosystem'] ?? null;
        $changeType = $validated['change_type'] ?? null;
        $includeMuted = $this->shouldIncludeMuted($validated);

        $ownedRepositoryIds = WatchedRepository::query()
            ->where('user_id', $request->user()->id)
            ->when(
                $repositoryId !== null,
                fn ($query) => $query->where('id', $repositoryId)
            )
            // Explicit repository_id always wins so a muted repo can still be inspected.
            ->when(
                $repositoryId === null && ! $includeMuted,
                fn ($query) => $query->whereNull('muted_at')
            )
            ->pluck('id');

        return DependencyChange::query()
            ->with('watchedRepository')
            ->whereIn('watched_repository_id', $ownedRepositoryIds->isEmpty() ? [-1] : $ownedRepositoryIds)
            ->when(
                is_string($ecosystem) && $ecosystem !== '',
                fn ($query) => $query->where('ecosystem', $ecosystem)
            )
            ->when(
                is_string($changeType) && $changeType !== '',
                fn ($query) => $query->where('change_type', $changeType)
            )
            ->orderByDesc('detected_at')
            ->orderByDesc('id');
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function shouldIncludeMuted(array $validated): bool
    {
        return filter_var($validated['include_muted'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: 'since'|'hours'|'default'|'clamped'}
     */
    private function resolveExportWindow(
        ?string $sinceInput,
        ?int $hoursInput,
        int $defaultHours,
        int $maxHours,
    ): array {
        $until = now();
        $earliest = $until->copy()->subHours($maxHours);

        if (is_string($sinceInput) && trim($sinceInput) !== '') {
            $since = Carbon::parse($sinceInput);
            if ($since->gt($until)) {
                $since = $until->copy();
            }
            if ($since->lt($earliest)) {
                return [$earliest, $until, 'clamped'];
            }

            return [$since, $until, 'since'];
        }

        if ($hoursInput !== null) {
            $hours = max(1, min($maxHours, $hoursInput));

            return [$until->copy()->subHours($hours), $until, 'hours'];
        }

        return [$until->copy()->subHours($defaultHours), $until, 'default'];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function buildCsv(Collection $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fputcsv($handle, [
            'detected_at',
            'full_name',
            'ecosystem',
            'manifest_path',
            'package_name',
            'change_type',
            'previous_version',
            'new_version',
            'previous_constraint',
            'new_constraint',
        ]);

        foreach ($rows as $row) {
            $repository = is_array($row['repository'] ?? null) ? $row['repository'] : [];
            fputcsv($handle, [
                $this->stringifyTimestamp($row['detected_at'] ?? null),
                $repository['full_name'] ?? '',
                $row['ecosystem'] ?? '',
                $row['manifest_path'] ?? '',
                $row['package_name'] ?? '',
                $row['change_type'] ?? '',
                $row['previous_version'] ?? '',
                $row['new_version'] ?? '',
                $row['previous_constraint'] ?? '',
                $row['new_constraint'] ?? '',
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $csv;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function buildSummary(Collection $rows, Carbon $since, Carbon $until): string
    {
        $lines = [
            'Repo Watch — dependency changes',
            sprintf('Window: %s → %s', $since->toIso8601String(), $until->toIso8601String()),
            sprintf('Rows: %d', $rows->count()),
            '',
        ];

        if ($rows->isEmpty()) {
            $lines[] = '(no dependency changes in this window)';

            return implode("\n", $lines)."\n";
        }

        $grouped = $rows->groupBy(function (array $row) {
            $repository = is_array($row['repository'] ?? null) ? $row['repository'] : [];

            return (string) ($repository['full_name'] ?? 'unknown');
        });

        foreach ($grouped as $fullName => $repoRows) {
            $lines[] = sprintf('## %s (%d)', $fullName, $repoRows->count());
            foreach ($repoRows as $row) {
                $lines[] = sprintf(
                    '- [%s] %s %s %s → %s (%s)',
                    $row['change_type'] ?? '?',
                    $row['ecosystem'] ?? '?',
                    $row['package_name'] ?? '?',
                    $row['previous_version'] ?? '—',
                    $row['new_version'] ?? '—',
                    $this->stringifyTimestamp($row['detected_at'] ?? null),
                );
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private function stringifyTimestamp(mixed $value): string
    {
        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function transformChange(DependencyChange $change): array
    {
        $repo = $change->watchedRepository;

        return [
            'id' => $change->id,
            'watched_repository_id' => $change->watched_repository_id,
            'repository' => $repo instanceof WatchedRepository ? [
                'id' => $repo->id,
                'full_name' => $repo->displayName(),
                'owner' => $repo->owner,
                'repo' => $repo->repo,
                'url' => $repo->url,
                'muted' => $repo->isMuted(),
            ] : null,
            'ecosystem' => $change->ecosystem,
            'manifest_path' => $change->manifest_path,
            'package_name' => $change->package_name,
            'change_type' => $change->change_type,
            'previous_constraint' => $change->previous_constraint,
            'new_constraint' => $change->new_constraint,
            'previous_version' => $change->previous_version,
            'new_version' => $change->new_version,
            'detected_at' => $change->detected_at,
        ];
    }
}
