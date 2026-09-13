'use client'

import { useCallback, useEffect, useState } from 'react'
import { ExternalLink, GitBranch, RefreshCw, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { EmptyState } from '@/components/ui/empty-state'
import {
  deleteWatchedRepository,
  listDependencyChanges,
  listWatchedRepositories,
  scanWatchedRepository,
  type DependencyChange,
  type WatchedRepository,
} from '@/lib/api/repo-watch'
import { formatDateTime } from './repoWatchUtils'

const changeTypeLabel: Record<DependencyChange['change_type'], string> = {
  added: '新增',
  removed: '移除',
  updated: '更新',
}

export default function RepositoriesPanel() {
  const [repositories, setRepositories] = useState<WatchedRepository[]>([])
  const [changes, setChanges] = useState<DependencyChange[]>([])
  const [loading, setLoading] = useState(true)
  const [actionId, setActionId] = useState<number | null>(null)

  const load = useCallback(async () => {
    try {
      const [repos, recentChanges] = await Promise.all([
        listWatchedRepositories(),
        listDependencyChanges(30),
      ])
      setRepositories(repos)
      setChanges(recentChanges)
    } catch (error) {
      console.error('加载仓库监控数据失败', error)
      toast.error('加载仓库列表失败')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    // Initial repository/changes sync.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load()
  }, [load])

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

  if (loading) {
    return <div className="text-muted-foreground text-sm">正在加载仓库监控…</div>
  }

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-base">关注中的仓库</CardTitle>
          <CardDescription>
            多仓库依赖快照扫描与变更检测。保存依赖或添加仓库后会自动排队扫描。
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          {repositories.length === 0 ? (
            <EmptyState
              icon={<GitBranch className="h-8 w-8" />}
              title="还没有关注仓库"
              description="通过「添加仓库」保存依赖后，会自动创建对应的仓库监控记录。"
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
                    <Badge variant="outline">{repo.scan_status}</Badge>
                    <Badge variant="secondary">{repo.package_count} 依赖</Badge>
                  </div>
                  {repo.description ? (
                    <p className="text-muted-foreground text-sm">{repo.description}</p>
                  ) : null}
                  <div className="text-muted-foreground space-y-1 text-xs">
                    <div>上次扫描：{formatDateTime(repo.last_scanned_at)}</div>
                    <div>下次扫描：{formatDateTime(repo.next_scan_at)}</div>
                    {repo.last_scan_error ? <div className="text-destructive">错误：{repo.last_scan_error}</div> : null}
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
          <CardTitle className="text-base">最近依赖变更</CardTitle>
          <CardDescription>仓库清单（composer/npm）相对上次快照的新增、更新与移除。</CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          {changes.length === 0 ? (
            <div className="text-muted-foreground text-sm">暂无检测到依赖变更。完成至少两次扫描后会出现差异。</div>
          ) : (
            changes.map(change => (
              <div key={change.id} className="rounded-lg border p-3 text-sm">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-medium">{change.package_name}</span>
                  <Badge variant="outline">{change.ecosystem}</Badge>
                  <Badge variant="secondary">{changeTypeLabel[change.change_type]}</Badge>
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
