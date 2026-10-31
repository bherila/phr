import { useCallback, useEffect, useState } from 'react'

import { pushMillerLocation, subscribeToMillerLocation } from './millerLocation'
import {
  type MillerColumnSpec,
  type MillerRoute,
  parseHash,
  pushColumn as pushColumnPure,
  replaceFrom as replaceFromPure,
  routesEqual,
  serializeRoute,
  truncateTo as truncateToPure,
} from './millerRoute'

export interface UseMillerRouteResult<Id extends string> {
  route: MillerRoute<Id>
  pushColumn: (column: MillerColumnSpec<Id>) => void
  replaceFrom: (depth: number, column: MillerColumnSpec<Id>) => void
  truncateTo: (depth: number) => void
  navigate: (route: MillerRoute<Id>) => void
}

function readCurrentHash(): string {
  if (typeof window === 'undefined') {
    return ''
  }
  return window.location.hash
}

/**
 * Every column change pushes a history entry (closing the last column included, so Back
 * reopens it) and announces it; the subscription below then derives the route state from
 * the new hash, exactly as it does after Back / Forward.
 */
function writeRoute<Id extends string>(route: MillerRoute<Id>): void {
  if (typeof window === 'undefined') {
    return
  }
  pushMillerLocation(`${window.location.pathname}${window.location.search}${serializeRoute(route)}`)
}

export function useMillerRoute<Id extends string>(validIds: ReadonlySet<string>): UseMillerRouteResult<Id> {
  const [route, setRoute] = useState<MillerRoute<Id>>(() => parseHash<Id>(readCurrentHash(), validIds))

  useEffect(() => {
    if (typeof window === 'undefined') {
      return
    }
    const sync = (): void => {
      const parsed = parseHash<Id>(window.location.hash, validIds)
      setRoute((prev) => (routesEqual(prev, parsed) ? prev : parsed))
    }
    const unsubscribe = subscribeToMillerLocation(sync)
    sync()
    return unsubscribe
  }, [validIds])

  // Mutations start from the location, not from React state: the location is the source of
  // truth, and reading it keeps these side-effecting writes out of state updater functions.
  const currentRoute = useCallback((): MillerRoute<Id> => parseHash<Id>(readCurrentHash(), validIds), [validIds])

  const pushColumn = useCallback((column: MillerColumnSpec<Id>): void => {
    writeRoute(pushColumnPure(currentRoute(), column))
  }, [currentRoute])

  const replaceFrom = useCallback((depth: number, column: MillerColumnSpec<Id>): void => {
    writeRoute(replaceFromPure(currentRoute(), depth, column))
  }, [currentRoute])

  const truncateTo = useCallback((depth: number): void => {
    writeRoute(truncateToPure(currentRoute(), depth))
  }, [currentRoute])

  const navigate = useCallback((next: MillerRoute<Id>): void => {
    writeRoute(next)
  }, [])

  return { route, pushColumn, replaceFrom, truncateTo, navigate }
}
