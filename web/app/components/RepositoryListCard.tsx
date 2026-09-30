'use client'

import { useRef, useState } from 'react'
import {
  Bell,
  BellOff,
  ExternalLink,
  GitBranch,
  Package,
  Plus,
  RefreshCw,
  Search,
  Trash2,
  X,
} from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import type { RepositoryScanStatus, WatchedRepository, WatchPriority } from '@/lib/api/repo-watch'
import { formatDateTime } from './repoWatchUtils'

const statuses: Record<RepositoryScanStatus | 'unscanned', string> = {
  idle: '扫描正常',
  pending: '等待扫描',
  scanning: '正在扫描',
  error: '扫描失败',
  unscanned: '尚未扫描',
}
const priorities: Record<WatchPriority, string> = {
  high: '高优先',
  normal: '普通',
  low: '低优先',
}
const selectClass =
  'border-input bg-background h-10 min-w-0 rounded-lg border px-3 text-sm disabled:opacity-50'
function displayStatus(repo: WatchedRepository) {
  return repo.scan_status === 'idle' && !repo.last_scanned_at ? 'unscanned' : repo.scan_status
}

export default function RepositoryListCard({
  repositories,
  actionId,
  onPriorityChange,
  onToggleMute,
  onScan,
  onDelete,
  onImport,
}: {
  repositories: WatchedRepository[]
  actionId: number | null
  onPriorityChange: (repo: WatchedRepository, priority: WatchPriority) => void
  onToggleMute: (repo: WatchedRepository) => void
  onScan: (id: number) => void
  onDelete: (id: number) => void
  onImport: () => void
}) {
  const [query, setQuery] = useState('')
  const [status, setStatus] = useState('all')
  const [notification, setNotification] = useState('all')
  const searchInput = useRef<HTMLInputElement>(null)
  const needle = query.trim().toLocaleLowerCase()
  const filtered = repositories.filter(
    repo =>
      `${repo.full_name} ${repo.description ?? ''}`.toLocaleLowerCase().includes(needle) &&
      (status === 'all' || displayStatus(repo) === status) &&
      (notification === 'all' || repo.muted === (notification === 'muted'))
  )
  const hasFilters = Boolean(needle || status !== 'all' || notification !== 'all')
  const resetFilters = () => {
    setQuery('')
    setStatus('all')
    setNotification('all')
  }
  return (
    <Card
      id="repo-watch-repositories"
      className="scroll-mt-6 gap-5 overflow-hidden rounded-2xl py-5"
    >
      <CardHeader className="px-4 sm:px-6">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="min-w-0 space-y-2">
            <CardTitle>
              <h2 className="text-lg">关注中的仓库</h2>
            </CardTitle>
            <CardDescription>扫描进度、依赖数量与通知状态，一处查看。</CardDescription>
          </div>
          <Button variant="outline" size="sm" onClick={onImport}>
            <Plus aria-hidden className="size-4" />
            批量导入
          </Button>
        </div>
      </CardHeader>
      <CardContent className="space-y-4 px-4 sm:px-6">
        <div className="grid grid-cols-2 gap-2 lg:grid-cols-[minmax(0,1fr)_auto_auto]">
          <div className="relative col-span-2 min-w-0 lg:col-span-1">
            <Search
              aria-hidden
              className="text-muted-foreground pointer-events-none absolute top-3 left-3 size-4"
            />
            <Input
              ref={searchInput}
              type="search"
              aria-label="搜索关注仓库"
              placeholder="搜索仓库名称或描述"
              className="h-10 rounded-lg pr-10 pl-10"
              value={query}
              onChange={event => setQuery(event.target.value)}
            />
            {query && (
              <Button
                variant="ghost"
                size="icon"
                className="absolute top-0.5 right-0.5 size-9"
                aria-label="清空仓库搜索"
                onClick={() => {
                  setQuery('')
                  searchInput.current?.focus()
                }}
              >
                <X aria-hidden className="size-4" />
              </Button>
            )}
          </div>
          <select
            aria-label="仓库扫描状态"
            className={selectClass}
            value={status}
            onChange={event => setStatus(event.target.value)}
          >
            <option value="all">全部扫描状态</option>
            {Object.entries(statuses).map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </select>
          <select
            aria-label="仓库通知状态"
            className={selectClass}
            value={notification}
            onChange={event => setNotification(event.target.value)}
          >
            <option value="all">全部通知状态</option>
            <option value="active">通知开启</option>
            <option value="muted">已静音</option>
          </select>
        </div>
        <div className="flex flex-wrap items-center justify-between gap-2 text-xs">
          <p role="status" className="text-muted-foreground">
            显示 {filtered.length} / {repositories.length} 个仓库 · 保持扫描优先顺序
          </p>
          {hasFilters && (
            <Button variant="ghost" size="sm" onClick={resetFilters}>
              清空仓库筛选
            </Button>
          )}
        </div>
        {!filtered.length ? (
          <div className="border-border bg-muted/20 flex flex-col items-center gap-3 rounded-xl border border-dashed px-4 py-10 text-center">
            <GitBranch aria-hidden className="text-muted-foreground size-8" />
            <h3 className="font-semibold">
              {repositories.length ? '没有匹配的仓库' : '从第一个仓库开始'}
            </h3>
            <p className="text-muted-foreground max-w-sm text-sm">
              {repositories.length
                ? '调整关键词或扫描、通知状态，不会改变已有监控配置。'
                : '导入 GitHub 仓库，开始追踪依赖快照、版本变化与安全公告。'}
            </p>
            <Button variant="outline" onClick={repositories.length ? resetFilters : onImport}>
              {repositories.length ? '查看全部仓库' : '导入仓库'}
            </Button>
          </div>
        ) : (
          <ul aria-label="关注仓库列表" className="space-y-3">
            {filtered.map(repo => {
              const state = displayStatus(repo)
              const busy = actionId === repo.id
              return (
                <li
                  key={repo.id}
                  className={`min-w-0 overflow-hidden rounded-xl border ${state === 'error' ? 'border-destructive/35 bg-destructive/5' : 'border-border bg-background'}`}
                >
                  <div className="space-y-3 p-4">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                      <a
                        href={repo.url}
                        target="_blank"
                        rel="noreferrer"
                        className="flex min-w-0 items-start gap-2 font-semibold hover:underline"
                      >
                        <GitBranch
                          aria-hidden
                          className="text-muted-foreground mt-0.5 size-4 shrink-0"
                        />
                        <span className="min-w-0 break-all">{repo.full_name}</span>
                        <ExternalLink
                          aria-hidden
                          className="text-muted-foreground mt-1 size-3 shrink-0"
                        />
                      </a>
                      <Badge
                        variant={state === 'error' ? 'destructive' : 'secondary'}
                        className={
                          state === 'idle'
                            ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'
                            : ''
                        }
                      >
                        {(state === 'pending' || state === 'scanning') && (
                          <RefreshCw
                            aria-hidden
                            className={`mr-1 size-3 ${state === 'scanning' ? 'animate-spin motion-reduce:animate-none' : ''}`}
                          />
                        )}
                        {statuses[state]}
                      </Badge>
                    </div>
                    {repo.description && (
                      <p className="text-muted-foreground break-words text-sm leading-6">
                        {repo.description}
                      </p>
                    )}
                    <div className="text-muted-foreground flex flex-wrap items-center gap-x-4 gap-y-2 text-xs">
                      <span className="flex items-center gap-1.5">
                        <Package aria-hidden className="size-3.5" />
                        <strong className="text-foreground font-semibold tabular-nums">
                          {repo.package_count}
                        </strong>{' '}
                        个依赖
                      </span>
                      <span>{repo.muted ? '通知已静音 · 扫描继续' : '通知已开启'}</span>
                      {repo.watch_priority !== 'normal' && (
                        <Badge variant="outline">{priorities[repo.watch_priority]}</Badge>
                      )}
                    </div>
                    <dl className="grid grid-cols-1 gap-2 text-xs sm:grid-cols-2">
                      <div className="flex flex-wrap gap-x-2">
                        <dt className="text-muted-foreground">上次扫描</dt>
                        <dd className="tabular-nums">{formatDateTime(repo.last_scanned_at)}</dd>
                      </div>
                      <div className="flex flex-wrap gap-x-2">
                        <dt className="text-muted-foreground">下次扫描</dt>
                        <dd className="tabular-nums">{formatDateTime(repo.next_scan_at)}</dd>
                      </div>
                    </dl>
                    {repo.last_scan_error && (
                      <p className="text-destructive break-all rounded-lg border border-destructive/20 px-3 py-2 text-xs leading-5">
                        扫描错误：{repo.last_scan_error}
                      </p>
                    )}
                  </div>
                  <div className="border-border bg-muted/25 flex flex-wrap items-center gap-2 border-t px-4 py-3">
                    <select
                      className={`${selectClass} mr-auto h-9 max-w-full text-xs`}
                      value={repo.watch_priority}
                      disabled={busy}
                      aria-label={`${repo.full_name} 关注优先级`}
                      onChange={event =>
                        onPriorityChange(repo, event.target.value as WatchPriority)
                      }
                    >
                      {Object.entries(priorities).map(([value, label]) => (
                        <option key={value} value={value}>
                          {label}
                        </option>
                      ))}
                    </select>
                    <Button
                      variant="ghost"
                      size="sm"
                      disabled={busy}
                      aria-label={`${repo.muted ? '恢复通知' : '静音通知'} ${repo.full_name}`}
                      onClick={() => onToggleMute(repo)}
                    >
                      {repo.muted ? (
                        <BellOff aria-hidden className="size-4" />
                      ) : (
                        <Bell aria-hidden className="size-4" />
                      )}
                      {repo.muted ? '恢复通知' : '静音'}
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      loading={busy}
                      aria-label={`扫描 ${repo.full_name}`}
                      onClick={() => onScan(repo.id)}
                    >
                      <RefreshCw aria-hidden className="size-4" />
                      扫描
                    </Button>
                    <Button
                      variant="ghost"
                      size="sm"
                      disabled={busy}
                      className="text-muted-foreground hover:text-destructive"
                      aria-label={`删除 ${repo.full_name}`}
                      onClick={() => onDelete(repo.id)}
                    >
                      <Trash2 aria-hidden className="size-4" />
                      <span className="sr-only sm:not-sr-only">删除</span>
                    </Button>
                  </div>
                </li>
              )
            })}
          </ul>
        )}
        <p className="text-muted-foreground text-xs leading-5">
          这里的搜索仅筛选仓库列表。依赖变更与安全公告仍使用各自的筛选条件；静音不会停止扫描。
        </p>
      </CardContent>
    </Card>
  )
}
