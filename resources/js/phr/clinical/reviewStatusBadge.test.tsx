import { render, screen } from '@testing-library/react'

import { reviewStatusBadge, reviewStatusNeedsAttention } from './ui'

describe('reviewStatusBadge', () => {
  it('shows a warning dot for pending review', () => {
    const { container } = render(<>{reviewStatusBadge('pending_review')}</>)
    expect(screen.getByText('Pending review')).toBeInTheDocument()
    expect(container.querySelector('[data-tone="warning"]')).not.toBeNull()
  })

  it('shows a muted dot for rejected', () => {
    const { container } = render(<>{reviewStatusBadge('rejected')}</>)
    expect(screen.getByText('Rejected')).toBeInTheDocument()
    expect(container.querySelector('[data-tone="neutral"]')).not.toBeNull()
  })

  it('shows nothing for approved or absent statuses', () => {
    const { container } = render(<>{reviewStatusBadge('confirmed')}{reviewStatusBadge(null)}{reviewStatusBadge(undefined)}</>)
    expect(container).toBeEmptyDOMElement()
  })

  it('names the statuses that need attention', () => {
    expect(reviewStatusNeedsAttention('pending_review')).toBe(true)
    expect(reviewStatusNeedsAttention('rejected')).toBe(true)
    expect(reviewStatusNeedsAttention('confirmed')).toBe(false)
    expect(reviewStatusNeedsAttention(null)).toBe(false)
  })
})
