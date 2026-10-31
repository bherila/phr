import { useCallback, useState, useSyncExternalStore } from 'react'

import { currentUser } from '@/lib/appShell'

export type SidebarMode = 'expanded' | 'rail'

/** A normal page, or a full-width view (document viewer, imaging study) that wants the room. */
export type SidebarContext = 'page' | 'full-width'

/** Below this width an open detail panel collapses the sidebar, so the list keeps its room. */
export const WIDE_VIEWPORT_QUERY = '(min-width: 1600px)'

export function automaticSidebarMode(context: SidebarContext, detailOpen: boolean, wideViewport: boolean): SidebarMode {
  if (context === 'full-width') return 'rail'
  if (detailOpen && !wideViewport) return 'rail'
  return 'expanded'
}

/** Per signed-in user and context type, so each keeps its own manual choice. */
export function sidebarModeStorageKey(context: SidebarContext): string {
  const user = currentUser()
  return `phr:sidebar-mode:${user?.email.toLowerCase() ?? 'anonymous'}:${context}`
}

function readOverride(key: string): SidebarMode | null {
  try {
    const stored = window.localStorage.getItem(key)
    return stored === 'expanded' || stored === 'rail' ? stored : null
  } catch {
    return null
  }
}

function subscribeToViewport(listener: () => void): () => void {
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return () => {}
  const query = window.matchMedia(WIDE_VIEWPORT_QUERY)
  query.addEventListener('change', listener)
  return () => query.removeEventListener('change', listener)
}

function isWideViewport(): boolean {
  // Without matchMedia (old browsers, tests) assume a desktop-sized window.
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return true
  return window.matchMedia(WIDE_VIEWPORT_QUERY).matches
}

export interface UseSidebarModeResult {
  mode: SidebarMode
  /** A manual choice: it overrides the automatic one for this context type and is remembered. */
  setMode: (mode: SidebarMode) => void
}

/**
 * The patient sidebar's width for the current context. Full-width views and an open detail
 * panel on a smaller desktop get the icon rail automatically; a manual toggle overrides that
 * per context type and is kept in localStorage (best effort).
 */
export function useSidebarMode(context: SidebarContext, detailOpen: boolean): UseSidebarModeResult {
  const wideViewport = useSyncExternalStore(subscribeToViewport, isWideViewport, () => true)
  const [overrides, setOverrides] = useState<Partial<Record<SidebarContext, SidebarMode | null>>>({})
  const override = context in overrides ? overrides[context] ?? null : readOverride(sidebarModeStorageKey(context))

  const setMode = useCallback((mode: SidebarMode): void => {
    setOverrides((current) => ({ ...current, [context]: mode }))
    try {
      window.localStorage.setItem(sidebarModeStorageKey(context), mode)
    } catch {
      /* Best effort: private windows and full quotas keep the choice for this visit only. */
    }
  }, [context])

  return { mode: override ?? automaticSidebarMode(context, detailOpen, wideViewport), setMode }
}
