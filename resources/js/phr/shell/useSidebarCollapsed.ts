import { useCallback, useState } from 'react'

import { currentUser } from '@/lib/appShell'

/** Per signed-in user, so people sharing a browser profile keep their own choice. */
export function sidebarStorageKey(): string {
  const user = currentUser()
  return `phr:sidebar-collapsed:${user?.email.toLowerCase() ?? 'anonymous'}`
}

function readCollapsed(key: string): boolean {
  try {
    return window.localStorage.getItem(key) === 'true'
  } catch {
    return false
  }
}

/** Whether the patient sidebar is collapsed to its icon rail, remembered across visits. */
export function useSidebarCollapsed(): [boolean, (collapsed: boolean) => void] {
  const [key] = useState(sidebarStorageKey)
  const [collapsed, setCollapsed] = useState(() => readCollapsed(key))
  const updateCollapsed = useCallback((next: boolean): void => {
    setCollapsed(next)
    try {
      window.localStorage.setItem(key, next ? 'true' : 'false')
    } catch {
      /* Best effort: private windows and full quotas keep the choice for this visit only. */
    }
  }, [key])
  return [collapsed, updateCollapsed]
}
