import { fireEvent, render, screen } from '@testing-library/react'
import React, { useState } from 'react'

jest.mock('@/components/phr/PhrNavbar', () => ({
  __esModule: true,
  default: function MockPhrNavbar({ children }: { children?: React.ReactNode }) {
    return <div data-testid="phr-navbar">{children}</div>
  },
}))

jest.mock('@/components/ui/miller', () => {
  const actual = jest.requireActual<typeof import('@/components/ui/miller')>('@/components/ui/miller')

  return {
    ...actual,
    MillerRegistryShell: function MockMillerRegistryShell() {
      return <div data-testid="miller-registry-shell" />
    },
  }
})

import { PhrCommandPalette, usePhrCommandPaletteShortcut } from './PhrCommandPalette'
import { PhrMillerShell } from './PhrMillerShell'
import { phrModuleRegistry } from './phrModuleRegistry'

function ShortcutHarness({ withDialog = false }: { withDialog?: boolean }): React.ReactElement {
  const [open, setOpen] = useState(false)
  usePhrCommandPaletteShortcut(open, setOpen)

  return (
    <>
      <div data-testid="palette-state">{open ? 'open' : 'closed'}</div>
      <input aria-label="Editable input" />
      <div aria-label="Rich editor" contentEditable role="textbox" tabIndex={0} />
      {withDialog ? <div role="dialog" data-open>Existing dialog</div> : null}
    </>
  )
}

describe('PhrCommandPalette', () => {
  beforeEach(() => {
    window.location.hash = ''
  })

  it('renders direct-jump modules grouped by PHR category', () => {
    render(
      <PhrCommandPalette
        open
        onClose={jest.fn()}
        onDrill={jest.fn()}
        registry={phrModuleRegistry}
      />,
    )

    expect(screen.getByRole('group', { name: 'Clinical' })).toBeInTheDocument()
    expect(screen.getByRole('group', { name: 'Documents & Imaging' })).toBeInTheDocument()
    expect(screen.getByRole('group', { name: 'Admin' })).toBeInTheDocument()
    expect(screen.getByText('Labs')).toBeInTheDocument()
    expect(screen.getByText('Documents')).toBeInTheDocument()
    expect(screen.getByText('Access')).toBeInTheDocument()
    expect(screen.queryByText('Lab Panel')).not.toBeInTheDocument()
    expect(screen.queryByText('Document Viewer')).not.toBeInTheDocument()
  })

  it('matches modules by keyword and id', () => {
    render(
      <PhrCommandPalette
        open
        onClose={jest.fn()}
        onDrill={jest.fn()}
        registry={phrModuleRegistry}
      />,
    )

    const input = screen.getByPlaceholderText(/jump to a PHR module/i)
    fireEvent.change(input, { target: { value: 'bloodwork' } })
    expect(screen.getByText('Labs')).toBeInTheDocument()
    expect(screen.queryByText('Medications')).not.toBeInTheDocument()

    fireEvent.change(input, { target: { value: 'office-visits' } })
    expect(screen.getByText('Office Visits')).toBeInTheDocument()
    expect(screen.queryByText('Labs')).not.toBeInTheDocument()
  })

  it('matches modules by short label', () => {
    render(
      <PhrCommandPalette
        open
        onClose={jest.fn()}
        onDrill={jest.fn()}
        registry={phrModuleRegistry}
      />,
    )

    fireEvent.change(screen.getByPlaceholderText(/jump to a PHR module/i), { target: { value: 'meds' } })

    expect(screen.getByText('Medications')).toBeInTheDocument()
  })

  it('selecting a module drills and closes the palette', () => {
    const onClose = jest.fn()
    const onDrill = jest.fn()

    render(
      <PhrCommandPalette
        open
        onClose={onClose}
        onDrill={onDrill}
        registry={phrModuleRegistry}
      />,
    )

    fireEvent.click(screen.getByText('Medications'))

    expect(onDrill).toHaveBeenCalledWith({ id: 'medications' })
    expect(onClose).toHaveBeenCalledTimes(1)
  })

  it('opens and toggles from the Cmd/Ctrl-K shortcut', () => {
    render(<ShortcutHarness />)

    fireEvent.keyDown(window, { key: 'k', metaKey: true })
    expect(screen.getByTestId('palette-state')).toHaveTextContent('open')

    fireEvent.keyDown(window, { key: 'K', ctrlKey: true })
    expect(screen.getByTestId('palette-state')).toHaveTextContent('closed')
  })

  it('suppresses the shortcut while editing', () => {
    render(<ShortcutHarness />)

    screen.getByLabelText('Editable input').focus()
    fireEvent.keyDown(window, { key: 'k', metaKey: true })

    expect(screen.getByTestId('palette-state')).toHaveTextContent('closed')
  })

  it('suppresses the shortcut while another dialog is open', () => {
    render(<ShortcutHarness withDialog />)

    fireEvent.keyDown(window, { key: 'k', metaKey: true })

    expect(screen.getByTestId('palette-state')).toHaveTextContent('closed')
  })

  it('opens the palette from the PHR shell shortcut', () => {
    render(<PhrMillerShell initialPatientId={42} />)

    fireEvent.keyDown(window, { key: 'k', metaKey: true })

    expect(screen.getByPlaceholderText(/jump to a PHR module/i)).toBeInTheDocument()
  })
})
