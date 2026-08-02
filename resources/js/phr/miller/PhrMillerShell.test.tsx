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

import { PhrMillerShell } from './PhrMillerShell'

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
    expect(screen.getByText('Coming soon.')).toBeInTheDocument()
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
})
