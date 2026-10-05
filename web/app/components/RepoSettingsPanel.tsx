'use client'

import { ExternalLink, Trash2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import type { RepoDependencyPreview, RepoDependencyPreviewItem, WatchedPackage } from '@/lib/api/repo-watch'
import { repoLabelOf } from './repoWatchUtils'
import type { SelectedDependency } from './types'

interface RepoSettingsPanelProps {
  repoOptions: string[]
  selectedRepoKey: string
  selectedRepoSample: {
    source_provider: string
    source_owner: string
    source_repo: string
    source_url: string
    description?: string | null
  } | null
  selectedRepoPackages: WatchedPackage[]
  selectedRepoWatchedMap: Map<string, SelectedDependency>
  repoSettingsPreview: RepoDependencyPreview | null
  repoSettingsLoading: boolean
  repoSettingsError: string | null
  repoSettingsActionKey: string | null
  onRetryPreview: () => void
  onRepoKeyChange: (key: string) => void
  onToggleAllRepoSettings: (
    deps: Array<
      RepoDependencyPreviewItem & { ecosystem: 'npm' | 'composer'; manifest_path: string }
    >,
    nextWatched: boolean
  ) => Promise<void>
  onToggleRepoSettingPackage: (
    dep: SelectedDependency,
    watched: SelectedDependency | undefined
  ) => Promise<void>
  onDeleteRepo: () => Promise<void>
}

export default function RepoSettingsPanel({
  repoOptions,
  selectedRepoKey,
  selectedRepoSample,
  selectedRepoPackages,
  selectedRepoWatchedMap,
  repoSettingsPreview,
  repoSettingsLoading,
  repoSettingsError,
  repoSettingsActionKey,
  onRetryPreview,
  onRepoKeyChange,
  onToggleAllRepoSettings,
  onToggleRepoSettingPackage,
  onDeleteRepo,
}: RepoSettingsPanelProps) {
  const repoOptionsFiltered = repoOptions.filter(item => item !== 'all' && item !== 'no-repo')

  const previewDependencyKeys =
    repoSettingsPreview?.manifests.flatMap(manifest =>
      manifest.dependencies.map(
        dep => `${manifest.ecosystem}:${manifest.path}:${dep.package_name}`
      )
    ) ?? []
  const allWatched =
    previewDependencyKeys.length > 0 &&
    previewDependencyKeys.every(key => selectedRepoWatchedMap.has(key))

  return (
    <>
      {/* 仓库选择 */}
      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-base">选择仓库</CardTitle>
        </CardHeader>
        <CardContent>
          <select
            className="border-input bg-background w-full h-9 rounded-md border px-3 text-sm"
            aria-label="设置中的仓库"
            value={repoOptionsFiltered.includes(selectedRepoKey) ? selectedRepoKey : ''}
            onChange={event => onRepoKeyChange(event.target.value)}
          >
            <option value="">选择一个仓库</option>
            {repoOptionsFiltered.map(item => (
              <option key={item} value={item}>
                {item}
              </option>
            ))}
          </select>
          {repoOptionsFiltered.length === 0 ? (
            <p className="text-muted-foreground mt-3 text-sm">
              还没有可设置的仓库。添加仓库并保存关注的依赖后，可在这里调整。
            </p>
          ) : (
            <p className="text-muted-foreground mt-2 text-xs">选择仓库后读取该仓库的依赖清单。</p>
          )}
        </CardContent>
      </Card>

      {/* 仓库设置内容 */}
      {selectedRepoSample && selectedRepoKey !== 'all' ? (
        <Card>
          <CardHeader className="pb-3">
            <div className="flex items-center justify-between gap-3">
              <div className="min-w-0">
                <CardTitle className="text-base truncate">
                  {repoLabelOf(selectedRepoSample)}
                </CardTitle>
                {selectedRepoSample.source_url ? (
                  <a
                    href={selectedRepoSample.source_url}
                    target="_blank"
                    rel="noreferrer"
                    className="text-muted-foreground flex min-w-0 items-center gap-1 text-xs transition-colors hover:text-foreground"
                  >
                    <ExternalLink className="h-3 w-3 shrink-0" />
                    <span className="truncate">{selectedRepoSample.source_url}</span>
                  </a>
                ) : null}
              </div>
              <div className="flex gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  disabled={
                    repoSettingsActionKey === 'toggle-all' ||
                    repoSettingsLoading ||
                    !repoSettingsPreview
                  }
                  onClick={() =>
                    void onToggleAllRepoSettings(
                      repoSettingsPreview?.manifests.flatMap(manifest =>
                        manifest.dependencies.map(dep => ({
                          ...dep,
                          ecosystem: manifest.ecosystem,
                          manifest_path: manifest.path,
                        }))
                      ) ?? [],
                      !allWatched
                    )
                  }
                >
                  {allWatched ? '取消全选' : '全选'}
                </Button>
                <Button
                  variant="ghost"
                  size="icon"
                  className="h-8 w-8 text-destructive hover:text-destructive"
                  onClick={() => {
                    if (
                      confirm(
                        `确认删除「${selectedRepoKey}」下的所有依赖关注（共 ${selectedRepoPackages.length} 个）？`
                      )
                    ) {
                      void onDeleteRepo()
                    }
                  }}
                  title="删除仓库"
                >
                  <Trash2 className="h-4 w-4" />
                </Button>
              </div>
            </div>
          </CardHeader>
          <CardContent className="space-y-4">
            {repoSettingsLoading ? (
              <div className="text-muted-foreground py-8 text-center text-sm">正在读取仓库依赖…</div>
            ) : repoSettingsError ? (
              <div className="space-y-3 py-8 text-center">
                <p className="text-destructive text-sm">{repoSettingsError}</p>
                <Button variant="outline" size="sm" onClick={onRetryPreview}>
                  重试
                </Button>
              </div>
            ) : repoSettingsPreview && repoSettingsPreview.manifests.length === 0 ? (
              <div className="text-muted-foreground py-8 text-center text-sm">
                这个仓库没有 npm 或 Composer 依赖
              </div>
            ) : repoSettingsPreview ? (
              repoSettingsPreview.manifests.map(manifest => {
                const watchedCount = manifest.dependencies.filter(dep =>
                  selectedRepoWatchedMap.has(
                    `${manifest.ecosystem}:${manifest.path}:${dep.package_name}`
                  )
                ).length
                return (
                  <div key={`${manifest.ecosystem}:${manifest.path}`} className="space-y-2">
                    <div className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                      <Badge variant="outline" className="text-xs">
                        {manifest.ecosystem}
                      </Badge>
                      <Badge variant="outline" className="text-xs">
                        {manifest.path}
                      </Badge>
                      <span>
                        {watchedCount}/{manifest.dependencies.length} 个包已关注
                      </span>
                    </div>
                    <div className="grid gap-1">
                      {manifest.dependencies.map(dep => {
                        const key = `${manifest.ecosystem}:${manifest.path}:${dep.package_name}`
                        const watched = selectedRepoWatchedMap.get(key)
                        return (
                          <label
                            key={key}
                            className="flex items-center gap-2 rounded-md border px-3 py-2 hover:bg-muted/50"
                          >
                            <input
                              type="checkbox"
                              checked={!!watched}
                              disabled={repoSettingsActionKey === key}
                              onChange={() =>
                                void onToggleRepoSettingPackage(
                                  {
                                    ...dep,
                                    ecosystem: manifest.ecosystem,
                                    manifest_path: manifest.path,
                                    selected: !!watched,
                                  },
                                  watched
                                )
                              }
                              className="accent-primary"
                            />
                            <div className="min-w-0 flex-1">
                              <div className="truncate text-sm font-medium">{dep.package_name}</div>
                              <div className="truncate text-xs text-muted-foreground">
                                {dep.current_version_constraint || '未声明'}
                                {dep.dependency_group ? ` · ${dep.dependency_group}` : ''}
                              </div>
                            </div>
                          </label>
                        )
                      })}
                    </div>
                  </div>
                )
              })
            ) : (
              <div className="text-muted-foreground py-8 text-center text-sm">
                {selectedRepoKey ? '选择仓库后会读取依赖清单' : '先选择一个仓库'}
              </div>
            )}
          </CardContent>
        </Card>
      ) : null}
    </>
  )
}
