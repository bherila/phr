import '@testing-library/jest-dom'

import { act, fireEvent, render, screen, within } from '@testing-library/react'

import { fetchWrapper } from '@/fetchWrapper'
import { resetDisplayPreferences } from '@/lib/displayPreferences'
import { recordChanged } from '@/phr/clinical/recordChanges'

import { MedicationFillHistory } from './MedicationFillHistory'

const FILL = {
  id: 12,
  patient_id: 42,
  medication_id: 7,
  drug_as_printed: 'Synthetic medicine',
  filled_on: '2030-04-15',
  quantity: '90.000',
  paid_cents: 1250,
  pharmacy_name: 'Raw pharmacy',
  display: { pharmacy_name: 'Synthetic pharmacy' },
  review_status: 'pending_review',
}

afterEach(() => jest.restoreAllMocks())
beforeEach(() => resetDisplayPreferences())

it('keeps date, review, quantity, paid and pharmacy available in the narrow inset', async () => {
  jest.spyOn(fetchWrapper, 'get').mockResolvedValue({ medication_fills: [FILL] })
  const onDrill = jest.fn()
  render(<MedicationFillHistory patientId={42} medicationId={7} onDrill={onDrill} />)
  const table = await screen.findByRole('table', { name: 'Fill history' })
  const link = await within(table).findByRole('link', { name: 'Synthetic medicine fill 2030-04-15' })
  const row = link.closest('tr')!
  expect(table.parentElement).toHaveClass('@container/record-list')
  expect(table.className).not.toMatch(/min-w-\[/)
  expect(within(table).getByRole('columnheader', { name: 'Qty' })).toHaveClass('hidden', '@[560px]/record-list:table-cell')
  expect(within(table).getByRole('columnheader', { name: 'Paid' })).toHaveClass('hidden', '@[560px]/record-list:table-cell')
  expect(within(table).getByRole('columnheader', { name: 'Pharmacy' })).toHaveClass('hidden', '@[960px]/record-list:table-cell')
  const primaryCell = link.closest('td')!
  expect(primaryCell).not.toHaveClass('whitespace-nowrap')
  expect(within(primaryCell).getByRole('img', { name: 'Pending review' })).toBeInTheDocument()
  expect(primaryCell).toHaveTextContent('90 · $12.50 · Synthetic pharmacy')
  expect(within(primaryCell).getByText('90 · $12.50 ·')).toHaveClass('@[560px]/record-list:hidden')
  expect(within(primaryCell).getByText(/Synthetic pharmacy/)).toHaveClass('@[960px]/record-list:hidden')
  fireEvent.keyDown(row, { key: 'Enter' })
  expect(onDrill).toHaveBeenCalledWith({ id: 'medication-fill-detail', instance: '12' })
  expect(screen.getByRole('link', { name: 'Show these fills' })).toHaveAttribute('href', '/phr/patient/42/medication-fills?medication_id=7')
})

it('refreshes after another panel changes a fill without treating another patient as current', async () => {
  const get = jest.spyOn(fetchWrapper, 'get').mockResolvedValueOnce({ medication_fills: [FILL] })
    .mockResolvedValueOnce({ medication_fills: [{ ...FILL, filled_on: '2030-04-16', review_status: 'confirmed' }] })
  render(<MedicationFillHistory patientId={42} medicationId={7} />)
  await screen.findByRole('link', { name: 'Synthetic medicine fill 2030-04-15' })
  act(() => recordChanged({ resource: 'medication-fills', patientId: 99, recordId: 12, origin: 'detail' }))
  expect(get).toHaveBeenCalledTimes(1)
  act(() => recordChanged({ resource: 'medication-fills', patientId: 42, recordId: 12, origin: 'detail' }))
  expect(await screen.findByRole('link', { name: 'Synthetic medicine fill 2030-04-16' })).toBeInTheDocument()
  expect(screen.queryByRole('img', { name: 'Pending review' })).not.toBeInTheDocument()
  expect(get).toHaveBeenCalledTimes(2)
})
