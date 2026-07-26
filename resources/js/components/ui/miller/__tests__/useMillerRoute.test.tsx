import { act, renderHook } from '@testing-library/react'

import { useMillerRoute } from '../useMillerRoute'

const VALID_IDS: ReadonlySet<string> = new Set(['form-1040', 'sch-1', 'sch-2', 'form-1116'])

describe('useMillerRoute', () => {
  beforeEach(() => {
    window.location.hash = ''
  })

  it('reads the initial hash on mount', () => {
    window.location.hash = '#/form-1040/sch-1'
    const { result } = renderHook(() => useMillerRoute<'form-1040' | 'sch-1' | 'sch-2' | 'form-1116'>(VALID_IDS))
    expect(result.current.route.columns).toEqual([{ id: 'form-1040' }, { id: 'sch-1' }])
  })

  it('pushColumn appends to the route and updates the hash', () => {
    const { result } = renderHook(() => useMillerRoute<'form-1040' | 'sch-1' | 'sch-2' | 'form-1116'>(VALID_IDS))
    act(() => {
      result.current.pushColumn({ id: 'form-1040' })
    })
    expect(result.current.route.columns).toEqual([{ id: 'form-1040' }])
    expect(window.location.hash).toBe('#/form-1040')

    act(() => {
      result.current.pushColumn({ id: 'sch-1' })
    })
    expect(result.current.route.columns).toEqual([{ id: 'form-1040' }, { id: 'sch-1' }])
    expect(window.location.hash).toBe('#/form-1040/sch-1')
  })

  it('replaceFrom drops columns to the right of the replaced depth', () => {
    window.location.hash = '#/form-1040/sch-1/form-1116'
    const { result } = renderHook(() => useMillerRoute<'form-1040' | 'sch-1' | 'sch-2' | 'form-1116'>(VALID_IDS))

    act(() => {
      result.current.replaceFrom(1, { id: 'sch-2' })
    })
    expect(result.current.route.columns).toEqual([{ id: 'form-1040' }, { id: 'sch-2' }])
    expect(window.location.hash).toBe('#/form-1040/sch-2')
  })

  it('truncateTo(0) clears the hash', () => {
    window.location.hash = '#/form-1040/sch-1'
    const { result } = renderHook(() => useMillerRoute<'form-1040' | 'sch-1' | 'sch-2' | 'form-1116'>(VALID_IDS))

    act(() => {
      result.current.truncateTo(0)
    })
    expect(result.current.route.columns).toEqual([])
    expect(window.location.hash).toBe('')
  })

  it('responds to external hashchange events', () => {
    const { result } = renderHook(() => useMillerRoute<'form-1040' | 'sch-1' | 'sch-2' | 'form-1116'>(VALID_IDS))

    act(() => {
      window.location.hash = '#/form-1040/sch-1'
      window.dispatchEvent(new HashChangeEvent('hashchange'))
    })
    expect(result.current.route.columns).toEqual([{ id: 'form-1040' }, { id: 'sch-1' }])
  })

  it('drops unknown ids from external hash mutations', () => {
    const { result } = renderHook(() => useMillerRoute<'form-1040' | 'sch-1' | 'sch-2' | 'form-1116'>(VALID_IDS))
    act(() => {
      window.location.hash = '#/form-1040/totally-fake/sch-1'
      window.dispatchEvent(new HashChangeEvent('hashchange'))
    })
    expect(result.current.route.columns).toEqual([{ id: 'form-1040' }, { id: 'sch-1' }])
  })
})
