import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'

import { recordChanged } from '@/phr/clinical/recordChanges'
import LabPanelDetail from '@/phr/labs/LabPanelDetail'
import LabsPage from '@/phr/labs/LabsPage'
import VitalsPage from '@/phr/vitals/VitalsPage'
import VitalsReadingDetail from '@/phr/vitals/VitalsReadingDetail'

const mockGet = jest.fn()
const mockPatch = jest.fn()
const mockDetail = jest.fn()
jest.mock('@/fetchWrapper', () => ({ fetchWrapper: {
  get: (...args: unknown[]) => mockGet(...args),
  patch: (...args: unknown[]) => mockPatch(...args),
} }))
jest.mock('@/phr/shared', () => ({ ...jest.requireActual('@/phr/shared'), fetchPhrDetail: (...args: unknown[]) => mockDetail(...args) }))
jest.mock('@/phr/imports/ehi/EhiPendingBanner', () => ({ EhiPendingBanner: () => null }))

const LAB = {
  id: 11, patient_id: 42, user_id: 7, test_name: 'Synthetic panel', analyte: 'Synthetic result A',
  collection_datetime: null, result_datetime: '2030-01-02', result_status: null,
  ordering_provider: null, resulting_lab: null, value: 'Not detected', value_numeric: null, unit: null,
  range_min: null, range_max: null, range_unit: null, reference_range_text: 'Not detected', normal_value: null,
  abnormal_flag: 'N', message_from_provider: null, result_comment: null, lab_director: null,
  source: null, notes: null, created_at: null, updated_at: null, review_status: 'pending_review',
} as const
const VITAL = {
  id: 21, patient_id: 42, user_id: 7, vital_name: 'Blood Pressure', vital_date: '2030-01-02', observed_at: null,
  vital_value: '120/80', value_numeric: '120', value_numeric_secondary: '80', unit: 'mmHg', secondary_unit: 'mmHg',
  body_site: 'Synthetic body site', source: null, notes: 'Synthetic reading note',
  created_at: null, updated_at: null, review_status: 'pending_review',
} as const
const PANEL = {
  id: 11, panel_name: 'Synthetic panel', collection_datetime: null, ordering_provider: null, resulting_lab: null,
  source: null, source_document_id: null, source_document_url: null,
  rows: [{ ...LAB, trend: null }, { ...LAB, id: 12, analyte: 'Synthetic result B', trend: null }],
}
function details(canManage: boolean): void {
  mockDetail.mockImplementation(async (url: string) => ({ notFound: false, data: url.includes('/labs/')
    ? { panel: PANEL } : url.includes('/lab-results/') ? { lab_result: LAB, can_manage: canManage } : { vital: VITAL, can_manage: canManage } }))
}

beforeEach(() => { mockGet.mockReset(); mockPatch.mockReset(); mockDetail.mockReset(); details(true) })
afterEach(() => { jest.restoreAllMocks() })

it('keeps lab values, flags and dates in a compact summary, with own-width columns', async () => {
  mockGet.mockResolvedValue({ lab_results: [LAB], can_manage: true })
  render(<LabsPage patientId={42} onDrill={jest.fn()} />)
  const name = await screen.findByText(LAB.analyte)
  const table = name.closest('table')!
  expect(table.parentElement).toHaveClass('@container/record-list')
  expect(within(table).getByRole('columnheader', { name: 'Range' })).toHaveClass('hidden', '@[960px]/record-list:table-cell')
  expect(within(table).getByRole('columnheader', { name: 'Value' })).toHaveClass('hidden', '@[560px]/record-list:table-cell')
  expect(name.closest('td')).toHaveTextContent('Not detected')
  expect(name.closest('td')).toHaveTextContent('2030-01-02')
  expect(within(table).getByRole('img', { name: 'Pending review' })).toBeInTheDocument()
  act(() => recordChanged({ resource: 'lab-results', patientId: 42, recordId: 11, origin: 'detail' }))
  await waitFor(() => expect(mockGet).toHaveBeenCalledTimes(2))
})

it('keeps vital value, unit and date together and retains actions in detail', async () => {
  mockGet.mockResolvedValue({ vitals: [VITAL], can_manage: true })
  const onDrill = jest.fn()
  render(<VitalsPage patientId={42} onDrill={onDrill} />)
  const name = await screen.findByText(VITAL.vital_name)
  expect(name.closest('table')!.parentElement).toHaveClass('@container/record-list')
  expect(name.closest('td')).toHaveTextContent('120/80 mmHg2030-01-02')
  expect(screen.getByRole('columnheader', { name: 'Body Site' })).toHaveClass('@[960px]/record-list:table-cell')
  fireEvent.click(name)
  expect(onDrill).toHaveBeenCalledWith({ id: 'vitals-reading-detail', instance: '21' })
  act(() => recordChanged({ resource: 'vitals', patientId: 42, recordId: 21, origin: 'detail' }))
  await waitFor(() => expect(mockGet).toHaveBeenCalledTimes(2))
})

it('reviews only the selected lab result, never the entire panel, and notifies the list', async () => {
  const dispatch = jest.spyOn(window, 'dispatchEvent')
  mockPatch.mockResolvedValue({ lab_result: { ...LAB, review_status: 'confirmed' } })
  render(<LabPanelDetail patientId={42} recordId="11" />)
  const approve = await screen.findByRole('button', { name: 'Approve Synthetic result A' })
  expect(screen.queryByRole('button', { name: 'Approve Synthetic result B' })).not.toBeInTheDocument()
  expect(screen.getByText('Selected result: Synthetic result A')).toBeInTheDocument()
  fireEvent.click(approve)
  await waitFor(() => expect(mockPatch).toHaveBeenCalledWith('/api/phr/patients/42/lab-results/11/review', { review_status: 'confirmed' }))
  await waitFor(() => expect(screen.queryByRole('button', { name: 'Approve Synthetic result A' })).not.toBeInTheDocument())
  expect(dispatch).toHaveBeenCalledWith(expect.objectContaining({ type: 'phr:record-changed', detail: { resource: 'lab-results', patientId: 42, recordId: 11, origin: 'detail' } }))
})

it('keeps lab review refusals visible and retryable with the selected result unchanged', async () => {
  mockPatch.mockRejectedValue(new Error('Synthetic result changed; reload before deciding.'))
  render(<LabPanelDetail patientId={42} recordId="11" />)
  fireEvent.click(await screen.findByRole('button', { name: 'Approve Synthetic result A' }))
  expect(await screen.findByRole('alert')).toHaveTextContent('Synthetic result changed')
  expect(screen.getByRole('button', { name: 'Approve Synthetic result A' })).toBeEnabled()
  expect(screen.getByText('Selected result: Synthetic result A')).toBeInTheDocument()
})

it('does not expose mutations to a lab reader', async () => {
  details(false)
  render(<LabPanelDetail patientId={42} recordId="11" />)
  await screen.findByText('Selected result: Synthetic result A')
  expect(screen.queryByRole('button', { name: /Approve|Reject/ })).not.toBeInTheDocument()
  expect(mockPatch).not.toHaveBeenCalled()
})

it('keeps the reading review and both blood-pressure trends reachable in detail', async () => {
  mockPatch.mockResolvedValue({ vital: { ...VITAL, review_status: 'confirmed' } })
  const onDrill = jest.fn()
  render(<VitalsReadingDetail patientId={42} recordId="21" onDrill={onDrill} />)
  fireEvent.click(await screen.findByRole('button', { name: 'Diastolic BP trend' }))
  expect(onDrill).toHaveBeenCalledWith({ id: 'vitals-trend', instance: 'diastolic_bp' })
  fireEvent.click(screen.getByRole('button', { name: 'Approve Blood Pressure' }))
  await waitFor(() => expect(mockPatch).toHaveBeenCalledWith('/api/phr/patients/42/vitals/21/review', { review_status: 'confirmed' }))
  await waitFor(() => expect(screen.queryByRole('button', { name: 'Approve Blood Pressure' })).not.toBeInTheDocument())
})

it('does not expose reading mutations to a viewer, but keeps trends available', async () => {
  details(false)
  render(<VitalsReadingDetail patientId={42} recordId="21" onDrill={jest.fn()} />)
  await screen.findByRole('heading', { name: 'Blood Pressure' })
  expect(screen.queryByRole('button', { name: /Approve|Reject/ })).not.toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Systolic BP trend' })).toBeEnabled()
})

it('ignores a completed review from the previously selected reading', async () => {
  let finish: (value: unknown) => void = () => undefined
  mockPatch.mockReturnValue(new Promise((resolve) => { finish = resolve }))
  const { rerender } = render(<VitalsReadingDetail patientId={42} recordId="21" />)
  fireEvent.click(await screen.findByRole('button', { name: 'Approve Blood Pressure' }))
  mockDetail.mockResolvedValue({ notFound: false, data: { vital: { ...VITAL, id: 22, vital_name: 'Synthetic next reading', review_status: 'confirmed' }, can_manage: true } })
  rerender(<VitalsReadingDetail patientId={42} recordId="22" />)
  await screen.findByRole('heading', { name: 'Synthetic next reading' })
  await act(async () => finish({ vital: { ...VITAL, review_status: 'confirmed' } }))
  expect(screen.getByRole('heading', { name: 'Synthetic next reading' })).toBeInTheDocument()
  expect(screen.queryByRole('heading', { name: 'Blood Pressure' })).not.toBeInTheDocument()
})
