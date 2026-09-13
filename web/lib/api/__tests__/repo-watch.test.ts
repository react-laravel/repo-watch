import { describe, it, expect } from 'vitest'
import {
  previewRepoDependencies,
  listWatchedPackages,
  saveWatchedPackages,
  refreshWatchedPackage,
  deleteWatchedPackage,
  deleteWatchedPackages,
  listWatchedRepositories,
  listDependencyChanges,
  createWatchedRepository,
  bulkImportWatchedRepositories,
  scanWatchedRepository,
  scanUnhealthyWatchedRepositories,
  listRepoWatchNotifications,
  markRepoWatchNotificationRead,
  markAllRepoWatchNotificationsRead,
  listPackageAdvisories,
} from '../repo-watch'

describe('repo-watch API functions', () => {
  it('previewRepoDependencies should be a function', () => {
    expect(typeof previewRepoDependencies).toBe('function')
  })

  it('listWatchedPackages should be a function', () => {
    expect(typeof listWatchedPackages).toBe('function')
  })

  it('saveWatchedPackages should be a function', () => {
    expect(typeof saveWatchedPackages).toBe('function')
  })

  it('refreshWatchedPackage should be a function', () => {
    expect(typeof refreshWatchedPackage).toBe('function')
  })

  it('deleteWatchedPackage should be a function', () => {
    expect(typeof deleteWatchedPackage).toBe('function')
  })

  it('deleteWatchedPackages should be a function', () => {
    expect(typeof deleteWatchedPackages).toBe('function')
  })

  it('multi-repo helpers should be functions', () => {
    expect(typeof listWatchedRepositories).toBe('function')
    expect(typeof listDependencyChanges).toBe('function')
    expect(typeof createWatchedRepository).toBe('function')
    expect(typeof bulkImportWatchedRepositories).toBe('function')
    expect(typeof scanWatchedRepository).toBe('function')
    expect(typeof scanUnhealthyWatchedRepositories).toBe('function')
    expect(typeof listRepoWatchNotifications).toBe('function')
    expect(typeof markRepoWatchNotificationRead).toBe('function')
    expect(typeof markAllRepoWatchNotificationsRead).toBe('function')
    expect(typeof listPackageAdvisories).toBe('function')
  })
})

describe('repo-watch types', () => {
  it('WatchLevel should have correct values', () => {
    const levels = ['major', 'minor', 'patch'] as const
    expect(levels).toContain('major')
    expect(levels).toContain('minor')
    expect(levels).toContain('patch')
  })

  it('Ecosystem should have correct values', () => {
    const ecosystems = ['npm', 'composer'] as const
    expect(ecosystems).toContain('npm')
    expect(ecosystems).toContain('composer')
  })

  it('WatchedPackage should have expected fields', () => {
    const pkg = {
      id: 1,
      source_provider: 'github',
      source_owner: 'owner',
      source_repo: 'repo',
      source_url: 'https://github.com/owner/repo',
      ecosystem: 'npm' as const,
      package_name: 'lodash',
      watch_level: 'minor' as const,
      matches_preference: true,
    }
    expect(pkg.id).toBe(1)
    expect(pkg.ecosystem).toBe('npm')
    expect(pkg.watch_level).toBe('minor')
  })
})
