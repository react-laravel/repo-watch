export type FirstRunStepId = 'import' | 'scan' | 'digest'

export type FirstRunStepStatus = 'todo' | 'active' | 'in_progress' | 'done'

export interface FirstRunStep {
  id: FirstRunStepId
  status: FirstRunStepStatus
  title: string
  description: string
}

export interface FirstRunChecklistInput {
  repositoryCount: number
  neverScanned: number
  pendingOrScanning: number
  digestOpened: boolean
  hasDigestLastVisit: boolean
  dismissed: boolean
}

export interface FirstRunChecklist {
  visible: boolean
  completed: boolean
  activeStepId: FirstRunStepId | null
  steps: FirstRunStep[]
}

export const FIRST_RUN_DISMISSED_KEY = 'repo-watch:firstRunChecklistDismissed'
export const FIRST_RUN_DIGEST_OPENED_KEY = 'repo-watch:firstRunDigestOpened'

export function readFirstRunFlag(key: string): boolean {
  if (typeof window === 'undefined') {
    return false
  }

  try {
    return window.localStorage.getItem(key) === '1'
  } catch {
    return false
  }
}

export function writeFirstRunFlag(key: string, value: boolean): void {
  if (typeof window === 'undefined') {
    return
  }

  try {
    if (value) {
      window.localStorage.setItem(key, '1')
    } else {
      window.localStorage.removeItem(key)
    }
  } catch {
    // Ignore quota / private-mode failures.
  }
}

/**
 * Thin first-run progress: bulk import → first scan complete → open fleet digest.
 * Pure glue over existing health / digest surfaces — no new backend.
 */
export function buildFirstRunChecklist(input: FirstRunChecklistInput): FirstRunChecklist {
  const importDone = input.repositoryCount > 0
  const scanDone = importDone && input.neverScanned === 0
  const scanInProgress =
    importDone && !scanDone && (input.pendingOrScanning > 0 || input.neverScanned > 0)
  const digestDone =
    scanDone && (input.digestOpened || input.hasDigestLastVisit)

  const steps: FirstRunStep[] = [
    {
      id: 'import',
      status: importDone ? 'done' : 'active',
      title: '批量导入仓库',
      description: importDone
        ? `已关注 ${input.repositoryCount} 个仓库`
        : '粘贴 owner/repo 列表，一次铺到 20–30 仓',
    },
    {
      id: 'scan',
      status: !importDone
        ? 'todo'
        : scanDone
          ? 'done'
          : scanInProgress
            ? 'in_progress'
            : 'active',
      title: '完成首次扫描',
      description: scanDone
        ? '所有仓库至少扫描过一次'
        : input.neverScanned > 0
          ? `还有 ${input.neverScanned} 个未扫描；可排队失败/未扫描仓`
          : '等待扫描排队完成',
    },
    {
      id: 'digest',
      status: !scanDone ? 'todo' : digestDone ? 'done' : 'active',
      title: '查看最近活动',
      description: digestDone
        ? '舰队 digest 已打开；之后用 last-visit 游标看增量'
        : '打开「最近活动」摘要，确认依赖变更信号',
    },
  ]

  const completed = importDone && scanDone && digestDone
  const activeStepId = steps.find(step => step.status === 'active' || step.status === 'in_progress')?.id ?? null

  return {
    visible: !input.dismissed && !completed,
    completed,
    activeStepId,
    steps,
  }
}
