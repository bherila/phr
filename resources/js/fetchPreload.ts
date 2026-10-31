import { PHR_LOCATION_CHANGE_EVENT } from '@/phr/shell/phrLocation'

/**
 * First responses the server inlined into the PHR shell page, so the views on screen at load
 * do not each wait for their own round trip. The page carries
 * `<script type="application/json" id="phr-preload">{ "<GET URL>": <JSON body> }</script>`,
 * each body being exactly what that API URL returned for the signed-in person
 * (`App\Services\PHR\Preload\PhrPreloadBuilder`).
 *
 * The map serves the first load and nothing else:
 * - each entry answers one request, so a refetch always reaches the network;
 * - the element is removed once read, and every unused entry is dropped as soon as the page
 *   moves on: an in-app navigation, Back/Forward, a write through {@link fetchWrapper}, a restore
 *   from the back/forward cache, or {@link PHR_PRELOAD_TTL_MS} after boot.
 */
export const PHR_PRELOAD_ELEMENT_ID = 'phr-preload'

/** Long enough for lazy page chunks on a slow link; a later consumer reads fresh data instead. */
export const PHR_PRELOAD_TTL_MS = 30_000

const LEAVE_EVENTS = ['popstate', 'hashchange', PHR_LOCATION_CHANGE_EVENT] as const

const entries = new Map<string, unknown>()
let bootLocation = ''
let bootedAt = 0

/**
 * The key a GET URL is preloaded under: its path plus its query parameters in sorted order, or
 * null for another origin (or an unparsable URL), which is never served from the map.
 */
export function phrPreloadKey(url: string): string | null {
  let parsed: URL
  try {
    parsed = new URL(url, window.location.href)
  } catch {
    return null
  }
  if (parsed.origin !== window.location.origin) return null
  parsed.searchParams.sort()
  const query = parsed.searchParams.toString()
  return query === '' ? parsed.pathname : `${parsed.pathname}?${query}`
}

function currentLocation(): string {
  return `${window.location.pathname}${window.location.search}`
}

function onPageShow(event: PageTransitionEvent): void {
  if (event.persisted) clearPhrPreload()
}

function detach(): void {
  for (const type of LEAVE_EVENTS) window.removeEventListener(type, clearPhrPreload)
  window.removeEventListener('pageshow', onPageShow)
}

/** Forget every entry that has not been used. */
export function clearPhrPreload(): void {
  if (entries.size === 0) return
  entries.clear()
  detach()
}

/**
 * Read the page's preload element into memory and remove it. Call once at boot, after any
 * boot-time URL rewrite: entries are only served while the location is still the one loaded.
 */
export function installPhrPreload(): void {
  clearPhrPreload()
  const element = document.getElementById(PHR_PRELOAD_ELEMENT_ID)
  if (element === null) return
  const text = element.textContent ?? ''
  element.remove()

  let raw: unknown
  try {
    raw = JSON.parse(text)
  } catch {
    return
  }
  if (typeof raw !== 'object' || raw === null || Array.isArray(raw)) return
  for (const [url, body] of Object.entries(raw)) {
    const key = phrPreloadKey(url)
    if (key !== null) entries.set(key, body)
  }
  if (entries.size === 0) return

  bootLocation = currentLocation()
  bootedAt = performance.now()
  for (const type of LEAVE_EVENTS) window.addEventListener(type, clearPhrPreload)
  window.addEventListener('pageshow', onPageShow)
}

/**
 * The preloaded response for a GET of `url`, removed from the map, or null when it must go to
 * the network. A hit is a 200 JSON Response carrying the API's body, so it passes through the
 * same handling as one fetched.
 */
export function takePhrPreload(url: string): Response | null {
  if (entries.size === 0) return null
  if (currentLocation() !== bootLocation || performance.now() - bootedAt > PHR_PRELOAD_TTL_MS) {
    clearPhrPreload()
    return null
  }
  const key = phrPreloadKey(url)
  if (key === null || !entries.has(key)) return null
  const body = entries.get(key)
  entries.delete(key)
  if (entries.size === 0) detach()
  return new Response(JSON.stringify(body), {
    status: 200,
    statusText: 'OK',
    headers: { 'Content-Type': 'application/json' },
  })
}
