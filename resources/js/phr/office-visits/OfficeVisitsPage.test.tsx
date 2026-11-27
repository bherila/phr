import '@testing-library/jest-dom'

import { act, fireEvent, render, screen, within } from '@testing-library/react'

import { fetchWrapper } from '@/fetchWrapper'
import { resetDisplayPreferences } from '@/lib/displayPreferences'
import { recordChanged } from '@/phr/clinical/recordChanges'

import OfficeVisitsPage from './OfficeVisitsPage'

jest.mock('@/phr/imports/ehi/EhiPendingBanner', () => ({ EhiPendingBanner: () => null }))

const VISIT = {
  id: 8, patient_id: 42, user_id: 7, visit_type: 'Synthetic follow-up', visit_date: '2030-04-15', visit_started_at: null, visit_ended_at: null,
  provider_name: 'Synthetic clinician', provider_specialty: null, facility_name: 'Synthetic clinic',
  chief_complaint: 'Synthetic complaint', assessment: null, plan: null, subjective: null, objective: null,
  icd10_codes: null, cpt_codes: null, review_status: 'pending_review', created_at: null, updated_at: null,
}
const SHOT = {
  id: 27, patient_id: 42, user_id: 7, name: 'Synthetic shot', performed_on: '2030-04-14', performed_at: null,
  status: 'completed', review_status: 'confirmed', cpt_code: '95115', snomed_code: null,
  performer_name: 'Synthetic clinician', performer_specialty: null, facility_name: 'Synthetic clinic',
  reason: null, outcome: null, notes: null, raw_text: null, created_at: null, updated_at: null,
}

beforeEach(() => resetDisplayPreferences())
afterEach(() => jest.restoreAllMocks())

it('uses the timeline width, preserves dates and review at compact sizes, and opens each record kind', async () => {
  jest.spyOn(fetchWrapper, 'get').mockImplementation(async (url) => {
    if (url.includes('/office-visits')) return { office_visits: [VISIT], can_manage: false }
    if (url.includes('/procedures')) return { procedures: [SHOT], can_manage: false }
    if (url.includes('/immunizations')) return { immunizations: [], can_manage: false }
    throw new Error(`Unexpected request: ${url}`)
  })
  const onDrill = jest.fn()
  render(<OfficeVisitsPage patientId={42} onDrill={onDrill} />)
  const title = await screen.findByText('Synthetic follow-up')
  const list = screen.getByRole('list', { name: 'Visit timeline' })
  expect(list).toHaveClass('@container/record-list')
  const row = title.closest('li')!
  expect(row.querySelector('.grid')).toHaveClass('@[960px]/record-list:grid-cols-[190px_minmax(0,1fr)_auto]')
  const compact = within(row).getByText(/2030-04-15 · Office Visit · 1 day since previous encounter/)
  expect(compact).toHaveClass('@[960px]/record-list:hidden')
  expect(within(row).getByRole('img', { name: 'Pending review' })).toHaveClass('@[560px]/record-list:hidden')
  expect(within(row).getByText('Synthetic complaint', { exact: false })).toHaveClass('hidden', '@[960px]/record-list:block')
  expect(title.closest('[class*="wrap-anywhere"]')).toBeInTheDocument()
  fireEvent.keyDown(row, { key: 'Enter' })
  expect(onDrill).toHaveBeenLastCalledWith({ id: 'office-visit-detail', instance: '8' })
  fireEvent.keyDown(screen.getByText('Single allergy injection').closest('li')!, { key: 'Enter' })
  expect(onDrill).toHaveBeenLastCalledWith({ id: 'procedure-detail', instance: '27' })
})

it('reloads changed procedures so a detail save reaches the combined timeline', async () => {
  let shotName = '95115'
  const get = jest.spyOn(fetchWrapper, 'get').mockImplementation(async (url) => {
    if (url.includes('/office-visits')) return { office_visits: [VISIT], can_manage: false }
    if (url.includes('/procedures')) return { procedures: [{ ...SHOT, cpt_code: shotName }], can_manage: false }
    if (url.includes('/immunizations')) return { immunizations: [], can_manage: false }
    throw new Error(`Unexpected request: ${url}`)
  })
  render(<OfficeVisitsPage patientId={42} />)
  await screen.findByText('Single allergy injection')
  act(() => recordChanged({ resource: 'procedures', patientId: 99, recordId: 27, origin: 'detail' }))
  expect(get).toHaveBeenCalledTimes(3)
  shotName = '95117'
  act(() => recordChanged({ resource: 'procedures', patientId: 42, recordId: 27, origin: 'detail' }))
  expect(await screen.findByText('Multiple allergy injections')).toBeInTheDocument()
  expect(screen.queryByText('Single allergy injection')).not.toBeInTheDocument()
  expect(get).toHaveBeenCalledTimes(6)
})
