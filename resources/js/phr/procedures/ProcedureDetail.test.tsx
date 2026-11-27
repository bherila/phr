import '@testing-library/jest-dom'

import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'

import { fetchWrapper } from '@/fetchWrapper'
import { recordChanged } from '@/phr/clinical/recordChanges'

import ProcedureDetail from './ProcedureDetail'

jest.mock('@/phr/review/reviewSummary', () => ({ reviewStateChanged: jest.fn().mockResolvedValue(undefined) }))

const PROCEDURE = {
  id: 27,
  patient_id: 42,
  user_id: 7,
  name: 'Synthetic procedure',
  cpt_code: null,
  snomed_code: null,
  performed_at: null,
  performed_on: '2030-04-15',
  performer_name: null,
  performer_specialty: null,
  facility_name: null,
  status: 'completed',
  reason: null,
  outcome: null,
  notes: null,
  raw_text: null,
  review_status: 'pending_review',
  eobs: [],
  created_at: null,
  updated_at: null,
}

function jsonResponse(payload: unknown): Response {
  return {
    ok: true,
    status: 200,
    statusText: 'OK',
    text: async () => JSON.stringify(payload),
  } as Response
}

describe('ProcedureDetail', () => {
  afterEach(() => {
    jest.restoreAllMocks()
  })

  it('shows when an agent-written procedure is pending review', async () => {
    jest.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ procedure: PROCEDURE, can_manage: false }))

    render(<ProcedureDetail patientId={42} recordId="27" />)

    expect(await screen.findByRole('heading', { name: 'Synthetic procedure' })).toBeInTheDocument()
    expect(screen.getByText('Pending review')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Edit Synthetic procedure' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Approve Synthetic procedure' })).not.toBeInTheDocument()
  })

  it('keeps edit and review reachable in the detail and announces each saved record', async () => {
    jest.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ procedure: PROCEDURE, can_manage: true }))
    const patch = jest.spyOn(fetchWrapper, 'patch').mockResolvedValue({ procedure: { ...PROCEDURE, name: 'Updated synthetic procedure' }, can_manage: true })
    const changed = jest.spyOn(window, 'dispatchEvent')
    render(<ProcedureDetail patientId={42} recordId="27" />)
    fireEvent.click(await screen.findByRole('button', { name: 'Edit Synthetic procedure' }))
    const form = screen.getByRole('form', { name: 'Edit procedure' })
    expect(screen.getByRole('button', { name: 'Approve Synthetic procedure' })).toBeDisabled()
    fireEvent.change(within(form).getByRole('textbox', { name: 'Name *' }), { target: { value: 'Updated synthetic procedure' } })
    fireEvent.click(within(form).getByRole('button', { name: 'Save' }))
    expect(await screen.findByRole('heading', { name: 'Updated synthetic procedure' })).toBeInTheDocument()
    expect(patch).toHaveBeenCalledWith('/api/phr/patients/42/procedures/27', expect.objectContaining({ name: 'Updated synthetic procedure', performed_on: '2030-04-15' }))
    expect(changed).toHaveBeenCalledWith(expect.objectContaining({ type: 'phr:record-changed', detail: { resource: 'procedures', patientId: 42, recordId: 27, origin: 'detail' } }))
    patch.mockResolvedValueOnce({ procedure: { ...PROCEDURE, name: 'Updated synthetic procedure', review_status: 'confirmed' }, can_manage: true })
    fireEvent.click(screen.getByRole('button', { name: 'Approve Updated synthetic procedure' }))
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Approve Updated synthetic procedure' })).not.toBeInTheDocument())
    expect(patch).toHaveBeenLastCalledWith('/api/phr/patients/42/procedures/27/review', { review_status: 'confirmed' })
  })

  it('keeps a refused edit and the typed fields on screen', async () => {
    jest.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ procedure: PROCEDURE, can_manage: true }))
    jest.spyOn(fetchWrapper, 'patch').mockRejectedValue(new Error('The record changed; reload before saving.'))
    render(<ProcedureDetail patientId={42} recordId="27" />)
    fireEvent.click(await screen.findByRole('button', { name: 'Edit Synthetic procedure' }))
    const name = screen.getByRole('textbox', { name: 'Name *' })
    fireEvent.change(name, { target: { value: 'Keep this synthetic edit' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('The record changed; reload before saving.')
    expect(name).toHaveValue('Keep this synthetic edit')
  })

  it('confirms deletion, retains a refusal, then returns to the list after success', async () => {
    jest.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ procedure: PROCEDURE, can_manage: true }))
    const remove = jest.spyOn(fetchWrapper, 'delete').mockRejectedValueOnce(new Error('Deletion refused.')).mockResolvedValueOnce({})
    const onDrill = jest.fn()
    render(<ProcedureDetail patientId={42} recordId="27" onDrill={onDrill} />)
    fireEvent.click(await screen.findByRole('button', { name: 'More actions for Synthetic procedure' }))
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Delete' }))
    const dialog = await screen.findByRole('alertdialog')
    expect(dialog).toHaveTextContent('Synthetic procedure')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Delete' }))
    expect(await within(dialog).findByRole('alert')).toHaveTextContent('Deletion refused.')
    expect(onDrill).not.toHaveBeenCalled()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Delete' }))
    await waitFor(() => expect(onDrill).toHaveBeenCalledWith({ id: 'procedures' }))
    expect(remove).toHaveBeenLastCalledWith('/api/phr/patients/42/procedures/27', {})
  })

  it('reloads list changes only for the selected patient and record', async () => {
    const fetch = jest.spyOn(fetchWrapper, 'getRaw').mockResolvedValueOnce(jsonResponse({ procedure: PROCEDURE, can_manage: false }))
      .mockResolvedValueOnce(jsonResponse({ procedure: { ...PROCEDURE, name: 'Changed from list' }, can_manage: false }))
    render(<ProcedureDetail patientId={42} recordId="27" />)
    await screen.findByRole('heading', { name: 'Synthetic procedure' })
    act(() => recordChanged({ resource: 'procedures', patientId: 99, recordId: 27, origin: 'list' }))
    expect(fetch).toHaveBeenCalledTimes(1)
    act(() => recordChanged({ resource: 'procedures', patientId: 42, recordId: 27, origin: 'list' }))
    expect(await screen.findByRole('heading', { name: 'Changed from list' })).toBeInTheDocument()
    expect(fetch).toHaveBeenCalledTimes(2)
  })

  it('preserves the review decision after a refusal and blocks overlapping mutations', async () => {
    jest.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ procedure: PROCEDURE, can_manage: true }))
    let rejectReview!: (reason: Error) => void
    const patch = jest.spyOn(fetchWrapper, 'patch').mockImplementation(() => new Promise((_resolve, reject) => { rejectReview = reject }))
    render(<ProcedureDetail patientId={42} recordId="27" />)
    const approve = await screen.findByRole('button', { name: 'Approve Synthetic procedure' })
    fireEvent.click(approve)
    expect(approve).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Edit Synthetic procedure' })).toBeDisabled()
    fireEvent.click(approve)
    expect(patch).toHaveBeenCalledTimes(1)
    await act(async () => rejectReview(new Error('This review changed; reload before approving.')))
    expect(await screen.findByRole('alert')).toHaveTextContent('This review changed; reload before approving.')
    expect(screen.getAllByText('Pending review')).toHaveLength(2)
    expect(approve).toBeEnabled()
  })

  it('does not navigate away from a newly selected screen when an earlier deletion completes', async () => {
    jest.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ procedure: PROCEDURE, can_manage: true }))
    let finishDelete!: (value: unknown) => void
    jest.spyOn(fetchWrapper, 'delete').mockImplementation(() => new Promise((resolve) => { finishDelete = resolve }))
    const onDrill = jest.fn()
    const { unmount } = render(<ProcedureDetail patientId={42} recordId="27" onDrill={onDrill} />)
    fireEvent.click(await screen.findByRole('button', { name: 'More actions for Synthetic procedure' }))
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Delete' }))
    fireEvent.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Delete' }))
    unmount()
    await act(async () => finishDelete({}))
    expect(onDrill).not.toHaveBeenCalled()
  })

})
