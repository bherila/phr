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
 * Reduce a URL from the wire to one that is safe to put in an `href`.
 *
 * These arrive as text in the page and end up as a link the browser will follow, so the
 * scheme is the thing that matters: `javascript:` and `data:` both pass the provider-side
 * FILTER_VALIDATE_URL check but execute rather than navigate. Parsing and allowing only
 * http(s) is stronger than matching a prefix — it normalises away the leading control
 * characters and escapes that a hand-rolled test can be walked past — and it returns the
 * parsed form so what is rendered is what was validated.
 */
function safeHref(url: string): string | null {
  try {
    const parsed = new URL(url)

    return parsed.protocol === 'https:' || parsed.protocol === 'http:' ? parsed.toString() : null
  } catch {
    return null
  }
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

  return apps.flatMap((app): RelyingApplication[] => {
    if (typeof app?.key !== 'string' || typeof app?.name !== 'string' || typeof app?.url !== 'string') {
      return []
    }

    const url = safeHref(app.url)

    return url === null ? [] : [{ key: app.key, name: app.name, url }]
  })
}
