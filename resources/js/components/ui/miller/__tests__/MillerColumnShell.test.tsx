import { fireEvent, render, screen } from '@testing-library/react'
import React from 'react'

import { MillerColumnShell, type MillerColumnShellColumn } from '../MillerColumnShell'

const COLUMN: MillerColumnShellColumn = {
  key: 'col-1',
  id: 'form-1040',
  label: 'Form 1040',
  shortLabel: '1040',
  children: <div>Column content</div>,
}

interface HarnessProps {
  columns?: MillerColumnShellColumn[]
  onTruncate?: jest.Mock
}

function Harness({
  columns = [COLUMN],
  onTruncate = jest.fn(),
}: HarnessProps): React.ReactElement {
  return (
    <MillerColumnShell
      homeView={<div>Home</div>}
      columns={columns}
      onTruncate={onTruncate}
    />
  )
}

function expectColumnClass(id: string, expectedClass: string): void {
  const column = document.querySelector<HTMLElement>(`section[data-column-id="${id}"]`)
  expect(column).not.toBeNull()
  expect(column!.className).toContain(expectedClass)
}

describe('MillerColumnShell', () => {
  it('maps column size values to responsive width classes', () => {
    render(
      <Harness
        columns={[
          { ...COLUMN, key: 'narrow', id: 'narrow', size: 'narrow' },
          { ...COLUMN, key: 'default', id: 'default' },
          { ...COLUMN, key: 'wide', id: 'wide', size: 'wide' },
          { ...COLUMN, key: 'full', id: 'full', size: 'full' },
          { ...COLUMN, key: 'viewport', id: 'viewport', size: 'viewport' },
        ]}
      />,
    )

    expectColumnClass('narrow', 'md:w-[400px]')
    expectColumnClass('default', 'md:w-[520px]')
    expectColumnClass('wide', 'md:w-[760px]')
    expectColumnClass('full', 'md:w-[1040px]')
    expectColumnClass('full', 'xl:w-[1200px]')
    expectColumnClass('viewport', 'w-screen')
    expectColumnClass('viewport', 'max-w-screen')
  })

  it('keeps deprecated wide columns compatible with the wide size', () => {
    render(<Harness columns={[{ ...COLUMN, key: 'wide', id: 'wide', wide: true }]} />)

    expectColumnClass('wide', 'md:w-[760px]')
  })

  it('uses the full width scale for the home column when columns are open', () => {
    render(<Harness />)

    const homeColumn = screen.getByText('Home').closest('section')
    expect(homeColumn).not.toBeNull()
    expect(homeColumn!.className).toContain('md:w-[1040px]')
    expect(homeColumn!.className).toContain('xl:w-[1200px]')
  })

  it('clicking the close button truncates the column at that depth', () => {
    const onTruncate = jest.fn()
    render(<Harness onTruncate={onTruncate} />)
    fireEvent.click(screen.getByRole('button', { name: /close columns after 1040/i }))
    expect(onTruncate).toHaveBeenCalledWith(0)
  })

  it('pressing Escape truncates the rightmost column', () => {
    const onTruncate = jest.fn()
    render(<Harness onTruncate={onTruncate} />)
    fireEvent.keyDown(window, { key: 'Escape' })
    expect(onTruncate).toHaveBeenCalledWith(0)
  })

  it('Escape is ignored when focus is on an editable field', () => {
    const onTruncate = jest.fn()
    render(
      <MillerColumnShell
        homeView={<div>Home</div>}
        columns={[{ ...COLUMN, children: <input data-testid="text-input" /> }]}
        onTruncate={onTruncate}
      />,
    )
    const input = screen.getByTestId('text-input')
    input.focus()
    const escEvent = new KeyboardEvent('keydown', { key: 'Escape', bubbles: true })
    Object.defineProperty(escEvent, 'target', { value: input, writable: false })
    window.dispatchEvent(escEvent)
    expect(onTruncate).not.toHaveBeenCalled()
  })

  it('Escape does not truncate when a dialog with data-open is present', () => {
    const onTruncate = jest.fn()
    render(<Harness onTruncate={onTruncate} />)
    // Add a dialog element that the shell checks for
    const dialog = document.createElement('div')
    dialog.setAttribute('role', 'dialog')
    dialog.setAttribute('data-open', 'true')
    document.body.appendChild(dialog)
    try {
      fireEvent.keyDown(window, { key: 'Escape' })
      expect(onTruncate).not.toHaveBeenCalled()
    } finally {
      document.body.removeChild(dialog)
    }
  })
})
