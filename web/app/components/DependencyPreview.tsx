'use client'

import { CheckSquare, ExternalLink, Square } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import type { RepoDependencyPreview } from '@/lib/api/repo-watch'
import type { SelectedDependency } from './types'

interface DependencyPreviewProps {
  preview: RepoDependencyPreview | null
  groupedDependencies: Record<string, SelectedDependency[]>
  selectedCount: number
  saving: boolean
  onToggleDependency: (dep: SelectedDependency, selected: boolean) => void
  onToggleAll: (selected: boolean) => void
  onSave: () => Promise<void>
  onReset: () => void
}

export default function DependencyPreview({
  preview,
  groupedDependencies,
  selectedCount,
  saving,
  onToggleDependency,
  onToggleAll,
  onSave,
  onReset,
}: DependencyPreviewProps) {
  if (!preview) return null

  return (
    <Card>
      <CardHeader className="pb-3">
        <div className="flex items-center justify-between gap-3">
          <div className="min-w-0 flex items-center gap-2">
            <CardTitle className="text-base truncate">{preview.source.full_name}</CardTitle>
            <Button variant="ghost" size="icon" className="h-7 w-7 shrink-0" asChild>
              <a
                href={preview.source.html_url}
                target="_blank"
                rel="noreferrer"
                aria-label="打开仓库"
              >
                <ExternalLink className="h-4 w-4" />
              </a>
            </Button>
          </div>
          <div className="flex flex-wrap gap-1">
            <Button size="sm" onClick={() => void onSave()} loading={saving}>
              <CheckSquare className="h-4 w-4" />
              保存 ({selectedCount})
            </Button>
            <Button
              variant="ghost"
              size="icon"
              className="h-7 w-7"
              onClick={() => onToggleAll(true)}
              aria-label="全选"
              title="全选"
            >
              <CheckSquare className="h-4 w-4" />
            </Button>
            <Button
              variant="ghost"
              size="icon"
              className="h-7 w-7"
              onClick={() => onToggleAll(false)}
              aria-label="取消全选"
              title="取消全选"
            >
              <Square className="h-4 w-4" />
            </Button>
          </div>
        </div>
        <CardDescription className="truncate">
          {preview.source.description || '暂无仓库描述'}
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-3">
        {Object.entries(groupedDependencies).map(([, items]) => {
          const selCount = items.filter(item => item.selected).length
          return (
            <div key={`${items[0]?.ecosystem}-${items[0]?.manifest_path}`} className="space-y-2">
              <div className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                <Badge variant="outline" className="text-xs">
                  {items[0]?.ecosystem}
                </Badge>
                <Badge variant="outline" className="text-xs">
                  {items[0]?.manifest_path}
                </Badge>
                <span>
                  {selCount}/{items.length}
                </span>
              </div>
              <div className="grid gap-1.5">
                {items.map(item => (
                  <label
                    key={`${item.ecosystem}-${item.package_name}`}
                    className="flex items-center gap-2 rounded-md border px-3 py-2 hover:bg-muted/50"
                  >
                    <input
                      type="checkbox"
                      checked={item.selected}
                      onChange={event => onToggleDependency(item, event.target.checked)}
                      className="accent-primary"
                    />
                    <div className="min-w-0 flex-1">
                      <div className="truncate text-sm font-medium">{item.package_name}</div>
                      <div className="truncate text-xs text-muted-foreground">
                        {item.current_version_constraint || '未声明'}
                        {item.dependency_group ? ` · ${item.dependency_group}` : ''}
                      </div>
                    </div>
                  </label>
                ))}
              </div>
            </div>
          )
        })}
        <div className="flex justify-end gap-2 pt-2">
          <Button variant="outline" size="sm" onClick={onReset}>
            取消
          </Button>
          <Button size="sm" onClick={() => void onSave()} loading={saving}>
            <CheckSquare className="h-4 w-4" />
            保存 ({selectedCount})
          </Button>
        </div>
      </CardContent>
    </Card>
  )
}
