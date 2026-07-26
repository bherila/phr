import { fireEvent, render, screen } from '@testing-library/react'
import React from 'react'

import type { MillerRegistryEntry, MillerRenderProps } from '../millerRegistry'
import { useMillerApp } from '../useMillerApp'

type Id = 'form-1040' | 'sch-1' | 'worksheet'
type TestState = { year: number }

function MockComponent({ instance, onDrill }: MillerRenderProps<TestState, Id>): React.ReactElement {
  return (
    <div data-testid="mock-content">
      <span>{instance ? `instance:${instance.key}` : 'singleton'}</span>
      <button type="button" onClick={() => onDrill({ id: 'sch-1' })}>drill-column</button>
      <button type="button" onClick={() => onDrill({ id: 'worksheet' })}>drill-modal</button>
    </div>
  )
}

const registry: Record<Id, MillerRegistryEntry<TestState, Id>> = {
  'form-1040': { id: 'form-1040', label: 'Form 1040', shortLabel: '1040', presentation: 'column', component: MockComponent },
  'sch-1': { id: 'sch-1', label: 'Schedule 1', shortLabel: 'Sch 1', presentation: 'column', component: MockComponent },
  worksheet: { id: 'worksheet', label: 'Worksheet', shortLabel: 'Wks', presentation: 'modal', component: MockComponent },
}

const IDS: ReadonlySet<string> = new Set(['form-1040', 'sch-1', 'worksheet'])

interface HarnessProps {
  onRightmostChange?: (id: Id | null) => void
  onDrillUnhandled?: (id: Id) => void
  homeViewAsFn?: boolean
}

function Harness({ onRightmostChange, onDrillUnhandled, homeViewAsFn }: HarnessProps): React.ReactElement {
  const app = useMillerApp<TestState, Id>({
    registry,
    ids: IDS,
    state: { year: 2025 },
    homeView: homeViewAsFn
      ? (route) => <button type="button" onClick={() => route.pushColumn({ id: 'form-1040' })}>HOME-PUSH</button>
      : <div>HOME-VIEW</div>,
    ...(onRightmostChange ? { onRightmostChange } : {}),
    ...(onDrillUnhandled ? { onDrillUnhandled: (target) => onDrillUnhandled(target.id) } : {}),
  })

  return (
    <div>
      <span data-testid="rightmost">{app.rightmostId ?? 'none'}</span>
      {app.shell}
    </div>
  )
}

describe('useMillerApp', () => {
  beforeEach(() => {
    window.location.hash = ''
  })

  it('renders the home view and reports a null rightmost id when the route is empty', () => {
    render(<Harness />)
    expect(screen.getByText('HOME-VIEW')).toBeInTheDocument()
    expect(screen.getByTestId('rightmost')).toHaveTextContent('none')
  })

  it('renders columns from the initial hash and reports the rightmost id', () => {
    window.location.hash = '#/form-1040/sch-1'
    render(<Harness />)
    const sections = document.querySelectorAll('[data-miller-id]')
    expect(sections).toHaveLength(2)
    expect(screen.getByTestId('rightmost')).toHaveTextContent('sch-1')
  })

  it('fires onRightmostChange when the rightmost column changes', () => {
    const onRightmostChange = jest.fn()
    window.location.hash = '#/form-1040'
    render(<Harness onRightmostChange={onRightmostChange} />)
    expect(onRightmostChange).toHaveBeenLastCalledWith('form-1040')

    fireEvent.click(screen.getByRole('button', { name: 'drill-column' }))
    expect(onRightmostChange).toHaveBeenLastCalledWith('sch-1')
    expect(window.location.hash).toBe('#/form-1040/sch-1')
  })

  it('exposes route handles to a function home view', () => {
    render(<Harness homeViewAsFn />)
    fireEvent.click(screen.getByRole('button', { name: 'HOME-PUSH' }))
    expect(window.location.hash).toBe('#/form-1040')
  })

  it('forwards modal drills to onDrillUnhandled without changing the route', () => {
    window.location.hash = '#/form-1040'
    const onDrillUnhandled = jest.fn()
    render(<Harness onDrillUnhandled={onDrillUnhandled} />)
    fireEvent.click(screen.getByRole('button', { name: 'drill-modal' }))
    expect(onDrillUnhandled).toHaveBeenCalledWith('worksheet')
    expect(window.location.hash).toBe('#/form-1040')
  })
})
