import '@testing-library/jest-dom'

import { fireEvent, render, screen } from '@testing-library/react'

import { needsReview, ReviewActions, ShowRejectedToggle } from './review'

describe('needsReview', () => {
  it('treats confirmed and absent statuses as settled', () => {
    expect(needsReview('pending_review')).toBe(true)
    expect(needsReview('rejected')).toBe(true)
    expect(needsReview('confirmed')).toBe(false)
    expect(needsReview(null)).toBe(false)
    expect(needsReview(undefined)).toBe(false)
  })
})

describe('ReviewActions', () => {
  it('offers both decisions on a record awaiting review', () => {
    const onReview = jest.fn()
    render(<ReviewActions status="pending_review" label="Penicillin" busy={false} onReview={onReview} />)

    fireEvent.click(screen.getByRole('button', { name: 'Confirm Penicillin' }))
    expect(onReview).toHaveBeenCalledWith('confirmed')

    fireEvent.click(screen.getByRole('button', { name: 'Reject Penicillin' }))
    expect(onReview).toHaveBeenCalledWith('rejected')
  })

  it('offers only confirmation on a rejected record, so a rejection can be undone', () => {
    render(<ReviewActions status="rejected" label="Latex" busy={false} onReview={jest.fn()} />)

    expect(screen.getByRole('button', { name: 'Confirm Latex' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Reject Latex' })).not.toBeInTheDocument()
  })

  it('renders nothing for a record that needs no decision', () => {
    const { container } = render(
      <ReviewActions status="confirmed" label="Shellfish" busy={false} onReview={jest.fn()} />,
    )

    expect(container).toBeEmptyDOMElement()
  })

  it('blocks a second decision while one is in flight', () => {
    const onReview = jest.fn()
    render(<ReviewActions status="pending_review" label="Sulfa" busy onReview={onReview} />)

    fireEvent.click(screen.getByRole('button', { name: 'Confirm Sulfa' }))
    expect(onReview).not.toHaveBeenCalled()
  })
})

describe('ShowRejectedToggle', () => {
  it('reports its state and flips on click', () => {
    const onChange = jest.fn()
    const { rerender } = render(<ShowRejectedToggle showRejected={false} onChange={onChange} />)

    const toggle = screen.getByRole('button', { name: 'Show rejected' })
    expect(toggle).toHaveAttribute('aria-pressed', 'false')
    fireEvent.click(toggle)
    expect(onChange).toHaveBeenCalledWith(true)

    rerender(<ShowRejectedToggle showRejected onChange={onChange} />)
    const active = screen.getByRole('button', { name: 'Hide rejected' })
    expect(active).toHaveAttribute('aria-pressed', 'true')
    fireEvent.click(active)
    expect(onChange).toHaveBeenCalledWith(false)
  })
})
