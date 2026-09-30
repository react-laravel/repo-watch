import { describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import PackageListPanel from '../PackageListPanel'
import type { WatchedPackage } from '@/lib/api/repo-watch'
const item: WatchedPackage = {
  id: 10,
  source_provider: 'github',
  source_owner: 'example',
  source_repo: 'api',
  source_url: 'https://github.com/example/api',
  ecosystem: 'npm',
  package_name: '@example/api-client',
  current_version_constraint: '^1.0.0',
  normalized_current_version: '1.0.0',
  latest_version: '2.0.0',
  watch_level: 'major',
  latest_update_type: 'major',
  matches_preference: true,
}
const props = {
  watchedPackages: [item],
  filteredWatchedPackages: [item],
  groupedWatchedPackages: { 'example/api': [item] },
  versionFilter: 'all' as const,
  selectedRepoKey: 'all',
  repoOptions: ['all', 'example/api'],
  activeAction: null,
  loadingList: false,
  onVersionFilterChange: vi.fn(),
  onRepoKeyChange: vi.fn(),
  onRefresh: vi.fn().mockResolvedValue(undefined),
  onCancelWatch: vi.fn().mockResolvedValue(undefined),
}
describe('dependency cards', () => {
  it('uses readable update labels and preserves version values and actions', () => {
    render(<PackageListPanel {...props} />)
    expect(screen.getByText('大版本更新')).toBeVisible()
    expect(screen.getByText('^1.0.0')).toBeVisible()
    fireEvent.click(screen.getByRole('button', { name: '刷新 @example/api-client' }))
    expect(props.onRefresh).toHaveBeenCalledWith(10)
    fireEvent.click(screen.getByRole('button', { name: '取消关注 @example/api-client' }))
    expect(props.onCancelWatch).toHaveBeenCalledWith(10)
  })
  it('clears view filters without removing watched dependencies', () => {
    render(<PackageListPanel {...props} filteredWatchedPackages={[]} />)
    fireEvent.click(screen.getByRole('button', { name: '清空依赖筛选' }))
    expect(props.onVersionFilterChange).toHaveBeenCalledWith('all')
    expect(props.onRepoKeyChange).toHaveBeenCalledWith('all')
    expect(props.watchedPackages).toEqual([item])
  })
  it('keeps the loading announcement and does not duplicate the parent onboarding empty state', () => {
    const { rerender } = render(<PackageListPanel {...props} loadingList />)
    expect(screen.getByRole('status')).toHaveTextContent('正在加载关注列表')
    rerender(<PackageListPanel {...props} watchedPackages={[]} filteredWatchedPackages={[]} />)
    expect(screen.queryByText('当前筛选条件下没有结果')).toBeNull()
  })
})
