'use client'

import { useState } from 'react'
import { ArrowUpRight, Boxes, FolderGit2, GitBranch, Plus, Settings2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import RepoWatchTool from './components/RepoWatchTool'
import RepositoriesPanel from './components/RepositoriesPanel'

type ToolView = 'packages' | 'repo-settings' | 'repositories'
const views = [
  {
    id: 'packages',
    label: '依赖动态',
    description: '版本变化与关注列表',
    icon: Boxes,
  },
  {
    id: 'repositories',
    label: '仓库监控',
    description: '扫描健康与安全公告',
    icon: GitBranch,
  },
  {
    id: 'repo-settings',
    label: '关注设置',
    description: '管理仓库中的依赖',
    icon: Settings2,
  },
] as const

export default function RepoWatchPage() {
  const [showAddPanel, setShowAddPanel] = useState(false)
  const [toolView, setToolView] = useState<ToolView>('packages')
  const currentView = views.find(view => view.id === toolView)!
  return (
    <main className="repo-watch-workspace mx-auto min-h-dvh w-full max-w-7xl px-3 py-4 sm:px-6 sm:py-6">
      <header className="bg-card mb-5 flex flex-wrap items-center justify-between gap-4 rounded-2xl border px-4 py-5 shadow-sm sm:px-6">
        <div className="flex min-w-0 items-center gap-3">
          <div className="bg-primary/10 text-primary flex size-12 shrink-0 items-center justify-center rounded-xl">
            <FolderGit2 aria-hidden className="size-6" />
          </div>
          <div className="min-w-0">
            <p className="text-muted-foreground text-[10px] font-semibold tracking-[0.2em]">
              DOGEOW
            </p>
            <h1 className="text-xl font-semibold tracking-tight sm:text-2xl">Repo Watch</h1>
            <p className="text-muted-foreground mt-1 text-xs sm:text-sm">
              追踪依赖变化，让仓库状态一目了然。
            </p>
          </div>
        </div>
        <div className="flex w-full flex-wrap items-center justify-between gap-3 sm:w-auto">
          <span className="text-muted-foreground rounded-full border px-3 py-1.5 text-xs">
            npm · Composer
          </span>
          <Button
            onClick={() => {
              setToolView('packages')
              setShowAddPanel(true)
            }}
          >
            <Plus aria-hidden className="size-4" />
            添加仓库
          </Button>
        </div>
      </header>
      <div className="grid items-start gap-5 lg:grid-cols-[200px_minmax(0,1fr)]">
        <aside className="lg:sticky lg:top-6">
          <nav
            aria-label="监控视图"
            className="bg-card grid grid-cols-3 gap-1 rounded-2xl border p-1.5 shadow-sm lg:grid-cols-1 lg:gap-2 lg:p-2"
          >
            {views.map(({ id, label, description, icon: Icon }) => (
              <button
                key={id}
                type="button"
                aria-label={label}
                aria-current={toolView === id ? 'page' : undefined}
                onClick={() => setToolView(id)}
                className={`flex min-w-0 flex-col items-center gap-2 rounded-xl px-2 py-3 text-center transition-colors lg:flex-row lg:items-start lg:gap-3 lg:px-3 lg:py-4 lg:text-left ${toolView === id ? 'bg-primary/10 text-foreground ring-primary/15 ring-1' : 'text-muted-foreground hover:bg-muted hover:text-foreground'}`}
              >
                <Icon
                  aria-hidden
                  className={`size-4 shrink-0 lg:mt-0.5 ${toolView === id ? 'text-primary' : ''}`}
                />
                <span className="min-w-0">
                  <span className="block text-xs font-semibold sm:text-sm">{label}</span>
                  <span className="mt-1 hidden text-[11px] leading-5 text-muted-foreground lg:block">
                    {description}
                  </span>
                </span>
              </button>
            ))}
          </nav>
          <p className="text-muted-foreground mt-4 hidden px-3 text-xs leading-6 lg:block">
            从仓库快照到依赖变化，集中查看需要关注的信息。
            <ArrowUpRight aria-hidden className="ml-1 inline size-3.5" />
          </p>
        </aside>
        <section aria-labelledby="workspace-view-heading" className="min-w-0 space-y-5">
          <div>
            <h2 id="workspace-view-heading" className="text-xl font-semibold tracking-tight">
              {currentView.label}
            </h2>
            <p className="text-muted-foreground mt-1 text-sm">{currentView.description}</p>
          </div>
          {toolView === 'repositories' ? (
            <RepositoriesPanel />
          ) : (
            <RepoWatchTool
              showAddPanel={showAddPanel}
              setShowAddPanel={setShowAddPanel}
              toolView={toolView}
            />
          )}
        </section>
      </div>
    </main>
  )
}
