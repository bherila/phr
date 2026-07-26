import type { Dispatch, SetStateAction } from 'react'
import { useCallback, useEffect, useRef, useState } from 'react'

import { fetchWrapper } from '@/fetchWrapper'
import { errorMessage } from '@/phr/shared'

export interface ClinicalRecordBase {
  id: number
}

export interface ClinicalListResult<TRecord> {
  records: TRecord[]
  canManage: boolean
}

interface UseClinicalCrudOptions<TRecord extends ClinicalRecordBase, TForm> {
  endpoint: string
  emptyForm: TForm
  formFromRecord: (record: TRecord) => TForm
  parseItem: (raw: unknown) => TRecord
  parseList: (raw: unknown) => ClinicalListResult<TRecord>
  payloadFromForm: (form: TForm) => Record<string, unknown>
  sortRecords?: (records: TRecord[]) => TRecord[]
}

export interface ClinicalCrudState<TRecord extends ClinicalRecordBase, TForm> {
  records: TRecord[]
  setRecords: Dispatch<SetStateAction<TRecord[]>>
  canManage: boolean
  busy: boolean
  error: string | null
  setError: Dispatch<SetStateAction<string | null>>
  editingId: number | null
  deletingId: number | null
  editForm: TForm
  setEditForm: Dispatch<SetStateAction<TForm>>
  mutatingKey: string | null
  addRecord: (form: TForm) => Promise<TRecord | null>
  patchRecord: (recordId: number, payload: Record<string, unknown>, mutationKey?: string) => Promise<TRecord | null>
  saveEdit: (recordId: number) => Promise<TRecord | null>
  deleteRecord: (recordId: number) => Promise<boolean>
  startEdit: (record: TRecord) => void
  cancelEdit: () => void
  startDelete: (recordId: number) => void
  cancelDelete: () => void
  isMutating: (key: string) => boolean
}

export function useClinicalCrud<TRecord extends ClinicalRecordBase, TForm>({
  endpoint,
  emptyForm,
  formFromRecord,
  parseItem,
  parseList,
  payloadFromForm,
  sortRecords,
}: UseClinicalCrudOptions<TRecord, TForm>): ClinicalCrudState<TRecord, TForm> {
  const [records, setRecords] = useState<TRecord[]>([])
  const [canManage, setCanManage] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [deletingId, setDeletingId] = useState<number | null>(null)
  const [editForm, setEditForm] = useState<TForm>(emptyForm)
  const [mutatingKey, setMutatingKey] = useState<string | null>(null)

  // Stable refs for callable options so the loader's effect doesn't re-fire
  // every render when the caller passes inline arrow functions.
  const parseListRef = useRef(parseList)
  const parseItemRef = useRef(parseItem)
  const payloadFromFormRef = useRef(payloadFromForm)
  const formFromRecordRef = useRef(formFromRecord)
  const sortRecordsRef = useRef(sortRecords)
  useEffect(() => {
    parseListRef.current = parseList
    parseItemRef.current = parseItem
    payloadFromFormRef.current = payloadFromForm
    formFromRecordRef.current = formFromRecord
    sortRecordsRef.current = sortRecords
  })

  const applySort = useCallback((nextRecords: TRecord[]): TRecord[] => {
    return sortRecordsRef.current ? sortRecordsRef.current(nextRecords) : nextRecords
  }, [])

  const load = useCallback(async () => {
    setBusy(true)
    setError(null)
    try {
      const rawRecords = await fetchWrapper.get(endpoint)
      const { records: parsedRecords, canManage: parsedCanManage } = parseListRef.current(rawRecords)
      setRecords(applySort(parsedRecords))
      setCanManage(parsedCanManage)
    } catch (caught) {
      setError(errorMessage(caught))
    } finally {
      setBusy(false)
    }
  }, [applySort, endpoint])

  useEffect(() => {
    void load()
  }, [load])

  function startEdit(record: TRecord): void {
    setDeletingId(null)
    setEditingId(record.id)
    setEditForm(formFromRecordRef.current(record))
  }

  function cancelEdit(): void {
    setEditingId(null)
    setEditForm(emptyForm)
  }

  function startDelete(recordId: number): void {
    setEditingId(null)
    setDeletingId(recordId)
  }

  function cancelDelete(): void {
    setDeletingId(null)
  }

  function replaceRecord(updatedRecord: TRecord): void {
    setRecords((current) => applySort(current.map((record) => (
      record.id === updatedRecord.id ? updatedRecord : record
    ))))
  }

  async function addRecord(form: TForm): Promise<TRecord | null> {
    setMutatingKey('add')
    setError(null)
    try {
      const raw: unknown = await fetchWrapper.post(endpoint, payloadFromFormRef.current(form))
      const addedRecord = parseItemRef.current(raw)
      setRecords((current) => applySort([addedRecord, ...current]))
      return addedRecord
    } catch (caught) {
      setError(errorMessage(caught))
      return null
    } finally {
      setMutatingKey(null)
    }
  }

  async function patchRecord(recordId: number, payload: Record<string, unknown>, mutationKey = `save:${recordId}`): Promise<TRecord | null> {
    setMutatingKey(mutationKey)
    setError(null)
    try {
      const raw: unknown = await fetchWrapper.patch(`${endpoint}/${recordId}`, payload)
      const updatedRecord = parseItemRef.current(raw)
      replaceRecord(updatedRecord)
      return updatedRecord
    } catch (caught) {
      setError(errorMessage(caught))
      return null
    } finally {
      setMutatingKey(null)
    }
  }

  async function saveEdit(recordId: number): Promise<TRecord | null> {
    const updatedRecord = await patchRecord(recordId, payloadFromFormRef.current(editForm))

    if (updatedRecord) {
      cancelEdit()
    }

    return updatedRecord
  }

  async function deleteRecord(recordId: number): Promise<boolean> {
    setMutatingKey(`delete:${recordId}`)
    setError(null)
    try {
      await fetchWrapper.delete(`${endpoint}/${recordId}`, {})
      setRecords((current) => current.filter((record) => record.id !== recordId))
      setDeletingId(null)
      if (editingId === recordId) {
        cancelEdit()
      }

      return true
    } catch (caught) {
      setError(errorMessage(caught))
      return false
    } finally {
      setMutatingKey(null)
    }
  }

  function isMutating(key: string): boolean {
    return mutatingKey === key
  }

  return {
    records,
    setRecords,
    canManage,
    busy,
    error,
    setError,
    editingId,
    deletingId,
    editForm,
    setEditForm,
    mutatingKey,
    addRecord,
    patchRecord,
    saveEdit,
    deleteRecord,
    startEdit,
    cancelEdit,
    startDelete,
    cancelDelete,
    isMutating,
  }
}
