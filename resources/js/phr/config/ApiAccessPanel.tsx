import { ApiCredentialsSection,type AuthComponentInput } from 'bwh-auth'
import type { ReactElement } from 'react'

import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { getCsrfToken } from '@/fetchWrapper'

const components = { Button, Card, CardContent, CardDescription, CardHeader, CardTitle, Input, Label } satisfies AuthComponentInput

/** The URLs a connector asks for, on this installation's own origin. */
export function connectorLinks(origin: string): { label: string, url: string }[] {
  return [
    { label: 'OpenAPI document', url: `${origin}/api/openapi.json` },
    { label: 'API base URL', url: `${origin}/api/v1` },
    { label: 'OAuth authorize URL', url: `${origin}/oauth/authorize` },
    { label: 'OAuth token URL', url: `${origin}/oauth/token` },
    { label: 'MCP endpoint', url: `${origin}/api/v1/mcp` },
  ]
}

export default function ApiAccessPanel(): ReactElement {
  const csrfToken = getCsrfToken()

  return (
    <div className="h-full overflow-y-auto p-6">
      <div className="mx-auto max-w-5xl space-y-4">
        <div>
          <h1 className="text-2xl font-semibold text-foreground">API access</h1>
          <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
            Create an API token for a connector that asks for a key, or register an OAuth app for one that signs in
            through PHR. Each grants only the permissions you choose, and patient access rules still apply to every
            request. MCP clients such as Claude connect on their own and need neither.
          </p>
        </div>
        <ApiCredentialsSection
          indexUrl="/account/api-credentials"
          components={components}
          {...(csrfToken === null ? {} : { csrfToken })}
          links={connectorLinks(window.location.origin)}
        />
      </div>
    </div>
  )
}
