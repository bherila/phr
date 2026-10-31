import { Fragment, type ReactElement } from 'react'

import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog'
import { keyLabel, type ShortcutBinding } from '@/lib/shortcuts'

interface ShortcutsDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** The registry's bindings. The dialog lists exactly these, grouped in their order. */
  bindings: readonly ShortcutBinding[]
}

function groupBindings(bindings: readonly ShortcutBinding[]): [string, ShortcutBinding[]][] {
  const groups = new Map<string, ShortcutBinding[]>()
  for (const binding of bindings) {
    const group = groups.get(binding.group) ?? []
    group.push(binding)
    groups.set(binding.group, group)
  }
  return Array.from(groups)
}

function Keys({ binding }: { binding: ShortcutBinding }): ReactElement {
  return (
    <span className="flex flex-wrap items-center gap-1">
      {binding.keys.map((sequence, index) => (
        <Fragment key={sequence.join(' ')}>
          {index > 0 && <span className="text-xs text-muted-foreground">or</span>}
          {sequence.map((token, position) => (
            <Fragment key={token}>
              {position > 0 && <span className="text-xs text-muted-foreground">then</span>}
              <kbd className="rounded border border-border bg-muted px-1.5 py-0.5 font-mono text-xs text-foreground">{keyLabel(token)}</kbd>
            </Fragment>
          ))}
        </Fragment>
      ))}
    </span>
  )
}

/** The keyboard shortcuts dialog (`?`), built from the same bindings the listener dispatches. */
export function ShortcutsDialog({ open, onOpenChange, bindings }: ShortcutsDialogProps): ReactElement {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[85dvh] overflow-y-auto sm:max-w-xl">
        <DialogTitle>Keyboard shortcuts</DialogTitle>
        <DialogDescription>Shortcuts pause while you type in a field. No single key changes a record.</DialogDescription>
        {groupBindings(bindings).map(([group, entries]) => (
          <section key={group} aria-label={group} className="grid gap-2">
            <h3 className="text-sm font-semibold text-foreground">{group}</h3>
            <table className="w-full text-sm">
              <tbody>
                {entries.map((binding) => (
                  <tr key={binding.id} data-shortcut={binding.id} className="border-t border-border first:border-t-0">
                    <td className="w-44 py-1.5 pr-3 align-top"><Keys binding={binding} /></td>
                    <td className="py-1.5 text-muted-foreground">{binding.description}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </section>
        ))}
      </DialogContent>
    </Dialog>
  )
}
