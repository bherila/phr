import { fireEvent, render, screen } from '@testing-library/react'
import { type ReactElement, useState } from 'react'

import { usePaletteShortcut } from './usePaletteShortcut'

function ShortcutHarness({ withDialog = false }: { withDialog?: boolean }): ReactElement {
  const [open, setOpen] = useState(false)
  usePaletteShortcut(open, setOpen)

  return (
    <>
      <div data-testid="palette-state">{open ? 'open' : 'closed'}</div>
      <input aria-label="Editable input" />
      <div aria-label="Rich editor" contentEditable role="textbox" tabIndex={0} />
      {withDialog ? <div role="dialog" data-open>Existing dialog</div> : null}
    </>
  )
}

describe('usePaletteShortcut', () => {
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
})
