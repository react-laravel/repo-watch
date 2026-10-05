'use client'

import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
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
  updateWatchedPackageLevel,
  type RepoDependencyPreview,
  type WatchedPackage,
  type WatchLevel,
} from '@/lib/api/repo-watch'
import { messageFrom, repoKeyOf, repoLabelOf, WATCH_LEVEL_LABEL } from './repoWatchUtils'
import PackageListPanel from './PackageListPanel'
import DependencyPreview from './DependencyPreview'
import RepoSettingsPanel from './RepoSettingsPanel'
import type { SelectedDependency } from './types'

type VersionFilter = 'all' | WatchLevel

type RepoWatchToolProps = {
  showAddPanel: boolean
  setShowAddPanel: (value: boolean | ((prev: boolean) => boolean)) => void
  toolView: 'packages' | 'repo-settings'
}

export default function RepoWatchTool({
  showAddPanel,
  setShowAddPanel,
  toolView,
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
    type: 'refresh' | 'cancel' | 'level'
  } | null>(null)
  const [versionFilter, setVersionFilter] = useState<VersionFilter>('all')
  const [selectedRepoKey, setSelectedRepoKey] = useState<string>('all')
  const [saveLevel, setSaveLevel] = useState<WatchLevel>('minor')
  const [repoSettingsPreview, setRepoSettingsPreview] = useState<RepoDependencyPreview | null>(null)
  const [repoSettingsLoading, setRepoSettingsLoading] = useState(false)
  const [repoSettingsError, setRepoSettingsError] = useState<string | null>(null)
  const [previewNonce, setPreviewNonce] = useState(0)
  const [repoSettingsActionKey, setRepoSettingsActionKey] = useState<string | null>(null)
  const refreshTimers = useRef<number[]>([])

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

  const scheduleVersionRefreshSync = useCallback(() => {
    for (const timer of refreshTimers.current) {
      window.clearTimeout(timer)
    }
    refreshTimers.current = [2000, 5000, 10000, 20000].map(delay =>
      window.setTimeout(() => void loadWatchedPackages(), delay)
    )
  }, [loadWatchedPackages])

  useEffect(() => {
    return () => {
      for (const timer of refreshTimers.current) {
        window.clearTimeout(timer)
      }
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

  // Repo settings derived
  const selectedRepoPackages = useMemo(() => {
    return watchedPackages.filter(item => repoKeyOf(item) === selectedRepoKey)
  }, [watchedPackages, selectedRepoKey])

  const selectedRepoSample = useMemo(() => {
    if (!selectedRepoKey || selectedRepoKey === 'all' || selectedRepoKey === 'no-repo') return null
    return selectedRepoPackages[0] ?? null
  }, [selectedRepoKey, selectedRepoPackages])

  const selectedRepoUrl = selectedRepoSample?.source_url ?? null

  useEffect(() => {
    // Request flags follow the selected repository; the panel only renders this state.
    /* eslint-disable react-hooks/set-state-in-effect */
    if (toolView !== 'repo-settings') return
    if (!selectedRepoUrl || selectedRepoKey === 'all' || selectedRepoKey === 'no-repo') {
      setRepoSettingsPreview(null)
      setRepoSettingsError(null)
      setRepoSettingsLoading(false)
      return
    }

    let cancelled = false
    setRepoSettingsLoading(true)
    setRepoSettingsError(null)

    void previewRepoDependencies(selectedRepoUrl)
      .then(result => {
        if (!cancelled) setRepoSettingsPreview(result)
      })
      .catch(error => {
        if (cancelled) return
        setRepoSettingsPreview(null)
        setRepoSettingsError(messageFrom(error, '加载仓库依赖失败'))
      })
      .finally(() => {
        if (!cancelled) setRepoSettingsLoading(false)
      })

    return () => {
      cancelled = true
    }
    /* eslint-enable react-hooks/set-state-in-effect */
  }, [previewNonce, selectedRepoKey, selectedRepoUrl, toolView])

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
    } catch (error) {
      toast.error(messageFrom(error, '解析仓库依赖失败'))
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

  const resetAddPanel = useCallback(() => {
    setPreview(null)
    setDependencies([])
    setUrl('')
    setShowAddPanel(false)
  }, [setShowAddPanel])

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
          watch_level: saveLevel,
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
      scheduleVersionRefreshSync()
      toast.success(`已保存 ${saved.length} 个依赖，正在后台获取最新版本`)
    } catch (error) {
      toast.error(messageFrom(error, '保存依赖失败'))
    } finally {
      setSaving(false)
    }
  }, [dependencies, preview, resetAddPanel, saveLevel, scheduleVersionRefreshSync, url])

  const handleRefresh = useCallback(async (id: number) => {
    setActiveAction({ id, type: 'refresh' })
    try {
      const item = await refreshWatchedPackage(id)
      setWatchedPackages(prev => prev.map(pkg => (pkg.id === id ? item : pkg)))
      toast.success('依赖更新已刷新')
    } catch (error) {
      toast.error(messageFrom(error, '刷新依赖失败'))
    } finally {
      setActiveAction(null)
    }
  }, [])

  const handleCancelWatch = useCallback(async (id: number) => {
    if (!window.confirm('确认取消关注这个依赖？')) return
    setActiveAction({ id, type: 'cancel' })
    try {
      await deleteWatchedPackage(id)
      setWatchedPackages(prev => prev.filter(pkg => pkg.id !== id))
      toast.success('已取消关注')
    } catch (error) {
      toast.error(messageFrom(error, '取消关注失败'))
    } finally {
      setActiveAction(null)
    }
  }, [])

  const handleWatchLevelChange = useCallback(async (item: WatchedPackage, level: WatchLevel) => {
    if (item.watch_level === level) return
    setActiveAction({ id: item.id, type: 'level' })
    try {
      const updated = await updateWatchedPackageLevel(item.id, level)
      setWatchedPackages(prev => prev.map(pkg => (pkg.id === item.id ? updated : pkg)))
      toast.success(`关注级别已设为「${WATCH_LEVEL_LABEL[level]}」`)
    } catch (error) {
      toast.error(messageFrom(error, '更新关注级别失败'))
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
              watch_level: saveLevel,
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
          scheduleVersionRefreshSync()
          toast.success(`已保存 ${saved.length} 个依赖，正在后台获取最新版本`)
        } catch (error) {
          toast.error(messageFrom(error, '保存依赖失败'))
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
        } catch (error) {
          toast.error(messageFrom(error, '取消关注失败'))
        } finally {
          setRepoSettingsActionKey(null)
        }
      }
    },
    [
      saveLevel,
      selectedRepoSample,
      selectedRepoWatchedMap,
      selectedRepoPackages,
      repoSettingsPreview,
      scheduleVersionRefreshSync,
    ]
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
                watch_level: saveLevel,
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
          scheduleVersionRefreshSync()
          toast.success('已加入关注，正在后台获取最新版本')
        }
      } catch (error) {
        toast.error(messageFrom(error, watched ? '取消关注失败' : '加入关注失败'))
      } finally {
        setRepoSettingsActionKey(null)
      }
    },
    [saveLevel, scheduleVersionRefreshSync, selectedRepoSample]
  )

  return (
    <div className="space-y-4">
      {/* 列表视图 */}
      {toolView === 'packages' ? (
        <>
          {/* 筛选栏 */}
          {watchedPackages.length > 0 ? (
            <div className="flex flex-wrap items-center gap-2">
              <select
                className="border-input bg-background h-10 min-w-0 max-w-full rounded-lg border px-3 text-sm"
                aria-label="依赖所属仓库"
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
                className="border-input bg-background h-10 min-w-0 max-w-full rounded-lg border px-3 text-sm"
                aria-label="依赖更新类型"
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

          {!loadingList && watchedPackages.length > 0 && <p role="status" className="text-muted-foreground text-xs">显示 {filteredWatchedPackages.length} / {watchedPackages.length} 个依赖</p>}

          {/* 添加仓库面板 */}
          {showAddPanel ? (
            <Card>
              <CardContent className="pt-4 space-y-4">
                <div className="flex flex-wrap gap-2">
                  <Input
                    aria-label="GitHub 仓库地址"
                    placeholder="例如 https://github.com/laravel/framework"
                    value={url}
                    onChange={event => setUrl(event.target.value)}
                    onKeyDown={event => {
                      if (event.key === 'Enter') {
                        event.preventDefault()
                        void handleAnalyze()
                      }
                    }}
                    className="min-w-0 basis-full sm:basis-auto sm:flex-1"
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
          {!loadingList && !showAddPanel && watchedPackages.length === 0 && !preview ? (
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
              watchLevel={saveLevel}
              onWatchLevelChange={setSaveLevel}
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
            versionFilter={versionFilter}
            selectedRepoKey={selectedRepoKey}
            activeAction={activeAction}
            loadingList={loadingList}
            onVersionFilterChange={setVersionFilter}
            onRepoKeyChange={setSelectedRepoKey}
            onRefresh={handleRefresh}
            onCancelWatch={handleCancelWatch}
            onWatchLevelChange={handleWatchLevelChange}
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
          repoSettingsLoading={repoSettingsLoading}
          repoSettingsError={repoSettingsError}
          repoSettingsActionKey={repoSettingsActionKey}
          onRetryPreview={() => setPreviewNonce(nonce => nonce + 1)}
          onRepoKeyChange={setSelectedRepoKey}
          onToggleAllRepoSettings={handleToggleAllRepoSettings}
          onToggleRepoSettingPackage={handleToggleRepoSettingPackage}
          onDeleteRepo={handleDeleteRepo}
        />
      )}
    </div>
  )
}
