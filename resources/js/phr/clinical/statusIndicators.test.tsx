import { fireEvent, render, screen, within } from '@testing-library/react'

import type { StatusTone } from '@/components/record-list/StatusDot'
import AllergiesPage from '@/phr/allergies/AllergiesPage'
import ConditionsPage from '@/phr/conditions/ConditionsPage'
import ProceduresPage from '@/phr/procedures/ProceduresPage'

import { statusBadge } from './ui'

const mockGet = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: (...args: unknown[]) => mockGet(...args),
  },
}))

const RECORD = {
  id: 17, patient_id: 42, user_id: 7,
  review_status: 'confirmed', notes: null, raw_text: null,
  created_at: null, updated_at: null,
}

function expectIndicator(element: HTMLElement, tone: StatusTone): void {
  expect(element).toHaveAttribute('data-tone', tone)
  expect(element).toHaveClass('text-foreground')
  expect(element.firstElementChild).toHaveAttribute('aria-hidden', 'true')
  expect(element.firstElementChild).toHaveClass('rounded-full')
}

function expectRowIndicators(row: HTMLElement, label: string, tone: StatusTone): void {
  for (const element of within(row).getAllByText(label)) expectIndicator(element, tone)
}

beforeEach(() => {
  mockGet.mockReset()
})

describe('clinical status indicators', () => {
  it.each(['success', 'warning', 'danger', 'neutral'] as const)('keeps the %s label readable alongside a decorative dot', (tone) => {
    render(<>{statusBadge('some_status', { some_status: tone })}</>)
    expectIndicator(screen.getByText('some status'), tone)
  })

  it.each(['legacy_status', 'toString', 'constructor'])('keeps an unknown value %s as a neutral label', (value) => {
    render(<>{statusBadge(value, {})}</>)
    expectIndicator(screen.getByText(value.replaceAll('_', ' ')), 'neutral')
  })

  it('omits absent optional values', () => {
    const { container } = render(<>{statusBadge(null, {})}{statusBadge('', {})}</>)
    expect(container).toBeEmptyDOMElement()
  })
})

describe('condition status', () => {
  it.each([
    ['active', 'danger'], ['recurrence', 'danger'], ['relapse', 'danger'],
    ['inactive', 'neutral'], ['remission', 'warning'], ['resolved', 'success'],
    ['legacy_status', 'neutral'],
  ] as const)('shows %s with a %s dot in the record list', async (status, tone) => {
    mockGet.mockResolvedValue({
      conditions: [{
        ...RECORD, name: 'Synthetic condition', clinical_status: status,
        icd10_code: null, snomed_code: null, onset_date: null, abated_date: null,
        verification_status: 'confirmed', severity: null,
      }],
      can_manage: false,
    })
    render(<ConditionsPage patientId={42} />)
    if (!['active', 'recurrence', 'relapse'].includes(status)) {
      fireEvent.click(await screen.findByRole('button', { name: /Resolved and Inactive/ }))
    }

    const row = (await screen.findByText('Synthetic condition')).closest('tr')!
    expectRowIndicators(row, status.replaceAll('_', ' '), tone)
    expect(within(row).queryByText('Confirmed')).not.toBeInTheDocument()
    expect(mockGet).toHaveBeenCalledWith('/api/phr/patients/42/conditions')
  })
})

describe('allergy status, criticality and severity', () => {
  it.each([
    ['active', 'danger', 'high', 'danger', 'severe', 'danger'],
    ['inactive', 'neutral', 'low', 'warning', 'moderate', 'neutral'],
    ['resolved', 'success', 'unable_to_assess', 'neutral', 'mild', 'neutral'],
    ['legacy_status', 'neutral', 'legacy_criticality', 'neutral', 'legacy_severity', 'neutral'],
  ] as const)('renders %s allergy status with criticality and severity dots', async (status, statusTone, criticality, criticalityTone, severity, severityTone) => {
    mockGet.mockResolvedValue({
      allergies: [{
        ...RECORD, substance: 'Synthetic allergen', clinical_status: status,
        rxnorm_code: null, snomed_code: null, category: 'environment',
        criticality, severity, verification_status: 'confirmed', reaction: 'Synthetic reaction',
      }],
      can_manage: false,
    })
    render(<AllergiesPage patientId={42} />)
    if (status !== 'active') {
      fireEvent.click(await screen.findByRole('button', { name: /Resolved and Inactive/ }))
    }

    const row = (await screen.findByText('Synthetic allergen')).closest('tr')!
    expectRowIndicators(row, status.replaceAll('_', ' '), statusTone)
    expectRowIndicators(row, criticality.replaceAll('_', ' '), criticalityTone)
    expectRowIndicators(row, severity.replaceAll('_', ' '), severityTone)
    expect(mockGet).toHaveBeenCalledWith('/api/phr/patients/42/allergies')
  })

  it('omits missing criticality and severity while retaining clinical status', async () => {
    mockGet.mockResolvedValue({
      allergies: [{
        ...RECORD, substance: 'Synthetic allergen', clinical_status: 'active',
        rxnorm_code: null, snomed_code: null, category: null,
        criticality: null, severity: null, verification_status: 'confirmed', reaction: null,
      }],
      can_manage: false,
    })
    render(<AllergiesPage patientId={42} />)

    const row = (await screen.findByText('Synthetic allergen')).closest('tr')!
    expectRowIndicators(row, 'active', 'danger')
    const indicators = row.querySelectorAll('[data-tone]')
    expect(indicators.length).toBeGreaterThan(0)
    for (const indicator of indicators) expect(indicator).toHaveTextContent(/^active$/)
  })
})

describe('procedure status', () => {
  it.each([
    ['preparation', 'neutral'], ['in_progress', 'warning'], ['completed', 'success'],
    ['cancelled', 'neutral'], ['entered_in_error', 'danger'], ['legacy_status', 'neutral'],
  ] as const)('shows %s with a %s dot in the timeline', async (status, tone) => {
    mockGet.mockResolvedValue({
      procedures: [{
        ...RECORD, name: 'Synthetic procedure', status,
        cpt_code: null, snomed_code: null, performed_at: null, performed_on: '2030-04-15',
        performer_name: null, performer_specialty: null, facility_name: null,
        reason: null, outcome: null, eobs: [],
      }],
      can_manage: false,
    })
    render(<ProceduresPage patientId={42} />)

    const row = (await screen.findByText('Synthetic procedure')).closest('li')!
    expectRowIndicators(row, status.replaceAll('_', ' '), tone)
    expect(mockGet).toHaveBeenCalledWith('/api/phr/patients/42/procedures')
  })
})
