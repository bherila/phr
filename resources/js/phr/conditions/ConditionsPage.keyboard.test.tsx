import '@testing-library/jest-dom'

import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'

import type { PhrDetailPageProps } from '@/phr/shell/phrPages'

const mockGet = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: (...args: unknown[]) => mockGet(...args),
    post: jest.fn(),
    patch: jest.fn(),
    delete: jest.fn(),
  },
}))
jest.mock('@/phr/conditions/ConditionDetail', () => ({
  __esModule: true,
  default: ({ recordId }: PhrDetailPageProps) => (
    <>
      <h2>Condition {recordId}</h2>
      <button type="button">Detail action</button>
    </>
  ),
}))

import { PhrShell } from '@/phr/shell/PhrShell'

function condition(id: number, name: string, onset: string) {
  return {
    id,
    patient_id: 2,
    user_id: 1,
    name,
    icd10_code: null,
    snomed_code: null,
    onset_date: onset,
    abated_date: null,
    clinical_status: 'active',
    verification_status: 'confirmed',
    severity: null,
    notes: null,
    raw_text: null,
    review_status: 'confirmed',
    created_at: null,
    updated_at: null,
  }
}

function seedInitialData(): void {
  document.getElementById('app-initial-data')?.remove()
  const node = document.createElement('script')
  node.id = 'app-initial-data'
  node.type = 'application/json'
  node.textContent = JSON.stringify({
    currentUser: { name: 'Synthetic User', email: 'user@example.test' },
    phr: {
      patient_list: {
        patients: [{
          id: 2, owner_user_id: 1, display_name: 'Alice', relationship: null, birth_date: null, sex_at_birth: null, notes: null,
          archived_at: null, created_at: null, updated_at: null, access_level: 'owner', can_manage: true, can_share: true, access_grants: [],
        }],
      },
    },
  })
  document.body.appendChild(node)
}

function row(name: string): HTMLElement {
  const table = screen.getByRole('table', { name: 'Active Problems' })
  return within(table).getByText(name).closest('tr')!
}

function press(key: string): void {
  fireEvent.keyDown(document.activeElement ?? document.body, { key })
}

describe('Conditions keyboard navigation with the detail panel', () => {
  let pushState: jest.SpyInstance
  let replaceState: jest.SpyInstance

  beforeEach(() => {
    seedInitialData()
    window.localStorage.clear()
    mockGet.mockReset()
    mockGet.mockImplementation(async (url: string) => {
      if (url.startsWith('/api/phr/patients/2/conditions')) {
        return {
          conditions: [condition(11, 'Asthma', '2026-03-01'), condition(12, 'Eczema', '2026-02-01'), condition(13, 'Migraine', '2026-01-01')],
          can_manage: true,
        }
      }
      return {}
    })
    window.history.replaceState(null, '', '/phr/patient/2/conditions')
    pushState = jest.spyOn(window.history, 'pushState')
    replaceState = jest.spyOn(window.history, 'replaceState')
  })

  afterEach(() => {
    pushState.mockRestore()
    replaceState.mockRestore()
  })

  it('opens with Enter, follows the active row with replaceState, and returns focus on Esc', async () => {
    render(<PhrShell />)
    await screen.findByRole('table', { name: 'Active Problems' })
    expect(screen.getByRole('button', { name: 'Edit Asthma' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Delete Eczema' })).toHaveAttribute('tabindex', '-1')

    act(() => row('Asthma').focus())
    press('ArrowDown')
    expect(row('Eczema')).toHaveFocus()
    press('Enter')
    expect(window.location.pathname).toBe('/phr/patient/2/conditions/12')
    expect(pushState).toHaveBeenCalledTimes(1)
    expect(await screen.findByRole('heading', { name: 'Condition 12' })).toBeInTheDocument()
    expect(row('Eczema')).toHaveAttribute('data-open')
    expect(row('Eczema')).toHaveFocus()

    // Arrows with the panel open: the panel follows, once, after the keys settle.
    press('ArrowDown')
    press('ArrowUp')
    press('ArrowUp')
    expect(row('Asthma')).toHaveFocus()
    expect(window.location.pathname).toBe('/phr/patient/2/conditions/12')
    await waitFor(() => expect(window.location.pathname).toBe('/phr/patient/2/conditions/11'))
    expect(await screen.findByRole('heading', { name: 'Condition 11' })).toBeInTheDocument()
    expect(pushState).toHaveBeenCalledTimes(1)
    expect(replaceState).toHaveBeenCalledTimes(1)
    expect(row('Asthma')).toHaveAttribute('data-open')

    press('Escape')
    expect(window.location.pathname).toBe('/phr/patient/2/conditions')
    await waitFor(() => expect(screen.queryByRole('heading', { name: 'Condition 11' })).not.toBeInTheDocument())
    expect(row('Asthma')).toHaveFocus()
  })

  it('puts focus back on the row when Esc closes the panel from inside it', async () => {
    window.history.replaceState(null, '', '/phr/patient/2/conditions/13')
    render(<PhrShell />)
    const action = await screen.findByRole('button', { name: 'Detail action' })
    await screen.findByRole('table', { name: 'Active Problems' })
    expect(row('Migraine')).toHaveAttribute('aria-selected', 'true')

    act(() => action.focus())
    press('Escape')

    expect(window.location.pathname).toBe('/phr/patient/2/conditions')
    await waitFor(() => expect(row('Migraine')).toHaveFocus())
  })
})
