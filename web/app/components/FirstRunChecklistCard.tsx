'use client'

import { CheckCircle2, Circle, ListChecks, LoaderCircle } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { cn } from '@/lib/helpers'
import type { FirstRunChecklist, FirstRunStepId } from '@/lib/repo-watch-first-run'

interface FirstRunChecklistCardProps {
  checklist: FirstRunChecklist
  rescanning: boolean
  onImport: () => void
  onScan: () => void
  onOpenDigest: () => void
  onDismiss: () => void
}

const STEP_ACTION_LABEL: Record<FirstRunStepId, string> = {
  import: '去导入',
  scan: '排队扫描',
  digest: '打开摘要',
}

export default function FirstRunChecklistCard({
  checklist,
  rescanning,
  onImport,
  onScan,
  onOpenDigest,
  onDismiss,
}: FirstRunChecklistCardProps) {
  if (!checklist.visible) {
    return null
  }

  const handleStepAction = (stepId: FirstRunStepId) => {
    if (stepId === 'import') {
      onImport()
      return
    }
    if (stepId === 'scan') {
      onScan()
      return
    }
    onOpenDigest()
  }

  const doneCount = checklist.steps.filter(step => step.status === 'done').length

  return (
    <Card className="border-primary/25 bg-muted/20">
      <CardHeader className="pb-3">
        <CardTitle className="flex flex-wrap items-center gap-2 text-base">
          <ListChecks className="h-4 w-4" />
          首次设置
          <Badge variant="secondary">
            {doneCount}/{checklist.steps.length}
          </Badge>
        </CardTitle>
        <CardDescription>
          批量导入 → 首次扫描 → 打开舰队摘要。完成后可关闭；不影响已有扫描与通知。
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-3">
        <ol className="space-y-2">
          {checklist.steps.map((step, index) => {
            const isActive = step.status === 'active' || step.status === 'in_progress'
            const Icon =
              step.status === 'done'
                ? CheckCircle2
                : step.status === 'in_progress'
                  ? LoaderCircle
                  : Circle

            return (
              <li
                key={step.id}
                className={cn(
                  'flex flex-wrap items-start justify-between gap-2 rounded-md border px-3 py-2 text-sm',
                  isActive ? 'border-primary/40 bg-background' : 'border-transparent'
                )}
              >
                <div className="flex min-w-0 items-start gap-2">
                  <Icon
                    className={cn(
                      'mt-0.5 h-4 w-4 shrink-0',
                      step.status === 'done' && 'text-emerald-600',
                      step.status === 'in_progress' && 'animate-spin text-primary',
                      step.status === 'todo' && 'text-muted-foreground',
                      step.status === 'active' && 'text-primary'
                    )}
                    aria-hidden
                  />
                  <div className="min-w-0">
                    <div className="font-medium">
                      {index + 1}. {step.title}
                    </div>
                    <div className="text-muted-foreground text-xs">{step.description}</div>
                  </div>
                </div>
                {isActive ? (
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-7 px-2 text-xs"
                    loading={step.id === 'scan' && rescanning}
                    onClick={() => handleStepAction(step.id)}
                  >
                    {STEP_ACTION_LABEL[step.id]}
                  </Button>
                ) : null}
              </li>
            )
          })}
        </ol>
        <div className="flex flex-wrap gap-2">
          <Button variant="ghost" size="sm" onClick={onDismiss}>
            稍后
          </Button>
        </div>
      </CardContent>
    </Card>
  )
}
