import { Plus } from 'lucide-react'

import { cn } from '@/lib/utils'

import type { MillerInstanceRef } from './millerRegistry'

interface MillerInstanceTabsProps {
  instances: MillerInstanceRef[]
  activeKey: string | undefined
  onSelect: (key: string) => void
  onCreate?: () => void
  className?: string
}

export function MillerInstanceTabs({
  instances,
  activeKey,
  onSelect,
  onCreate,
  className,
}: MillerInstanceTabsProps): React.ReactElement {
  return (
    <div
      role="tablist"
      className={cn(
        'flex items-stretch border-b border-border bg-card text-xs',
        className,
      )}
    >
      <div className="flex flex-1 overflow-x-auto">
        {instances.map((instance) => {
          const isActive = instance.key === activeKey
          return (
            <button
              key={instance.key}
              type="button"
              role="tab"
              aria-selected={isActive}
              onClick={() => onSelect(instance.key)}
              className={cn(
                'relative whitespace-nowrap px-3 py-2 font-mono uppercase tracking-wider transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                isActive
                  ? 'text-primary after:absolute after:inset-x-0 after:bottom-0 after:h-0.5 after:bg-primary'
                  : 'text-muted-foreground hover:text-foreground',
              )}
            >
              {instance.label}
            </button>
          )
        })}
      </div>
      {onCreate && (
        <button
          type="button"
          onClick={onCreate}
          className="flex items-center gap-1 border-l border-border px-3 py-2 font-mono text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          aria-label="Add new instance"
        >
          <Plus className="h-3.5 w-3.5" aria-hidden="true" />
        </button>
      )}
    </div>
  )
}
