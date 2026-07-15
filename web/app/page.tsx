'use client'

import { useState } from 'react'
import { FolderGit2, Plus, Settings2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import RepoWatchTool from './components/RepoWatchTool'

export default function RepoWatchPage() {
  const [showAddPanel, setShowAddPanel] = useState(false)
  const [toolView, setToolView] = useState<'packages' | 'repo-settings'>('packages')

  return (
    <main className="mx-auto min-h-dvh w-full max-w-6xl px-4 py-6 sm:px-6">
      <header className="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
          <div className="flex items-center gap-2">
            <FolderGit2 className="text-primary h-7 w-7" />
            <h1 className="text-2xl font-bold">Repo Watch</h1>
          </div>
          <p className="text-muted-foreground mt-1 text-sm">追踪 GitHub 仓库的 npm 与 Composer 依赖更新</p>
        </div>
        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            onClick={() => setToolView(view => (view === 'packages' ? 'repo-settings' : 'packages'))}
          >
            <Settings2 className="h-4 w-4" />
            {toolView === 'packages' ? '仓库设置' : '依赖列表'}
          </Button>
          <Button onClick={() => { setToolView('packages'); setShowAddPanel(true) }}>
            <Plus className="h-4 w-4" />
            添加仓库
          </Button>
        </div>
      </header>

      <RepoWatchTool
        showAddPanel={showAddPanel}
        setShowAddPanel={setShowAddPanel}
        toolView={toolView}
        setToolView={setToolView}
      />
    </main>
  )
}
