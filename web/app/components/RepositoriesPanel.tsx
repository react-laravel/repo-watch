'use client'

import { useCallback, useEffect, useState } from 'react'
import { Activity, Bell, ExternalLink, Filter, GitBranch, RefreshCw, ShieldAlert, Trash2, Upload } from 'lucide-react'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { EmptyState } from '@/components/ui/empty-state'
import { cn } from '@/lib/helpers'
import {
  bulkImportWatchedRepositories,
  deleteWatchedRepository,
  listDependencyChanges,
  listPackageAdvisories,
  listRepoWatchNotifications,
  listWatchedRepositories,
  markAllRepoWatchNotificationsRead,
  markRepoWatchNotificationRead,
  scanUnhealthyWatchedRepositories,
  scanWatchedRepository,
  type BulkImportRepositoryResult,
  type DependencyChange,
  type DependencyChangeType,
  type Ecosystem,
  type PackageAdvisoryFinding,
  type PackageAdvisoryPolicy,
  type RepositoryScanStatus,
  type RepoWatchNotification,
  type RepoWatchNotificationPolicy,
  type ScanHealthSummary,
  type SnapshotRetentionPolicy,
  type WatchedRepository,
} from '@/lib/api/repo-watch'
import { formatDateTime } from './repoWatchUtils'

const CHANGE_TYPE_LABEL: Record<DependencyChangeType, string> = {
  added: '新增',
  removed: '移除',
  updated: '更新',
}

const IMPORT_STATUS_LABEL: Record<BulkImportRepositoryResult['status'], string> = {
  created: '已新增',
  already_watched: '已存在',
  duplicate_in_request: '请求重复',
  invalid: '无效',
}

const SCAN_STATUS_LABEL: Record<RepositoryScanStatus, string> = {
  idle: '正常',
  pending: '排队中',
  scanning: '扫描中',
  error: '失败',
}

const selectClassName =
  'border-input bg-background h-8 rounded-md border px-2 text-xs disabled:cursor-not-allowed disabled:opacity-50'

type EcosystemFilter = 'all' | Ecosystem
type ChangeTypeFilter = 'all' | DependencyChangeType

export default function RepositoriesPanel() {
  const [repositories, setRepositories] = useState<WatchedRepository[]>([])
  const [health, setHealth] = useState<ScanHealthSummary | null>(null)
  const [retention, setRetention] = useState<SnapshotRetentionPolicy | null>(null)
  const [notifications, setNotifications] = useState<RepoWatchNotification[]>([])
  const [notificationPolicy, setNotificationPolicy] = useState<RepoWatchNotificationPolicy | null>(null)
  const [unreadCount, setUnreadCount] = useState(0)
  const [markingNotifications, setMarkingNotifications] = useState(false)
  const [advisories, setAdvisories] = useState<PackageAdvisoryFinding[]>([])
  const [advisoryPolicy, setAdvisoryPolicy] = useState<PackageAdvisoryPolicy | null>(null)
  const [advisoriesLoading, setAdvisoriesLoading] = useState(false)
  const [changes, setChanges] = useState<DependencyChange[]>([])
  const [loading, setLoading] = useState(true)
  const [changesLoading, setChangesLoading] = useState(false)
  const [actionId, setActionId] = useState<number | null>(null)
  const [rescanningUnhealthy, setRescanningUnhealthy] = useState(false)
  const [importText, setImportText] = useState('')
  const [importing, setImporting] = useState(false)
  const [importResults, setImportResults] = useState<BulkImportRepositoryResult[] | null>(null)
  const [repoFilter, setRepoFilter] = useState<string>('all')
  const [ecosystemFilter, setEcosystemFilter] = useState<EcosystemFilter>('all')
  const [changeTypeFilter, setChangeTypeFilter] = useState<ChangeTypeFilter>('all')

  const loadRepositories = useCallback(async () => {
    const response = await listWatchedRepositories()
    setRepositories(response.repositories)
    setHealth(response.health)
    setRetention(response.retention)
    return response.repositories
  }, [])

  const loadNotifications = useCallback(async () => {
    const response = await listRepoWatchNotifications({ limit: 20 })
    setNotifications(response.notifications)
    setUnreadCount(response.unread_count)
    setNotificationPolicy(response.policy)
  }, [])

  const loadAdvisories = useCallback(async () => {
    setAdvisoriesLoading(true)
    try {
      const response = await listPackageAdvisories({
        limit: 30,
        repositoryId: repoFilter === 'all' ? null : Number(repoFilter),
        ecosystem: ecosystemFilter,
        status: 'open',
      })
      setAdvisories(response.findings)
      setAdvisoryPolicy(response.policy)
    } finally {
      setAdvisoriesLoading(false)
    }
  }, [repoFilter, ecosystemFilter])

  const loadChanges = useCallback(async () => {
    setChangesLoading(true)
    try {
      const recentChanges = await listDependencyChanges({
        limit: 50,
        repositoryId: repoFilter === 'all' ? null : Number(repoFilter),
        ecosystem: ecosystemFilter,
        changeType: changeTypeFilter,
      })
      setChanges(recentChanges)
    } finally {
      setChangesLoading(false)
    }
  }, [repoFilter, ecosystemFilter, changeTypeFilter])

  const load = useCallback(async () => {
    try {
      await loadRepositories()
      await Promise.all([loadChanges(), loadNotifications(), loadAdvisories()])
    } catch (error) {
      console.error('加载仓库监控数据失败', error)
      toast.error('加载仓库列表失败')
    } finally {
      setLoading(false)
    }
  }, [loadRepositories, loadChanges, loadNotifications, loadAdvisories])

  useEffect(() => {
    // Initial repository/changes sync.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load()
  }, [load])

  useEffect(() => {
    if (loading) return
    // Keep selected repository valid after deletes.
    if (repoFilter !== 'all' && !repositories.some(repo => String(repo.id) === repoFilter)) {
      setRepoFilter('all')
    }
  }, [loading, repositories, repoFilter])

  const handleImport = useCallback(async () => {
    const lines = importText
      .split(/\r\n|\r|\n/)
      .map(line => line.trim())
      .filter(Boolean)

    if (lines.length === 0) {
      toast.error('请粘贴至少一行 owner/repo 或 GitHub URL')
      return
    }

    if (lines.length > 50) {
      toast.error('单次最多导入 50 个仓库')
      return
    }

    setImporting(true)
    try {
      const response = await bulkImportWatchedRepositories(lines)
      setImportResults(response.results)
      setImportText('')
      toast.success(
        `导入完成：新增 ${response.summary.created}，已存在 ${response.summary.already_watched}，无效 ${response.summary.invalid}`
      )
      await load()
    } catch {
      toast.error('批量导入失败')
    } finally {
      setImporting(false)
    }
  }, [importText, load])

  const handleScan = useCallback(
    async (id: number) => {
      setActionId(id)
      try {
        await scanWatchedRepository(id)
        toast.success('依赖快照扫描已排队')
        await load()
      } catch {
        toast.error('触发扫描失败')
      } finally {
        setActionId(null)
      }
    },
    [load]
  )

  const handleRescanUnhealthy = useCallback(async () => {
    setRescanningUnhealthy(true)
    try {
      const response = await scanUnhealthyWatchedRepositories()
      setHealth(response.health)
      setRetention(response.retention)
      toast.success(
        response.queued > 0 ? `已排队重新扫描 ${response.queued} 个仓库` : '没有需要重新扫描的仓库'
      )
      await load()
    } catch {
      toast.error('批量重新扫描失败')
    } finally {
      setRescanningUnhealthy(false)
    }
  }, [load])

  const handleDelete = useCallback(
    async (id: number) => {
      setActionId(id)
      try {
        await deleteWatchedRepository(id)
        toast.success('已取消关注仓库')
        await load()
      } catch {
        toast.error('删除失败')
      } finally {
        setActionId(null)
      }
    },
    [load]
  )

  const handleMarkNotificationRead = useCallback(async (id: number) => {
    try {
      await markRepoWatchNotificationRead(id)
      setNotifications(current =>
        current.map(item =>
          item.id === id ? { ...item, read_at: item.read_at ?? new Date().toISOString() } : item
        )
      )
      setUnreadCount(count => Math.max(0, count - 1))
    } catch {
      toast.error('标记已读失败')
    }
  }, [])

  const handleMarkAllNotificationsRead = useCallback(async () => {
    setMarkingNotifications(true)
    try {
      const response = await markAllRepoWatchNotificationsRead()
      setNotifications(current =>
        current.map(item => ({
          ...item,
          read_at: item.read_at ?? new Date().toISOString(),
        }))
      )
      setUnreadCount(0)
      toast.success(response.marked > 0 ? `已标记 ${response.marked} 条通知为已读` : '没有未读通知')
    } catch {
      toast.error('全部标为已读失败')
    } finally {
      setMarkingNotifications(false)
    }
  }, [])

  const hasActiveFilters =
    repoFilter !== 'all' || ecosystemFilter !== 'all' || changeTypeFilter !== 'all'
  const needsAttention = (health?.failing ?? 0) + (health?.never_scanned ?? 0) > 0

  if (loading) {
    return <div className="text-muted-foreground text-sm">正在加载仓库监控…</div>
  }

  return (
    <div className="space-y-4">
      {health ? (
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="flex items-center gap-2 text-base">
              <Activity className="h-4 w-4" />
              扫描健康
            </CardTitle>
            <CardDescription>
              全局扫描状态一览。失败或从未扫描的仓库可一键重新排队。
              {retention
                ? ` 快照保留：每清单最近 ${retention.snapshot_keep} 份；变更保留 ${retention.change_retention_days} 天。`
                : null}
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            <div className="flex flex-wrap gap-2 text-xs">
              <Badge variant="secondary">合计 {health.total}</Badge>
              <Badge variant="outline">正常 {health.by_status.idle}</Badge>
              <Badge variant="outline">排队 {health.by_status.pending}</Badge>
              <Badge variant="outline">扫描中 {health.by_status.scanning}</Badge>
              <Badge variant={health.failing > 0 ? 'destructive' : 'outline'}>
                失败 {health.failing}
              </Badge>
              <Badge variant={health.never_scanned > 0 ? 'secondary' : 'outline'}>
                未扫描 {health.never_scanned}
              </Badge>
              <Badge variant={health.overdue > 0 ? 'secondary' : 'outline'}>
                逾期 {health.overdue}
              </Badge>
            </div>
            {needsAttention ? (
              <Button
                variant="outline"
                size="sm"
                loading={rescanningUnhealthy}
                onClick={() => void handleRescanUnhealthy()}
              >
                <RefreshCw className="h-4 w-4" />
                重新扫描失败 / 未扫描
              </Button>
            ) : null}
          </CardContent>
        </Card>
      ) : null}

      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="flex items-center gap-2 text-base">
            <Bell className="h-4 w-4" />
            高信号通知
            {unreadCount > 0 ? <Badge variant="destructive">{unreadCount} 未读</Badge> : null}
          </CardTitle>
          <CardDescription>
            默认只提醒 major 升级、依赖移除
            {notificationPolicy?.on_scan_failure ? '、扫描失败' : ''}
            {notificationPolicy?.on_advisory ? '与高危公告' : ''}
            。可选 webhook：
            {notificationPolicy?.webhook_configured ? '已配置' : '未配置（仅应用内）'}。
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          {unreadCount > 0 ? (
            <Button
              variant="outline"
              size="sm"
              loading={markingNotifications}
              onClick={() => void handleMarkAllNotificationsRead()}
            >
              全部标为已读
            </Button>
          ) : null}
          {notifications.length === 0 ? (
            <EmptyState
              variant="compact"
              icon={<Bell className="h-8 w-8" />}
              title="暂无高信号通知"
              description="出现 major 升级、依赖移除或扫描失败时会显示在这里。"
            />
          ) : (
            notifications.map(notification => (
              <div
                key={notification.id}
                className={cn(
                  'rounded-lg border p-3 text-sm',
                  notification.read_at ? 'opacity-70' : 'border-primary/30 bg-muted/30'
                )}
              >
                <div className="flex flex-wrap items-start justify-between gap-2">
                  <div className="min-w-0 space-y-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="font-medium">{notification.title}</span>
                      <Badge
                        variant={
                          notification.type === 'scan_failed' || notification.type === 'package_advisory'
                            ? 'destructive'
                            : 'secondary'
                        }
                      >
                        {notification.type === 'scan_failed'
                          ? '扫描失败'
                          : notification.type === 'package_advisory'
                            ? '安全公告'
                            : '依赖变更'}
                      </Badge>
                      {!notification.read_at ? <Badge variant="outline">未读</Badge> : null}
                    </div>
                    <p className="text-muted-foreground text-xs">{notification.body}</p>
                    <div className="text-muted-foreground text-xs">
                      {formatDateTime(notification.created_at)}
                      {notification.repository ? ` · ${notification.repository.full_name}` : null}
                    </div>
                  </div>
                  {!notification.read_at ? (
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => void handleMarkNotificationRead(notification.id)}
                    >
                      标为已读
                    </Button>
                  ) : null}
                </div>
              </div>
            ))
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-base">批量导入仓库</CardTitle>
          <CardDescription>
            每行一个 owner/repo 或 GitHub URL，一次最多 50 个，便于快速铺到 20–30 仓。
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          <textarea
            value={importText}
            onChange={event => setImportText(event.target.value)}
            rows={6}
            placeholder={'laravel/framework\nvercel/next.js\nhttps://github.com/acme/demo'}
            className={cn(
              'border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring',
              'flex min-h-[140px] w-full rounded-md border px-3 py-2 font-mono text-sm',
              'focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none',
              'disabled:cursor-not-allowed disabled:opacity-50'
            )}
            disabled={importing}
          />
          <div className="flex flex-wrap items-center gap-2">
            <Button loading={importing} onClick={() => void handleImport()}>
              <Upload className="h-4 w-4" />
              导入并排队扫描
            </Button>
            <span className="text-muted-foreground text-xs">
              已填写 {importText.split(/\r\n|\r|\n/).filter(line => line.trim()).length} 行
            </span>
          </div>
          {importResults ? (
            <div className="space-y-2 rounded-lg border p-3 text-sm">
              <div className="font-medium">导入结果</div>
              {importResults.map((result, index) => (
                <div key={`${result.input}-${index}`} className="flex flex-wrap items-center gap-2 text-xs">
                  <Badge variant={result.status === 'created' ? 'secondary' : 'outline'}>
                    {IMPORT_STATUS_LABEL[result.status]}
                  </Badge>
                  <span className="font-mono">{result.input}</span>
                  <span className="text-muted-foreground">{result.message}</span>
                </div>
              ))}
            </div>
          ) : null}
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-base">关注中的仓库</CardTitle>
          <CardDescription>
            多仓库依赖快照扫描与变更检测。失败仓库排在前面；可单仓或批量重新扫描。
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          {repositories.length === 0 ? (
            <EmptyState
              icon={<GitBranch className="h-8 w-8" />}
              title="还没有关注仓库"
              description="在上方粘贴 owner/repo 列表批量导入，或通过「添加仓库」保存依赖。"
            />
          ) : (
            repositories.map(repo => (
              <div key={repo.id} className="flex flex-wrap items-start justify-between gap-3 rounded-lg border p-4">
                <div className="min-w-0 space-y-2">
                  <div className="flex flex-wrap items-center gap-2">
                    <a
                      href={repo.url}
                      target="_blank"
                      rel="noreferrer"
                      className="inline-flex items-center gap-1 font-medium hover:underline"
                    >
                      {repo.full_name}
                      <ExternalLink className="h-3.5 w-3.5" />
                    </a>
                    <Badge variant={repo.scan_status === 'error' ? 'destructive' : 'outline'}>
                      {SCAN_STATUS_LABEL[repo.scan_status]}
                    </Badge>
                    <Badge variant="secondary">{repo.package_count} 依赖</Badge>
                  </div>
                  {repo.description ? (
                    <p className="text-muted-foreground text-sm">{repo.description}</p>
                  ) : null}
                  <div className="text-muted-foreground space-y-1 text-xs">
                    <div>上次扫描：{formatDateTime(repo.last_scanned_at)}</div>
                    <div>下次扫描：{formatDateTime(repo.next_scan_at)}</div>
                    {repo.last_scan_error ? (
                      <div className="text-destructive">错误：{repo.last_scan_error}</div>
                    ) : null}
                  </div>
                </div>
                <div className="flex items-center gap-2">
                  <Button
                    variant="outline"
                    size="sm"
                    loading={actionId === repo.id}
                    onClick={() => void handleScan(repo.id)}
                  >
                    <RefreshCw className="h-4 w-4" />
                    扫描
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    loading={actionId === repo.id}
                    onClick={() => void handleDelete(repo.id)}
                  >
                    <Trash2 className="h-4 w-4" />
                    删除
                  </Button>
                </div>
              </div>
            ))
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="flex items-center gap-2 text-base">
            <ShieldAlert className="h-4 w-4" />
            包安全公告
            {advisories.length > 0 ? <Badge variant="destructive">{advisories.length}</Badge> : null}
          </CardTitle>
          <CardDescription>
            基于最新快照的 lock 版本查询 OSV（不消耗 GitHub 配额）。默认只保留 ≥
            {advisoryPolicy?.min_severity ?? 'high'} 的命中；critical/high 会进入高信号通知。
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          {advisoriesLoading ? (
            <div className="text-muted-foreground text-sm">正在加载安全公告…</div>
          ) : advisories.length === 0 ? (
            <EmptyState
              variant="compact"
              icon={<ShieldAlert className="h-8 w-8" />}
              title="暂无开放公告"
              description="完成仓库扫描后，OSV 会按小时（或扫描后）检查 lock 版本。"
            />
          ) : (
            advisories.map(finding => (
              <div key={finding.id} className="rounded-lg border p-3 text-sm">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-medium">{finding.package_name}</span>
                  <Badge variant="outline">{finding.ecosystem}</Badge>
                  <Badge
                    variant={
                      finding.advisory?.severity === 'critical' || finding.advisory?.severity === 'high'
                        ? 'destructive'
                        : 'secondary'
                    }
                  >
                    {finding.advisory?.severity ?? 'unknown'}
                  </Badge>
                  {finding.repository ? (
                    <span className="text-muted-foreground">{finding.repository.full_name}</span>
                  ) : null}
                </div>
                <div className="text-muted-foreground mt-1 space-y-1 text-xs">
                  <div>
                    版本 {finding.installed_version}
                    {finding.advisory?.fixed_version ? ` → 修复 ${finding.advisory.fixed_version}` : ''}
                    {finding.advisory?.advisory_id ? ` · ${finding.advisory.advisory_id}` : ''}
                  </div>
                  {finding.advisory?.summary ? <div>{finding.advisory.summary}</div> : null}
                  <div className="flex flex-wrap items-center gap-2">
                    <span>{formatDateTime(finding.last_seen_at)}</span>
                    {finding.advisory?.reference_url ? (
                      <a
                        href={finding.advisory.reference_url}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex items-center gap-1 hover:underline"
                      >
                        详情
                        <ExternalLink className="h-3 w-3" />
                      </a>
                    ) : null}
                  </div>
                </div>
              </div>
            ))
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-base">最近依赖变更</CardTitle>
          <CardDescription>
            跨仓库查看清单差异。可用仓库、生态与变更类型筛选，定位 20–30 仓中的噪声。
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          <div className="flex flex-wrap items-center gap-2">
            <Filter className="text-muted-foreground h-3.5 w-3.5" />
            <select
              className={selectClassName}
              value={repoFilter}
              onChange={event => setRepoFilter(event.target.value)}
              disabled={changesLoading}
            >
              <option value="all">全部仓库</option>
              {repositories.map(repo => (
                <option key={repo.id} value={String(repo.id)}>
                  {repo.full_name}
                </option>
              ))}
            </select>
            <select
              className={selectClassName}
              value={ecosystemFilter}
              onChange={event => setEcosystemFilter(event.target.value as EcosystemFilter)}
              disabled={changesLoading}
            >
              <option value="all">全部生态</option>
              <option value="npm">npm</option>
              <option value="composer">composer</option>
            </select>
            <select
              className={selectClassName}
              value={changeTypeFilter}
              onChange={event => setChangeTypeFilter(event.target.value as ChangeTypeFilter)}
              disabled={changesLoading}
            >
              <option value="all">全部类型</option>
              <option value="added">新增</option>
              <option value="updated">更新</option>
              <option value="removed">移除</option>
            </select>
            {hasActiveFilters ? (
              <Button
                variant="ghost"
                size="sm"
                disabled={changesLoading}
                onClick={() => {
                  setRepoFilter('all')
                  setEcosystemFilter('all')
                  setChangeTypeFilter('all')
                }}
              >
                清除筛选
              </Button>
            ) : null}
          </div>

          {changesLoading ? (
            <div className="text-muted-foreground text-sm">正在加载依赖变更…</div>
          ) : changes.length === 0 ? (
            <EmptyState
              variant="compact"
              icon={<Filter className="h-8 w-8" />}
              title={hasActiveFilters ? '没有匹配的依赖变更' : '暂无依赖变更'}
              description={
                hasActiveFilters
                  ? '试试放宽仓库、生态或变更类型筛选。'
                  : '完成至少两次仓库扫描后，清单差异会出现在这里。'
              }
            />
          ) : (
            changes.map(change => (
              <div key={change.id} className="rounded-lg border p-3 text-sm">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-medium">{change.package_name}</span>
                  <Badge variant="outline">{change.ecosystem}</Badge>
                  <Badge
                    variant={
                      change.change_type === 'removed'
                        ? 'destructive'
                        : change.change_type === 'added'
                          ? 'secondary'
                          : 'outline'
                    }
                  >
                    {CHANGE_TYPE_LABEL[change.change_type]}
                  </Badge>
                  {change.repository ? (
                    <span className="text-muted-foreground">{change.repository.full_name}</span>
                  ) : null}
                </div>
                <div className="text-muted-foreground mt-1 space-y-1 text-xs">
                  <div>
                    版本：{change.previous_version ?? '—'} → {change.new_version ?? '—'}
                  </div>
                  <div>
                    约束：{change.previous_constraint ?? '—'} → {change.new_constraint ?? '—'}
                  </div>
                  <div>{formatDateTime(change.detected_at)}</div>
                </div>
              </div>
            ))
          )}
        </CardContent>
      </Card>
    </div>
  )
}
