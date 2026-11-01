import { AppWindow, ShieldOff } from 'lucide-react'
import type { ReactElement } from 'react'
import { useCallback, useEffect, useRef, useState } from 'react'

import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@/components/ui/alert-dialog'
import { Badge } from '@/components/ui/badge'
import { Button, buttonVariants } from '@/components/ui/button'
import { formatInstant } from '@/lib/dateFormat'
import { errorMessage } from '@/phr/shared'

import { type ConnectedApp, type ConnectedAppsResult, listConnectedApps, revokeConnectedApp } from './connectedApps'

function appName(app: ConnectedApp): string {
  return app.name.trim() || 'Unnamed app'
}

function grantDate(value: string | null): string {
  if (value === null) return 'Unknown'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return 'Unknown'
  return formatInstant(value)
}

function resourceLabel(resource: string): string {
  if (resource.endsWith('/api/v1/mcp')) return 'MCP'
  if (resource.endsWith('/api/v1')) return 'REST API'
  return 'Resource'
}

export default function ConnectedAppsPage(): ReactElement {
  const [result, setResult] = useState<ConnectedAppsResult | null>(null)
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [actionBusyId, setActionBusyId] = useState<string | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const requestIdRef = useRef(0)
  const requestedPageRef = useRef(1)
  const mountedRef = useRef(false)

  const loadApps = useCallback(async (page: number): Promise<void> => {
    const currentRequest = ++requestIdRef.current
    let pageToLoad = page
    requestedPageRef.current = pageToLoad
    setLoading(true)
    setLoadError(null)
    try {
      while (true) {
        requestedPageRef.current = pageToLoad
        const response = await listConnectedApps(pageToLoad)
        if (!mountedRef.current || currentRequest !== requestIdRef.current) return
        // Another window can revoke apps while this user is paging. Follow the
        // server's last page rather than claiming there are no connected apps.
        if (response.data.length === 0 && response.meta.total > 0 && pageToLoad > response.meta.last_page) {
          pageToLoad = response.meta.last_page
          continue
        }
        setResult(response)
        return
      }
    } catch (caught) {
      if (mountedRef.current && currentRequest === requestIdRef.current) setLoadError(errorMessage(caught))
    } finally {
      if (mountedRef.current && currentRequest === requestIdRef.current) setLoading(false)
    }
  }, [])

  useEffect(() => {
    mountedRef.current = true
    void loadApps(1)
    return () => {
      mountedRef.current = false
    }
  }, [loadApps])

  async function revoke(app: ConnectedApp): Promise<void> {
    if (actionBusyId !== null) return
    setActionError(null)
    setNotice(null)
    setActionBusyId(app.client_id)
    try {
      await revokeConnectedApp(app.client_id)
      if (!mountedRef.current) return
      setNotice(`Access revoked for ${appName(app)}.`)
      setResult((current) => current === null ? null : {
        ...current,
        data: current.data.filter((candidate) => candidate.client_id !== app.client_id),
      })
      // Removing the last app on a later page must return to an existing page.
      const page = result?.meta.current_page ?? 1
      await loadApps(page > 1 && result?.data.length === 1 ? page - 1 : page)
    } catch (caught) {
      if (mountedRef.current) setActionError(errorMessage(caught))
    } finally {
      if (mountedRef.current) setActionBusyId(null)
    }
  }

  const busy = loading || actionBusyId !== null

  return (
    <div className="h-full overflow-y-auto p-6">
      <div className="mx-auto max-w-5xl">
        <h1 className="text-2xl font-semibold text-foreground">Connected apps</h1>
        <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
          Apps you have approved to access PHR. Review their permissions or revoke access across all connected resources.
          Personal API tokens are managed under API Access; paired devices are managed under Connected Devices.
        </p>

        {notice ? <p role="status" className="mt-4 text-sm text-foreground">{notice}</p> : null}
        {actionError ? (
          <p role="alert" className="mt-4 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
            Could not revoke access. {actionError}
          </p>
        ) : null}
        {loadError ? (
          <div role="alert" className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
            <span>Could not load connected apps. {loadError}</span>
            <Button type="button" variant="outline" size="sm" disabled={busy} onClick={() => void loadApps(requestedPageRef.current)}>Retry</Button>
          </div>
        ) : null}
        {loading ? <p role="status" className="mt-6 text-sm text-muted-foreground">Loading connected apps…</p> : null}

        {!loading && !loadError && result?.data.length === 0 ? (
          <div className="mt-6 rounded-lg border border-dashed border-border p-10 text-center">
            <AppWindow aria-hidden="true" className="mx-auto size-8 text-muted-foreground/60" />
            <p className="mt-3 text-sm text-muted-foreground">No apps are connected. Apps appear here after you approve their access to PHR.</p>
          </div>
        ) : null}

        <div aria-busy={busy} className="mt-6 space-y-4">
          {result?.data.map((app) => (
            <article key={app.client_id} className="rounded-lg border border-border bg-card p-4 shadow-sm">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <h2 className="min-w-0 break-words font-semibold text-card-foreground">{appName(app)}</h2>
                <AlertDialog>
                  <AlertDialogTrigger asChild>
                    <Button type="button" variant="destructive" size="sm" disabled={busy}>
                      <ShieldOff aria-hidden="true" className="size-4" />
                      {actionBusyId === app.client_id ? 'Revoking…' : 'Revoke access'}
                    </Button>
                  </AlertDialogTrigger>
                  <AlertDialogContent>
                    <AlertDialogHeader>
                      <AlertDialogTitle className="break-words">Revoke access for {appName(app)}?</AlertDialogTitle>
                      <AlertDialogDescription>
                        This disconnects the app from PHR across all resources and invalidates its existing access.
                        You will need to approve access again before the app can reconnect.
                      </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                      <AlertDialogCancel>Cancel</AlertDialogCancel>
                      <AlertDialogAction className={buttonVariants({ variant: 'destructive' })} onClick={() => void revoke(app)}>
                        Revoke access
                      </AlertDialogAction>
                    </AlertDialogFooter>
                  </AlertDialogContent>
                </AlertDialog>
              </div>
              <div className="mt-4 space-y-4">
                {app.resources.map((resource) => (
                  <section key={resource.resource} className="rounded-md bg-muted/40 p-3">
                    <h3 className="text-sm font-medium">{resourceLabel(resource.resource)}</h3>
                    <p className="mt-1 break-all font-mono text-xs text-muted-foreground">{resource.resource}</p>
                    <dl className="mt-3 space-y-2 text-sm">
                      <div>
                        <dt className="text-muted-foreground">Permissions</dt>
                        <dd className="mt-1 flex flex-wrap gap-1">
                          {resource.scopes.length === 0 ? 'No permissions listed' : resource.scopes.map((scope) => (
                            <Badge key={scope} variant="secondary" className="max-w-full whitespace-normal break-all">{scope}</Badge>
                          ))}
                        </dd>
                      </div>
                      <div className="flex flex-wrap gap-x-2">
                        <dt className="text-muted-foreground">Approved</dt>
                        <dd>{grantDate(resource.granted_at)}</dd>
                      </div>
                    </dl>
                  </section>
                ))}
              </div>
            </article>
          ))}
        </div>

        {result !== null && result.meta.last_page > 1 ? (
          <nav aria-label="Connected apps pages" className="mt-6 flex flex-wrap items-center justify-between gap-3">
            <p className="text-sm text-muted-foreground">Page {result.meta.current_page} of {result.meta.last_page}</p>
            <div className="flex gap-2">
              <Button type="button" variant="outline" size="sm" disabled={busy || result.meta.current_page <= 1} onClick={() => void loadApps(result.meta.current_page - 1)}>Previous</Button>
              <Button type="button" variant="outline" size="sm" disabled={busy || result.meta.current_page >= result.meta.last_page} onClick={() => void loadApps(result.meta.current_page + 1)}>Next</Button>
            </div>
          </nav>
        ) : null}
      </div>
    </div>
  )
}
