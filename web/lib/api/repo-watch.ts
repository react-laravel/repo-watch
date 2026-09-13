'use client'

import { del, get, patch, post } from './core'

export type WatchLevel = 'major' | 'minor' | 'patch'
export type Ecosystem = 'npm' | 'composer'
export type DependencyChangeType = 'added' | 'removed' | 'updated'
export type RepositoryScanStatus = 'idle' | 'pending' | 'scanning' | 'error'
export type WatchPriority = 'high' | 'normal' | 'low'

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
  muted: boolean
  muted_at?: string | null
  watch_priority: WatchPriority
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
    muted?: boolean
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

export const listWatchedRepositories = () =>
  get<WatchedRepositoriesListResponse>('/repo-watch/repositories')

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

export interface ScanHealthSummary {
  total: number
  by_status: {
    idle: number
    pending: number
    scanning: number
    error: number
  }
  never_scanned: number
  overdue: number
  failing: number
}

export interface SnapshotRetentionPolicy {
  snapshot_keep: number
  change_retention_days: number
}

export interface WatchedRepositoriesListResponse {
  repositories: WatchedRepository[]
  health: ScanHealthSummary
  retention: SnapshotRetentionPolicy
}

export const bulkImportWatchedRepositories = (repositories: string[], scan = true) =>
  post<BulkImportRepositoriesResponse>('/repo-watch/repositories/bulk', { repositories, scan })

export const deleteWatchedRepository = (id: number) =>
  del<void>(`/repo-watch/repositories/${id}`)

export interface UpdateWatchedRepositoryPreferencesInput {
  muted?: boolean
  watch_priority?: WatchPriority
}

export const updateWatchedRepositoryPreferences = (
  id: number,
  preferences: UpdateWatchedRepositoryPreferencesInput
) => patch<WatchedRepository>(`/repo-watch/repositories/${id}`, preferences)

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

export const scanUnhealthyWatchedRepositories = () =>
  post<{
    queued: number
    health: ScanHealthSummary
    retention: SnapshotRetentionPolicy
  }>('/repo-watch/repositories/scan-unhealthy', {})

export interface DependencyChangeFilters {
  limit?: number
  repositoryId?: number | null
  ecosystem?: Ecosystem | 'all' | null
  changeType?: DependencyChangeType | 'all' | null
  includeMuted?: boolean
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
    if (filters.includeMuted) {
      params.set('include_muted', '1')
    }
  }

  return get<DependencyChange[]>(`/repo-watch/dependency-changes?${params.toString()}`)
}

export type DependencyChangeExportFormat = 'csv' | 'summary'

export interface DependencyChangeExportFilters extends DependencyChangeFilters {
  format?: DependencyChangeExportFormat
  since?: string | null
  hours?: number | null
}

export interface DependencyChangeExport {
  format: DependencyChangeExportFormat
  filename: string
  content: string
  row_count: number
  truncated: boolean
  since: string
  until: string
  window_source: 'since' | 'hours' | 'default' | 'clamped'
  filters: {
    repository_id?: number | null
    ecosystem?: string | null
    change_type?: string | null
    include_muted?: boolean
  }
}

export const exportDependencyChanges = (filters: DependencyChangeExportFilters = {}) => {
  const params = new URLSearchParams()
  params.set('format', filters.format ?? 'csv')
  params.set('limit', String(filters.limit ?? 500))
  if (filters.since) {
    params.set('since', filters.since)
  } else if (filters.hours) {
    params.set('hours', String(filters.hours))
  }
  if (filters.repositoryId) {
    params.set('repository_id', String(filters.repositoryId))
  }
  if (filters.ecosystem && filters.ecosystem !== 'all') {
    params.set('ecosystem', filters.ecosystem)
  }
  if (filters.changeType && filters.changeType !== 'all') {
    params.set('change_type', filters.changeType)
  }
  if (filters.includeMuted) {
    params.set('include_muted', '1')
  }

  return get<DependencyChangeExport>(`/repo-watch/dependency-changes/export?${params.toString()}`)
}

export const downloadTextFile = (filename: string, content: string, mime = 'text/plain;charset=utf-8') => {
  if (typeof window === 'undefined') {
    return
  }
  const blob = new Blob([content], { type: mime })
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = filename
  anchor.click()
  URL.revokeObjectURL(url)
}

export type RepoWatchNotificationType = 'dependency_high_signal' | 'scan_failed' | 'package_advisory'

export interface RepoWatchNotification {
  id: number
  type: RepoWatchNotificationType
  severity: string
  title: string
  body: string
  payload?: Record<string, unknown> | null
  read_at?: string | null
  webhook_delivered_at?: string | null
  created_at?: string | null
  repository?: {
    id: number
    full_name: string
    url: string
  } | null
}

export interface RepoWatchNotificationPolicy {
  enabled: boolean
  webhook_configured: boolean
  on_major: boolean
  on_removed: boolean
  on_scan_failure: boolean
  on_advisory: boolean
}

export interface RepoWatchNotificationsResponse {
  notifications: RepoWatchNotification[]
  unread_count: number
  policy: RepoWatchNotificationPolicy
}

export const listRepoWatchNotifications = (options?: { limit?: number; unreadOnly?: boolean }) => {
  const params = new URLSearchParams()
  params.set('limit', String(options?.limit ?? 30))
  if (options?.unreadOnly) {
    params.set('unread_only', '1')
  }

  return get<RepoWatchNotificationsResponse>(`/repo-watch/notifications?${params.toString()}`)
}

export const markRepoWatchNotificationRead = (id: number) =>
  post<RepoWatchNotification>(`/repo-watch/notifications/${id}/read`, {})

export const markAllRepoWatchNotificationsRead = () =>
  post<{ marked: number }>('/repo-watch/notifications/read-all', {})

export type AdvisorySeverity = 'critical' | 'high' | 'moderate' | 'low' | 'unknown'
export type AdvisoryFindingStatus = 'open' | 'resolved'

export interface PackageAdvisorySummary {
  id: number
  source: string
  advisory_id: string
  ghsa_id?: string | null
  ghsa_enriched_at?: string | null
  severity: AdvisorySeverity
  summary?: string | null
  aliases?: string[] | null
  fixed_version?: string | null
  reference_url?: string | null
  published_at?: string | null
}

export interface PackageAdvisoryFinding {
  id: number
  watched_repository_id: number
  repository?: {
    id: number
    full_name: string
    owner: string
    repo: string
    url: string
    muted?: boolean
  } | null
  ecosystem: Ecosystem
  manifest_path: string
  package_name: string
  installed_version: string
  status: AdvisoryFindingStatus
  first_detected_at: string
  last_seen_at: string
  resolved_at?: string | null
  advisory?: PackageAdvisorySummary | null
}

export interface PackageAdvisoryPolicy {
  enabled: boolean
  min_severity: string
  notify_on_advisory: boolean
  osv_base_url: string
  ghsa_enrichment_enabled: boolean
  ghsa_enrichment_cache_ttl: number
  ghsa_enrichment_max_per_refresh: number
}

export interface PackageAdvisoriesResponse {
  findings: PackageAdvisoryFinding[]
  policy: PackageAdvisoryPolicy
}

export interface PackageAdvisoryFilters {
  limit?: number
  repositoryId?: number | null
  ecosystem?: Ecosystem | 'all' | null
  severity?: AdvisorySeverity | 'all' | null
  status?: AdvisoryFindingStatus | 'all' | null
  includeMuted?: boolean
}

export const listPackageAdvisories = (filters: PackageAdvisoryFilters = {}) => {
  const params = new URLSearchParams()
  params.set('limit', String(filters.limit ?? 50))
  if (filters.repositoryId) {
    params.set('repository_id', String(filters.repositoryId))
  }
  if (filters.ecosystem && filters.ecosystem !== 'all') {
    params.set('ecosystem', filters.ecosystem)
  }
  if (filters.severity && filters.severity !== 'all') {
    params.set('severity', filters.severity)
  }
  if (filters.status && filters.status !== 'all') {
    params.set('status', filters.status)
  } else if (!filters.status) {
    params.set('status', 'open')
  }
  if (filters.includeMuted) {
    params.set('include_muted', '1')
  }

  return get<PackageAdvisoriesResponse>(`/repo-watch/advisories?${params.toString()}`)
}

export interface FleetActivityDigestRepo {
  id: number
  full_name: string
  url: string
  dependency_changes: number
  change_types: {
    added: number
    updated: number
    removed: number
  }
  notifications: number
  advisories_new: number
  muted: boolean
  watch_priority: WatchPriority
}

export interface FleetActivityDigest {
  since: string
  until: string
  window_hours: number
  source: 'since' | 'hours' | 'default' | 'clamped'
  fleet: {
    watched: number
    active: number
    muted: number
  }
  totals: {
    dependency_changes: number
    notifications: number
    unread_notifications: number
    advisories_new: number
    active: {
      dependency_changes: number
      notifications: number
      advisories_new: number
    }
    muted: {
      dependency_changes: number
      notifications: number
      advisories_new: number
    }
  }
  by_repository: FleetActivityDigestRepo[]
  repositories_capped: boolean
  policy: {
    default_hours: number
    max_hours: number
    max_repositories: number
  }
}

export const listFleetActivityDigest = (options?: { since?: string | null; hours?: number | null }) => {
  const params = new URLSearchParams()
  if (options?.since) {
    params.set('since', options.since)
  } else if (options?.hours) {
    params.set('hours', String(options.hours))
  }

  const query = params.toString()
  return get<FleetActivityDigest>(`/repo-watch/activity-digest${query ? `?${query}` : ''}`)
}

export const FLEET_DIGEST_LAST_VISIT_KEY = 'repo-watch:lastVisitAt'

export const readFleetDigestLastVisit = (): string | null => {
  if (typeof window === 'undefined') {
    return null
  }
  try {
    const value = window.localStorage.getItem(FLEET_DIGEST_LAST_VISIT_KEY)
    return value && value.trim() !== '' ? value : null
  } catch {
    return null
  }
}

export const writeFleetDigestLastVisit = (iso: string = new Date().toISOString()): void => {
  if (typeof window === 'undefined') {
    return
  }
  try {
    window.localStorage.setItem(FLEET_DIGEST_LAST_VISIT_KEY, iso)
  } catch {
    // Ignore quota / private-mode failures; digest falls back to default window.
  }
}

