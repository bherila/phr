import { useCallback, useEffect, useState } from 'react'

export interface MillerDockPrefsSnapshot<Id extends string> {
  pinned: Id[]
  recent: Id[]
}

export interface UseMillerDockPrefsResult<Id extends string> extends MillerDockPrefsSnapshot<Id> {
  addRecent: (id: Id) => void
  togglePin: (id: Id) => void
  clearRecent: () => void
  isPinned: (id: Id) => boolean
}

interface UseMillerDockPrefsOptions<Id extends string> {
  canCommit?: boolean
  readPrefs: () => MillerDockPrefsSnapshot<Id>
  writePrefs: (prefs: MillerDockPrefsSnapshot<Id>) => void
  recentCap?: number
}

export function useMillerDockPrefs<Id extends string>({
  canCommit = true,
  readPrefs,
  writePrefs,
  recentCap = 5,
}: UseMillerDockPrefsOptions<Id>): UseMillerDockPrefsResult<Id> {
  const [prefs, setPrefs] = useState<MillerDockPrefsSnapshot<Id>>({ pinned: [], recent: [] })

  useEffect(() => {
    // eslint-disable-next-line @eslint-react/set-state-in-effect -- refresh prefs when the storage adapter key changes.
    setPrefs(readPrefs())
  }, [readPrefs])

  const commit = useCallback(
    (mutate: (current: MillerDockPrefsSnapshot<Id>) => MillerDockPrefsSnapshot<Id>): void => {
      if (!canCommit) {
        return
      }

      const next = mutate(readPrefs())
      writePrefs(next)
      setPrefs(next)
    },
    [canCommit, readPrefs, writePrefs],
  )

  const addRecent = useCallback(
    (id: Id): void => {
      commit((current) => {
        if (current.recent.includes(id)) {
          return current
        }

        return {
          ...current,
          recent: [id, ...current.recent].slice(0, recentCap),
        }
      })
    },
    [commit, recentCap],
  )

  const togglePin = useCallback(
    (id: Id): void => {
      commit((current) =>
        current.pinned.includes(id)
          ? { ...current, pinned: current.pinned.filter((pinnedId) => pinnedId !== id) }
          : { ...current, pinned: [...current.pinned, id] },
      )
    },
    [commit],
  )

  const clearRecent = useCallback((): void => {
    commit((current) => ({
      ...current,
      recent: [],
    }))
  }, [commit])

  const isPinned = useCallback((id: Id): boolean => prefs.pinned.includes(id), [prefs.pinned])

  return {
    pinned: prefs.pinned,
    recent: prefs.recent,
    addRecent,
    togglePin,
    clearRecent,
    isPinned,
  }
}
