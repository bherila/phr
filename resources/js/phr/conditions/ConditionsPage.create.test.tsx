import '@testing-library/jest-dom'

import { fireEvent, render, screen, within } from '@testing-library/react'

const mockGet = jest.fn()
const mockPost = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: (...args: unknown[]) => mockGet(...args),
    post: (...args: unknown[]) => mockPost(...args),
    patch: jest.fn(),
    delete: jest.fn(),
  },
}))

import ConditionsPage from '@/phr/conditions/ConditionsPage'

/**
 * The create response as the server now returns it: the record a later GET
 * returns, database defaults included (issue 253).
 */
function createdCondition(overrides: Record<string, unknown> = {}) {
  return {
    id: 21,
    patient_id: 2,
    user_id: 1,
    import_source: null,
    external_id: null,
    source_document_id: null,
    review_status: 'confirmed',
    name: 'Synthetic migraine',
    icd10_code: null,
    snomed_code: null,
    onset_date: null,
    abated_date: null,
    clinical_status: 'active',
    verification_status: 'confirmed',
    severity: null,
    notes: null,
    raw_text: null,
    created_at: '2026-03-04 05:06:07',
    updated_at: '2026-03-04 05:06:07',
    ...overrides,
  }
}

async function addCondition(name: string): Promise<void> {
  fireEvent.click(await screen.findByRole('button', { name: 'Add Condition' }))
  fireEvent.change(screen.getByRole('textbox', { name: 'Name *' }), { target: { value: name } })
  fireEvent.click(screen.getByRole('button', { name: 'Add' }))
}

describe('Adding a condition', () => {
  beforeEach(() => {
    mockGet.mockReset()
    mockPost.mockReset()
    mockGet.mockResolvedValue({ conditions: [], can_manage: true })
  })

  it('shows the new condition, with no error, from the create response', async () => {
    mockPost.mockResolvedValue({ condition: createdCondition() })
    render(<ConditionsPage patientId={2} />)

    await addCondition('Synthetic migraine')

    const table = await screen.findByRole('table', { name: 'Active Problems' })
    expect(await within(table).findByText('Synthetic migraine')).toBeInTheDocument()
    expect(mockPost).toHaveBeenCalledWith('/api/phr/patients/2/conditions', expect.objectContaining({ name: 'Synthetic migraine' }))
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    expect(screen.queryByText('No conditions recorded.')).not.toBeInTheDocument()
    // The add form closes on success.
    expect(screen.queryByRole('textbox', { name: 'Name *' })).not.toBeInTheDocument()
  })

  it('still refuses a create response without a review status rather than show an incomplete record', async () => {
    mockPost.mockResolvedValue({ condition: createdCondition({ review_status: null }) })
    render(<ConditionsPage patientId={2} />)

    await addCondition('Synthetic migraine')

    expect(await screen.findByRole('alert')).toBeInTheDocument()
    expect(screen.queryByText('Synthetic migraine')).not.toBeInTheDocument()
  })
})
