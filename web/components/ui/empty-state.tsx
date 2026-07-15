import { memo, ReactNode } from 'react'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/helpers'

interface Action {
  label: string
  onClick: () => void
  variant?: 'default' | 'outline' | 'secondary' | 'ghost'
}

interface EmptyStateProps {
  icon?: ReactNode | string
  title: string
  description?: string
  primaryAction?: Action
  secondaryAction?: Action
  className?: string
  /** 'default' = normal padding, 'compact' = less padding for inline use */
  variant?: 'default' | 'compact' | 'full-page'
}

export const EmptyState = memo(
  ({
    icon = '📝',
    title,
    description,
    primaryAction,
    secondaryAction,
    className,
    variant = 'default',
  }: EmptyStateProps) => {
    const paddingMap = {
      default: 'py-12',
      compact: 'py-6',
      'full-page': 'min-h-[50vh] py-16',
    }

    return (
      <div
        className={cn(
          'flex flex-col items-center justify-center text-center',
          paddingMap[variant],
          className
        )}
      >
        {typeof icon === 'string' ? (
          <div className="mb-4 text-4xl" role="img" aria-label={title}>
            {icon}
          </div>
        ) : (
          <div className="mb-4 flex justify-center">{icon}</div>
        )}

        <h3 className="mb-2 text-lg font-medium">{title}</h3>

        {description && <p className="text-muted-foreground mb-4 text-sm">{description}</p>}

        {(primaryAction || secondaryAction) && (
          <div className="flex flex-wrap items-center justify-center gap-3">
            {secondaryAction && (
              <Button
                onClick={secondaryAction.onClick}
                variant={secondaryAction.variant ?? 'ghost'}
              >
                {secondaryAction.label}
              </Button>
            )}
            {primaryAction && (
              <Button onClick={primaryAction.onClick} variant={primaryAction.variant ?? 'outline'}>
                {primaryAction.label}
              </Button>
            )}
          </div>
        )}
      </div>
    )
  }
)

EmptyState.displayName = 'EmptyState'
