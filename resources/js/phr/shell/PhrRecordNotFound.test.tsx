import '@testing-library/jest-dom'

import { render, screen } from '@testing-library/react'

import { PhrRecordNotFound } from './PhrRecordNotFound'

describe('PhrRecordNotFound', () => {
  it('renders the default message', () => {
    render(<PhrRecordNotFound />)

    expect(screen.getByText('Record not found. It may belong to a different patient.')).toBeInTheDocument()
  })

  it('renders a custom message', () => {
    render(<PhrRecordNotFound message="Record missing for this patient." />)

    expect(screen.getByText('Record missing for this patient.')).toBeInTheDocument()
  })
})
