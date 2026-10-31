/**
 * The browser location is the single source of truth for a Miller surface: the path carries
 * the surface's own context (e.g. the PHR patient) and the hash carries the column stack.
 * Everything that reads either one subscribes here, and everything that changes either one
 * goes through {@link pushMillerLocation}, so every listener resyncs on the same notification.
 *
 * Why three events: a history traversal (Back / Forward) always fires `popstate`, but
 * Blink and WebKit fire `hashchange` only when the two entries differ in nothing but the
 * fragment. Back from `/phr/patient/2` to `/phr/patients#/patients` therefore arrives as
 * `popstate` alone, and a listener that only watched `hashchange` kept the previous page's
 * columns. `history.pushState` fires neither, so programmatic navigation announces itself
 * with {@link MILLER_LOCATION_CHANGE_EVENT}.
 */
export const MILLER_LOCATION_CHANGE_EVENT = 'miller:locationchange'

const LOCATION_EVENTS = ['popstate', 'hashchange', MILLER_LOCATION_CHANGE_EVENT] as const

/** Run `listener` after every location change; returns the unsubscribe function. */
export function subscribeToMillerLocation(listener: () => void): () => void {
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

/**
 * Push a new history entry for `url` (path, query and/or hash) and notify subscribers.
 * A no-op when `url` already is the current location, so repeated clicks do not stack
 * duplicate entries that Back would have to step through.
 */
export function pushMillerLocation(url: string): void {
  if (typeof window === 'undefined') {
    return
  }
  const next = new URL(url, window.location.href)
  if (next.href === window.location.href) {
    return
  }
  window.history.pushState(null, '', `${next.pathname}${next.search}${next.hash}`)
  window.dispatchEvent(new Event(MILLER_LOCATION_CHANGE_EVENT))
}
