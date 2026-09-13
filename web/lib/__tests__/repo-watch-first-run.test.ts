import { describe, expect, it } from 'vitest'
import { buildFirstRunChecklist, type FirstRunChecklistInput } from '../repo-watch-first-run'

const base = (overrides: Partial<FirstRunChecklistInput> = {}): FirstRunChecklistInput => ({
  repositoryCount: 0,
  neverScanned: 0,
  pendingOrScanning: 0,
  digestOpened: false,
  hasDigestLastVisit: false,
  dismissed: false,
  ...overrides,
})

describe('buildFirstRunChecklist', () => {
  it('starts on import when fleet is empty', () => {
    const checklist = buildFirstRunChecklist(base())

    expect(checklist.visible).toBe(true)
    expect(checklist.completed).toBe(false)
    expect(checklist.activeStepId).toBe('import')
    expect(checklist.steps.map(step => step.status)).toEqual(['active', 'todo', 'todo'])
  })

  it('moves to first scan after import while never_scanned remains', () => {
    const checklist = buildFirstRunChecklist(
      base({
        repositoryCount: 12,
        neverScanned: 12,
        pendingOrScanning: 5,
      })
    )

    expect(checklist.activeStepId).toBe('scan')
    expect(checklist.steps[0]?.status).toBe('done')
    expect(checklist.steps[1]?.status).toBe('in_progress')
    expect(checklist.steps[2]?.status).toBe('todo')
  })

  it('activates digest after first scan completes', () => {
    const checklist = buildFirstRunChecklist(
      base({
        repositoryCount: 20,
        neverScanned: 0,
      })
    )

    expect(checklist.activeStepId).toBe('digest')
    expect(checklist.steps.map(step => step.status)).toEqual(['done', 'done', 'active'])
    expect(checklist.visible).toBe(true)
  })

  it('completes and hides when digest is opened', () => {
    const checklist = buildFirstRunChecklist(
      base({
        repositoryCount: 20,
        neverScanned: 0,
        digestOpened: true,
      })
    )

    expect(checklist.completed).toBe(true)
    expect(checklist.visible).toBe(false)
    expect(checklist.activeStepId).toBeNull()
  })

  it('treats existing last-visit cursor as digest opened', () => {
    const checklist = buildFirstRunChecklist(
      base({
        repositoryCount: 8,
        neverScanned: 0,
        hasDigestLastVisit: true,
      })
    )

    expect(checklist.completed).toBe(true)
    expect(checklist.visible).toBe(false)
  })

  it('hides when dismissed even mid-flow', () => {
    const checklist = buildFirstRunChecklist(
      base({
        repositoryCount: 0,
        dismissed: true,
      })
    )

    expect(checklist.visible).toBe(false)
  })
})
