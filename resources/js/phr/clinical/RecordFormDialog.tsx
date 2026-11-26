import type { FormEvent, ReactElement, ReactNode, RefObject } from 'react'

import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'

interface RecordFormDialogProps {
  title: string
  /** What the form holds, for a screen reader; the fields say it to everyone else. */
  description: string
  submitLabel: string
  busy: boolean
  /** A refused save, shown inside the dialog: the page behind it is covered. */
  error?: string | null | undefined
  onSubmit: (event: FormEvent<HTMLFormElement>) => void
  onClose: () => void
  formRef?: RefObject<HTMLFormElement | null>
  children: ReactNode
}

/**
 * Adds or edits a record over the page, so the list beside it never moves and a detail panel
 * keeps its place. A click outside does not discard what was typed; Cancel and Esc do.
 */
export function RecordFormDialog({ title, description, submitLabel, busy, error = null, onSubmit, onClose, formRef, children }: RecordFormDialogProps): ReactElement {
  return (
    <Dialog open onOpenChange={(open) => { if (!open && !busy) onClose() }} disablePointerDismissal>
      <DialogContent className="max-h-dvh overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription className="sr-only">{description}</DialogDescription>
        </DialogHeader>
        <form ref={formRef} noValidate onSubmit={onSubmit} className="space-y-4">
          {error && <div role="alert" className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">{error}</div>}
          {children}
          <DialogFooter>
            <Button type="button" variant="outline" disabled={busy} onClick={onClose}>Cancel</Button>
            <Button type="submit" disabled={busy}>{busy ? 'Saving…' : submitLabel}</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
