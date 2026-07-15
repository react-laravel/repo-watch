'use client'

import { useCallback, useEffect, useMemo, useState } from 'react'
import { FolderGit2, Plus, Search } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { EmptyState } from '@/components/ui/empty-state'
import {
  deleteWatchedPackage,
  deleteWatchedPackages,
  listWatchedPackages,
  previewRepoDependencies,
  refreshWatchedPackage,
  saveWatchedPackages,
  type RepoDependencyPreview,
  type WatchedPackage,
  type WatchLevel,
} from '@/lib/api/repo-watch'
import { repoKeyOf, repoLabelOf } from './repoWatchUtils'
import PackageListPanel from './PackageListPanel'
import DependencyPreview from './DependencyPreview'
import RepoSettingsPanel from './RepoSettingsPanel'
import type { SelectedDependency, RepoSettingsPreview } from './types'

type VersionFilter = 'all' | WatchLevel

const DEFAULT_SAVE_LEVEL: WatchLevel = 'minor'

type RepoWatchToolProps = {
  showAddPanel: boolean
  setShowAddPanel: (value: boolean | ((prev: boolean) => boolean)) => void
  toolView: 'packages' | 'repo-settings'
  setToolView: (view: 'packages' | 'repo-settings') => void
}

export default function RepoWatchTool({
  showAddPanel,
  setShowAddPanel,
  toolView,
  setToolView,
}: RepoWatchToolProps) {
  const [url, setUrl] = useState('')
  const [preview, setPreview] = useState<RepoDependencyPreview | null>(null)
  const [dependencies, setDependencies] = useState<SelectedDependency[]>([])
  const [watchedPackages, setWatchedPackages] = useState<WatchedPackage[]>([])
  const [loadingList, setLoadingList] = useState(true)
  const [analyzing, setAnalyzing] = useState(false)
  const [saving, setSaving] = useState(false)
  const [activeAction, setActiveAction] = useState<{
    id: number
    type: 'refresh' | 'cancel'
  } | null>(null)
  const [versionFilter, setVersionFilter] = useState<VersionFilter>('all')
  const [selectedRepoKey, setSelectedRepoKey] = useState<string>('all')
  const [repoSettingsPreview, setRepoSettingsPreview] = useState<RepoSettingsPreview | null>(null)
  const [repoSettingsActionKey, setRepoSettingsActionKey] = useState<string | null>(null)

  // Load watched packages
  const loadWatchedPackages = useCallback(async () => {
    try {
      const data = await listWatchedPackages()
      setWatchedPackages(data)
    } catch (error) {
      console.error('加载依赖关注列表失败', error)
    } finally {
      setLoadingList(false)
    }
  }, [])

  useEffect(() => {
    // Initial data synchronization intentionally updates local request state.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void loadWatchedPackages()
  }, [loadWatchedPackages])

  // Repo options
  const repoOptions = useMemo(() => {
    return [
      'all',
      ...Array.from(new Set(watchedPackages.map(item => repoKeyOf(item)))).sort((a, b) =>
        a.localeCompare(b)
      ),
    ]
  }, [watchedPackages])

  useEffect(() => {
    // Keep the selected repository valid after a delete or refresh.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    if (!repoOptions.includes(selectedRepoKey)) setSelectedRepoKey('all')
  }, [repoOptions, selectedRepoKey])

  useEffect(() => {
    if (selectedRepoKey === 'all' || selectedRepoKey === 'no-repo') setToolView('packages')
  }, [selectedRepoKey, setToolView])

  // Derived state
  const selectedCount = useMemo(
    () => dependencies.filter(item => item.selected).length,
    [dependencies]
  )

  const groupedDependencies = useMemo(() => {
    return dependencies.reduce<Record<string, SelectedDependency[]>>((acc, item) => {
      const key = `${item.ecosystem}:${item.manifest_path}`
      if (!acc[key]) acc[key] = []
      acc[key].push(item)
      return acc
    }, {})
  }, [dependencies])

  const filteredWatchedPackages = useMemo(() => {
    return watchedPackages.filter(item => {
      if (versionFilter !== 'all' && item.latest_update_type !== versionFilter) return false
      if (selectedRepoKey !== 'all' && repoKeyOf(item) !== selectedRepoKey) return false
      return true
    })
  }, [watchedPackages, versionFilter, selectedRepoKey])

  const groupedWatchedPackages = useMemo(() => {
    return filteredWatchedPackages.reduce<Record<string, WatchedPackage[]>>((acc, item) => {
      const key = repoKeyOf(item)
      if (!acc[key]) acc[key] = []
      acc[key].push(item)
      return acc
    }, {})
  }, [filteredWatchedPackages])

  // Repo settings derived
  const selectedRepoPackages = useMemo(() => {
    return watchedPackages.filter(item => repoKeyOf(item) === selectedRepoKey)
  }, [watchedPackages, selectedRepoKey])

  const selectedRepoSample = useMemo(() => {
    if (!selectedRepoKey || selectedRepoKey === 'all' || selectedRepoKey === 'no-repo') return null
    return selectedRepoPackages[0] ?? null
  }, [selectedRepoKey, selectedRepoPackages])

  const selectedRepoWatchedMap = useMemo(() => {
    const map = new Map<string, SelectedDependency>()
    selectedRepoPackages.forEach(pkg => {
      const key = `${pkg.ecosystem}:${pkg.manifest_path}:${pkg.package_name}`
      map.set(key, pkg as unknown as SelectedDependency)
    })
    return map
  }, [selectedRepoPackages])

  // Actions
  const handleAnalyze = useCallback(async () => {
    if (!url.trim()) {
      toast.error('请先输入 GitHub 仓库地址')
      return
    }
    setAnalyzing(true)
    try {
      const result = await previewRepoDependencies(url.trim())
      setPreview(result)
      setDependencies(
        result.manifests.flatMap(manifest =>
          manifest.dependencies.map(item => ({
            ...item,
            ecosystem: manifest.ecosystem,
            manifest_path: manifest.path,
            selected: true,
          }))
        )
      )
      setShowAddPanel(true)
      toast.success('依赖解析完成')
    } finally {
      setAnalyzing(false)
    }
  }, [url, setShowAddPanel])

  const handleToggleDependency = useCallback((target: SelectedDependency, selected: boolean) => {
    setDependencies(prev =>
      prev.map(item =>
        item.ecosystem === target.ecosystem &&
        item.package_name === target.package_name &&
        item.manifest_path === target.manifest_path
          ? { ...item, selected }
          : item
      )
    )
  }, [])

  const toggleAll = useCallback((selected: boolean) => {
    setDependencies(prev => prev.map(item => ({ ...item, selected })))
  }, [])

  const handleSave = useCallback(async () => {
    if (!preview) {
      toast.error('请先解析仓库依赖')
      return
    }
    const selectedPackages = dependencies.filter(item => item.selected)
    if (selectedPackages.length === 0) {
      toast.error('请至少选择一个依赖包')
      return
    }
    setSaving(true)
    try {
      const saved = await saveWatchedPackages(
        preview.source,
        url.trim(),
        selectedPackages.map(item => ({
          ecosystem: item.ecosystem,
          package_name: item.package_name,
          manifest_path: item.manifest_path,
          current_version_constraint: item.current_version_constraint,
          normalized_current_version: item.normalized_current_version,
          current_version_source: item.current_version_source as
            | 'lock'
            | 'manifest'
            | null
            | undefined,
          watch_level: DEFAULT_SAVE_LEVEL,
          dependency_group: item.dependency_group,
        }))
      )
      setWatchedPackages(prev => {
        const merged = [...prev]
        saved.forEach(item => {
          const index = merged.findIndex(existing => existing.id === item.id)
          if (index >= 0) merged[index] = item
          else merged.unshift(item)
        })
        return merged
      })
      setSelectedRepoKey(repoKeyOf(saved[0]))
      resetAddPanel()
      toast.success(`已新增 ${saved.length} 个依赖关注`)
    } finally {
      setSaving(false)
    }
    // resetAddPanel is stable and declared below to keep related handlers together.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [dependencies, preview, url])

  const handleRefresh = useCallback(async (id: number) => {
    setActiveAction({ id, type: 'refresh' })
    try {
      const item = await refreshWatchedPackage(id)
      setWatchedPackages(prev => prev.map(pkg => (pkg.id === id ? item : pkg)))
      toast.success('依赖更新已刷新')
    } finally {
      setActiveAction(null)
    }
  }, [])

  const handleCancelWatch = useCallback(async (id: number) => {
    setActiveAction({ id, type: 'cancel' })
    try {
      await deleteWatchedPackage(id)
      setWatchedPackages(prev => prev.filter(pkg => pkg.id !== id))
      toast.success('已取消关注')
    } finally {
      setActiveAction(null)
    }
  }, [])

  const handleDeleteRepo = useCallback(async () => {
    const ids = selectedRepoPackages.map(pkg => pkg.id)
    if (ids.length === 0) return
    try {
      await deleteWatchedPackages(ids)
      setWatchedPackages(prev => prev.filter(pkg => !ids.includes(pkg.id)))
      setRepoSettingsPreview(null)
      toast.success(`已删除 ${ids.length} 个依赖关注`)
    } catch {
      toast.error('删除失败，请重试')
    }
  }, [selectedRepoPackages])

  const handleToggleAllRepoSettings = useCallback(
    async (
      deps: Array<{
        ecosystem: 'npm' | 'composer'
        manifest_path: string
        package_name: string
        current_version_constraint?: string | null
        normalized_current_version?: string | null
        current_version_source?: string | null
        dependency_group?: string | null
      }>,
      nextWatched: boolean
    ) => {
      if (!selectedRepoSample || !repoSettingsPreview) return
      if (nextWatched) {
        const missingDependencies = deps.filter(
          item =>
            !selectedRepoWatchedMap.has(
              `${item.ecosystem}:${item.manifest_path}:${item.package_name}`
            )
        )
        if (missingDependencies.length === 0) return
        setRepoSettingsActionKey('toggle-all')
        try {
          const saved = await saveWatchedPackages(
            {
              provider: selectedRepoSample.source_provider,
              owner: selectedRepoSample.source_owner,
              repo: selectedRepoSample.source_repo,
              full_name: repoLabelOf(selectedRepoSample),
              html_url: selectedRepoSample.source_url,
              description: null,
            },
            selectedRepoSample.source_url,
            missingDependencies.map(item => ({
              ...item,
              current_version_source: item.current_version_source as
                | 'lock'
                | 'manifest'
                | null
                | undefined,
              watch_level: DEFAULT_SAVE_LEVEL,
            }))
          )
          setWatchedPackages(prev => {
            const merged = [...prev]
            for (const item of saved) {
              const index = merged.findIndex(existing => existing.id === item.id)
              if (index >= 0) merged[index] = item
              else merged.push(item)
            }
            return merged
          })
          toast.success(`已关注 ${saved.length} 个依赖`)
        } finally {
          setRepoSettingsActionKey(null)
        }
      } else {
        const toDelete = selectedRepoPackages.filter(pkg =>
          deps.some(
            dep =>
              pkg.ecosystem === dep.ecosystem &&
              pkg.manifest_path === dep.manifest_path &&
              pkg.package_name === dep.package_name
          )
        )
        if (toDelete.length === 0) return
        setRepoSettingsActionKey('toggle-all')
        try {
          await deleteWatchedPackages(toDelete.map(p => p.id))
          setWatchedPackages(prev => prev.filter(pkg => !toDelete.some(d => d.id === pkg.id)))
          toast.success(`已取消关注 ${toDelete.length} 个依赖`)
        } finally {
          setRepoSettingsActionKey(null)
        }
      }
    },
    [selectedRepoSample, selectedRepoWatchedMap, selectedRepoPackages, repoSettingsPreview]
  )

  const handleToggleRepoSettingPackage = useCallback(
    async (dep: SelectedDependency, watched: SelectedDependency | undefined) => {
      if (!selectedRepoSample) return
      const key = `${dep.ecosystem}:${dep.manifest_path}:${dep.package_name}`
      setRepoSettingsActionKey(key)
      try {
        if (watched) {
          await deleteWatchedPackages([(watched as unknown as WatchedPackage).id])
          setWatchedPackages(prev =>
            prev.filter(pkg => pkg.id !== (watched as unknown as WatchedPackage).id)
          )
          toast.success('已取消关注')
        } else {
          const saved = await saveWatchedPackages(
            {
              provider: selectedRepoSample.source_provider,
              owner: selectedRepoSample.source_owner,
              repo: selectedRepoSample.source_repo,
              full_name: repoLabelOf(selectedRepoSample),
              html_url: selectedRepoSample.source_url,
              description: null,
            },
            selectedRepoSample.source_url,
            [
              {
                ...dep,
                current_version_source: (
                  dep as SelectedDependency & { current_version_source?: string | null }
                ).current_version_source as 'lock' | 'manifest' | null | undefined,
                watch_level: DEFAULT_SAVE_LEVEL,
              },
            ]
          )
          setWatchedPackages(prev => {
            const merged = [...prev]
            for (const item of saved) {
              const index = merged.findIndex(existing => existing.id === item.id)
              if (index >= 0) merged[index] = item
              else merged.unshift(item)
            }
            return merged
          })
          toast.success('已加入关注')
        }
      } finally {
        setRepoSettingsActionKey(null)
      }
    },
    [selectedRepoSample]
  )

  const resetAddPanel = useCallback(() => {
    setPreview(null)
    setDependencies([])
    setUrl('')
    setShowAddPanel(false)
  }, [setShowAddPanel])

  return (
    <div className="space-y-4">
      {/* 列表视图 */}
      {toolView === 'packages' ? (
        <>
          {/* 筛选栏 */}
          {watchedPackages.length > 0 ? (
            <div className="flex flex-wrap items-center gap-2">
              <select
                className="border-input bg-background h-8 rounded-md border px-2 text-xs"
                value={selectedRepoKey}
                onChange={event => setSelectedRepoKey(event.target.value)}
              >
                {repoOptions.map(item => (
                  <option key={item} value={item}>
                    {item === 'all' ? '全部仓库' : item === 'no-repo' ? '无仓库' : item}
                  </option>
                ))}
              </select>
              <select
                className="border-input bg-background h-8 rounded-md border px-2 text-xs"
                value={versionFilter}
                onChange={event => setVersionFilter(event.target.value as VersionFilter)}
              >
                <option value="all">全部更新</option>
                <option value="major">只看大版本</option>
                <option value="minor">只看功能版本</option>
                <option value="patch">只看小版本</option>
              </select>
            </div>
          ) : null}

          {/* 添加仓库面板 */}
          {showAddPanel ? (
            <Card>
              <CardContent className="pt-4 space-y-4">
                <div className="flex gap-2">
                  <Input
                    placeholder="例如 https://github.com/laravel/framework"
                    value={url}
                    onChange={event => setUrl(event.target.value)}
                    onKeyDown={event => {
                      if (event.key === 'Enter') {
                        event.preventDefault()
                        void handleAnalyze()
                      }
                    }}
                    className="flex-1"
                  />
                  <Button variant="outline" onClick={resetAddPanel}>
                    取消
                  </Button>
                  <Button onClick={() => void handleAnalyze()} loading={analyzing}>
                    <Search className="h-4 w-4" />
                    解析
                  </Button>
                </div>
              </CardContent>
            </Card>
          ) : null}

          {/* 空状态 */}
          {!showAddPanel && watchedPackages.length === 0 && !preview ? (
            <Card className="border-primary/20 bg-gradient-to-br from-background via-background to-primary/5">
              <CardContent className="py-12">
                <EmptyState
                  icon={<FolderGit2 className="h-10 w-10" />}
                  title="还没有关注任何依赖"
                  description="点击上方「添加仓库」按钮，输入 GitHub 仓库地址开始追踪依赖更新。"
                />
              </CardContent>
            </Card>
          ) : null}

          {/* 解析预览 */}
          {preview ? (
            <DependencyPreview
              preview={preview as Parameters<typeof DependencyPreview>[0]['preview']}
              groupedDependencies={groupedDependencies}
              selectedCount={selectedCount}
              saving={saving}
              onToggleDependency={handleToggleDependency}
              onToggleAll={toggleAll}
              onSave={handleSave}
              onReset={resetAddPanel}
            />
          ) : null}

          {/* 包列表 */}
          <PackageListPanel
            watchedPackages={watchedPackages}
            filteredWatchedPackages={filteredWatchedPackages}
            groupedWatchedPackages={groupedWatchedPackages}
            versionFilter={versionFilter}
            selectedRepoKey={selectedRepoKey}
            repoOptions={repoOptions}
            activeAction={activeAction}
            loadingList={loadingList}
            onVersionFilterChange={setVersionFilter}
            onRepoKeyChange={setSelectedRepoKey}
            onRefresh={handleRefresh}
            onCancelWatch={handleCancelWatch}
          />
        </>
      ) : (
        /* 仓库设置视图 */
        <RepoSettingsPanel
          repoOptions={repoOptions}
          selectedRepoKey={selectedRepoKey}
          selectedRepoSample={selectedRepoSample}
          selectedRepoPackages={selectedRepoPackages}
          selectedRepoWatchedMap={selectedRepoWatchedMap}
          repoSettingsPreview={repoSettingsPreview}
          repoSettingsActionKey={repoSettingsActionKey}
          onRepoKeyChange={setSelectedRepoKey}
          onToggleAllRepoSettings={handleToggleAllRepoSettings}
          onToggleRepoSettingPackage={handleToggleRepoSettingPackage}
          onDeleteRepo={handleDeleteRepo}
        />
      )}
    </div>
  )
}
