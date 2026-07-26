import { act, renderHook } from '@testing-library/react'

import { type MillerDockPrefsSnapshot, useMillerDockPrefs } from '../useMillerDockPrefs'

type TestId = 'summary' | 'labs' | 'vitals' | 'documents'

describe('useMillerDockPrefs', () => {
  it('prepends new recent ids, preserves existing recent order, caps recent ids, and toggles pins', () => {
    let stored: MillerDockPrefsSnapshot<TestId> = { pinned: [], recent: [] }
    const readPrefs = jest.fn(() => stored)
    const writePrefs = jest.fn((next: MillerDockPrefsSnapshot<TestId>) => {
      stored = next
    })

    const { result } = renderHook(() => useMillerDockPrefs<TestId>({ readPrefs, writePrefs, recentCap: 2 }))

    act(() => {
      result.current.addRecent('summary')
      result.current.addRecent('labs')
      result.current.addRecent('vitals')
      result.current.addRecent('labs')
      result.current.togglePin('documents')
    })

    expect(result.current.recent).toEqual(['vitals', 'labs'])
    expect(result.current.pinned).toEqual(['documents'])
    expect(result.current.isPinned('documents')).toBe(true)

    act(() => {
      result.current.clearRecent()
      result.current.togglePin('documents')
    })

    expect(result.current.recent).toEqual([])
    expect(result.current.pinned).toEqual([])
  })

  it('does not persist when commits are disabled', () => {
    const readPrefs = jest.fn((): MillerDockPrefsSnapshot<TestId> => ({ pinned: [], recent: [] }))
    const writePrefs = jest.fn()

    const { result } = renderHook(() => useMillerDockPrefs<TestId>({ canCommit: false, readPrefs, writePrefs }))

    act(() => {
      result.current.addRecent('labs')
      result.current.togglePin('labs')
      result.current.clearRecent()
    })

    expect(result.current.recent).toEqual([])
    expect(result.current.pinned).toEqual([])
    expect(writePrefs).not.toHaveBeenCalled()
  })
})
