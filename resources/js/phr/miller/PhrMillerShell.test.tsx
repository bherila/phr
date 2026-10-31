import '@testing-library/jest-dom'

import { act, fireEvent, render, screen } from '@testing-library/react'
import React from 'react'

import type { PhrSection } from '@/lib/phrRouteBuilder'

// PhrNavbar fetches the patient list and renders a combobox; stub it with buttons that invoke
// the patient/section callbacks so these tests exercise the shell's navigation in isolation.
jest.mock('@/components/phr/PhrNavbar', () => ({
  __esModule: true,
  default: function MockPhrNavbar({
    children,
    onPatientChange,
    onSectionChange,
  }: {
    children?: React.ReactNode
    onPatientChange?: (patientId: number) => void
    onSectionChange?: (section: PhrSection) => void
  }) {
    return (
      <div data-testid="phr-navbar">
        <button type="button" onClick={() => onPatientChange?.(2)}>pick-patient-2</button>
        <button type="button" onClick={() => onSectionChange?.('imports')}>nav-imports</button>
        {children}
      </div>
    )
  },
}))

// The real patients list and labs module fetch data; stub them so the history tests below drive
// the shell's real registry wiring (PatientsColumn -> onPatientChange, dock tile -> replaceFrom).
jest.mock('@/phr/patients/PatientsPage', () => ({
  __esModule: true,
  default: function MockPatientsPage({ onPatientSelect }: { onPatientSelect?: (patientId: number, hash?: string) => void }) {
    return <button type="button" onClick={() => onPatientSelect?.(2)}>select-patient-2</button>
  },
}))
jest.mock('@/phr/labs/LabsPage', () => ({
  __esModule: true,
  default: function MockLabsPage({ patientId }: { patientId: number }) {
    return <p>labs for patient {patientId}</p>
  },
}))

import { PhrMillerShell } from './PhrMillerShell'

/**
 * Blink and WebKit fire `hashchange` on a history traversal only when the two entries differ
 * in nothing but the fragment; crossing a path change (e.g. /phr/patient/2 back to
 * /phr/patients#/patients) fires `popstate` alone. jsdom follows the spec text and fires both,
 * which hides the bug, so drop the traversal hashchange the way those engines never send it.
 */
function emulateBrowserHashchangeOnTraversal(): () => void {
  const suppress = (event: Event): void => {
    if (!(event instanceof HashChangeEvent) || !event.oldURL || !event.newURL) {
      return
    }
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
    // Let any hashchange queued behind the popstate run before asserting.
    await new Promise((resolve) => setTimeout(resolve, 0))
  })
}

/** Click a dock tile (its accessible name starts with the module label; pin toggles do not). */
function openDockModule(label: string): void {
  fireEvent.click(screen.getAllByRole('button', { name: new RegExp(`^${label}\\b`) })[0]!)
}

function columnIds(): string[] {
  return Array.from(document.querySelectorAll<HTMLElement>('section[data-miller-column]')).map((el) => el.dataset.columnId ?? '')
}

function setHash(hash: string): void {
  act(() => {
    window.location.hash = hash
    window.dispatchEvent(new HashChangeEvent('hashchange'))
  })
}

describe('PhrMillerShell', () => {
  beforeEach(() => {
    window.location.hash = ''
    window.history.replaceState(null, '', '/phr/patients')
  })

  it('renders the imports section module from the hash', async () => {
    window.location.hash = '#/imports'
    render(<PhrMillerShell />)
    expect(await screen.findByRole('heading', { name: 'Imports' })).toBeInTheDocument()
    // The placeholder is gone: the Imports tab now owns the DICOM import entry point.
    expect(await screen.findByRole('button', { name: /choose dicom folder/i })).toBeInTheDocument()
  })

  it('swaps the rendered section when the hash changes (no full reload)', async () => {
    window.location.hash = '#/imports'
    render(<PhrMillerShell />)
    expect(await screen.findByRole('heading', { name: 'Imports' })).toBeInTheDocument()

    setHash('#/config')
    expect(await screen.findByRole('heading', { name: 'AI Provider Settings' })).toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Imports' })).not.toBeInTheDocument()
  })

  it('shows the patient dock home view when a patient is active and no column is open', async () => {
    window.history.replaceState(null, '', '/phr/patient/42')
    render(<PhrMillerShell initialPatientId={42} />)
    expect(await screen.findByRole('heading', { name: 'Patient 42' })).toBeInTheDocument()
  })

  it('keeps the open module when switching patients via the navbar picker', () => {
    window.history.replaceState(null, '', '/phr/patient/1')
    window.location.hash = '#/labs'
    render(<PhrMillerShell initialPatientId={1} />)

    act(() => {
      fireEvent.click(screen.getByRole('button', { name: 'pick-patient-2' }))
    })

    expect(window.location.pathname).toBe('/phr/patient/2')
    expect(window.location.hash).toBe('#/labs')
  })

  it('navigates to the section path and clears patient context on section change', () => {
    window.history.replaceState(null, '', '/phr/patient/42')
    window.location.hash = '#/labs'
    render(<PhrMillerShell initialPatientId={42} />)

    act(() => {
      fireEvent.click(screen.getByRole('button', { name: 'nav-imports' }))
    })

    expect(window.location.pathname).toBe('/phr/imports')
    expect(window.location.hash).toBe('#/imports')
  })

  describe('browser Back and Forward', () => {
    let restoreHashchange: () => void

    beforeEach(() => {
      restoreHashchange = emulateBrowserHashchangeOnTraversal()
    })

    afterEach(() => {
      restoreHashchange()
    })

    it('follows history from the patients section into a patient module and back again', async () => {
      // pages.tsx seeds the section column into the hash on a /phr/patients load.
      window.history.replaceState(null, '', '/phr/patients#/patients')
      render(<PhrMillerShell />)

      fireEvent.click(await screen.findByRole('button', { name: 'select-patient-2' }))
      expect(window.location.pathname).toBe('/phr/patient/2')
      expect(window.location.hash).toBe('')
      expect(await screen.findByRole('heading', { name: 'Patient 2' })).toBeInTheDocument()

      openDockModule('Labs')
      expect(window.location.hash).toBe('#/labs')
      expect(await screen.findByText('labs for patient 2')).toBeInTheDocument()

      // Back #1: same path, hash only -> the patient's dock.
      await traverse(-1)
      expect(window.location.pathname).toBe('/phr/patient/2')
      expect(columnIds()).toEqual([])
      expect(screen.getByRole('heading', { name: 'Patient 2' })).toBeInTheDocument()

      // Back #2: crosses the path change -> the patients section, with no patient selected.
      await traverse(-1)
      expect(window.location.pathname).toBe('/phr/patients')
      expect(window.location.hash).toBe('#/patients')
      expect(columnIds()).toEqual(['patients'])
      expect(screen.getByRole('button', { name: 'select-patient-2' })).toBeInTheDocument()
      expect(screen.queryByRole('heading', { name: 'Patient 2' })).not.toBeInTheDocument()
      expect(screen.getByText('No patient selected')).toBeInTheDocument()

      // Forward retraces the same steps.
      await traverse(1)
      expect(window.location.pathname).toBe('/phr/patient/2')
      expect(columnIds()).toEqual([])
      expect(screen.getByRole('heading', { name: 'Patient 2' })).toBeInTheDocument()

      await traverse(1)
      expect(window.location.hash).toBe('#/labs')
      expect(columnIds()).toEqual(['labs'])
      expect(await screen.findByText('labs for patient 2')).toBeInTheDocument()
    })

    it('restores the patient module columns when Back crosses from a section into a patient', async () => {
      window.history.replaceState(null, '', '/phr/patient/2#/labs')
      render(<PhrMillerShell initialPatientId={2} />)
      expect(await screen.findByText('labs for patient 2')).toBeInTheDocument()

      act(() => {
        fireEvent.click(screen.getByRole('button', { name: 'nav-imports' }))
      })
      expect(window.location.pathname).toBe('/phr/imports')
      expect(columnIds()).toEqual(['imports'])

      await traverse(-1)
      expect(window.location.pathname).toBe('/phr/patient/2')
      expect(window.location.hash).toBe('#/labs')
      expect(columnIds()).toEqual(['labs'])
      expect(await screen.findByText('labs for patient 2')).toBeInTheDocument()
    })

    it('adds a history entry when the last column is closed, so Back reopens it', async () => {
      window.history.replaceState(null, '', '/phr/patient/2')
      render(<PhrMillerShell initialPatientId={2} />)
      await screen.findByRole('heading', { name: 'Patient 2' })
      openDockModule('Labs')
      expect(await screen.findByText('labs for patient 2')).toBeInTheDocument()

      act(() => {
        fireEvent.click(screen.getByRole('button', { name: 'Close columns after Labs' }))
      })
      expect(window.location.hash).toBe('')
      expect(columnIds()).toEqual([])

      await traverse(-1)
      expect(window.location.hash).toBe('#/labs')
      expect(columnIds()).toEqual(['labs'])
    })
  })
})
