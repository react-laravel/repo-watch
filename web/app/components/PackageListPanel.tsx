'use client'

import { ExternalLink, RefreshCw, X } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { type WatchedPackage, type WatchLevel } from '@/lib/api/repo-watch'
import {
  formatDateTime,
  repoKeyOf,
  repoLabelOf,
  getLeadingSymbolPrefix,
  renderVersionDiff,
} from './repoWatchUtils'

type VersionFilter = 'all' | WatchLevel

interface PackageListPanelProps {
  watchedPackages: WatchedPackage[]
  filteredWatchedPackages: WatchedPackage[]
  groupedWatchedPackages: Record<string, WatchedPackage[]>
  versionFilter: VersionFilter
  selectedRepoKey: string
  repoOptions: string[]
  activeAction: { id: number; type: 'refresh' | 'cancel' } | null
  loadingList: boolean
  onVersionFilterChange: (filter: VersionFilter) => void
  onRepoKeyChange: (key: string) => void
  onRefresh: (id: number) => Promise<void>
  onCancelWatch: (id: number) => Promise<void>
}

const VERSION_FILTER_OPTIONS: Array<{ value: VersionFilter; label: string }> = [
  { value: 'all', label: '全部更新' },
  { value: 'major', label: '只看大版本' },
  { value: 'minor', label: '只看功能版本' },
  { value: 'patch', label: '只看小版本' },
]

export default function PackageListPanel({
  watchedPackages,
  filteredWatchedPackages,
  groupedWatchedPackages,
  versionFilter,
  selectedRepoKey,
  repoOptions,
  activeAction,
  loadingList,
  onVersionFilterChange,
  onRepoKeyChange,
  onRefresh,
  onCancelWatch,
}: PackageListPanelProps) {
  const updateLabels = { major: '大版本更新', minor: '功能版本更新', patch: '修复版本更新' }
  const isRepoFiltered = selectedRepoKey !== 'all'

  const renderPackageCard = (item: WatchedPackage) => (
    <div key={item.id} className="bg-card min-w-0 rounded-2xl border p-4 shadow-sm sm:p-5">
      <div className="flex min-w-0 flex-col items-stretch justify-between gap-4 sm:flex-row sm:items-start">
        <div className="min-w-0 flex-1 space-y-3">
          <div className="flex flex-wrap items-center gap-2">
            <div className="min-w-0 break-all font-semibold">
              {item.publisher_display_name ?? item.package_name}
            </div>
            <Badge variant="outline">{item.ecosystem}</Badge>
            {item.latest_update_type ? (
              <Badge variant={item.latest_update_type === 'major' ? 'destructive' : 'secondary'}>
                {updateLabels[item.latest_update_type]}
              </Badge>
            ) : (
              <Badge variant="secondary">暂无更新类型</Badge>
            )}
          </div>
          <div className="space-y-1 text-sm text-muted-foreground">
            {(() => {
              const prefix = getLeadingSymbolPrefix(item.current_version_constraint)
              const prefixPad = prefix ? (
                <span aria-hidden className="text-transparent select-none">
                  {prefix}
                </span>
              ) : null
              return (
                <>
                  <div className="grid grid-cols-[5.5rem_minmax(0,1fr)] gap-2">
                    <span>当前约束：</span>
                    <span className="min-w-0 break-all font-mono">
                      {item.current_version_constraint || '未声明'}
                    </span>
                  </div>
                  <div className="grid grid-cols-[5.5rem_minmax(0,1fr)] gap-2">
                    <span>当前基线：</span>
                    <span className="min-w-0 break-all font-mono">
                      {prefixPad}
                      {item.normalized_current_version || '未知'}
                    </span>
                  </div>
                  <div className="grid grid-cols-[5.5rem_minmax(0,1fr)] gap-2">
                    <span>最新版本：</span>
                    <span className="min-w-0 break-all font-mono">
                      {prefixPad}
                      {renderVersionDiff(item.normalized_current_version, item.latest_version)}
                    </span>
                  </div>
                </>
              )
            })()}
          </div>
          <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
            <span className="text-xs text-muted-foreground">
              最近检查：{formatDateTime(item.last_checked_at)}
            </span>
            {!isRepoFiltered ? (
              <span className="text-xs text-muted-foreground">来源：{repoLabelOf(item)}</span>
            ) : null}
          </div>
        </div>
        <div className="flex shrink-0 flex-wrap items-center gap-1 border-t pt-3 sm:flex-col sm:items-stretch sm:border-t-0 sm:pt-0">
          {item.registry_url ? (
            <Button variant="ghost" size="sm" asChild>
              <a href={item.registry_url} target="_blank" rel="noreferrer">
                <ExternalLink className="h-4 w-4" />
                包页
              </a>
            </Button>
          ) : null}
          <Button
            variant="ghost"
            size="sm"
            aria-label={`刷新 ${item.package_name}`}
            onClick={() => void onRefresh(item.id)}
            disabled={activeAction?.id === item.id && activeAction.type === 'refresh'}
          >
            <RefreshCw className="h-4 w-4" />
            刷新
          </Button>
          <Button
            variant="ghost"
            size="sm"
            aria-label={`取消关注 ${item.package_name}`}
            onClick={() => void onCancelWatch(item.id)}
            disabled={activeAction?.id === item.id && activeAction.type === 'cancel'}
          >
            <X className="h-4 w-4" />
            取消
          </Button>
        </div>
      </div>
    </div>
  )

  if (loadingList) {
    return (
      <Card>
        <CardContent className="py-12 text-center text-sm text-muted-foreground">
          <span role="status">正在加载关注列表…</span>
        </CardContent>
      </Card>
    )
  }

  if (watchedPackages.length === 0) return null

  if (filteredWatchedPackages.length === 0) {
    return (
      <Card>
        <CardContent className="py-12">
          <div className="text-center">
            <p className="text-sm text-muted-foreground">当前筛选条件下没有结果</p>
            <p className="mt-1 text-xs text-muted-foreground">切换仓库范围或更新类型</p>
            <Button
              className="mt-4"
              variant="outline"
              onClick={() => {
                onVersionFilterChange('all')
                onRepoKeyChange('all')
              }}
            >
              清空依赖筛选
            </Button>
          </div>
        </CardContent>
      </Card>
    )
  }

  if (selectedRepoKey !== 'all') {
    return (
      <div className="space-y-3">
        {Object.entries(groupedWatchedPackages).map(([repoKey, items]) => (
          <Card key={repoKey} className="min-w-0 overflow-hidden rounded-2xl">
            <CardHeader className="pb-2">
              <div className="flex items-center justify-between">
                <CardTitle className="min-w-0 break-all text-sm">
                  {repoKey === 'no-repo' ? '无仓库' : repoKey}
                </CardTitle>
                <span className="text-xs text-muted-foreground">{items.length} 个依赖</span>
              </div>
            </CardHeader>
            <CardContent className="space-y-2">{items.map(renderPackageCard)}</CardContent>
          </Card>
        ))}
      </div>
    )
  }

  return <div className="space-y-3">{filteredWatchedPackages.map(renderPackageCard)}</div>
}
