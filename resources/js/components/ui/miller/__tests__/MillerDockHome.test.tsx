import { fireEvent, render, screen } from '@testing-library/react'

import { MillerDockClearButton, MillerDockSection } from '../MillerDockHome'

type TestId = 'summary' | 'labs'

describe('MillerDockSection', () => {
  it('renders launch tiles with amounts, badges, and pin controls', () => {
    const onOpen = jest.fn()
    const onTogglePin = jest.fn()

    render(
      <MillerDockSection<TestId>
        title="Pinned"
        entries={[
          {
            id: 'labs',
            label: 'Labs',
            shortLabel: 'Labs',
            amounts: [{ label: 'Records', value: '12' }],
            badge: <span>2</span>,
          },
        ]}
        onOpen={onOpen}
        isPinned={(id) => id === 'labs'}
        onTogglePin={onTogglePin}
      />,
    )

    expect(screen.getAllByText('Labs')).toHaveLength(1)

    fireEvent.click(screen.getByRole('button', { name: /Labs Records 12/i }))
    expect(onOpen).toHaveBeenCalledWith('labs')
    expect(screen.getByText('2')).toBeInTheDocument()

    const pinButton = screen.getByRole('button', { name: 'Unpin Labs' })
    expect(pinButton).toHaveAttribute('aria-pressed', 'true')

    fireEvent.click(pinButton)
    expect(onTogglePin).toHaveBeenCalledWith('labs')
  })

  it('exposes the full label to assistive tech when the visible label is abbreviated', () => {
    render(
      <MillerDockSection<TestId>
        title="Clinical"
        entries={[{ id: 'labs', label: 'Lab Results', shortLabel: 'Labs' }]}
        onOpen={jest.fn()}
      />,
    )

    expect(screen.getByText('Labs')).toBeInTheDocument()
    expect(screen.getByText('Lab Results')).toHaveClass('sr-only')
    expect(screen.getByRole('button', { name: /Lab Results/i })).toBeInTheDocument()
  })

  it('keeps amount typography caller-controlled', () => {
    render(
      <MillerDockSection<TestId>
        title="Clinical"
        entries={[
          {
            id: 'labs',
            label: 'Labs',
            shortLabel: 'Labs',
            amounts: [
              { label: 'Records', value: '12' },
              { label: 'Cost', value: '$4.00', className: 'font-currency' },
            ],
          },
        ]}
        onOpen={jest.fn()}
      />,
    )

    expect(screen.getByText('Records').parentElement).not.toHaveClass('font-currency')
    expect(screen.getByText('Cost').parentElement).toHaveClass('font-currency')
  })

  it('hides pin controls for entries that opt out', () => {
    render(
      <MillerDockSection<TestId>
        title="Recent"
        entries={[{ id: 'summary', label: 'Summary', shortLabel: 'Summary', canPin: false }]}
        onOpen={jest.fn()}
        isPinned={() => false}
        onTogglePin={jest.fn()}
      />,
    )

    expect(screen.queryByRole('button', { name: 'Pin Summary' })).not.toBeInTheDocument()
  })

  it('renders a reusable clear button', () => {
    const onClear = jest.fn()

    render(<MillerDockClearButton onClear={onClear} />)

    fireEvent.click(screen.getByRole('button', { name: 'Clear' }))
    expect(onClear).toHaveBeenCalledTimes(1)
  })
})
