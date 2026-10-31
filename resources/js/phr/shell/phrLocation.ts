/**
 * The browser location is the single source of truth for the PHR shell: the path says which
 * patient, page and record are on screen. Everything that reads it subscribes here, and
 * everything that changes it goes through {@link pushPhrLocation} or {@link replacePhrLocation},
 * so every listener resyncs on the same notification.
 *
 * Why three events: a history traversal (Back / Forward) always fires `popstate`, but Blink and
 * WebKit fire `hashchange` only when the two entries differ in nothing but the fragment, and
 * `history.pushState` fires neither. Programmatic navigation therefore announces itself with
 * {@link PHR_LOCATION_CHANGE_EVENT}; `hashchange` is still watched so a pasted legacy hash link
 * is redirected.
 */
export const PHR_LOCATION_CHANGE_EVENT = 'phr:locationchange'

const LOCATION_EVENTS = ['popstate', 'hashchange', PHR_LOCATION_CHANGE_EVENT] as const

/** Run `listener` after every location change; returns the unsubscribe function. */
export function subscribeToPhrLocation(listener: () => void): () => void {
  if (typeof window === 'undefined') {
    return () => {}
  }
  for (const type of LOCATION_EVENTS) {
    window.addEventListener(type, listener)
  }
  return () => {
    for (const type of LOCATION_EVENTS) {
      window.removeEventListener(type, listener)
    }
  }
}

function writeLocation(url: string, mode: 'push' | 'replace'): void {
  if (typeof window === 'undefined') {
    return
  }
  const next = new URL(url, window.location.href)
  if (next.href === window.location.href) {
    return
  }
  const target = `${next.pathname}${next.search}${next.hash}`
  if (mode === 'push') {
    window.history.pushState(null, '', target)
  } else {
    window.history.replaceState(null, '', target)
  }
  window.dispatchEvent(new Event(PHR_LOCATION_CHANGE_EVENT))
}

/**
 * Push a new history entry for `url` and notify subscribers. A no-op when `url` already is the
 * current location, so repeated clicks do not stack entries that Back would have to step through.
 */
export function pushPhrLocation(url: string): void {
  writeLocation(url, 'push')
}

/** Replace the current history entry (used for redirects, which must not add a Back step). */
export function replacePhrLocation(url: string): void {
  writeLocation(url, 'replace')
}
