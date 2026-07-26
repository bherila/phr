import { fireEvent, render, screen } from '@testing-library/react'
import { type ReactNode, useState } from 'react'

import {
  MillerCommandPalette,
  type MillerCommandPaletteRow,
  useMillerCommandPaletteShortcut,
} from '../MillerCommandPalette'

type TestCategory = 'Clinical' | 'Admin'

interface TestRow extends MillerCommandPaletteRow<TestCategory> {
  target: string
}

interface ShortcutHarnessProps {
  children?: ReactNode
  initialOpen?: boolean
  name?: string
  priority?: 'default' | 'page'
}

const rows: TestRow[] = [
  {
    rowKey: 'labs',
    label: 'Labs',
    keywords: ['bloodwork'],
    category: 'Clinical',
    target: '/phr/labs',
  },
  {
    rowKey: 'access',
    label: 'Access',
    keywords: ['sharing'],
    category: 'Admin',
    target: '/phr/access',
  },
]

function ShortcutHarness({
  children,
  initialOpen = false,
  name = 'palette',
  priority = 'default',
}: ShortcutHarnessProps): React.ReactElement {
  const [open, setOpen] = useState(initialOpen)
  useMillerCommandPaletteShortcut(open, setOpen, { priority })

  return (
    <>
      <div data-testid={`${name}-state`}>{open ? 'open' : 'closed'}</div>
      {children}
    </>
  )
}

describe('MillerCommandPalette', () => {
  it('groups rows and closes before selecting a row', () => {
    const onOpenChange = jest.fn()
    const onSelect = jest.fn()

    render(
      <MillerCommandPalette<TestCategory, TestRow>
        open
        onOpenChange={onOpenChange}
        title="Jump"
        description="Search destinations"
        placeholder="Jump somewhere..."
        emptyMessage="No matches."
        groupOrder={['Clinical', 'Admin']}
        groupHeadings={{ Clinical: 'Clinical', Admin: 'Admin' }}
        rows={rows}
        onSelect={onSelect}
      />,
    )

    expect(screen.getByRole('group', { name: 'Clinical' })).toBeInTheDocument()
    expect(screen.getByRole('group', { name: 'Admin' })).toBeInTheDocument()

    fireEvent.click(screen.getByText('Access'))

    expect(onOpenChange).toHaveBeenCalledWith(false)
    expect(onSelect).toHaveBeenCalledWith(rows[1])
  })



  it('searches row labels even when labels are absent from explicit keywords', () => {
    render(
      <MillerCommandPalette<TestCategory, TestRow>
        open
        onOpenChange={jest.fn()}
        title="Jump"
        description="Search destinations"
        placeholder="Jump somewhere..."
        emptyMessage="No matches."
        groupOrder={['Clinical', 'Admin']}
        groupHeadings={{ Clinical: 'Clinical', Admin: 'Admin' }}
        rows={rows}
        onSelect={jest.fn()}
      />,
    )

    fireEvent.change(screen.getByPlaceholderText('Jump somewhere...'), { target: { value: 'labs' } })

    expect(screen.getByText('Labs')).toBeInTheDocument()
  })

  it('passes a custom filter to cmdk', () => {
    const filter = jest.fn((value: string) => (value === 'access' ? 1 : 0))
    render(
      <MillerCommandPalette<TestCategory, TestRow>
        open
        onOpenChange={jest.fn()}
        title="Jump"
        description="Search destinations"
        placeholder="Jump somewhere..."
        emptyMessage="No matches."
        groupOrder={['Clinical', 'Admin']}
        groupHeadings={{ Clinical: 'Clinical', Admin: 'Admin' }}
        rows={rows}
        onSelect={jest.fn()}
        filter={filter}
      />,
    )

    fireEvent.change(screen.getByPlaceholderText('Jump somewhere...'), { target: { value: 'only access' } })

    expect(filter).toHaveBeenCalled()
    expect(screen.getByText('Access')).toBeInTheDocument()
    expect(screen.queryByText('Labs')).not.toBeInTheDocument()
  })

  it('toggles from the Cmd/Ctrl-K shortcut', () => {
    render(<ShortcutHarness />)

    fireEvent.keyDown(window, { key: 'k', metaKey: true })
    expect(screen.getByTestId('palette-state')).toHaveTextContent('open')

    fireEvent.keyDown(window, { key: 'K', ctrlKey: true })
    expect(screen.getByTestId('palette-state')).toHaveTextContent('closed')
  })

  it('gives a page-local shortcut priority over an earlier site listener', () => {
    render(
      <>
        <ShortcutHarness name="site-palette" />
        <ShortcutHarness name="page-palette" priority="page" />
      </>,
    )

    fireEvent.keyDown(window, { key: 'k', metaKey: true })

    expect(screen.getByTestId('site-palette-state')).toHaveTextContent('closed')
    expect(screen.getByTestId('page-palette-state')).toHaveTextContent('open')
  })

  it('ignores shortcut events that were already handled', () => {
    render(<ShortcutHarness />)

    const handledEvent = new KeyboardEvent('keydown', {
      key: 'k',
      metaKey: true,
      cancelable: true,
    })
    handledEvent.preventDefault()
    window.dispatchEvent(handledEvent)

    expect(screen.getByTestId('palette-state')).toHaveTextContent('closed')
  })

  it('suppresses opening while a contenteditable target has focus', () => {
    render(
      <ShortcutHarness>
        <div aria-label="Rich editor" contentEditable role="textbox" suppressContentEditableWarning tabIndex={0}>
          Editable text
        </div>
      </ShortcutHarness>,
    )

    screen.getByRole('textbox', { name: 'Rich editor' }).focus()
    fireEvent.keyDown(window, { key: 'k', metaKey: true })

    expect(screen.getByTestId('palette-state')).toHaveTextContent('closed')
  })

  it('still allows the shortcut to close an open palette from editable focus', () => {
    render(
      <ShortcutHarness initialOpen>
        <input aria-label="Editable input" />
      </ShortcutHarness>,
    )

    screen.getByLabelText('Editable input').focus()
    fireEvent.keyDown(window, { key: 'k', metaKey: true })

    expect(screen.getByTestId('palette-state')).toHaveTextContent('closed')
  })
})
