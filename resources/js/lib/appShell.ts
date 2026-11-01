import { type RelyingApplication, relyingApplicationsFrom } from 'bwh-auth'

export type { RelyingApplication }

interface AppInitialData {
  authenticated?: boolean
  currentUser?: {
    name?: string
    email?: string
    genai_execution_mode?: unknown
    force_lowercase_display?: unknown
  } | null
  // Deliberately `unknown`: this is untrusted JSON from the page, not a validated
  // list. `relyingApplicationsFrom` is what turns it into one.
  applications?: unknown
}

function readInitialData(): AppInitialData {
  const node = document.getElementById('app-initial-data')
  if (!node?.textContent) {
    // Fail closed: absent initial data means "signed out", never an assumed identity.
    // The server is the real authorization boundary; this only decides what chrome renders.
    return {}
  }

  try {
    return JSON.parse(node.textContent) as AppInitialData
  } catch {
    return {}
  }
}

export function currentUser(): { name: string; email: string } | null {
  const user = readInitialData().currentUser

  if (!user?.name && !user?.email) {
    return null
  }

  return { name: user?.name ?? '', email: user?.email ?? '' }
}

export function genAiExecutionMode(): 'api' | 'external' {
  return readInitialData().currentUser?.genai_execution_mode === 'external' ? 'external' : 'api'
}

/** The saved force-lowercase display preference, or null when the page did not carry it. */
export function forceLowercaseDisplay(): boolean | null {
  const value = readInitialData().currentUser?.force_lowercase_display
  return typeof value === 'boolean' ? value : null
}

/**
 * The other applications this person can reach, as the identity provider reported them.
 *
 * Injected per request from the session rather than compiled in, so the set of applications
 * that exist is not readable by anyone who simply downloads the bundle. Which entries are
 * safe to render is `bwh-auth`'s call, not this app's: these become an `href` the browser
 * will follow, and that check is shared with the other relying parties so it cannot be
 * fixed in one of them and left wrong in the rest.
 */
export function relyingApplications(): RelyingApplication[] {
  return relyingApplicationsFrom(readInitialData().applications)
}
