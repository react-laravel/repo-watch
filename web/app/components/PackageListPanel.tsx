'use client'

import { useMemo, useState } from 'react'
import { ExternalLink, RefreshCw, Search, X } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { type WatchedPackage, type WatchLevel } from '@/lib/api/repo-watch'
import {
  formatDateTime,
  repoKeyOf,
  repoLabelOf,
  getLeadingSymbolPrefix,
  renderVersionDiff,
  WATCH_LEVEL_LABEL,
} from './repoWatchUtils'

type VersionFilter = 'all' | WatchLevel

interface PackageListPanelProps {
  watchedPackages: WatchedPackage[]
  filteredWatchedPackages: WatchedPackage[]
  versionFilter: VersionFilter
  selectedRepoKey: string
  activeAction: { id: number; type: 'refresh' | 'cancel' | 'level' } | null
  loadingList: boolean
  onVersionFilterChange: (filter: VersionFilter) => void
  onRepoKeyChange: (key: string) => void
  onRefresh: (id: number) => Promise<void>
  onCancelWatch: (id: number) => Promise<void>
  onWatchLevelChange: (item: WatchedPackage, level: WatchLevel) => Promise<void>
}

const PAGE_SIZE = 40

const updateLabels = { major: '大版本更新', minor: '功能版本更新', patch: '修复版本更新' }

const selectClassName =
  'border-input bg-background h-8 rounded-md border px-2 text-xs disabled:cursor-not-allowed disabled:opacity-50'

export default function PackageListPanel({
  watchedPackages,
  filteredWatchedPackages,
  versionFilter,
  selectedRepoKey,
  activeAction,
  loadingList,
  onVersionFilterChange,
  onRepoKeyChange,
  onRefresh,
  onCancelWatch,
  onWatchLevelChange,
}: PackageListPanelProps) {
  const [query, setQuery] = useState('')
  const listKey = `${query}\0${versionFilter}\0${selectedRepoKey}`
  const [windowState, setWindowState] = useState({ listKey, count: PAGE_SIZE })
  if (windowState.listKey !== listKey) {
    setWindowState({ listKey, count: PAGE_SIZE })
  }
  const visibleCount = windowState.count
  const isRepoFiltered = selectedRepoKey !== 'all'

  const searchedPackages = useMemo(() => {
    const needle = query.trim().toLowerCase()
    if (!needle) return filteredWatchedPackages

    return filteredWatchedPackages.filter(item => {
      const repo = repoLabelOf(item).toLowerCase()
      return (
        item.package_name.toLowerCase().includes(needle) ||
        repo.includes(needle) ||
        (item.manifest_path ?? '').toLowerCase().includes(needle)
      )
    })
  }, [filteredWatchedPackages, query])

  const groupedAll = useMemo(() => {
    return searchedPackages.reduce<Record<string, WatchedPackage[]>>((acc, item) => {
      const key = repoKeyOf(item)
      if (!acc[key]) acc[key] = []
      acc[key].push(item)
      return acc
    }, {})
  }, [searchedPackages])

  const visibleGroups = useMemo(() => {
    let remaining = visibleCount
    const sections: Array<{ key: string; items: WatchedPackage[]; total: number }> = []
    for (const [key, items] of Object.entries(groupedAll)) {
      if (remaining <= 0) break
      const slice = items.slice(0, remaining)
      remaining -= slice.length
      sections.push({ key, items: slice, total: items.length })
    }
    return sections
  }, [groupedAll, visibleCount])

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
          {item.manifest_path ? (
            <div className="text-muted-foreground truncate text-xs">{item.manifest_path}</div>
          ) : null}
          {item.last_error ? (
            <div className="text-destructive text-xs break-words">检查失败：{item.last_error}</div>
          ) : null}
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
          <select
            className={selectClassName}
            value={item.watch_level}
            aria-label={`${item.package_name} 关注级别`}
            disabled={activeAction?.id === item.id}
            onChange={event => void onWatchLevelChange(item, event.target.value as WatchLevel)}
          >
            {(Object.keys(WATCH_LEVEL_LABEL) as WatchLevel[]).map(level => (
              <option key={level} value={level}>
                {WATCH_LEVEL_LABEL[level]}
              </option>
            ))}
          </select>
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
            loading={activeAction?.id === item.id && activeAction.type === 'refresh'}
          >
            <RefreshCw className="h-4 w-4" />
            刷新
          </Button>
          <Button
            variant="ghost"
            size="sm"
            aria-label={`取消关注 ${item.package_name}`}
            onClick={() => void onCancelWatch(item.id)}
            loading={activeAction?.id === item.id && activeAction.type === 'cancel'}
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
        <CardContent className="text-muted-foreground py-12 text-center text-sm">
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
            <p className="text-muted-foreground text-sm">当前筛选条件下没有结果</p>
            <p className="text-muted-foreground mt-1 text-xs">切换仓库范围或更新类型</p>
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

  const groups = isRepoFiltered
    ? [
        {
          key: 'flat',
          items: visibleGroups.flatMap(group => group.items),
          total: searchedPackages.length,
        },
      ]
    : visibleGroups

  return (
    <div className="space-y-3">
      <div className="relative">
        <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2" />
        <Input
          value={query}
          onChange={event => setQuery(event.target.value)}
          placeholder="搜索包名、清单或仓库"
          className="h-8 pl-8 text-xs"
          aria-label="搜索依赖"
        />
      </div>

      {searchedPackages.length === 0 ? (
        <Card>
          <CardContent className="py-12 text-center">
            <p className="text-muted-foreground text-sm">没有匹配的依赖</p>
            <p className="text-muted-foreground mt-1 text-xs">换一个关键词，或清空搜索</p>
          </CardContent>
        </Card>
      ) : (
        groups.map(group => (
          <Card key={group.key} className="min-w-0 overflow-hidden rounded-2xl">
            {!isRepoFiltered ? (
              <CardHeader className="pb-2">
                <div className="flex items-center justify-between gap-2">
                  <CardTitle className="min-w-0 text-sm break-all">
                    {group.key === 'no-repo' ? '无仓库' : group.key}
                  </CardTitle>
                  <span className="text-muted-foreground shrink-0 text-xs">
                    {group.items.length === group.total
                      ? `${group.total} 个依赖`
                      : `${group.items.length}/${group.total} 个依赖`}
                  </span>
                </div>
              </CardHeader>
            ) : null}
            <CardContent className="space-y-2">{group.items.map(renderPackageCard)}</CardContent>
          </Card>
        ))
      )}

      {visibleCount < searchedPackages.length ? (
        <div className="flex justify-center">
          <Button
            variant="outline"
            size="sm"
            onClick={() =>
              setWindowState(current => ({ ...current, count: current.count + PAGE_SIZE }))
            }
          >
            显示更多（{visibleCount}/{searchedPackages.length}）
          </Button>
        </div>
      ) : null}
    </div>
  )
}
