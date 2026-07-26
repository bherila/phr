import { render } from '@testing-library/react'

import { PhrNotFoundColumn } from './PhrNotFoundColumn'

describe('PhrNotFoundColumn', () => {
  it('renders the default message', () => {
    const { asFragment } = render(<PhrNotFoundColumn />)

    expect(asFragment()).toMatchSnapshot()
  })

  it('renders a custom message', () => {
    const { asFragment } = render(<PhrNotFoundColumn message="Record missing for this patient." />)

    expect(asFragment()).toMatchSnapshot()
  })
})

