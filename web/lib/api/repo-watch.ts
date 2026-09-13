'use client'

import { del, get, post } from './core'

export type WatchLevel = 'major' | 'minor' | 'patch'
export type Ecosystem = 'npm' | 'composer'
export type DependencyChangeType = 'added' | 'removed' | 'updated'
export type RepositoryScanStatus = 'idle' | 'pending' | 'scanning' | 'error'

export interface RepoDependencyPreviewItem {
  package_name: string
  current_version_constraint?: string | null
  normalized_current_version?: string | null
  current_version_source?: 'lock' | 'manifest' | null
  dependency_group?: string | null
}

export interface RepoDependencyManifest {
  ecosystem: Ecosystem
  path: string
  package_name?: string | null
  dependencies: RepoDependencyPreviewItem[]
}

export interface RepoDependencyPreview {
  source: {
    provider: string
    owner: string
    repo: string
    full_name: string
    html_url: string
    description?: string | null
  }
  manifests: RepoDependencyManifest[]
}

export interface WatchedPackage {
  id: number
  watched_repository_id?: number | null
  source_provider: string
  source_owner: string
  source_repo: string
  source_url: string
  ecosystem: Ecosystem
  package_name: string
  manifest_path?: string | null
  current_version_constraint?: string | null
  normalized_current_version?: string | null
  current_version_source?: 'lock' | 'manifest' | null
  latest_version?: string | null
  watch_level: WatchLevel
  latest_update_type?: WatchLevel | null
  matches_preference: boolean
  publisher_display_name?: string | null
  registry_url?: string | null
  last_checked_at?: string | null
  last_error?: string | null
  metadata?: Record<string, unknown> | null
}

export interface WatchedRepository {
  id: number
  provider: string
  owner: string
  repo: string
  full_name: string
  url: string
  description?: string | null
  default_branch?: string | null
  scan_status: RepositoryScanStatus
  last_scanned_at?: string | null
  next_scan_at?: string | null
  last_scan_error?: string | null
  package_count: number
  watched_packages_count: number
  updated_at?: string | null
}

export interface DependencyChange {
  id: number
  watched_repository_id: number
  repository?: {
    id: number
    full_name: string
    owner: string
    repo: string
    url: string
  } | null
  ecosystem: Ecosystem
  manifest_path: string
  package_name: string
  change_type: DependencyChangeType
  previous_constraint?: string | null
  new_constraint?: string | null
  previous_version?: string | null
  new_version?: string | null
  detected_at: string
}

export interface SaveWatchedPackageInput {
  ecosystem: Ecosystem
  package_name: string
  manifest_path: string
  current_version_constraint?: string | null
  normalized_current_version?: string | null
  current_version_source?: 'lock' | 'manifest' | null
  watch_level: WatchLevel
  dependency_group?: string | null
}

export const previewRepoDependencies = (url: string) =>
  post<RepoDependencyPreview>('/repo-watch/preview', { url })

export const listWatchedPackages = () => get<WatchedPackage[]>('/repo-watch/packages')

export const saveWatchedPackages = (
  source: RepoDependencyPreview['source'],
  sourceUrl: string,
  packages: SaveWatchedPackageInput[]
) =>
  post<WatchedPackage[]>('/repo-watch/packages', {
    source_url: sourceUrl,
    source_owner: source.owner,
    source_repo: source.repo,
    packages,
  })

export const refreshWatchedPackage = (id: number) =>
  post<WatchedPackage>(`/repo-watch/packages/${id}/refresh`, {})

export const deleteWatchedPackage = (id: number) => del<void>(`/repo-watch/packages/${id}`)

export const deleteWatchedPackages = (ids: number[]) =>
  del<{ deleted: number }>('/repo-watch/packages', { ids })

export const listWatchedRepositories = () => get<WatchedRepository[]>('/repo-watch/repositories')

export const createWatchedRepository = (url: string, scan = true) =>
  post<WatchedRepository>('/repo-watch/repositories', { url, scan })

export type BulkImportRepositoryStatus =
  | 'created'
  | 'already_watched'
  | 'duplicate_in_request'
  | 'invalid'

export interface BulkImportRepositoryResult {
  input: string
  status: BulkImportRepositoryStatus
  message: string
  repository: WatchedRepository | null
}

export interface BulkImportRepositoriesResponse {
  summary: {
    created: number
    already_watched: number
    invalid: number
    total: number
    scans_queued: number
  }
  results: BulkImportRepositoryResult[]
}

export const bulkImportWatchedRepositories = (repositories: string[], scan = true) =>
  post<BulkImportRepositoriesResponse>('/repo-watch/repositories/bulk', { repositories, scan })

export const deleteWatchedRepository = (id: number) =>
  del<void>(`/repo-watch/repositories/${id}`)

export const scanWatchedRepository = (id: number, sync = false) =>
  post<{
    repository?: WatchedRepository
    snapshots_created?: number
    changes_detected?: number
    deferred?: boolean
  } | WatchedRepository>(
    `/repo-watch/repositories/${id}/scan${sync ? '?sync=1' : ''}`,
    {}
  )

export interface DependencyChangeFilters {
  limit?: number
  repositoryId?: number | null
  ecosystem?: Ecosystem | 'all' | null
  changeType?: DependencyChangeType | 'all' | null
}

export const listDependencyChanges = (filters: number | DependencyChangeFilters = 50) => {
  const params = new URLSearchParams()

  if (typeof filters === 'number') {
    params.set('limit', String(filters))
  } else {
    params.set('limit', String(filters.limit ?? 50))
    if (filters.repositoryId) {
      params.set('repository_id', String(filters.repositoryId))
    }
    if (filters.ecosystem && filters.ecosystem !== 'all') {
      params.set('ecosystem', filters.ecosystem)
    }
    if (filters.changeType && filters.changeType !== 'all') {
      params.set('change_type', filters.changeType)
    }
  }

  return get<DependencyChange[]>(`/repo-watch/dependency-changes?${params.toString()}`)
}
