import { render, screen } from '@testing-library/react'

import { MillerColumnShell } from './MillerColumnShell'

describe('MillerColumnShell', () => {
  it('uses the requested home column size when detail columns are open', () => {
    render(
      <MillerColumnShell
        homeView={<div>Home</div>}
        columns={[
          {
            key: 'labs',
            id: 'labs',
            label: 'Labs',
            shortLabel: 'Labs',
            children: <div>Labs list</div>,
          },
        ]}
        onTruncate={jest.fn()}
        homeColumnSize="narrow"
      />,
    )

    expect(screen.getByText('Home').closest('section')).toHaveClass('md:w-[400px]')
  })
})
