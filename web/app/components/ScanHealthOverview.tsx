import { Activity, AlertCircle, Clock3, FolderGit2, RefreshCw, ScanLine } from 'lucide-react'
import { Button } from '@/components/ui/button'
import type { ScanHealthSummary, SnapshotRetentionPolicy } from '@/lib/api/repo-watch'

export default function ScanHealthOverview({
  health,
  retention,
  rescanning,
  onRescan,
}: {
  health: ScanHealthSummary
  retention: SnapshotRetentionPolicy | null
  rescanning: boolean
  onRescan: () => void
}) {
  const metrics = [
    {
      label: '关注仓库',
      value: health.total,
      icon: FolderGit2,
      caption: '所有已添加的仓库',
      tone: 'text-foreground',
    },
    {
      label: '执行中 / 排队',
      value: health.by_status.scanning + health.by_status.pending,
      icon: Activity,
      caption: `${health.by_status.scanning} 扫描中 · ${health.by_status.pending} 等待`,
      tone: 'text-blue-600 dark:text-blue-400',
    },
    {
      label: '扫描失败',
      value: health.failing,
      icon: AlertCircle,
      caption: '需要检查或重新扫描',
      tone: health.failing ? 'text-destructive' : 'text-foreground',
    },
    {
      label: '尚未扫描',
      value: health.never_scanned,
      icon: ScanLine,
      caption: '等待首次依赖快照',
      tone: 'text-foreground',
    },
    {
      label: '扫描逾期',
      value: health.overdue,
      icon: Clock3,
      caption: '已超过计划扫描时间',
      tone: health.overdue ? 'text-amber-700 dark:text-amber-400' : 'text-foreground',
    },
  ]
  return (
    <section aria-labelledby="scan-health-heading" className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 id="scan-health-heading" className="text-lg font-semibold">
            扫描概览
          </h2>
          <p className="text-muted-foreground mt-1 text-sm">先定位需要关注的仓库，再查看变化。</p>
        </div>
        {(health.failing > 0 || health.never_scanned > 0) && (
          <Button variant="outline" size="sm" loading={rescanning} onClick={onRescan}>
            <RefreshCw aria-hidden className="size-4" />
            重新扫描失败 / 未扫描
          </Button>
        )}
      </div>
      <dl className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
        {metrics.map(({ label, value, icon: Icon, caption, tone }) => (
          <div key={label} className="bg-card rounded-2xl border p-4 shadow-sm">
            <dt className="text-muted-foreground flex items-center justify-between gap-2 text-xs">
              <span>{label}</span>
              <Icon aria-hidden className="size-4 shrink-0" />
            </dt>
            <dd className={`mt-3 text-3xl font-semibold tracking-tight tabular-nums ${tone}`}>
              {value}
            </dd>
            <p className="text-muted-foreground mt-2 text-xs leading-5">{caption}</p>
          </div>
        ))}
      </dl>
      {retention && (
        <p className="text-muted-foreground text-xs">
          快照：每份清单保留最近 {retention.snapshot_keep} 份 · 依赖变更保留{' '}
          {retention.change_retention_days} 天
        </p>
      )}
    </section>
  )
}
