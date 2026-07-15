/**
 * Shared types for RepoWatchTool components
 */
import type { RepoDependencyPreview, RepoDependencyPreviewItem } from '@/lib/api/repo-watch'

export type SelectedDependency = RepoDependencyPreviewItem & {
  ecosystem: 'npm' | 'composer'
  manifest_path: string
  selected: boolean
}

export type RepoSettingsPreview = Omit<RepoDependencyPreview, 'manifests'> & {
  manifests: Array<{
    ecosystem: 'npm' | 'composer'
    path: string
    dependencies: Array<RepoDependencyPreviewItem & { selected: boolean }>
  }>
}
