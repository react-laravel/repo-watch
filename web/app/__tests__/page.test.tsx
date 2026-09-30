import { describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import RepoWatchPage from '../page'
import { listWatchedPackages } from '@/lib/api/repo-watch'

vi.mock('@/lib/api/repo-watch', async importOriginal => {
  const actual = await importOriginal<typeof import('@/lib/api/repo-watch')>()
  return { ...actual, listWatchedPackages: vi.fn().mockResolvedValue([]) }
})
vi.mock('../components/RepositoriesPanel', () => ({ default: () => <div>仓库监控内容</div> }))

describe('monitor workspace navigation', () => {
  it('keeps explicit view selection and allows settings without bouncing back when no repository is selected', async () => {
    render(<RepoWatchPage />)
    expect(screen.getByRole('button', { name: '依赖动态' })).toHaveAttribute('aria-current', 'page')
    await screen.findByText('还没有关注任何依赖')
    fireEvent.click(screen.getByRole('button', { name: '关注设置' }))
    expect(await screen.findByRole('combobox', { name: '设置中的仓库' })).toHaveValue('')
    expect(screen.getByRole('button', { name: '关注设置' })).toHaveAttribute('aria-current', 'page')
    expect(screen.getByText(/还没有可设置的仓库/)).toBeVisible()
    fireEvent.click(screen.getByRole('button', { name: '仓库监控' }))
    expect(screen.getByText('仓库监控内容')).toBeVisible()
    fireEvent.click(screen.getByRole('button', { name: '关注设置' }))
    expect(await screen.findByRole('combobox', { name: '设置中的仓库' })).toBeVisible()
    expect(vi.mocked(listWatchedPackages)).toHaveBeenCalled()
  })
  it('opens the add-repository form from another view without starting a scan', async () => {
    render(<RepoWatchPage />)
    fireEvent.click(screen.getByRole('button', { name: '仓库监控' }))
    fireEvent.click(screen.getByRole('button', { name: '添加仓库' }))
    expect(await screen.findByRole('textbox', { name: 'GitHub 仓库地址' })).toHaveValue('')
    expect(screen.getByRole('button', { name: '依赖动态' })).toHaveAttribute('aria-current', 'page')
    fireEvent.click(screen.getByRole('button', { name: '取消', exact: true }))
    expect(screen.queryByRole('textbox', { name: 'GitHub 仓库地址' })).toBeNull()
  })
})
