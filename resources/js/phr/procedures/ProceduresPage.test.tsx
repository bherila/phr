import '@testing-library/jest-dom'

import { act, fireEvent, render, screen, within } from '@testing-library/react'

import { fetchWrapper } from '@/fetchWrapper'
import { recordChanged } from '@/phr/clinical/recordChanges'

import ProceduresPage from './ProceduresPage'

const PROCEDURE = {
  id: 27, patient_id: 42, user_id: 7, name: 'Synthetic procedure',
  performed_on: '2030-04-15', performed_at: null, status: 'completed', review_status: 'pending_review',
  cpt_code: '12345', snomed_code: null, performer_name: 'Synthetic clinician', performer_specialty: 'Synthetic specialty', facility_name: 'Synthetic clinic',
  reason: 'Synthetic reason', outcome: 'Synthetic outcome', notes: 'Synthetic notes', raw_text: null, created_at: null, updated_at: null,
}

afterEach(() => jest.restoreAllMocks())

it('uses its own width and keeps the compact date, status, review and keyboard detail route', async () => {
  jest.spyOn(fetchWrapper, 'get').mockResolvedValue({ procedures: [PROCEDURE], can_manage: true })
  const onDrill = jest.fn()
  render(<ProceduresPage patientId={42} onDrill={onDrill} />)
  const name = await screen.findByText('Synthetic procedure')
  const list = screen.getByRole('list', { name: 'Procedures' })
  expect(list).toHaveClass('@container/record-list')
  expect(list.querySelector('.grid')).toHaveClass('@[960px]/record-list:grid-cols-[150px_minmax(0,1fr)_auto]')
  const row = name.closest('li')!
  expect(within(row).getByRole('img', { name: 'Pending review' })).toHaveClass('@[560px]/record-list:hidden')
  expect(within(row).getByText(/2030-04-15 · completed · Synthetic clinician/)).toHaveClass('@[960px]/record-list:hidden')
  const edit = within(row).getByRole('button', { name: 'Edit Synthetic procedure' })
  expect(edit.parentElement).toHaveClass('hidden', '@[960px]/record-list:flex')
  expect(name.closest('[class*="wrap-anywhere"]')).toBeInTheDocument()
  fireEvent.keyDown(row, { key: 'Enter' })
  expect(onDrill).toHaveBeenCalledWith({ id: 'procedure-detail', instance: '27' })
})

it('refreshes the timeline after a detail save and preserves patient scope', async () => {
  const get = jest.spyOn(fetchWrapper, 'get').mockResolvedValueOnce({ procedures: [PROCEDURE], can_manage: false })
    .mockResolvedValueOnce({ procedures: [{ ...PROCEDURE, name: 'Changed from detail' }], can_manage: false })
  render(<ProceduresPage patientId={42} />)
  await screen.findByText('Synthetic procedure')
  act(() => recordChanged({ resource: 'procedures', patientId: 99, recordId: 27, origin: 'detail' }))
  expect(get).toHaveBeenCalledTimes(1)
  act(() => recordChanged({ resource: 'procedures', patientId: 42, recordId: 27, origin: 'detail' }))
  expect(await screen.findByText('Changed from detail')).toBeInTheDocument()
  expect(screen.queryByText('Synthetic procedure')).not.toBeInTheDocument()
  expect(get).toHaveBeenCalledTimes(2)
})
