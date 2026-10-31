import { act, fireEvent, render, screen, within } from '@testing-library/react'
import { type ReactElement, useState } from 'react'

import { RecordList } from './RecordList'
import { type RecordColumn, RecordTable } from './RecordTable'
import { StatusDot } from './StatusDot'
import type { RecordOpenOptions } from './useRecordNavigation'

interface Item {
  id: string
  name: string
}

const ITEMS: Item[] = [
  { id: 'a', name: 'Alpha' },
  { id: 'b', name: 'Beta' },
  { id: 'c', name: 'Gamma' },
]

const COLUMNS: RecordColumn<Item>[] = [
  { id: 'name', header: 'Name', cell: (item) => item.name },
  {
    id: 'actions',
    header: 'Actions',
    cell: (item) => (
      <>
        <button type="button">{`Edit ${item.name}`}</button>
        <button type="button">{`Delete ${item.name}`}</button>
      </>
    ),
  },
]

/**
 * jsdom does not move focus on Tab, so this does what a browser does with the sequential focus
 * order: every focusable element with a non-negative tabindex, in document order.
 */
function tabbables(): HTMLElement[] {
  const candidates = Array.from(document.body.querySelectorAll<HTMLElement>('a[href], button, input, select, textarea, [tabindex]'))
  return candidates.filter((element) => !(element as HTMLButtonElement).disabled && element.tabIndex >= 0)
}

function pressTab(shift = false): void {
  const order = tabbables()
  const current = order.indexOf(document.activeElement as HTMLElement)
  const next = order[shift ? current - 1 : current + 1]
  if (next) act(() => next.focus())
}

function rowNamed(name: string): HTMLElement {
  return screen.getByText(name, { selector: 'td' }).closest('tr')!
}

function key(target: Element, value: string, init: Partial<KeyboardEventInit> = {}): void {
  fireEvent.keyDown(target, { key: value, ...init })
}

interface HarnessProps {
  onOpen?: (item: Item, options: RecordOpenOptions) => void
  initialOpen?: string | null
  selectable?: boolean
}

function Harness({ onOpen, initialOpen = null, selectable = false }: HarnessProps): ReactElement {
  const [openId, setOpenId] = useState<string | null>(initialOpen)
  const [selected, setSelected] = useState<Set<string>>(() => new Set())
  return (
    <>
      <input aria-label="Filter" />
      <RecordTable
        label="Things"
        rows={ITEMS}
        rowId={(item) => item.id}
        columns={COLUMNS}
        openRowId={openId}
        onOpen={(item, options) => {
          onOpen?.(item, options)
          setOpenId(item.id)
        }}
        {...(selectable ? {
          selection: {
            isSelected: (id: string) => selected.has(id),
            onToggle: (id: string) => setSelected((current) => {
              const next = new Set(current)
              if (next.has(id)) next.delete(id)
              else next.add(id)
              return next
            }),
          },
          selectionColumn: { label: (item: Item) => item.name },
        } : {})}
      />
      <button type="button">After the table</button>
      {openId !== null && (
        <aside aria-label="Detail">
          <button type="button" onClick={() => setOpenId(null)}>Close detail</button>
        </aside>
      )}
    </>
  )
}

describe('RecordTable keyboard model', () => {
  it('is one Tab stop with a roving tabindex and aria-selected on the active row', () => {
    render(<Harness />)

    expect(rowNamed('Alpha')).toHaveAttribute('tabindex', '0')
    expect(rowNamed('Alpha')).toHaveAttribute('aria-selected', 'true')
    expect(rowNamed('Beta')).toHaveAttribute('tabindex', '-1')
    expect(rowNamed('Beta')).toHaveAttribute('aria-selected', 'false')
    expect(screen.getByRole('table', { name: 'Things' })).toBeInTheDocument()
  })

  it('moves with the arrow keys, J and K, Home and End', () => {
    render(<Harness />)
    act(() => rowNamed('Alpha').focus())

    key(rowNamed('Alpha'), 'ArrowDown')
    expect(rowNamed('Beta')).toHaveFocus()
    expect(rowNamed('Beta')).toHaveAttribute('tabindex', '0')
    expect(rowNamed('Alpha')).toHaveAttribute('tabindex', '-1')

    key(rowNamed('Beta'), 'j')
    expect(rowNamed('Gamma')).toHaveFocus()
    key(rowNamed('Gamma'), 'ArrowDown')
    expect(rowNamed('Gamma')).toHaveFocus()

    key(rowNamed('Gamma'), 'k')
    expect(rowNamed('Beta')).toHaveFocus()
    key(rowNamed('Beta'), 'ArrowUp')
    expect(rowNamed('Alpha')).toHaveFocus()

    key(rowNamed('Alpha'), 'End')
    expect(rowNamed('Gamma')).toHaveFocus()
    expect(rowNamed('Gamma')).toHaveAttribute('aria-selected', 'true')
    key(rowNamed('Gamma'), 'Home')
    expect(rowNamed('Alpha')).toHaveFocus()
  })

  it('opens the active row with Enter and with a click, but not from a click on its buttons', () => {
    const onOpen = jest.fn()
    render(<Harness onOpen={onOpen} />)

    act(() => rowNamed('Alpha').focus())
    key(rowNamed('Alpha'), 'ArrowDown')
    key(rowNamed('Beta'), 'Enter')
    expect(onOpen).toHaveBeenLastCalledWith(ITEMS[1], { replace: false })
    expect(rowNamed('Beta')).toHaveAttribute('data-open')

    fireEvent.click(screen.getByRole('button', { name: 'Edit Gamma' }))
    expect(onOpen).toHaveBeenCalledTimes(1)

    fireEvent.click(within(rowNamed('Gamma')).getByText('Gamma'))
    expect(onOpen).toHaveBeenLastCalledWith(ITEMS[2], { replace: false })
  })

  it('makes a row active when one of its buttons is clicked or focused', () => {
    render(<Harness />)

    act(() => screen.getByRole('button', { name: 'Delete Gamma' }).focus())

    expect(rowNamed('Gamma')).toHaveAttribute('aria-selected', 'true')
    expect(rowNamed('Gamma')).toHaveAttribute('tabindex', '0')
    expect(screen.getByRole('button', { name: 'Delete Gamma' })).not.toHaveAttribute('tabindex')
    expect(screen.getByRole('button', { name: 'Delete Alpha' })).toHaveAttribute('tabindex', '-1')
  })

  it('tabs from the active row through its buttons and out of the table, and back with Shift+Tab', () => {
    render(<Harness />)
    act(() => screen.getByLabelText('Filter').focus())

    pressTab()
    expect(rowNamed('Alpha')).toHaveFocus()
    key(rowNamed('Alpha'), 'ArrowDown')
    expect(rowNamed('Beta')).toHaveFocus()

    pressTab()
    expect(screen.getByRole('button', { name: 'Edit Beta' })).toHaveFocus()
    pressTab()
    expect(screen.getByRole('button', { name: 'Delete Beta' })).toHaveFocus()
    pressTab()
    expect(screen.getByRole('button', { name: 'After the table' })).toHaveFocus()

    pressTab(true)
    expect(screen.getByRole('button', { name: 'Delete Beta' })).toHaveFocus()
    pressTab(true)
    expect(screen.getByRole('button', { name: 'Edit Beta' })).toHaveFocus()
    pressTab(true)
    expect(rowNamed('Beta')).toHaveFocus()
    pressTab(true)
    expect(screen.getByLabelText('Filter')).toHaveFocus()

    const reached = tabbables()
    for (const name of ['Alpha', 'Gamma']) {
      expect(reached).not.toContain(screen.getByRole('button', { name: `Edit ${name}` }))
      expect(reached).not.toContain(screen.getByRole('button', { name: `Delete ${name}` }))
      expect(reached).not.toContain(rowNamed(name))
    }
  })

  it('moves to the next row, not the next row’s button, when an arrow is pressed on a button', () => {
    render(<Harness />)

    act(() => screen.getByRole('button', { name: 'Edit Alpha' }).focus())
    key(screen.getByRole('button', { name: 'Edit Alpha' }), 'ArrowDown')

    expect(rowNamed('Beta')).toHaveFocus()
    expect(rowNamed('Beta')).toHaveAttribute('aria-selected', 'true')
  })

  it('returns from a button to its row with Esc', () => {
    render(<Harness />)

    act(() => screen.getByRole('button', { name: 'Delete Beta' }).focus())
    key(screen.getByRole('button', { name: 'Delete Beta' }), 'Escape')

    expect(rowNamed('Beta')).toHaveFocus()
  })

  it('ignores list keys typed into a field inside a row', () => {
    render(
      <RecordTable
        label="Editable"
        rows={ITEMS}
        rowId={(item) => item.id}
        columns={[{ id: 'name', header: 'Name', cell: (item) => <input aria-label={`Name of ${item.name}`} defaultValue={item.name} /> }]}
      />,
    )
    const field = screen.getByLabelText('Name of Alpha')
    act(() => field.focus())

    key(field, 'j')
    key(field, 'ArrowDown')

    expect(field).toHaveFocus()
  })

  it('follows the active row with the open panel, debounced, replacing rather than pushing', () => {
    jest.useFakeTimers()
    try {
      const onOpen = jest.fn()
      render(<Harness onOpen={onOpen} />)
      act(() => rowNamed('Alpha').focus())
      key(rowNamed('Alpha'), 'Enter')
      expect(onOpen).toHaveBeenLastCalledWith(ITEMS[0], { replace: false })

      key(rowNamed('Alpha'), 'ArrowDown')
      act(() => { jest.advanceTimersByTime(100) })
      key(rowNamed('Beta'), 'ArrowDown')
      act(() => { jest.advanceTimersByTime(100) })
      expect(onOpen).toHaveBeenCalledTimes(1)

      act(() => { jest.advanceTimersByTime(60) })
      expect(onOpen).toHaveBeenCalledTimes(2)
      expect(onOpen).toHaveBeenLastCalledWith(ITEMS[2], { replace: true })
      expect(rowNamed('Gamma')).toHaveAttribute('data-open')
    } finally {
      jest.useRealTimers()
    }
  })

  it('does not open anything when the active row moves while no panel is open', () => {
    jest.useFakeTimers()
    try {
      const onOpen = jest.fn()
      render(<Harness onOpen={onOpen} />)
      act(() => rowNamed('Alpha').focus())

      key(rowNamed('Alpha'), 'ArrowDown')
      act(() => { jest.advanceTimersByTime(500) })

      expect(onOpen).not.toHaveBeenCalled()
    } finally {
      jest.useRealTimers()
    }
  })

  it('puts focus back on the open row when the panel closes', () => {
    render(<Harness initialOpen="b" />)
    expect(rowNamed('Beta')).toHaveAttribute('aria-selected', 'true')
    expect(rowNamed('Beta')).toHaveAttribute('data-open')

    // Closing from inside the panel removes the focused control, so focus would fall to the page.
    const close = screen.getByRole('button', { name: 'Close detail' })
    act(() => close.focus())
    fireEvent.click(close)

    expect(screen.queryByRole('complementary', { name: 'Detail' })).not.toBeInTheDocument()
    expect(rowNamed('Beta')).toHaveFocus()
    expect(rowNamed('Beta')).not.toHaveAttribute('data-open')
  })

  it('toggles a row’s checkbox with Space', () => {
    render(<Harness selectable />)
    act(() => rowNamed('Alpha').focus())

    key(rowNamed('Alpha'), ' ')
    expect(screen.getByRole('checkbox', { name: 'Select Alpha' })).toBeChecked()
    key(rowNamed('Alpha'), 'ArrowDown')
    key(rowNamed('Beta'), ' ')
    expect(screen.getByRole('checkbox', { name: 'Select Beta' })).toBeChecked()
    key(rowNamed('Beta'), 'ArrowUp')
    key(rowNamed('Alpha'), ' ')
    expect(screen.getByRole('checkbox', { name: 'Select Alpha' })).not.toBeChecked()
  })

  it('renders its loading and empty states instead of the table', () => {
    const { rerender } = render(<RecordTable label="Things" rows={ITEMS} rowId={(item) => item.id} columns={COLUMNS} loading />)
    expect(screen.getByRole('status')).toHaveTextContent('Loading…')
    expect(screen.queryByRole('table')).not.toBeInTheDocument()

    rerender(<RecordTable label="Things" rows={[]} rowId={(item: Item) => item.id} columns={COLUMNS} empty={<p>Nothing yet.</p>} />)
    expect(screen.getByText('Nothing yet.')).toBeInTheDocument()
    expect(screen.queryByRole('table')).not.toBeInTheDocument()
  })
})

describe('RecordTable column sizing', () => {
  interface Result { id: string, analyte: string, value: string }
  const results: Result[] = [{ id: '1', analyte: 'Glucose', value: '112' }, { id: '2', analyte: 'Influenza A RNA', value: 'Not Detected' }]

  it('fits short columns to their content and right-aligns only numeric values', () => {
    render(
      <RecordTable
        label="Results"
        rows={results}
        rowId={(result) => result.id}
        columns={[
          { id: 'analyte', header: 'Analyte', cell: (result) => result.analyte },
          { id: 'value', header: 'Value', fit: true, numeric: (result) => /^\d/.test(result.value), cell: (result) => result.value },
          { id: 'status', header: 'Status', fit: true, cell: () => <StatusDot tone="success" label="confirmed" /> },
        ]}
      />,
    )

    const [analyteHead, valueHead, statusHead] = screen.getAllByRole('columnheader')
    expect(analyteHead).not.toHaveClass('w-[1%]')
    expect(valueHead).toHaveClass('w-[1%]', 'whitespace-nowrap', 'text-left')
    expect(statusHead).toHaveClass('w-[1%]', 'whitespace-nowrap')
    expect(screen.getByText('Glucose').closest('td')).toHaveClass('text-left')
    expect(screen.getByText('112').closest('td')).toHaveClass('w-[1%]', 'whitespace-nowrap', 'text-right', 'tabular-nums')
    expect(screen.getByText('Not Detected').closest('td')).toHaveClass('text-left')
    expect(screen.getByText('Not Detected').closest('td')).not.toHaveClass('text-right')
  })

  it('shows a status as a dot and a label on one line', () => {
    render(<StatusDot tone="warning" label="pending review" />)
    const status = screen.getByText('pending review')
    expect(status).toHaveClass('whitespace-nowrap', 'text-foreground')
    expect(status).toHaveAttribute('data-tone', 'warning')
    expect(status.querySelector('[aria-hidden="true"]')).toHaveClass('bg-warning', 'rounded-full')
  })
})

describe('RecordList', () => {
  it('shares the keyboard model and marks the active item with aria-current', () => {
    const onOpen = jest.fn()
    render(
      <RecordList
        label="Timeline"
        as="ol"
        rows={ITEMS}
        rowId={(item) => item.id}
        onOpen={onOpen}
        renderItem={(item) => <><span>{item.name}</span><button type="button">{`Edit ${item.name}`}</button></>}
      />,
    )
    const items = within(screen.getByRole('list', { name: 'Timeline' })).getAllByRole('listitem')
    expect(items[0]).toHaveAttribute('aria-current', 'true')
    expect(screen.getByRole('button', { name: 'Edit Beta' })).toHaveAttribute('tabindex', '-1')

    act(() => items[0]!.focus())
    key(items[0]!, 'j')
    expect(items[1]).toHaveFocus()
    expect(items[1]).toHaveAttribute('aria-current', 'true')
    expect(screen.getByRole('button', { name: 'Edit Beta' })).not.toHaveAttribute('tabindex')
    key(items[1]!, 'Enter')
    expect(onOpen).toHaveBeenCalledWith(ITEMS[1], { replace: false })
  })
})
