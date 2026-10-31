import { act, renderHook } from '@testing-library/react'

import { automaticSidebarMode, sidebarModeStorageKey, useSidebarMode, WIDE_VIEWPORT_QUERY } from './useSidebarMode'

function mockViewport(wide: boolean): () => void {
  const original = window.matchMedia
  window.matchMedia = ((query: string) => ({
    matches: query === WIDE_VIEWPORT_QUERY ? wide : false,
    media: query,
    addEventListener: () => {},
    removeEventListener: () => {},
  })) as unknown as typeof window.matchMedia
  return () => { window.matchMedia = original }
}

describe('automaticSidebarMode', () => {
  it.each([
    ['page', false, true, 'expanded'],
    ['page', false, false, 'expanded'],
    ['page', true, true, 'expanded'],
    ['page', true, false, 'rail'],
    ['full-width', false, true, 'rail'],
    ['full-width', true, true, 'rail'],
  ] as const)('%s, detail open %s, wide %s → %s', (context, detailOpen, wide, mode) => {
    expect(automaticSidebarMode(context, detailOpen, wide)).toBe(mode)
  })
})

describe('WIDE_VIEWPORT_QUERY', () => {
  it('switches at 1600px, so 1440 with a panel open is the rail and 1700 is expanded', () => {
    expect(WIDE_VIEWPORT_QUERY).toBe('(min-width: 1600px)')
    const width = (w: number): boolean => w >= Number(/min-width: (\d+)px/.exec(WIDE_VIEWPORT_QUERY)?.[1])
    expect(automaticSidebarMode('page', true, width(1440))).toBe('rail')
    expect(automaticSidebarMode('page', true, width(1700))).toBe('expanded')
  })
})

describe('useSidebarMode', () => {
  let restore: () => void

  beforeEach(() => {
    window.localStorage.clear()
    restore = mockViewport(false)
  })

  afterEach(() => restore())

  it('follows the automatic choice until toggled', () => {
    const { result, rerender } = renderHook(({ detailOpen }) => useSidebarMode('page', detailOpen), { initialProps: { detailOpen: false } })
    expect(result.current.mode).toBe('expanded')
    rerender({ detailOpen: true })
    expect(result.current.mode).toBe('rail')
  })

  it('lets a manual choice override the automatic one, per context type, and remembers it', () => {
    const { result, rerender } = renderHook(({ context }) => useSidebarMode(context, true), { initialProps: { context: 'page' as 'page' | 'full-width' } })
    act(() => result.current.setMode('expanded'))
    expect(result.current.mode).toBe('expanded')
    expect(window.localStorage.getItem(sidebarModeStorageKey('page'))).toBe('expanded')

    // A full-width view keeps its own automatic choice.
    rerender({ context: 'full-width' })
    expect(result.current.mode).toBe('rail')

    rerender({ context: 'page' })
    expect(result.current.mode).toBe('expanded')

    const remounted = renderHook(() => useSidebarMode('page', true))
    expect(remounted.result.current.mode).toBe('expanded')
  })

  it('works without storage', () => {
    const getItem = jest.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('blocked') })
    const setItem = jest.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('blocked') })
    try {
      const { result } = renderHook(() => useSidebarMode('full-width', false))
      expect(result.current.mode).toBe('rail')
      act(() => result.current.setMode('expanded'))
      expect(result.current.mode).toBe('expanded')
    } finally {
      getItem.mockRestore()
      setItem.mockRestore()
    }
  })
})
