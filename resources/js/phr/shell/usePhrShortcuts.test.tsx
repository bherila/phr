import '@testing-library/jest-dom'

import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'

import { SEQUENCE_TIMEOUT_MS } from '@/lib/shortcuts'

import type { PhrListPageProps } from './phrPages'

jest.mock('@/phr/summary/SummaryPage', () => ({
  __esModule: true,
  default: ({ patientId }: PhrListPageProps) => <h1>Summary for {patientId}</h1>,
}))
jest.mock('@/phr/labs/LabsPage', () => ({
  __esModule: true,
  default: ({ patientId }: PhrListPageProps) => (
    <>
      <h1>Labs for {patientId}</h1>
      <input aria-label="Lab note" />
      <input aria-label="Search labs" data-record-list-filter="" />
    </>
  ),
}))
jest.mock('@/phr/labs/LabPanelDetail', () => ({
  __esModule: true,
  default: () => <h2>Lab panel</h2>,
}))
jest.mock('@/phr/conditions/ConditionsPage', () => ({
  __esModule: true,
  default: ({ patientId }: PhrListPageProps) => <h1>Conditions for {patientId}</h1>,
}))
jest.mock('@/phr/review/ReviewPage', () => ({
  __esModule: true,
  default: ({ patientId }: PhrListPageProps) => <h1>Review for {patientId}</h1>,
}))
jest.mock('@/phr/imports/ImportsPage', () => ({
  __esModule: true,
  default: () => <h1>Imports</h1>,
}))

import { PhrShell } from './PhrShell'
import { GO_TO_KEYS, phrShortcutBindings } from './usePhrShortcuts'
import { sidebarModeStorageKey } from './useSidebarMode'

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

function path(): string {
  return window.location.pathname
}

function press(key: string, init: Partial<KeyboardEventInit> = {}): void {
  fireEvent.keyDown(document.activeElement ?? document.body, { key, ...init })
}

function sidebar(): HTMLElement {
  return screen.getAllByRole('complementary', { name: 'Patient record' })[0]!
}

async function renderAt(url: string, heading: string): Promise<void> {
  window.history.replaceState(null, '', url)
  render(<PhrShell />)
  await screen.findByRole('heading', { name: heading })
}

describe('PHR keyboard shortcuts', () => {
  beforeEach(() => {
    seedInitialData()
    window.localStorage.clear()
  })

  afterEach(() => {
    jest.useRealTimers()
  })

  it('goes to a section with G then its letter', async () => {
    await renderAt('/phr/patient/2/labs', 'Labs for 2')

    press('g')
    press('c')

    expect(path()).toBe('/phr/patient/2/conditions')
    expect(await screen.findByRole('heading', { name: 'Conditions for 2' })).toBeInTheDocument()
    press('g')
    press('s')
    expect(path()).toBe('/phr/patient/2')
  })

  it('drops the G sequence after a timeout or an invalid second key', async () => {
    await renderAt('/phr/patient/2/labs', 'Labs for 2')
    jest.useFakeTimers()

    press('g')
    act(() => { jest.advanceTimersByTime(SEQUENCE_TIMEOUT_MS + 10) })
    press('c')
    expect(path()).toBe('/phr/patient/2/labs')

    press('g')
    press('q')
    press('c')
    expect(path()).toBe('/phr/patient/2/labs')

  })

  it('goes to the Review page with G then R', async () => {
    await renderAt('/phr/patient/2/labs', 'Labs for 2')

    press('g')
    press('r')

    expect(path()).toBe('/phr/patient/2/review')
    expect(await screen.findByRole('heading', { name: 'Review for 2' })).toBeInTheDocument()
    expect(GO_TO_KEYS.find((entry) => entry.key === 'r')?.page).toBe('review')
  })

  it('toggles the sidebar with [ as the manual sizing choice', async () => {
    await renderAt('/phr/patient/2/labs', 'Labs for 2')
    expect(sidebar()).toHaveAttribute('data-rail', 'false')

    press('[')
    expect(sidebar()).toHaveAttribute('data-rail', 'true')
    expect(window.localStorage.getItem(sidebarModeStorageKey('page'))).toBe('rail')

    press('[')
    expect(sidebar()).toHaveAttribute('data-rail', 'false')
    expect(window.localStorage.getItem(sidebarModeStorageKey('page'))).toBe('expanded')
  })

  it('opens a focus-trapped shortcuts dialog with ? that lists exactly the registry’s bindings', async () => {
    await renderAt('/phr/patient/2/labs', 'Labs for 2')

    press('?', { shiftKey: true })
    const dialog = await screen.findByRole('dialog', { name: 'Keyboard shortcuts' })
    await waitFor(() => expect(dialog.contains(document.activeElement)).toBe(true))

    const expected = phrShortcutBindings({
      view: { kind: 'patient', patientId: 2, page: 'labs' },
      paletteOpen: false,
      setPaletteOpen: jest.fn(),
      setHelpOpen: jest.fn(),
      sidebarMode: 'expanded',
      setSidebarMode: jest.fn(),
    })
    const listed = Array.from(dialog.querySelectorAll<HTMLElement>('[data-shortcut]'))
    expect(listed.map((row) => row.dataset.shortcut)).toEqual(expected.map((binding) => binding.id))
    for (const binding of expected) {
      expect(within(dialog.querySelector<HTMLElement>(`[data-shortcut="${binding.id}"]`)!).getByText(binding.description)).toBeInTheDocument()
    }
    expect(within(dialog).getByText('Conditions')).toBeInTheDocument()
    expect(within(dialog.querySelector<HTMLElement>('[data-shortcut="go-review"]')!).getByText('Review')).toBeInTheDocument()

    // While the dialog is open the other shortcuts pause.
    press('g')
    press('c')
    expect(path()).toBe('/phr/patient/2/labs')

    press('Escape')
    await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Keyboard shortcuts' })).not.toBeInTheDocument())
  })

  it('lists no record-section shortcuts outside a patient’s record', () => {
    const bindings = phrShortcutBindings({
      view: { kind: 'section', section: 'imports', search: '' },
      paletteOpen: false,
      setPaletteOpen: jest.fn(),
      setHelpOpen: jest.fn(),
      sidebarMode: 'expanded',
      setSidebarMode: jest.fn(),
    })
    expect(bindings.map((binding) => binding.id)).not.toContain('sidebar')
    expect(bindings.some((binding) => binding.id.startsWith('go-'))).toBe(false)
  })

  it('ignores shortcuts typed into a field', async () => {
    await renderAt('/phr/patient/2/labs', 'Labs for 2')
    const field = screen.getByLabelText('Lab note')
    act(() => field.focus())

    press('g')
    press('c')
    press('?')
    press('[')
    press('/')
    press('k', { metaKey: true })

    expect(path()).toBe('/phr/patient/2/labs')
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(sidebar()).toHaveAttribute('data-rail', 'false')
    expect(field).toHaveFocus()
  })

  it('ignores shortcuts while the search palette is open, and ⌘K closes it again', async () => {
    await renderAt('/phr/patient/2/labs', 'Labs for 2')

    press('k', { metaKey: true })
    const palette = await screen.findByPlaceholderText(/search visits, clinicians/i)
    act(() => palette.focus())
    press('?')
    press('g')
    press('c')
    expect(screen.queryByRole('dialog', { name: 'Keyboard shortcuts' })).not.toBeInTheDocument()
    expect(path()).toBe('/phr/patient/2/labs')

    press('k', { metaKey: true })
    await waitFor(() => expect(screen.queryByPlaceholderText(/search visits, clinicians/i)).not.toBeInTheDocument())
  })

  it('focuses the list’s filter field with /', async () => {
    await renderAt('/phr/patient/2/labs', 'Labs for 2')

    press('/')

    expect(screen.getByLabelText('Search labs')).toHaveFocus()
  })

  it('closes the detail panel with Esc through the registry', async () => {
    await renderAt('/phr/patient/2/labs/5', 'Labs for 2')
    expect(await screen.findByRole('complementary', { name: 'Lab Panel detail' })).toBeInTheDocument()

    press('Escape')

    expect(path()).toBe('/phr/patient/2/labs')
    await waitFor(() => expect(screen.queryByRole('complementary', { name: 'Lab Panel detail' })).not.toBeInTheDocument())
  })
})
