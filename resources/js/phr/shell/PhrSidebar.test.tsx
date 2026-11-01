import '@testing-library/jest-dom'

import { act, render, screen, waitFor, within } from '@testing-library/react'

import { fetchWrapper } from '@/fetchWrapper'
import { PhrPatientsProvider } from '@/phr/patients/usePhrPatients'
import { resetReviewSummaries,reviewStateChanged } from '@/phr/review/reviewSummary'

import { navGroupOf, PHR_NAV_GROUPS, sidebarPageOf } from './phrPages'
import { PhrSidebar, sidebarAmounts } from './PhrSidebar'

jest.mock('@/fetchWrapper', () => ({ fetchWrapper: { get: jest.fn() } }))

const get = fetchWrapper.get as jest.Mock

function summary(medications: { pending: number, records: number }) {
  return {
    summary: { total: medications.pending + 1, ai_results: 1, sections: { medications, labs: { pending: 0, records: 6 } } },
    can_manage: true,
  }
}

function renderSidebars(): void {
  render(
    <PhrPatientsProvider>
      <PhrSidebar patientId={2} page="summary" rail={false} />
      <PhrSidebar patientId={2} page="summary" rail />
    </PhrPatientsProvider>,
  )
}

function summaryRequests(): number {
  return get.mock.calls.filter(([url]) => url === '/api/phr/patients/2/review/summary').length
}

beforeEach(() => {
  resetReviewSummaries()
  get.mockReset()
  get.mockImplementation((url: string) => (url === '/api/phr/patients' ? Promise.resolve({ patients: [] }) : Promise.resolve(summary({ pending: 2, records: 12 }))))
})

describe('PhrSidebar review counts', () => {
  it('shows Fills as part of Medications: one item, marked on either list, with its dot covering both', () => {
    const summary = { total: 5, ai_results: 0, sections: { medications: { records: 12, pending: 0 }, 'medication-fills': { records: 40, pending: 3 } } }
    expect(sidebarAmounts('medications', summary)).toEqual({ count: 12, pending: 3 })
    expect(PHR_NAV_GROUPS.flatMap((group) => group.pages)).not.toContain('medication-fills')
    expect(sidebarPageOf('medication-fills')).toBe('medications')
    expect(navGroupOf('medication-fills')).toBe('Clinical')
  })

  it('shows each section’s record count and a dot where records wait, from one shared request', async () => {
    renderSidebars()
    const [expanded, rail] = screen.getAllByRole('complementary', { name: 'Patient record' })

    const medications = await within(expanded!).findByRole('link', { name: 'Medications, 12 records, 2 waiting for review' })
    expect(medications).toHaveTextContent('12')
    expect(medications.querySelector('[data-pending-dot]')).not.toBeNull()
    const labs = within(expanded!).getByRole('link', { name: 'Labs, 6 records' })
    expect(labs.querySelector('[data-pending-dot]')).toBeNull()
    // Nothing known for a section: no count and no dot.
    expect(within(expanded!).getByRole('link', { name: 'Allergies' }).querySelector('[data-pending-dot]')).toBeNull()

    // The rail keeps the dot and says the same in the item's name.
    expect(within(rail!).getByRole('link', { name: 'Medications, 12 records, 2 waiting for review' }).querySelector('[data-pending-dot]')).not.toBeNull()
    expect(summaryRequests()).toBe(1)
  })

  it('follows a decision without a reload', async () => {
    renderSidebars()
    const [expanded] = screen.getAllByRole('complementary', { name: 'Patient record' })
    await within(expanded!).findByRole('link', { name: 'Medications, 12 records, 2 waiting for review' })

    get.mockImplementation(() => Promise.resolve(summary({ pending: 0, records: 12 })))
    await act(async () => {
      await reviewStateChanged(2)
    })

    await waitFor(() => expect(within(expanded!).getByRole('link', { name: 'Medications, 12 records' }).querySelector('[data-pending-dot]')).toBeNull())
    expect(summaryRequests()).toBe(2)
  })

  it('shows no counts when the summary cannot be read', async () => {
    get.mockImplementation((url: string) => (url === '/api/phr/patients' ? Promise.resolve({ patients: [] }) : Promise.reject(new Error('offline'))))
    renderSidebars()
    const [expanded] = screen.getAllByRole('complementary', { name: 'Patient record' })

    await waitFor(() => expect(summaryRequests()).toBe(1))
    expect(within(expanded!).getByRole('link', { name: 'Medications' })).not.toHaveTextContent(/\d/)
  })
})
