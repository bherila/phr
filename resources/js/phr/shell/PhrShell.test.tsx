import '@testing-library/jest-dom'

import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'

import type { PhrDetailPageProps, PhrListPageProps } from './phrPages'

// Pages fetch their own data; stub them so these tests drive the shell's real routing, top bar
// and sidebar. Each stub can ask the shell to navigate the way the real page does.
jest.mock('@/phr/patients/PatientsPage', () => ({
  __esModule: true,
  default: function MockPatientsPage() {
    return <><h1>Patients</h1><a href="/phr/patient/2">Alice</a></>
  },
}))
jest.mock('@/phr/summary/SummaryPage', () => ({
  __esModule: true,
  default: ({ patientId }: PhrListPageProps) => <h1>Summary for {patientId}</h1>,
}))
jest.mock('@/phr/labs/LabsPage', () => ({
  __esModule: true,
  default: ({ patientId, onDrill }: PhrListPageProps) => (
    <>
      <h1>Labs for {patientId}</h1>
      <button type="button" onClick={() => onDrill?.({ id: 'lab-panel-detail', instance: '5' })}>row-5</button>
      <button type="button" onClick={() => onDrill?.({ id: 'lab-panel-detail', instance: '6' })}>row-6</button>
    </>
  ),
}))
jest.mock('@/phr/labs/LabPanelDetail', () => ({
  __esModule: true,
  default: ({ recordId }: PhrDetailPageProps) => <h2>Lab panel {recordId}</h2>,
}))
jest.mock('@/phr/office-visits/OfficeVisitsPage', () => ({
  __esModule: true,
  default: () => <h1>Visits</h1>,
}))
jest.mock('@/phr/office-visits/OfficeVisitDetail', () => ({
  __esModule: true,
  default: ({ recordId, onDrill }: PhrDetailPageProps) => (
    <>
      <h2>Visit {recordId}</h2>
      <button type="button" onClick={() => onDrill?.({ id: 'imaging-study-detail', instance: '3' })}>open-study-3</button>
    </>
  ),
}))
jest.mock('@/phr/imaging/ImagingStudyDetail', () => ({
  __esModule: true,
  default: ({ recordId }: PhrDetailPageProps) => <h2>Study {recordId}</h2>,
}))
jest.mock('@/phr/documents/DocumentsPage', () => ({
  __esModule: true,
  default: () => <h1>Documents</h1>,
}))
jest.mock('@/phr/documents/DocumentViewer', () => ({
  __esModule: true,
  default: ({ recordId }: PhrDetailPageProps) => <h2>Document {recordId}</h2>,
}))
jest.mock('@/phr/imports/ImportsPage', () => ({
  __esModule: true,
  default: () => <h1>Imports</h1>,
}))

import { PhrShell } from './PhrShell'
import { sidebarModeStorageKey } from './useSidebarMode'

function patient(id: number, name: string) {
  return {
    id,
    owner_user_id: 1,
    display_name: name,
    relationship: null,
    birth_date: null,
    sex_at_birth: null,
    notes: null,
    archived_at: null,
    created_at: null,
    updated_at: null,
    access_level: 'owner',
    can_manage: true,
    can_share: true,
    access_grants: [],
  }
}

function seedInitialData(): void {
  document.getElementById('app-initial-data')?.remove()
  const node = document.createElement('script')
  node.id = 'app-initial-data'
  node.type = 'application/json'
  node.textContent = JSON.stringify({
    currentUser: { name: 'Synthetic User', email: 'user@example.test' },
    phr: { patient_list: { patients: [patient(2, 'Alice'), patient(3, 'Bob')] } },
  })
  document.body.appendChild(node)
}

/**
 * Blink and WebKit fire `hashchange` on a history traversal only when the two entries differ
 * in nothing but the fragment; jsdom fires it for every traversal, which would mask a shell
 * that listened for hashchange alone. Drop it the way those engines never send it.
 */
function emulateBrowserHashchangeOnTraversal(): () => void {
  const suppress = (event: Event): void => {
    if (!(event instanceof HashChangeEvent) || !event.oldURL || !event.newURL) return
    const before = new URL(event.oldURL)
    const after = new URL(event.newURL)
    if (before.pathname !== after.pathname || before.search !== after.search) {
      event.stopImmediatePropagation()
    }
  }
  window.addEventListener('hashchange', suppress, { capture: true })
  return () => window.removeEventListener('hashchange', suppress, { capture: true })
}

/** history.go(delta), resolved once the traversal's popstate has been delivered. */
async function traverse(delta: number): Promise<void> {
  await act(async () => {
    const popped = new Promise<void>((resolve) => {
      window.addEventListener('popstate', () => resolve(), { once: true })
    })
    window.history.go(delta)
    await popped
    await new Promise((resolve) => setTimeout(resolve, 0))
  })
}

function sidebar(): HTMLElement | null {
  return screen.queryAllByRole('complementary', { name: 'Patient record' })[0] ?? null
}

function sidebarLink(name: string): HTMLElement {
  return within(sidebar()!).getByRole('link', { name })
}

function path(): string {
  return `${window.location.pathname}${window.location.search}${window.location.hash}`
}

describe('PhrShell', () => {
  let restoreHashchange: () => void

  beforeEach(() => {
    seedInitialData()
    window.localStorage.clear()
    window.history.replaceState(null, '', '/phr/patients')
    restoreHashchange = emulateBrowserHashchangeOnTraversal()
  })

  afterEach(() => {
    restoreHashchange()
  })

  it('follows Back and Forward across patients, page and detail', async () => {
    render(<PhrShell />)
    expect(await screen.findByRole('heading', { name: 'Patients' })).toBeInTheDocument()
    // Section pages have no patient sidebar.
    expect(sidebar()).toBeNull()

    fireEvent.click(screen.getByRole('link', { name: 'Alice' }))
    expect(path()).toBe('/phr/patient/2')
    expect(await screen.findByRole('heading', { name: 'Summary for 2' })).toBeInTheDocument()
    expect(sidebarLink('Summary')).toHaveAttribute('aria-current', 'page')

    fireEvent.click(sidebarLink('Labs'))
    expect(path()).toBe('/phr/patient/2/labs')
    expect(await screen.findByRole('heading', { name: 'Labs for 2' })).toBeInTheDocument()
    expect(sidebarLink('Labs')).toHaveAttribute('aria-current', 'page')

    fireEvent.click(screen.getByRole('button', { name: 'row-5' }))
    expect(path()).toBe('/phr/patient/2/labs/5')
    const panel = await screen.findByRole('complementary', { name: 'Lab Panel detail' })
    expect(await within(panel).findByRole('heading', { name: 'Lab panel 5' })).toBeInTheDocument()
    // The list stays beside the panel.
    expect(screen.getByRole('heading', { name: 'Labs for 2' })).toBeInTheDocument()

    // Selecting another row swaps the panel.
    fireEvent.click(screen.getByRole('button', { name: 'row-6' }))
    expect(path()).toBe('/phr/patient/2/labs/6')
    expect(await screen.findByRole('heading', { name: 'Lab panel 6' })).toBeInTheDocument()

    await traverse(-1)
    expect(path()).toBe('/phr/patient/2/labs/5')
    expect(await screen.findByRole('heading', { name: 'Lab panel 5' })).toBeInTheDocument()

    await traverse(-1)
    expect(path()).toBe('/phr/patient/2/labs')
    expect(screen.queryByRole('complementary', { name: 'Lab Panel detail' })).not.toBeInTheDocument()

    await traverse(-1)
    expect(path()).toBe('/phr/patient/2')
    expect(await screen.findByRole('heading', { name: 'Summary for 2' })).toBeInTheDocument()

    // Crosses the path change back to the section, with no patient.
    await traverse(-1)
    expect(path()).toBe('/phr/patients')
    expect(await screen.findByRole('heading', { name: 'Patients' })).toBeInTheDocument()
    expect(sidebar()).toBeNull()

    await traverse(1)
    expect(await screen.findByRole('heading', { name: 'Summary for 2' })).toBeInTheDocument()
    await traverse(1)
    await traverse(1)
    expect(path()).toBe('/phr/patient/2/labs/5')
    expect(await screen.findByRole('heading', { name: 'Lab panel 5' })).toBeInTheDocument()
  })

  it('restores the patient and its open record when Back leaves a section', async () => {
    window.history.replaceState(null, '', '/phr/patient/2/labs/5')
    render(<PhrShell />)
    expect(await screen.findByRole('heading', { name: 'Lab panel 5' })).toBeInTheDocument()

    fireEvent.click(screen.getByRole('link', { name: 'Imports' }))
    expect(path()).toBe('/phr/imports')
    expect(await screen.findByRole('heading', { name: 'Imports' })).toBeInTheDocument()
    expect(sidebar()).toBeNull()

    await traverse(-1)
    expect(path()).toBe('/phr/patient/2/labs/5')
    expect(await screen.findByRole('heading', { name: 'Lab panel 5' })).toBeInTheDocument()
    expect(sidebarLink('Labs')).toHaveAttribute('aria-current', 'page')
  })

  it('adds a history entry when the detail is closed, so Back reopens it', async () => {
    window.history.replaceState(null, '', '/phr/patient/2/labs/5')
    render(<PhrShell />)
    await screen.findByRole('heading', { name: 'Lab panel 5' })

    fireEvent.click(screen.getByRole('link', { name: 'Close detail' }))
    expect(path()).toBe('/phr/patient/2/labs')
    expect(screen.queryByRole('heading', { name: 'Lab panel 5' })).not.toBeInTheDocument()

    await traverse(-1)
    expect(path()).toBe('/phr/patient/2/labs/5')
    expect(await screen.findByRole('heading', { name: 'Lab panel 5' })).toBeInTheDocument()
  })

  it('redirects a legacy hash URL in place, without a history entry', async () => {
    window.history.replaceState(null, '', '/phr/patient/2#/labs/lab-panel-detail:5')
    const entries = window.history.length
    render(<PhrShell />)

    await waitFor(() => expect(path()).toBe('/phr/patient/2/labs/5'))
    expect(await screen.findByRole('heading', { name: 'Lab panel 5' })).toBeInTheDocument()
    expect(window.history.length).toBe(entries)
  })

  it('swaps a visit for its linked imaging study in the panel, with a link back to the visit', async () => {
    window.history.replaceState(null, '', '/phr/patient/2/office-visits/4')
    render(<PhrShell />)
    fireEvent.click(await screen.findByRole('button', { name: 'open-study-3' }))

    expect(path()).toBe('/phr/patient/2/office-visits/4/imaging/3')
    const panel = await screen.findByRole('complementary', { name: 'Study detail' })
    expect(await within(panel).findByRole('heading', { name: 'Study 3' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Visits' })).toBeInTheDocument()

    fireEvent.click(within(panel).getByRole('link', { name: 'Visit' }))
    expect(path()).toBe('/phr/patient/2/office-visits/4')
    expect(await screen.findByRole('heading', { name: 'Visit 4' })).toBeInTheDocument()
  })

  it('opens the document viewer full-width under the sidebar, not in the panel', async () => {
    window.history.replaceState(null, '', '/phr/patient/2/documents/9')
    render(<PhrShell />)

    expect(await screen.findByRole('heading', { name: 'Document 9' })).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Documents' })).not.toBeInTheDocument()
    expect(screen.queryByRole('complementary', { name: 'Document detail' })).not.toBeInTheDocument()
    const breadcrumb = screen.getByRole('navigation', { name: 'Breadcrumb' })
    expect(within(breadcrumb).getByRole('link', { name: 'Documents' })).toHaveAttribute('href', '/phr/patient/2/documents')
    expect(within(breadcrumb).getByText('Document')).toHaveAttribute('aria-current', 'page')
    expect(sidebarLink('Documents')).toHaveAttribute('aria-current', 'page')
    // Full-width views drop the sidebar to its rail.
    expect(sidebar()).toHaveAttribute('data-rail', 'true')
  })

  it('switches patient from the sidebar and stays on the same page', async () => {
    window.history.replaceState(null, '', '/phr/patient/2/labs/5')
    render(<PhrShell />)
    await screen.findByRole('heading', { name: 'Lab panel 5' })

    fireEvent.click(within(sidebar()!).getByRole('button', { name: 'Switch patient: Alice' }))
    fireEvent.click(await screen.findByRole('menuitem', { name: 'Bob' }))

    expect(path()).toBe('/phr/patient/3/labs')
    expect(await screen.findByRole('heading', { name: 'Labs for 3' })).toBeInTheDocument()
  })

  it('collapses the sidebar to a labeled icon rail and remembers it', async () => {
    window.history.replaceState(null, '', '/phr/patient/2/labs')
    const { unmount } = render(<PhrShell />)
    await screen.findByRole('heading', { name: 'Labs for 2' })

    fireEvent.click(within(sidebar()!).getByRole('button', { name: 'Collapse sidebar' }))
    expect(sidebar()).toHaveAttribute('data-rail', 'true')
    // Icon-only items keep their names.
    expect(sidebarLink('Labs')).toHaveAttribute('aria-current', 'page')
    expect(window.localStorage.getItem(sidebarModeStorageKey('page'))).toBe('rail')

    unmount()
    render(<PhrShell />)
    await screen.findByRole('heading', { name: 'Labs for 2' })
    expect(sidebar()).toHaveAttribute('data-rail', 'true')
    fireEvent.click(within(sidebar()!).getByRole('button', { name: 'Expand sidebar' }))
    expect(sidebar()).toHaveAttribute('data-rail', 'false')
  })

  it('still renders when storage is unavailable', async () => {
    const getItem = jest.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('blocked') })
    const setItem = jest.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('blocked') })
    try {
      window.history.replaceState(null, '', '/phr/patient/2/labs')
      render(<PhrShell />)
      await screen.findByRole('heading', { name: 'Labs for 2' })
      fireEvent.click(within(sidebar()!).getByRole('button', { name: 'Collapse sidebar' }))
      expect(sidebar()).toHaveAttribute('data-rail', 'true')
    } finally {
      getItem.mockRestore()
      setItem.mockRestore()
    }
  })

  it('opens patient search from ⌘K inside a record and section jumps outside one', async () => {
    window.history.replaceState(null, '', '/phr/patient/2/labs')
    const { unmount } = render(<PhrShell />)
    await screen.findByRole('heading', { name: 'Labs for 2' })
    fireEvent.keyDown(window, { key: 'k', metaKey: true })
    expect(await screen.findByPlaceholderText(/search visits, clinicians, notes, studies, and documents/i)).toBeInTheDocument()
    unmount()

    window.history.replaceState(null, '', '/phr/imports')
    render(<PhrShell />)
    await screen.findByRole('heading', { name: 'Imports' })
    fireEvent.keyDown(window, { key: 'k', metaKey: true })
    fireEvent.click(await screen.findByRole('option', { name: 'Bob' }))
    expect(path()).toBe('/phr/patient/3')
  })

  it('leaves links to separate PHR documents to the browser', async () => {
    window.history.replaceState(null, '', '/phr/patient/2')
    render(<PhrShell />)
    await screen.findByRole('heading', { name: 'Summary for 2' })

    const link = document.createElement('a')
    link.href = '/phr/patient/2/eobs/7'
    link.textContent = 'EOB'
    screen.getByRole('main').appendChild(link)
    const event = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 })
    link.dispatchEvent(event)
    expect(event.defaultPrevented).toBe(false)
    link.remove()
  })
})
