import { ApiError } from '@/lib/api/core'
import type { WatchLevel, WatchedPackage } from '@/lib/api/repo-watch'

export const WATCH_LEVEL_LABEL: Record<WatchLevel, string> = {
  major: '大版本',
  minor: '功能版本',
  patch: '补丁',
}

export const messageFrom = (error: unknown, fallback: string) => {
  if (error instanceof ApiError && error.message.trim() !== '') {
    return error.message
  }

  if (error instanceof Error && error.message.trim() !== '') {
    return error.message
  }

  return fallback
}

export const formatDateTime = (value?: string | null) => {
  if (!value) return '暂无'

  return new Intl.DateTimeFormat('zh-CN', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  }).format(new Date(value))
}

export const repoKeyOf = (item: Pick<WatchedPackage, 'source_owner' | 'source_repo'>) =>
  item.source_owner && item.source_repo ? `${item.source_owner}/${item.source_repo}` : 'no-repo'

export const repoLabelOf = (item: Pick<WatchedPackage, 'source_owner' | 'source_repo'>) =>
  repoKeyOf(item) === 'no-repo' ? '无仓库' : `${item.source_owner}/${item.source_repo}`

export const getLeadingSymbolPrefix = (value?: string | null) => {
  if (!value) return ''

  const match = value.match(/^[^\d]*/)
  return match?.[0] ?? ''
}

export const renderVersionDiff = (
  currentVersion?: string | null,
  latestVersion?: string | null
) => {
  if (!latestVersion) {
    return <span>未知</span>
  }

  const currentParts = (currentVersion ?? '').split('.')
  const latestParts = latestVersion.split('.')

  return latestParts.map((part, index) => {
    const changed = currentParts[index] !== part

    return (
      <span
        key={`${part}-${index}`}
        className={changed ? 'text-green-600 dark:text-green-400' : ''}
      >
        {index > 0 ? '.' : ''}
        {part}
      </span>
    )
  })
}
