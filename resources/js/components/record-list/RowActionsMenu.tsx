import { MoreHorizontal } from 'lucide-react'
import type { ReactElement } from 'react'

import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { cn } from '@/lib/utils'

export interface RowAction {
  label: string
  onSelect: () => void
  destructive?: boolean | undefined
  disabled?: boolean | undefined
}

/**
 * The ⋯ menu of a row or a record's detail: the actions that change the record but are not its
 * review decision. `label` names the record, as every row button does.
 */
export function RowActionsMenu({ label, actions, disabled = false, triggerClassName }: {
  label: string
  actions: readonly RowAction[]
  disabled?: boolean | undefined
  triggerClassName?: string | undefined
}): ReactElement {
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button type="button" size="icon-sm" variant="ghost" className={cn('text-muted-foreground', triggerClassName)} aria-label={`More actions for ${label}`} title="More actions" disabled={disabled}>
          <MoreHorizontal className="size-4" aria-hidden="true" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        {actions.map((action) => (
          <DropdownMenuItem
            key={action.label}
            variant={action.destructive === true ? 'destructive' : 'default'}
            disabled={action.disabled}
            onClick={action.onSelect}
          >
            {action.label}
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
