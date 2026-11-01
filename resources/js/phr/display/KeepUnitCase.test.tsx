import { render } from '@testing-library/react'

import { KeepUnitCase } from './DisplayPreferences'

function renderInLowercase(text: string | null) {
  return render(<div className="lowercase"><KeepUnitCase text={text} /></div>).container.firstElementChild!
}

describe('KeepUnitCase', () => {
  it('wraps cased units so force-lowercase leaves them as written', () => {
    const root = renderInLowercase('5 mL in 1 bottle, 10 IU/mL, 2 mEq/L')
    expect(root.textContent).toBe('5 mL in 1 bottle, 10 IU/mL, 2 mEq/L')
    const kept = Array.from(root.querySelectorAll('.normal-case')).map((span) => span.textContent)
    expect(kept).toEqual(['mL', 'IU', 'mL', 'mEq', 'L'])
  })

  it('does not touch letters inside words or units that are already lowercase', () => {
    const root = renderInLowercase('20 mg/1 tablet, Lilly Labs')
    expect(root.textContent).toBe('20 mg/1 tablet, Lilly Labs')
    expect(root.querySelector('.normal-case')).toBeNull()
  })

  it('renders nothing for missing text', () => {
    expect(renderInLowercase(null).textContent).toBe('')
  })
})
