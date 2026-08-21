import '@testing-library/jest-dom'

import { fireEvent, render, screen, waitFor } from '@testing-library/react'

import AllergiesPage from './AllergiesPage'
import { notifyAllergyChanged } from './allergyEvents'

const mockGet = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: (...args: unknown[]) => mockGet(...args),
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
  mockGet.mockReset()
  mockGet.mockResolvedValue({ allergies: [ALLERGY], can_manage: true })
})

describe('AllergiesPage', () => {
  it('opens detail from the table without rendering inline mutation actions', async () => {
    const onDrill = jest.fn()
    render(<AllergiesPage patientId={42} onDrill={onDrill} />)

    const detailButton = await screen.findByRole('button', { name: 'Synthetic allergen' })
    detailButton.focus()
    expect(detailButton).toHaveFocus()
    fireEvent.click(detailButton)

    expect(onDrill).toHaveBeenCalledWith({ id: 'allergy-detail', instance: '17' })
    expect(onDrill).toHaveBeenCalledTimes(1)
    expect(screen.queryByRole('button', { name: /edit allergy/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /delete allergy/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('columnheader', { name: 'Actions' })).not.toBeInTheDocument()
  })

  it('keeps the mounted list synchronized with detail mutations', async () => {
    render(<AllergiesPage patientId={42} />)
    await screen.findByText('Synthetic reaction')
    expect(screen.getByText('pending review')).toBeInTheDocument()

    notifyAllergyChanged({
      action: 'updated',
      allergy: { ...ALLERGY, reaction: 'Updated synthetic reaction' },
      patientId: 42,
    })
    await waitFor(() => expect(screen.getByText('Updated synthetic reaction')).toBeInTheDocument())

    notifyAllergyChanged({ action: 'deleted', allergyId: ALLERGY.id, patientId: 42 })
    await waitFor(() => expect(screen.queryByText('Synthetic allergen')).not.toBeInTheDocument())
  })
})
