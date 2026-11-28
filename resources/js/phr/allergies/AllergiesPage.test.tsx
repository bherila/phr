import '@testing-library/jest-dom'

import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'

import AllergiesPage from './AllergiesPage'
import { notifyAllergyChanged } from './allergyEvents'

const mockGet = jest.fn()
const mockPost = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: (...args: unknown[]) => mockGet(...args),
    post: (...args: unknown[]) => mockPost(...args),
  },
}))

const ALLERGY = {
  id: 17,
  patient_id: 42,
  user_id: 7,
  substance: 'Synthetic allergen',
  rxnorm_code: null,
  snomed_code: null,
  category: 'environment',
  criticality: 'low',
  clinical_status: 'active',
  verification_status: 'confirmed',
  review_status: 'pending_review' as const,
  reaction: 'Synthetic reaction',
  severity: 'mild',
  notes: null,
  raw_text: null,
  created_at: null,
  updated_at: null,
}

beforeEach(() => {
  mockPost.mockReset()
  mockGet.mockReset()
  mockGet.mockResolvedValue({ allergies: [ALLERGY], can_manage: true })
})

describe('AllergiesPage', () => {
  it('adds in a dialog, leaving the list where it is, then opens the new allergy', async () => {
    mockPost.mockResolvedValue({ allergy: { ...ALLERGY, id: 18, substance: 'Penicillin', review_status: 'confirmed' } })
    const onDrill = jest.fn()
    render(<AllergiesPage patientId={42} onDrill={onDrill} />)
    fireEvent.click(await screen.findByRole('button', { name: 'Add allergy' }))
    const dialog = await screen.findByRole('dialog', { name: 'Add allergy' })
    expect(screen.getByRole('table', { name: 'Active Allergies', hidden: true })).toBeInTheDocument()

    fireEvent.click(within(dialog).getByRole('button', { name: 'Add allergy' }))
    expect(within(dialog).getByLabelText('Substance *')).toHaveAccessibleDescription('Enter the substance.')
    expect(mockPost).not.toHaveBeenCalled()

    fireEvent.change(within(dialog).getByLabelText('Substance *'), { target: { value: 'Penicillin' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add allergy' }))
    await waitFor(() => expect(onDrill).toHaveBeenCalledWith({ id: 'allergy-detail', instance: '18' }))
    expect(mockPost).toHaveBeenCalledWith('/api/phr/patients/42/allergies', expect.objectContaining({ substance: 'Penicillin' }))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })

  it('opens detail from the table without rendering inline mutation actions', async () => {
    const onDrill = jest.fn()
    render(<AllergiesPage patientId={42} onDrill={onDrill} />)

    const row = (await screen.findByText('Synthetic allergen')).closest('tr')!
    expect(row).toHaveAttribute('tabindex', '0')
    row.focus()
    fireEvent.keyDown(row, { key: 'Enter' })

    expect(onDrill).toHaveBeenCalledWith({ id: 'allergy-detail', instance: '17' })
    expect(onDrill).toHaveBeenCalledTimes(1)
    expect(screen.queryByRole('button', { name: /edit allergy/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /delete allergy/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('columnheader', { name: 'Actions' })).not.toBeInTheDocument()
  })

  it('keeps the mounted list synchronized with detail mutations', async () => {
    render(<AllergiesPage patientId={42} />)
    await screen.findAllByText('Synthetic reaction')
    expect(screen.getByRole('img', { name: 'Pending review' })).toBeInTheDocument()

    notifyAllergyChanged({
      action: 'updated',
      allergy: { ...ALLERGY, reaction: 'Updated synthetic reaction' },
      patientId: 42,
    })
    await waitFor(() => expect(screen.getAllByText('Updated synthetic reaction')).toHaveLength(2))

    notifyAllergyChanged({ action: 'deleted', allergyId: ALLERGY.id, patientId: 42 })
    await waitFor(() => expect(screen.queryByText('Synthetic allergen')).not.toBeInTheDocument())
  })
})
