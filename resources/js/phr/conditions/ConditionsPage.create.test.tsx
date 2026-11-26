import '@testing-library/jest-dom'

import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'

const mockGet = jest.fn()
const mockPost = jest.fn()
const mockPatch = jest.fn()
const mockDelete = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: (...args: unknown[]) => mockGet(...args),
    post: (...args: unknown[]) => mockPost(...args),
    patch: (...args: unknown[]) => mockPatch(...args),
    delete: (...args: unknown[]) => mockDelete(...args),
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
  fireEvent.click(await screen.findByRole('button', { name: 'Add condition' }))
  const dialog = await screen.findByRole('dialog', { name: 'Add condition' })
  fireEvent.change(within(dialog).getByRole('textbox', { name: 'Name *' }), { target: { value: name } })
  fireEvent.click(within(dialog).getByRole('button', { name: 'Add condition' }))
}

describe('Adding a condition', () => {
  beforeEach(() => {
    mockGet.mockReset()
    mockPost.mockReset()
    mockGet.mockResolvedValue({ conditions: [], can_manage: true })
  })

  it('shows the new condition, with no error, from the create response', async () => {
    mockPost.mockResolvedValue({ condition: createdCondition() })
    const onDrill = jest.fn()
    render(<ConditionsPage patientId={2} onDrill={onDrill} />)

    await addCondition('Synthetic migraine')

    const table = await screen.findByRole('table', { name: 'Active Problems' })
    expect(await within(table).findByText('Synthetic migraine')).toBeInTheDocument()
    expect(mockPost).toHaveBeenCalledWith('/api/phr/patients/2/conditions', expect.objectContaining({ name: 'Synthetic migraine' }))
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    expect(screen.queryByText('No conditions recorded.')).not.toBeInTheDocument()
    // The dialog closes on success, and the new condition opens.
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(onDrill).toHaveBeenCalledWith({ id: 'condition-detail', instance: '21' })
  })

  it('reports a missing name on the field, in its own words, without sending anything', async () => {
    render(<ConditionsPage patientId={2} />)
    fireEvent.click(await screen.findByRole('button', { name: 'Add condition' }))
    const dialog = await screen.findByRole('dialog', { name: 'Add condition' })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Add condition' }))
    const name = within(dialog).getByRole('textbox', { name: 'Name *' })
    expect(name).toHaveAttribute('aria-invalid', 'true')
    expect(name).toHaveAccessibleDescription("Enter the condition's name.")
    await waitFor(() => expect(name).toHaveFocus())
    expect(screen.queryByText(/Too small/)).toBeNull()
    expect(mockPost).not.toHaveBeenCalled()
  })

  it('still refuses a create response without a review status rather than show an incomplete record', async () => {
    mockPost.mockResolvedValue({ condition: createdCondition({ review_status: null }) })
    render(<ConditionsPage patientId={2} />)

    await addCondition('Synthetic migraine')

    // The refusal is shown in the dialog, which stays open with what was typed.
    const dialog = screen.getByRole('dialog', { name: 'Add condition' })
    expect(await within(dialog).findByRole('alert')).toBeInTheDocument()
    expect(within(dialog).getByRole('textbox', { name: 'Name *' })).toHaveValue('Synthetic migraine')
    expect(screen.queryByRole('table', { hidden: true })).toBeNull()
  })
})

describe('Editing and deleting a condition from the list', () => {
  beforeEach(() => {
    mockGet.mockReset()
    mockPatch.mockReset()
    mockDelete.mockReset()
    mockGet.mockResolvedValue({ conditions: [createdCondition({ name: 'Asthma' })], can_manage: true })
  })

  it('edits in a dialog, leaving the rows where they are', async () => {
    mockPatch.mockResolvedValue({ condition: createdCondition({ name: 'Asthma, mild intermittent' }) })
    render(<ConditionsPage patientId={2} />)
    fireEvent.click(await screen.findByRole('button', { name: 'Edit Asthma' }))
    const dialog = await screen.findByRole('dialog', { name: 'Edit condition' })
    expect(screen.getAllByRole('row', { hidden: true })).toHaveLength(2)
    fireEvent.change(within(dialog).getByRole('textbox', { name: 'Name *' }), { target: { value: 'Asthma, mild intermittent' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }))
    expect(await screen.findByText('Asthma, mild intermittent')).toBeInTheDocument()
    expect(mockPatch).toHaveBeenCalledWith('/api/phr/patients/2/conditions/21', expect.objectContaining({ name: 'Asthma, mild intermittent' }))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })

  it('asks before deleting, and keeps the dialog open with the reason when the delete is refused', async () => {
    mockDelete.mockRejectedValueOnce({ message: 'This condition changed since the page loaded.' }).mockResolvedValueOnce({})
    render(<ConditionsPage patientId={2} />)
    fireEvent.click(await screen.findByRole('button', { name: 'Delete Asthma' }))
    const dialog = await screen.findByRole('alertdialog', { name: 'Delete this condition?' })
    expect(dialog).toHaveTextContent("Asthma will be removed from this record. You can't undo this.")
    fireEvent.click(within(dialog).getByRole('button', { name: 'Delete' }))
    expect(await within(dialog).findByRole('alert')).toHaveTextContent('changed since the page loaded')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Delete' }))
    await waitFor(() => expect(screen.queryByText('Asthma')).not.toBeInTheDocument())
    expect(mockDelete).toHaveBeenCalledWith('/api/phr/patients/2/conditions/21', {})
  })
})
