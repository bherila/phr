import type { ReactElement, ReactNode } from 'react'

import { AlertDialog, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from '@/components/ui/alert-dialog'
import { Button } from '@/components/ui/button'

interface ConfirmDeleteDialogProps {
  open: boolean
  /** What is deleted, as a question: "Delete this condition?" */
  title: string
  /** Names the record, so the person can see which one goes. */
  description: ReactNode
  busy: boolean
  /** A refused delete, shown inside the dialog: the page behind it is covered. */
  error?: string | null | undefined
  onConfirm: () => void
  onCancel: () => void
}

/**
 * Asks before a record is deleted, over the page rather than inside the list, so the rows do not
 * move. It stays open, busy, until the caller closes it: a refused delete leaves it open, and the
 * error is shown where the caller shows errors.
 */
export function ConfirmDeleteDialog({ open, title, description, busy, error = null, onConfirm, onCancel }: ConfirmDeleteDialogProps): ReactElement {
  return (
    <AlertDialog open={open} onOpenChange={(next) => { if (!next && !busy) onCancel() }}>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>{title}</AlertDialogTitle>
          <AlertDialogDescription>{description}</AlertDialogDescription>
        </AlertDialogHeader>
        {error && <p role="alert" className="text-sm text-destructive">{error}</p>}
        <AlertDialogFooter>
          <Button type="button" variant="outline" disabled={busy} onClick={onCancel}>Cancel</Button>
          <Button type="button" variant="destructive" disabled={busy} onClick={onConfirm}>{busy ? 'Deleting…' : 'Delete'}</Button>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  )
}
