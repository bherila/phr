import { type ReactElement, useState } from 'react'

import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { Button } from '@/components/ui/button'
import { fetchWrapper } from '@/fetchWrapper'
import { reviewStateChanged } from '@/phr/review/reviewSummary'
import { errorMessage } from '@/phr/shared'

export interface EobDeleteActionProps {
  patientId: number
  eobId: number
  /** What the person sees the claim as, e.g. "claim CLM-1 from Example Provider". */
  claimLabel: string
  /** Where to go once the EOB is deleted: the patient it belonged to. */
  returnUrl: string
  /** Leaves the page; a seam for tests. */
  navigate?: (url: string) => void
}

/**
 * Deletes the EOB on its own page (#444), after the person confirms. The claim leaves every
 * list; its lines, correction history and links are kept but no longer count, so a visit,
 * procedure or fill stops showing it.
 */
export function EobDeleteAction({ patientId, eobId, claimLabel, returnUrl, navigate = (url) => window.location.assign(url) }: EobDeleteActionProps): ReactElement {
  const [open, setOpen] = useState(false)
  const [deleting, setDeleting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function remove(): Promise<void> {
    setDeleting(true)
    setError(null)
    try {
      await fetchWrapper.delete(`/api/phr/patients/${patientId}/eobs/${eobId}`, {})
      // A claim still pending review leaves the review counts with it.
      void reviewStateChanged(patientId)
      navigate(returnUrl)
    } catch (caught) {
      setError(errorMessage(caught))
      setDeleting(false)
      setOpen(false)
    }
  }

  return (
    <div className="space-y-2">
      <Button type="button" variant="destructive" onClick={() => setOpen(true)}>Delete EOB</Button>
      {error !== null && <p role="alert" className="text-sm text-destructive">{error}</p>}
      <AlertDialog open={open} onOpenChange={(next) => { if (!next && !deleting) setOpen(false) }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete this EOB?</AlertDialogTitle>
            <AlertDialogDescription>
              The {claimLabel} will be removed, and visits, procedures and fills will no longer show it. Importing the same claim again will not bring it back.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={deleting}>Cancel</AlertDialogCancel>
            <AlertDialogAction disabled={deleting} onClick={() => void remove()}>{deleting ? 'Deleting…' : 'Delete'}</AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}

/** Reads the mount point the EOB page renders, or null when it offers no delete. */
export function eobDeleteMountProps(mount: HTMLElement): Omit<EobDeleteActionProps, 'navigate'> | null {
  const { patientId, eobId, canDelete, claimLabel, returnUrl } = mount.dataset
  const patient = Number(patientId)
  const eob = Number(eobId)
  if (canDelete !== 'true' || !Number.isInteger(patient) || !Number.isInteger(eob) || patient < 1 || eob < 1 || !returnUrl) {
    return null
  }

  return { patientId: patient, eobId: eob, claimLabel: claimLabel ?? 'claim', returnUrl }
}
