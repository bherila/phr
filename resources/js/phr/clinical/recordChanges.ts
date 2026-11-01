import { useEffect, useRef } from 'react'

/** A list and the detail panel beside it load their own copies; each says when it changed one. */
export type RecordChangeOrigin = 'list' | 'detail'

export interface RecordChange {
  /** The section that owns the record, as in its URL. */
  resource: string
  patientId: number
  recordId: number
  origin: RecordChangeOrigin
}

const RECORD_CHANGED_EVENT = 'phr:record-changed'

export function recordChanged(change: RecordChange): void {
  window.dispatchEvent(new CustomEvent<RecordChange>(RECORD_CHANGED_EVENT, { detail: change }))
}

/** Runs `listener` when the other side (`from`) changes one of this patient's `resource` records. */
export function useRecordChanges(resource: string, patientId: number, from: RecordChangeOrigin, listener: (recordId: number) => void): void {
  const listenerRef = useRef(listener)
  useEffect(() => {
    listenerRef.current = listener
  })
  useEffect(() => {
    const handle = (event: Event): void => {
      const change = (event as CustomEvent<RecordChange>).detail
      if (change.resource === resource && change.patientId === patientId && change.origin === from) {
        listenerRef.current(change.recordId)
      }
    }
    window.addEventListener(RECORD_CHANGED_EVENT, handle)
    return () => window.removeEventListener(RECORD_CHANGED_EVENT, handle)
  }, [resource, patientId, from])
}
