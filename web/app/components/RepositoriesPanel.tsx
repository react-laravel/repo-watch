'use client'

import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import {
  Bell,
  Clock3,
  Copy,
  Download,
  ExternalLink,
  Filter,
  ShieldAlert,
  Upload,
} from 'lucide-react'
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
  exportDependencyChanges,
  downloadTextFile,
  listFleetActivityDigest,
  listPackageAdvisories,
  listRepoWatchNotifications,
  listWatchedRepositories,
  markAllRepoWatchNotificationsRead,
  markRepoWatchNotificationRead,
  readFleetDigestLastVisit,
  scanUnhealthyWatchedRepositories,
  scanWatchedRepository,
  updateWatchedRepositoryPreferences,
  writeFleetDigestLastVisit,
  type BulkImportRepositoryResult,
  type DependencyChange,
  type DependencyChangeType,
  type Ecosystem,
  type FleetActivityDigest,
  type PackageAdvisoryFinding,
  type PackageAdvisoryPolicy,
  type AdvisorySeverity,
  type RepoWatchNotification,
  type RepoWatchNotificationPolicy,
  type ScanHealthSummary,
  type SnapshotRetentionPolicy,
  type WatchPriority,
  type WatchedRepository,
} from '@/lib/api/repo-watch'
import {
  FIRST_RUN_DIGEST_OPENED_KEY,
  FIRST_RUN_DISMISSED_KEY,
  buildFirstRunChecklist,
  readFirstRunFlag,
  writeFirstRunFlag,
} from '@/lib/repo-watch-first-run'
import { formatDateTime } from './repoWatchUtils'
import FirstRunChecklistCard from './FirstRunChecklistCard'
import RepositoryListCard from './RepositoryListCard'
import ScanHealthOverview from './ScanHealthOverview'

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

const WATCH_PRIORITY_LABEL: Record<WatchPriority, string> = {
  high: '高优先',
  normal: '普通',
  low: '低优先',
}

const ADVISORY_SEVERITY_LABEL: Record<AdvisorySeverity, string> = {
  critical: '严重',
  high: '高',
  moderate: '中',
  low: '低',
  unknown: '未知',
}

type RepoAction = 'mute' | 'scan' | 'delete' | 'priority'

const selectClassName =
  'border-input bg-background h-8 rounded-md border px-2 text-xs disabled:cursor-not-allowed disabled:opacity-50'

type EcosystemFilter = 'all' | Ecosystem
type ChangeTypeFilter = 'all' | DependencyChangeType
type SeverityFilter = 'all' | AdvisorySeverity

export default function RepositoriesPanel() {
  const [repositories, setRepositories] = useState<WatchedRepository[]>([])
  const [health, setHealth] = useState<ScanHealthSummary | null>(null)
  const [retention, setRetention] = useState<SnapshotRetentionPolicy | null>(null)
  const [notifications, setNotifications] = useState<RepoWatchNotification[]>([])
  const [notificationPolicy, setNotificationPolicy] = useState<RepoWatchNotificationPolicy | null>(
    null
  )
  const [unreadCount, setUnreadCount] = useState(0)
  const [markingNotifications, setMarkingNotifications] = useState(false)
  const [advisories, setAdvisories] = useState<PackageAdvisoryFinding[]>([])
  const [advisoryPolicy, setAdvisoryPolicy] = useState<PackageAdvisoryPolicy | null>(null)
  const [advisoriesLoading, setAdvisoriesLoading] = useState(false)
  const [changes, setChanges] = useState<DependencyChange[]>([])
  const [loading, setLoading] = useState(true)
  const [changesLoading, setChangesLoading] = useState(false)
  const [repoAction, setRepoAction] = useState<{ id: number; type: RepoAction } | null>(null)
  const [rescanningUnhealthy, setRescanningUnhealthy] = useState(false)
  const [importText, setImportText] = useState('')
  const [importing, setImporting] = useState(false)
  const [importResults, setImportResults] = useState<BulkImportRepositoryResult[] | null>(null)
  const [repoFilter, setRepoFilter] = useState<string>('all')
  const [ecosystemFilter, setEcosystemFilter] = useState<EcosystemFilter>('all')
  const [changeTypeFilter, setChangeTypeFilter] = useState<ChangeTypeFilter>('all')
  const [includeMuted, setIncludeMuted] = useState(false)
  const [severityFilter, setSeverityFilter] = useState<SeverityFilter>('all')
  const [digest, setDigest] = useState<FleetActivityDigest | null>(null)
  const [digestLoading, setDigestLoading] = useState(false)
  const [digestCursor, setDigestCursor] = useState<string | null>(null)
  const [exporting, setExporting] = useState<'csv' | 'summary' | null>(null)
  const [firstRunDismissed, setFirstRunDismissed] = useState(() =>
    readFirstRunFlag(FIRST_RUN_DISMISSED_KEY)
  )
  const [firstRunDigestOpened, setFirstRunDigestOpened] = useState(() =>
    readFirstRunFlag(FIRST_RUN_DIGEST_OPENED_KEY)
  )

  const notificationRequest = useRef(0)
  const advisoryRequest = useRef(0)
  const changeRequest = useRef(0)
  const pollCount = useRef(0)
  const skipFilterReload = useRef(true)

  const loadRepositories = useCallback(async () => {
    const response = await listWatchedRepositories()
    setRepositories(response.repositories)
    setHealth(response.health)
    setRetention(response.retention)
    return response.repositories
  }, [])

  // Derive a valid filter so deleting the selected repo does not need setState-in-effect.
  const effectiveRepoFilter = useMemo(() => {
    if (repoFilter === 'all') {
      return 'all'
    }

    return repositories.some(repo => String(repo.id) === repoFilter) ? repoFilter : 'all'
  }, [repositories, repoFilter])

  const loadNotifications = useCallback(async () => {
    const requestId = ++notificationRequest.current
    const response = await listRepoWatchNotifications({
      limit: 20,
      repositoryId: effectiveRepoFilter === 'all' ? null : Number(effectiveRepoFilter),
      includeMuted,
    })
    if (requestId !== notificationRequest.current) return
    setNotifications(response.notifications)
    setUnreadCount(response.unread_count)
    setNotificationPolicy(response.policy)
  }, [effectiveRepoFilter, includeMuted])

  const loadDigest = useCallback(async () => {
    setDigestLoading(true)
    try {
      const lastVisit = readFleetDigestLastVisit()
      setDigestCursor(lastVisit)
      const response = await listFleetActivityDigest({ since: lastVisit })
      setDigest(response)
    } finally {
      setDigestLoading(false)
    }
  }, [])

  const loadAdvisories = useCallback(async () => {
    const requestId = ++advisoryRequest.current
    setAdvisoriesLoading(true)
    try {
      const response = await listPackageAdvisories({
        limit: 30,
        repositoryId: effectiveRepoFilter === 'all' ? null : Number(effectiveRepoFilter),
        ecosystem: ecosystemFilter,
        severity: severityFilter,
        status: 'open',
        includeMuted,
      })
      if (requestId !== advisoryRequest.current) return
      setAdvisories(response.findings)
      setAdvisoryPolicy(response.policy)
    } finally {
      if (requestId === advisoryRequest.current) setAdvisoriesLoading(false)
    }
  }, [effectiveRepoFilter, ecosystemFilter, severityFilter, includeMuted])

  const loadChanges = useCallback(async () => {
    const requestId = ++changeRequest.current
    setChangesLoading(true)
    try {
      const recentChanges = await listDependencyChanges({
        limit: 50,
        repositoryId: effectiveRepoFilter === 'all' ? null : Number(effectiveRepoFilter),
        ecosystem: ecosystemFilter,
        changeType: changeTypeFilter,
        includeMuted,
      })
      if (requestId !== changeRequest.current) return
      setChanges(recentChanges)
    } finally {
      if (requestId === changeRequest.current) setChangesLoading(false)
    }
  }, [effectiveRepoFilter, ecosystemFilter, changeTypeFilter, includeMuted])

  const load = useCallback(async () => {
    try {
      await loadRepositories()
      await Promise.all([loadChanges(), loadNotifications(), loadAdvisories(), loadDigest()])
    } catch (error) {
      console.error('加载仓库监控数据失败', error)
      toast.error('加载仓库列表失败')
    } finally {
      setLoading(false)
    }
  }, [loadRepositories, loadChanges, loadNotifications, loadAdvisories, loadDigest])

  useEffect(() => {
    // Initial repository/changes sync. Later filter changes reload only the filtered lists.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  useEffect(() => {
    if (skipFilterReload.current) {
      skipFilterReload.current = false
      return
    }
    void Promise.all([loadChanges(), loadAdvisories(), loadNotifications()]).catch(error => {
      console.error('加载筛选结果失败', error)
      toast.error('加载筛选结果失败')
    })
  }, [loadChanges, loadAdvisories, loadNotifications])

  useEffect(() => {
    const active = repositories.some(
      repo => repo.scan_status === 'pending' || repo.scan_status === 'scanning'
    )
    if (!active || pollCount.current > 24) {
      if (!active) pollCount.current = 0
      return
    }

    const timer = window.setInterval(() => {
      pollCount.current += 1
      if (pollCount.current > 24) {
        window.clearInterval(timer)
        return
      }
      void loadRepositories()
    }, 8000)

    return () => window.clearInterval(timer)
  }, [repositories, loadRepositories])

  useEffect(() => {
    const markVisited = () => {
      writeFleetDigestLastVisit()
    }
    window.addEventListener('pagehide', markVisited)
    return () => {
      window.removeEventListener('pagehide', markVisited)
    }
  }, [])

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
      const duplicate = response.summary.duplicate_in_request ?? 0
      toast.success(
        `导入完成：新增 ${response.summary.created}，已存在 ${response.summary.already_watched}${
          duplicate > 0 ? `，请求重复 ${duplicate}` : ''
        }，无效 ${response.summary.invalid}`
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
      pollCount.current = 0
      setRepoAction({ id, type: 'scan' })
      try {
        await scanWatchedRepository(id)
        toast.success('依赖快照扫描已排队')
        await load()
      } catch {
        toast.error('触发扫描失败')
      } finally {
        setRepoAction(null)
      }
    },
    [load]
  )

  const handleRescanUnhealthy = useCallback(async () => {
    pollCount.current = 0
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

  const handleToggleMute = useCallback(
    async (repo: WatchedRepository) => {
      setRepoAction({ id: repo.id, type: 'mute' })
      try {
        await updateWatchedRepositoryPreferences(repo.id, {
          muted: !repo.muted,
        })
        toast.success(repo.muted ? '已恢复通知' : '已静音（仍扫描，不推送高信号）')
        await load()
      } catch {
        toast.error('更新静音状态失败')
      } finally {
        setRepoAction(null)
      }
    },
    [load]
  )

  const handlePriorityChange = useCallback(
    async (repo: WatchedRepository, watchPriority: WatchPriority) => {
      if (repo.watch_priority === watchPriority) {
        return
      }

      setRepoAction({ id: repo.id, type: 'priority' })
      try {
        await updateWatchedRepositoryPreferences(repo.id, {
          watch_priority: watchPriority,
        })
        toast.success(`优先级已设为「${WATCH_PRIORITY_LABEL[watchPriority]}」`)
        await load()
      } catch {
        toast.error('更新优先级失败')
      } finally {
        setRepoAction(null)
      }
    },
    [load]
  )

  const handleDelete = useCallback(
    async (id: number) => {
      const repo = repositories.find(item => item.id === id)
      if (!repo) return
      if (
        !window.confirm(
          `确认取消关注「${repo.full_name}」？该仓库下的依赖关注会一并删除。`
        )
      ) {
        return
      }
      setRepoAction({ id: repo.id, type: 'delete' })
      try {
        await deleteWatchedRepository(repo.id)
        toast.success('已取消关注仓库')
        await load()
      } catch {
        toast.error('删除失败')
      } finally {
        setRepoAction(null)
      }
    },
    [load, repositories]
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

  const focusSection = useCallback(
    (
      section: 'notifications' | 'advisories' | 'changes' | 'import' | 'digest',
      repositoryId?: number
    ) => {
      if (repositoryId) {
        setRepoFilter(String(repositoryId))
      }
      if (section === 'changes') {
        setChangeTypeFilter('all')
      }
      requestAnimationFrame(() => {
        document.getElementById(`repo-watch-${section}`)?.scrollIntoView({
          behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches
            ? 'auto'
            : 'smooth',
          block: 'start',
        })
      })
    },
    []
  )

  const handleOpenFirstRunDigest = useCallback(() => {
    writeFirstRunFlag(FIRST_RUN_DIGEST_OPENED_KEY, true)
    setFirstRunDigestOpened(true)
    focusSection('digest')
  }, [focusSection])

  const handleDismissFirstRun = useCallback(() => {
    writeFirstRunFlag(FIRST_RUN_DISMISSED_KEY, true)
    setFirstRunDismissed(true)
  }, [])

  const handleExportChanges = useCallback(
    async (format: 'csv' | 'summary') => {
      setExporting(format)
      try {
        const payload = await exportDependencyChanges({
          format,
          since: digestCursor ?? digest?.since ?? null,
          repositoryId: effectiveRepoFilter === 'all' ? null : Number(effectiveRepoFilter),
          ecosystem: ecosystemFilter,
          changeType: changeTypeFilter,
          includeMuted,
          limit: 500,
        })

        if (format === 'csv') {
          downloadTextFile(payload.filename, payload.content, 'text/csv;charset=utf-8')
          toast.success(
            payload.truncated
              ? `已导出 ${payload.row_count} 条（已截断）`
              : `已导出 ${payload.row_count} 条 CSV`
          )
          return
        }

        if (typeof navigator !== 'undefined' && navigator.clipboard?.writeText) {
          await navigator.clipboard.writeText(payload.content)
          toast.success(
            payload.row_count > 0
              ? `已复制 ${payload.row_count} 条变更摘要`
              : '已复制空摘要（该时间窗无变更）'
          )
        } else {
          downloadTextFile(payload.filename, payload.content, 'text/plain;charset=utf-8')
          toast.success('当前环境不支持剪贴板，已改为下载文本文件')
        }
      } catch {
        toast.error(format === 'csv' ? '导出 CSV 失败' : '复制摘要失败')
      } finally {
        setExporting(null)
      }
    },
    [changeTypeFilter, digest, digestCursor, ecosystemFilter, effectiveRepoFilter, includeMuted]
  )

  const hasActiveFilters =
    effectiveRepoFilter !== 'all' ||
    ecosystemFilter !== 'all' ||
    changeTypeFilter !== 'all' ||
    includeMuted
  const hasAdvisoryFilters = severityFilter !== 'all' || hasActiveFilters
  const listFiltersActive = hasAdvisoryFilters
  const filtersBusy = changesLoading || advisoriesLoading
  const firstRunChecklist = useMemo(
    () =>
      buildFirstRunChecklist({
        repositoryCount: health?.total ?? repositories.length,
        neverScanned: health?.never_scanned ?? 0,
        pendingOrScanning: (health?.by_status.pending ?? 0) + (health?.by_status.scanning ?? 0),
        digestOpened: firstRunDigestOpened,
        hasDigestLastVisit: Boolean(digestCursor),
        dismissed: firstRunDismissed,
      }),
    [digestCursor, firstRunDigestOpened, firstRunDismissed, health, repositories.length]
  )

  if (loading) {
    return (
      <div role="status" className="space-y-4">
        <p className="text-muted-foreground text-sm">正在加载仓库监控…</p>
        <div aria-hidden className="grid grid-cols-2 gap-3 sm:grid-cols-3">
          {[0, 1, 2].map(key => (
            <div
              key={key}
              className="bg-muted h-28 animate-pulse rounded-2xl motion-reduce:animate-none"
            />
          ))}
        </div>
        <div
          aria-hidden
          className="bg-muted h-56 animate-pulse rounded-2xl motion-reduce:animate-none"
        />
      </div>
    )
  }

  return (
    <div className="min-w-0 space-y-6">
      <FirstRunChecklistCard
        checklist={firstRunChecklist}
        rescanning={rescanningUnhealthy}
        onImport={() => focusSection('import')}
        onScan={() => void handleRescanUnhealthy()}
        onOpenDigest={handleOpenFirstRunDigest}
        onDismiss={handleDismissFirstRun}
      />

      {health && (
        <ScanHealthOverview
          health={health}
          retention={retention}
          rescanning={rescanningUnhealthy}
          onRescan={() => void handleRescanUnhealthy()}
        />
      )}

      <nav aria-label="仓库监控区块" className="flex flex-wrap gap-2 text-xs">
        {[
          ['repositories', '关注仓库'],
          ['digest', '最近活动'],
          ['changes', '依赖变更'],
          ['advisories', '安全公告'],
          ['notifications', '通知'],
          ['import', '批量导入'],
        ].map(([id, label]) => (
          <a
            key={id}
            href={`#repo-watch-${id}`}
            className="bg-card hover:bg-muted rounded-full border px-3 py-2 transition-colors"
          >
            {label}
          </a>
        ))}
      </nav>

      <RepositoryListCard
        repositories={repositories}
        actionId={repoAction?.id ?? null}
        onPriorityChange={(repo, priority) => void handlePriorityChange(repo, priority)}
        onToggleMute={repo => void handleToggleMute(repo)}
        onScan={id => void handleScan(id)}
        onDelete={id => void handleDelete(id)}
        onImport={() => focusSection('import')}
      />

      <Card id="repo-watch-digest" className="scroll-mt-6 min-w-0 overflow-hidden rounded-2xl">
        <CardHeader className="pb-3">
          <CardTitle className="flex items-center gap-2 text-base">
            <Clock3 className="h-4 w-4" />
            最近活动
            {digest &&
            digest.totals.dependency_changes +
              digest.totals.notifications +
              digest.totals.advisories_new >
              0 ? (
              <Badge variant="secondary">
                {digest.totals.dependency_changes +
                  digest.totals.notifications +
                  digest.totals.advisories_new}
              </Badge>
            ) : null}
          </CardTitle>
          <CardDescription>
            {digestCursor
              ? `自上次查看（${formatDateTime(digestCursor)}）起的舰队摘要`
              : `默认最近 ${digest?.policy.default_hours ?? 24} 小时；下次打开将使用本地 last-visit 游标`}
            。顶部展示关注中 / 活跃 / 静音仓数，以及活跃 vs 静音活动拆分。仅查本地库，不消耗 GitHub
            配额。
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          {digestLoading && !digest ? (
            <div className="text-muted-foreground text-sm">正在汇总最近活动…</div>
          ) : digest ? (
            <>
              <div className="flex flex-wrap gap-2 text-xs">
                <Badge variant="secondary">关注 {digest.fleet.watched}</Badge>
                <Badge variant="outline">活跃 {digest.fleet.active}</Badge>
                <Badge variant={digest.fleet.muted > 0 ? 'secondary' : 'outline'}>
                  静音 {digest.fleet.muted}
                </Badge>
                <Badge variant="outline">窗口 {digest.window_hours}h</Badge>
              </div>
              <div className="flex flex-wrap gap-2 text-xs">
                <button
                  type="button"
                  className="inline-flex"
                  onClick={() => focusSection('changes')}
                >
                  <Badge variant="outline">
                    依赖变更 {digest.totals.dependency_changes}
                    {digest.fleet.muted > 0
                      ? ` · 活跃 ${digest.totals.active.dependency_changes} / 静音 ${digest.totals.muted.dependency_changes}`
                      : ''}
                  </Badge>
                </button>
                <button
                  type="button"
                  className="inline-flex"
                  onClick={() => focusSection('notifications')}
                >
                  <Badge
                    variant={digest.totals.unread_notifications > 0 ? 'destructive' : 'outline'}
                  >
                    通知 {digest.totals.notifications}
                    {digest.totals.unread_notifications > 0
                      ? ` · ${digest.totals.unread_notifications} 未读`
                      : ''}
                    {digest.fleet.muted > 0
                      ? ` · 活跃 ${digest.totals.active.notifications} / 静音 ${digest.totals.muted.notifications}`
                      : ''}
                  </Badge>
                </button>
                <button
                  type="button"
                  className="inline-flex"
                  onClick={() => focusSection('advisories')}
                >
                  <Badge variant={digest.totals.advisories_new > 0 ? 'secondary' : 'outline'}>
                    新公告 {digest.totals.advisories_new}
                    {digest.fleet.muted > 0
                      ? ` · 活跃 ${digest.totals.active.advisories_new} / 静音 ${digest.totals.muted.advisories_new}`
                      : ''}
                  </Badge>
                </button>
              </div>
              {digest.by_repository.length === 0 ? (
                <div className="text-muted-foreground text-sm">该时间窗内暂无仓库级活动。</div>
              ) : (
                <div className="space-y-2">
                  {digest.by_repository.map(row => (
                    <div
                      key={row.id}
                      className="flex flex-wrap items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm"
                    >
                      <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                          <div className="truncate font-medium">{row.full_name}</div>
                          {row.muted ? <Badge variant="outline">已静音</Badge> : null}
                          {row.watch_priority !== 'normal' ? (
                            <Badge variant="secondary">
                              {WATCH_PRIORITY_LABEL[row.watch_priority]}
                            </Badge>
                          ) : null}
                        </div>
                        <div className="text-muted-foreground text-xs">
                          变更 {row.dependency_changes}
                          {row.change_types.added ? ` · +${row.change_types.added}` : ''}
                          {row.change_types.updated ? ` · ~${row.change_types.updated}` : ''}
                          {row.change_types.removed ? ` · -${row.change_types.removed}` : ''}
                          {' · '}通知 {row.notifications}
                          {' · '}公告 {row.advisories_new}
                        </div>
                      </div>
                      <div className="flex flex-wrap gap-1">
                        <Button
                          variant="ghost"
                          size="sm"
                          className="h-7 px-2 text-xs"
                          onClick={() => focusSection('changes', row.id)}
                        >
                          变更
                        </Button>
                        <Button
                          variant="ghost"
                          size="sm"
                          className="h-7 px-2 text-xs"
                          onClick={() => focusSection('notifications', row.id)}
                        >
                          通知
                        </Button>
                        <Button
                          variant="ghost"
                          size="sm"
                          className="h-7 px-2 text-xs"
                          onClick={() => focusSection('advisories', row.id)}
                        >
                          公告
                        </Button>
                      </div>
                    </div>
                  ))}
                  {digest.repositories_capped ? (
                    <div className="text-muted-foreground text-xs">
                      已截断至 {digest.policy.max_repositories} 个仓库（按活动量排序）。
                    </div>
                  ) : null}
                </div>
              )}
            </>
          ) : (
            <div className="text-muted-foreground text-sm">暂无活动摘要。</div>
          )}
        </CardContent>
      </Card>

      <Card
        id="repo-watch-notifications"
        className="scroll-mt-6 min-w-0 overflow-hidden rounded-2xl"
      >
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
                          notification.type === 'scan_failed' ||
                          notification.type === 'package_advisory'
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

      <Card id="repo-watch-import" className="scroll-mt-6 min-w-0 overflow-hidden rounded-2xl">
        <CardHeader className="pb-3">
          <CardTitle className="text-base">批量导入仓库</CardTitle>
          <CardDescription>
            每行一个 owner/repo 或 GitHub URL，一次最多 50 个，便于快速铺到 20–30 仓。
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          <textarea
            aria-label="批量导入仓库地址"
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
                <div
                  key={`${result.input}-${index}`}
                  className="flex flex-wrap items-center gap-2 text-xs"
                >
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

      <Card className="scroll-mt-6 min-w-0 overflow-hidden rounded-2xl">
        <CardHeader className="pb-3">
          <CardTitle className="flex items-center gap-2 text-base">
            <Filter className="h-4 w-4" />
            公告与变更筛选
          </CardTitle>
          <CardDescription>
            仓库、生态和静音同时作用于下面的公告、通知和变更。严重度只影响公告，变更类型只影响变更列表。
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="flex flex-wrap items-center gap-2">
            <select
              className={selectClassName}
              value={effectiveRepoFilter}
              onChange={event => setRepoFilter(event.target.value)}
              disabled={filtersBusy}
              aria-label="仓库筛选"
            >
              <option value="all">全部仓库</option>
              {repositories.map(repo => (
                <option key={repo.id} value={String(repo.id)}>
                  {repo.full_name}
                  {repo.muted ? '（已静音）' : ''}
                </option>
              ))}
            </select>
            <select
              className={selectClassName}
              value={ecosystemFilter}
              onChange={event => setEcosystemFilter(event.target.value as EcosystemFilter)}
              disabled={filtersBusy}
              aria-label="生态筛选"
            >
              <option value="all">全部生态</option>
              <option value="npm">npm</option>
              <option value="composer">composer</option>
            </select>
            <select
              className={selectClassName}
              value={changeTypeFilter}
              onChange={event => setChangeTypeFilter(event.target.value as ChangeTypeFilter)}
              disabled={filtersBusy}
              aria-label="变更类型筛选"
            >
              <option value="all">全部类型</option>
              <option value="added">新增</option>
              <option value="updated">更新</option>
              <option value="removed">移除</option>
            </select>
            <select
              className={selectClassName}
              value={severityFilter}
              onChange={event => setSeverityFilter(event.target.value as SeverityFilter)}
              disabled={filtersBusy}
              aria-label="公告严重度筛选"
            >
              <option value="all">全部严重度</option>
              {(Object.keys(ADVISORY_SEVERITY_LABEL) as AdvisorySeverity[]).map(level => (
                <option key={level} value={level}>
                  {ADVISORY_SEVERITY_LABEL[level]}
                </option>
              ))}
            </select>
            <label className="text-muted-foreground flex items-center gap-1.5 text-xs">
              <input
                type="checkbox"
                className="border-input size-3.5 rounded"
                checked={includeMuted}
                disabled={filtersBusy}
                onChange={event => setIncludeMuted(event.target.checked)}
              />
              显示已静音
            </label>
            {listFiltersActive ? (
              <Button
                variant="ghost"
                size="sm"
                disabled={filtersBusy}
                onClick={() => {
                  setRepoFilter('all')
                  setEcosystemFilter('all')
                  setChangeTypeFilter('all')
                  setSeverityFilter('all')
                  setIncludeMuted(false)
                }}
              >
                清除筛选
              </Button>
            ) : null}
            <div className="ml-auto flex flex-wrap gap-2">
              <Button
                variant="outline"
                size="sm"
                disabled={filtersBusy || exporting !== null}
                loading={exporting === 'summary'}
                onClick={() => void handleExportChanges('summary')}
              >
                <Copy className="h-4 w-4" />
                复制摘要
              </Button>
              <Button
                variant="outline"
                size="sm"
                disabled={filtersBusy || exporting !== null}
                loading={exporting === 'csv'}
                onClick={() => void handleExportChanges('csv')}
              >
                <Download className="h-4 w-4" />
                导出 CSV
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>

      <Card id="repo-watch-advisories" className="scroll-mt-6 min-w-0 overflow-hidden rounded-2xl">
        <CardHeader className="pb-3">
          <CardTitle className="flex items-center gap-2 text-base">
            <ShieldAlert className="h-4 w-4" />
            包安全公告
            {advisories.length > 0 ? (
              <Badge variant="destructive">{advisories.length}</Badge>
            ) : null}
          </CardTitle>
          <CardDescription>
            基于最新快照的 lock 版本查询 OSV（不消耗 GitHub 配额）
            {advisoryPolicy?.ghsa_enrichment_enabled
              ? '；可选 GHSA 二次富化在有 PAT 且未触达速率地板时补充 GHSA id / 严重度 / 链接'
              : ''}
            。默认只保留 ≥{advisoryPolicy?.min_severity ?? 'high'} 的命中。严重和高危会进入高信号通知。仓库、生态、严重度和静音使用上方的筛选。
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          {advisoriesLoading ? (
            <div className="text-muted-foreground text-sm">正在加载安全公告…</div>
          ) : advisories.length === 0 ? (
            <EmptyState
              variant="compact"
              icon={<ShieldAlert className="h-8 w-8" />}
              title={hasAdvisoryFilters ? '没有匹配的安全公告' : '暂无开放公告'}
              description={
                hasAdvisoryFilters
                  ? '试试放宽上方的仓库、生态、严重度或静音筛选。'
                  : '完成仓库扫描后，OSV 会按小时（或扫描后）检查 lock 版本。'
              }
            />
          ) : (
            advisories.map(finding => (
              <div key={finding.id} className="rounded-lg border p-3 text-sm">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-medium">{finding.package_name}</span>
                  <Badge variant="outline">{finding.ecosystem}</Badge>
                  <Badge
                    variant={
                      finding.advisory?.severity === 'critical' ||
                      finding.advisory?.severity === 'high'
                        ? 'destructive'
                        : 'secondary'
                    }
                  >
                    {ADVISORY_SEVERITY_LABEL[finding.advisory?.severity ?? 'unknown']}
                  </Badge>
                  {finding.advisory?.ghsa_enriched_at ? (
                    <Badge variant="outline">GHSA</Badge>
                  ) : null}
                  {finding.repository ? (
                    <>
                      <span className="text-muted-foreground">{finding.repository.full_name}</span>
                      {finding.repository.muted ? <Badge variant="outline">已静音</Badge> : null}
                    </>
                  ) : null}
                </div>
                <div className="text-muted-foreground mt-1 space-y-1 text-xs">
                  <div>
                    版本 {finding.installed_version}
                    {finding.advisory?.fixed_version
                      ? ` → 修复 ${finding.advisory.fixed_version}`
                      : ''}
                    {finding.advisory?.advisory_id ? ` · ${finding.advisory.advisory_id}` : ''}
                    {finding.advisory?.ghsa_id &&
                    finding.advisory.ghsa_id.toLowerCase() !==
                      finding.advisory.advisory_id.toLowerCase()
                      ? ` · ${finding.advisory.ghsa_id}`
                      : ''}
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

      <Card id="repo-watch-changes" className="scroll-mt-6 min-w-0 overflow-hidden rounded-2xl">
        <CardHeader className="pb-3">
          <CardTitle className="text-base">最近依赖变更</CardTitle>
          <CardDescription>
            跨仓库查看清单差异。默认隐藏已静音仓库。导出沿用上方筛选和活动摘要的时间窗。
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          {changesLoading ? (
            <div className="text-muted-foreground text-sm">正在加载依赖变更…</div>
          ) : changes.length === 0 ? (
            <EmptyState
              variant="compact"
              icon={<Filter className="h-8 w-8" />}
              title={hasActiveFilters ? '没有匹配的依赖变更' : '暂无依赖变更'}
              description={
                hasActiveFilters
                  ? '试试放宽仓库、生态、变更类型，或打开「显示已静音」。'
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
                    <>
                      <span className="text-muted-foreground">{change.repository.full_name}</span>
                      {change.repository.muted ? <Badge variant="outline">已静音</Badge> : null}
                    </>
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
