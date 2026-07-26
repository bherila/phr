import { useCallback, useEffect, useState } from 'react'

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

function writeRoute<Id extends string>(route: MillerRoute<Id>): void {
  if (typeof window === 'undefined') {
    return
  }
  const next = serializeRoute(route)
  const current = window.location.hash
  if (next === current) {
    return
  }
  if (next === '') {
    history.replaceState(null, '', window.location.pathname + window.location.search)
    window.dispatchEvent(new Event('hashchange'))
  } else {
    window.location.hash = next
  }
}

export function useMillerRoute<Id extends string>(validIds: ReadonlySet<string>): UseMillerRouteResult<Id> {
  const [route, setRoute] = useState<MillerRoute<Id>>(() => parseHash<Id>(readCurrentHash(), validIds))

  useEffect(() => {
    if (typeof window === 'undefined') {
      return
    }
    const handler = (): void => {
      const parsed = parseHash<Id>(window.location.hash, validIds)
      setRoute((prev) => (routesEqual(prev, parsed) ? prev : parsed))
    }
    window.addEventListener('hashchange', handler)
    handler()
    return () => {
      window.removeEventListener('hashchange', handler)
    }
  }, [validIds])

  const pushColumn = useCallback((column: MillerColumnSpec<Id>): void => {
    setRoute((prev) => {
      const next = pushColumnPure(prev, column)
      writeRoute(next)
      return next
    })
  }, [])

  const replaceFrom = useCallback((depth: number, column: MillerColumnSpec<Id>): void => {
    setRoute((prev) => {
      const next = replaceFromPure(prev, depth, column)
      writeRoute(next)
      return next
    })
  }, [])

  const truncateTo = useCallback((depth: number): void => {
    setRoute((prev) => {
      const next = truncateToPure(prev, depth)
      writeRoute(next)
      return next
    })
  }, [])

  const navigate = useCallback((next: MillerRoute<Id>): void => {
    setRoute(() => {
      writeRoute(next)
      return next
    })
  }, [])

  return { route, pushColumn, replaceFrom, truncateTo, navigate }
}
