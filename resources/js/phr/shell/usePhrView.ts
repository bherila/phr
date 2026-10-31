import { type MouseEvent, useCallback, useEffect, useMemo, useSyncExternalStore } from 'react'

import { pushPhrLocation, replacePhrLocation, subscribeToPhrLocation } from './phrLocation'
import { isPhrShellPath, legacyHashUrl, parsePhrLocation, type PhrView, phrViewUrl } from './phrRoutes'

function currentLocation(): string {
  return typeof window === 'undefined' ? '' : `${window.location.pathname}${window.location.search}`
}

/**
 * The view the location describes. Re-read on every location change, so Back and Forward
 * restore patient, page and detail together from one event.
 */
export function usePhrView(): PhrView | null {
  const location = useSyncExternalStore(subscribeToPhrLocation, currentLocation, currentLocation)
  return useMemo(() => {
    const [pathname = '', search = ''] = location.split(/(?=\?)/)
    return parsePhrLocation(pathname, search)
  }, [location])
}

/** Rewrite a legacy Miller hash URL to its path, now and whenever one is pasted in later. */
export function useLegacyHashRedirect(): void {
  useEffect(() => {
    const redirect = (): void => {
      const target = legacyHashUrl(window.location.pathname, window.location.search, window.location.hash)
      if (target !== null) replacePhrLocation(target)
    }
    redirect()
    return subscribeToPhrLocation(redirect)
  }, [])
}

export function navigateToView(view: PhrView): void {
  pushPhrLocation(phrViewUrl(view))
}

/**
 * Click handler for the shell root: same-tab clicks on links to shell routes navigate in place
 * instead of reloading the page, so every PHR link can be a real `<a href>` (open in new tab,
 * copy link) without dropping upload state. Links handled elsewhere call preventDefault first.
 */
export function useShellLinkInterception(): (event: MouseEvent<HTMLElement>) => void {
  return useCallback((event: MouseEvent<HTMLElement>): void => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
      return
    }
    const anchor = event.target instanceof Element ? event.target.closest('a[href]') : null
    if (!(anchor instanceof HTMLAnchorElement) || anchor.hasAttribute('download')) {
      return
    }
    if (anchor.target && anchor.target !== '_self') {
      return
    }
    const url = new URL(anchor.href, window.location.href)
    if (url.origin !== window.location.origin || !isPhrShellPath(url.pathname)) {
      return
    }
    event.preventDefault()
    pushPhrLocation(`${url.pathname}${url.search}${url.hash}`)
  }, [])
}
