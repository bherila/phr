export interface RelyingApplication {
  key: string
  name: string
  url: string
}

interface AppInitialData {
  authenticated?: boolean
  currentUser?: {
    name?: string
    email?: string
  } | null
  applications?: RelyingApplication[]
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

/**
 * The other applications this person can reach, as the identity provider reported them.
 *
 * Injected per request from the session rather than compiled in, so the set of applications
 * that exist is not readable by anyone who simply downloads the bundle.
 */
export function relyingApplications(): RelyingApplication[] {
  const apps = readInitialData().applications

  if (!Array.isArray(apps)) {
    return []
  }

  // A `javascript:` or `data:` URL passes FILTER_VALIDATE_URL on the way out and would be
  // followed here, so the scheme is checked again rather than trusted from the wire.
  return apps.filter((app): app is RelyingApplication =>
    typeof app?.key === 'string'
    && typeof app?.name === 'string'
    && typeof app?.url === 'string'
    && /^https?:\/\//i.test(app.url))
}
