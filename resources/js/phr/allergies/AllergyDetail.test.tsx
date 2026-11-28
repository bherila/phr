import '@testing-library/jest-dom'

import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'

import AllergyDetail from './AllergyDetail'

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
  reaction: 'Synthetic reaction',
  severity: 'mild',
  notes: null,
  raw_text: null,
  created_at: null,
  updated_at: null,
}

function jsonResponse(payload: unknown, status = 200): Response {
  return {
    ok: status >= 200 && status < 300,
    status,
    statusText: status === 404 ? 'Not Found' : 'OK',
    text: async () => payload === null ? '' : JSON.stringify(payload),
  } as Response
}

describe('AllergyDetail', () => {
  afterEach(() => {
    jest.restoreAllMocks()
  })

  it('edits in a dialog over the panel and shows what was saved', async () => {
    const fetchMock = jest.spyOn(globalThis, 'fetch').mockImplementation(async (_url, options) => {
      if (options?.method === 'PATCH') {
        return jsonResponse({ allergy: { ...ALLERGY, reaction: 'Updated synthetic reaction' } })
      }

      return jsonResponse({ allergy: ALLERGY, can_manage: true })
    })

    render(<AllergyDetail patientId={42} recordId="17" />)

    fireEvent.click(await screen.findByRole('button', { name: 'Edit' }))

    const dialog = await screen.findByRole('dialog', { name: 'Edit allergy' })
    expect(within(dialog).getByLabelText('Substance *')).toHaveValue('Synthetic allergen')
    fireEvent.change(within(dialog).getByLabelText('Reaction'), { target: { value: 'Updated synthetic reaction' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }))

    await waitFor(() => expect(screen.getByText('Updated synthetic reaction')).toBeInTheDocument())
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(screen.getByRole('button', { name: 'Edit' })).toBeInTheDocument()

    const patchCall = fetchMock.mock.calls.find(([, options]) => options?.method === 'PATCH')
    expect(patchCall?.[0]).toBe('/api/phr/patients/42/allergies/17')
    expect(JSON.parse(patchCall?.[1]?.body as string)).toMatchObject({
      substance: 'Synthetic allergen',
      reaction: 'Updated synthetic reaction',
      rxnorm_code: null,
    })
  })

  it('requires confirmation before deleting and returns to the allergy list', async () => {
    const onDrill = jest.fn()
    const fetchMock = jest.spyOn(globalThis, 'fetch').mockImplementation(async (_url, options) => (
      options?.method === 'DELETE'
        ? jsonResponse(null, 204)
        : jsonResponse({ allergy: ALLERGY, can_manage: true })
    ))

    render(<AllergyDetail patientId={42} recordId="17" onDrill={onDrill} />)

    fireEvent.click(await screen.findByRole('button', { name: 'Delete' }))
    const dialog = await screen.findByRole('alertdialog', { name: 'Delete this allergy?' })
    expect(dialog).toHaveTextContent("Synthetic allergen will be removed from this record. You can't undo this.")
    expect(fetchMock.mock.calls.some(([, options]) => options?.method === 'DELETE')).toBe(false)

    fireEvent.click(within(dialog).getByRole('button', { name: 'Delete' }))

    await waitFor(() => expect(onDrill).toHaveBeenCalledWith({ id: 'allergies' }))
    expect(fetchMock.mock.calls.some(([, options]) => options?.method === 'DELETE')).toBe(true)
  })

  it('reports a cleared substance on its field without saving', async () => {
    const fetchMock = jest.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ allergy: ALLERGY, can_manage: true }))
    // The spy outlives restoreAllMocks, so start from this test's own calls.
    fetchMock.mockClear()

    render(<AllergyDetail patientId={42} recordId="17" />)

    fireEvent.click(await screen.findByRole('button', { name: 'Edit' }))
    const dialog = await screen.findByRole('dialog', { name: 'Edit allergy' })
    fireEvent.change(within(dialog).getByLabelText('Substance *'), { target: { value: '  ' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Save' }))

    const substance = within(dialog).getByLabelText('Substance *')
    expect(substance).toHaveAttribute('aria-invalid', 'true')
    expect(substance).toHaveAccessibleDescription('Enter the substance.')
    await waitFor(() => expect(substance).toHaveFocus())
    expect(fetchMock.mock.calls.some(([, options]) => options?.method === 'PATCH')).toBe(false)
  })

  it('does not expose mutation controls to read-only viewers', async () => {
    jest.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse({ allergy: ALLERGY, can_manage: false }))

    render(<AllergyDetail patientId={42} recordId="17" />)

    expect(await screen.findByRole('heading', { name: 'Synthetic allergen' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Edit' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Delete' })).not.toBeInTheDocument()
  })
})


it('keeps the detail review bar actionable and uses the allergy review endpoint', async () => {
  jest.clearAllMocks()
  const fetchMock = jest.spyOn(globalThis, 'fetch').mockImplementation(async (_url, options) => jsonResponse({
    allergy: { ...ALLERGY, review_status: options?.method === 'PATCH' ? 'confirmed' : 'pending_review' }, can_manage: true,
  }))
  render(<AllergyDetail patientId={42} recordId="17" />)
  fireEvent.click(await screen.findByRole('button', { name: 'Approve Synthetic allergen' }))
  await waitFor(() => expect(screen.queryByTestId('record-review-bar')).not.toBeInTheDocument())
  expect(fetchMock.mock.calls.find(([, options]) => options?.method === 'PATCH')?.[0]).toBe('/api/phr/patients/42/allergies/17/review')
  jest.restoreAllMocks()
})
