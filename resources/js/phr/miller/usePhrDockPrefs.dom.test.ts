import { act, renderHook } from '@testing-library/react'

import { usePhrDockPrefs } from './usePhrDockPrefs'

function storageKey(patientId: number): string {
  return `phr-dock-prefs-patient-${patientId}`
}

beforeEach(() => {
  window.localStorage.clear()
})

describe('usePhrDockPrefs', () => {
  it('hydrates with empty defaults when no prefs exist', () => {
    const { result } = renderHook(() => usePhrDockPrefs(123))

    expect(result.current.pinned).toEqual([])
    expect(result.current.recent).toEqual([])
    expect(result.current.isPinned('labs')).toBe(false)
  })

  it('hydrates from patient-scoped stored prefs', () => {
    window.localStorage.setItem(storageKey(123), JSON.stringify({ pinned: ['labs'], recent: ['vitals'] }))
    window.localStorage.setItem(storageKey(456), JSON.stringify({ pinned: ['documents'], recent: ['access'] }))

    const { result } = renderHook(() => usePhrDockPrefs(123))

    expect(result.current.pinned).toEqual(['labs'])
    expect(result.current.recent).toEqual(['vitals'])
    expect(result.current.isPinned('labs')).toBe(true)
  })

  it('discards corrupt stored prefs', () => {
    window.localStorage.setItem(storageKey(123), 'not json')

    const { result } = renderHook(() => usePhrDockPrefs(123))

    expect(result.current.pinned).toEqual([])
    expect(result.current.recent).toEqual([])
  })

  it('addRecent prepends new ids, preserves existing order, and caps at 5', () => {
    const { result } = renderHook(() => usePhrDockPrefs(123))

    act(() => {
      result.current.addRecent('summary')
      result.current.addRecent('labs')
      result.current.addRecent('vitals')
      result.current.addRecent('imaging')
      result.current.addRecent('documents')
      result.current.addRecent('access')
      result.current.addRecent('labs')
    })

    expect(result.current.recent).toEqual(['access', 'documents', 'imaging', 'vitals', 'labs'])
  })

  it('togglePin adds and removes an id', () => {
    const { result } = renderHook(() => usePhrDockPrefs(123))

    act(() => {
      result.current.togglePin('labs')
    })

    expect(result.current.pinned).toEqual(['labs'])

    act(() => {
      result.current.togglePin('labs')
    })

    expect(result.current.pinned).toEqual([])
  })

  it('clearRecent clears only the active patient prefs', () => {
    const { result: patient123 } = renderHook(() => usePhrDockPrefs(123))
    const { result: patient456 } = renderHook(() => usePhrDockPrefs(456))

    act(() => {
      patient123.current.addRecent('labs')
      patient456.current.addRecent('documents')
    })

    act(() => {
      patient123.current.clearRecent()
    })

    expect(JSON.parse(window.localStorage.getItem(storageKey(123)) ?? '{}')).toMatchObject({ recent: [] })
    expect(JSON.parse(window.localStorage.getItem(storageKey(456)) ?? '{}')).toMatchObject({ recent: ['documents'] })
  })

  it('returns empty prefs and no-op mutators when patientId is undefined', () => {
    const { result } = renderHook(() => usePhrDockPrefs(undefined))

    act(() => {
      result.current.addRecent('labs')
      result.current.togglePin('labs')
      result.current.clearRecent()
    })

    expect(result.current.pinned).toEqual([])
    expect(result.current.recent).toEqual([])
    expect(result.current.isPinned('labs')).toBe(false)
    expect(window.localStorage.length).toBe(0)
  })
})
