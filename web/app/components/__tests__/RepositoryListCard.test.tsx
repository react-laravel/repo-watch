import { describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import RepositoryListCard from '../RepositoryListCard'
import ScanHealthOverview from '../ScanHealthOverview'
import type { WatchedRepository } from '@/lib/api/repo-watch'

const base: WatchedRepository = {
  id: 1,
  provider: 'github',
  owner: 'example',
  repo: 'api',
  full_name: 'example/api',
  url: 'https://github.com/example/api',
  description: '订单与支付',
  scan_status: 'error',
  last_scanned_at: '2026-09-30T10:00:00Z',
  next_scan_at: '2026-09-30T12:00:00Z',
  last_scan_error: '请求限流，请稍后重试',
  package_count: 24,
  watched_packages_count: 5,
  muted: false,
  watch_priority: 'high',
}
const repositories: WatchedRepository[] = [
  base,
  {
    ...base,
    id: 2,
    repo: 'web',
    full_name: 'example/web',
    url: 'https://github.com/example/web',
    description: 'Website',
    scan_status: 'idle',
    last_scan_error: null,
    muted: true,
    watch_priority: 'normal',
  },
  {
    ...base,
    id: 3,
    repo: 'new',
    full_name: 'example/new',
    url: 'https://github.com/example/new',
    description: '',
    scan_status: 'idle',
    last_scanned_at: null,
    last_scan_error: null,
    watch_priority: 'low',
  },
]
function setup(items = repositories, actionId: number | null = null) {
  const handlers = {
    onPriorityChange: vi.fn(),
    onToggleMute: vi.fn(),
    onScan: vi.fn(),
    onDelete: vi.fn(),
    onImport: vi.fn(),
  }
  const view = render(<RepositoryListCard repositories={items} actionId={actionId} {...handlers} />)
  return { ...handlers, ...view }
}
function rows() {
  return within(screen.getByRole('list', { name: '关注仓库列表' })).getAllByRole('listitem')
}

describe('repository dashboard list', () => {
  it('preserves API ordering and distinguishes an unscanned idle repository from a healthy one', () => {
    setup()
    expect(rows().map(row => within(row).getByRole('link').textContent)).toEqual([
      'example/api',
      'example/web',
      'example/new',
    ])
    expect(within(rows()[0]).getByText('扫描失败')).toBeVisible()
    expect(within(rows()[1]).getByText('扫描正常')).toBeVisible()
    expect(within(rows()[2]).getByText('尚未扫描')).toBeVisible()
    expect(screen.getByText('通知已静音 · 扫描继续')).toBeVisible()
    expect(screen.getByText(/请求限流，请稍后重试/)).toBeVisible()
  })
  it('combines local name/description search with scan and notification filters without mutations', () => {
    const handlers = setup()
    fireEvent.change(screen.getByRole('searchbox', { name: '搜索关注仓库' }), {
      target: { value: '  WEBSITE  ' },
    })
    expect(rows()).toHaveLength(1)
    expect(rows()[0]).toHaveTextContent('example/web')
    fireEvent.change(screen.getByRole('combobox', { name: '仓库通知状态' }), {
      target: { value: 'active' },
    })
    expect(screen.getByText('没有匹配的仓库')).toBeVisible()
    expect(screen.getByRole('status')).toHaveTextContent('显示 0 / 3 个仓库')
    fireEvent.click(screen.getByRole('button', { name: '查看全部仓库' }))
    fireEvent.change(screen.getByRole('combobox', { name: '仓库扫描状态' }), {
      target: { value: 'unscanned' },
    })
    expect(rows()).toHaveLength(1)
    expect(rows()[0]).toHaveTextContent('example/new')
    expect(handlers.onScan).not.toHaveBeenCalled()
    expect(handlers.onPriorityChange).not.toHaveBeenCalled()
    expect(handlers.onToggleMute).not.toHaveBeenCalled()
    expect(handlers.onDelete).not.toHaveBeenCalled()
  })
  it('passes each action to the existing handler with the unchanged repository or ID', () => {
    const handlers = setup()
    fireEvent.change(screen.getByRole('combobox', { name: 'example/api 关注优先级' }), {
      target: { value: 'low' },
    })
    expect(handlers.onPriorityChange).toHaveBeenCalledWith(base, 'low')
    fireEvent.click(screen.getByRole('button', { name: '静音通知 example/api' }))
    expect(handlers.onToggleMute).toHaveBeenCalledWith(base)
    fireEvent.click(screen.getByRole('button', { name: '扫描 example/api' }))
    expect(handlers.onScan).toHaveBeenCalledWith(1)
    fireEvent.click(screen.getByRole('button', { name: '删除 example/api' }))
    expect(handlers.onDelete).toHaveBeenCalledWith(1)
    expect(base.watch_priority).toBe('high')
    expect(base.muted).toBe(false)
  })
  it('locks only the active repository while retaining accessible action names', () => {
    const handlers = setup(repositories, 1)
    for (const button of within(rows()[0]).getAllByRole('button')) {
      expect(button).toBeDisabled()
      fireEvent.click(button)
    }
    expect(within(rows()[0]).getByRole('combobox')).toBeDisabled()
    expect(screen.getByRole('button', { name: '扫描 example/web' })).toBeEnabled()
    expect(handlers.onScan).not.toHaveBeenCalled()
    expect(handlers.onDelete).not.toHaveBeenCalled()
  })
  it('offers import for the actual empty state and restores keyboard search focus', async () => {
    const user = userEvent.setup()
    const { onImport, rerender, ...rest } = setup([])
    expect(screen.getByText('从第一个仓库开始')).toBeVisible()
    await user.click(screen.getByRole('button', { name: '导入仓库', exact: true }))
    expect(onImport).toHaveBeenCalledOnce()
    rerender(
      <RepositoryListCard
        repositories={repositories}
        actionId={null}
        onImport={onImport}
        {...rest}
      />
    )
    const search = screen.getByRole('searchbox', { name: '搜索关注仓库' })
    await user.type(search, 'api')
    await user.click(screen.getByRole('button', { name: '清空仓库搜索' }))
    expect(search).toHaveFocus()
    expect(search).toHaveValue('')
    expect(rows()).toHaveLength(3)
  })
})

describe('scan overview', () => {
  it('shows server-supplied totals without combining overlapping attention categories', () => {
    const onRescan = vi.fn()
    render(
      <ScanHealthOverview
        health={{
          total: 9,
          by_status: { idle: 4, pending: 2, scanning: 1, error: 2 },
          failing: 2,
          never_scanned: 3,
          overdue: 4,
        }}
        retention={{ snapshot_keep: 5, change_retention_days: 30 }}
        rescanning={false}
        onRescan={onRescan}
      />
    )
    const values = screen.getAllByRole('definition').map(node => node.textContent)
    expect(values).toEqual(['9', '3', '2', '3', '4'])
    expect(screen.getByText('1 扫描中 · 2 等待')).toBeVisible()
    expect(screen.getByText(/依赖变更保留 30 天/)).toBeVisible()
    fireEvent.click(screen.getByRole('button', { name: '重新扫描失败 / 未扫描 / 逾期' }))
    expect(onRescan).toHaveBeenCalledOnce()
  })
  it('does not offer an inapplicable rescan action for healthy repositories', () => {
    render(
      <ScanHealthOverview
        health={{
          total: 2,
          by_status: { idle: 2, pending: 0, scanning: 0, error: 0 },
          failing: 0,
          never_scanned: 0,
          overdue: 0,
        }}
        retention={null}
        rescanning={false}
        onRescan={vi.fn()}
      />
    )
    expect(screen.queryByRole('button')).toBeNull()
  })
})
