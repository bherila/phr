import { Laptop, ShieldOff } from 'lucide-react'
import type { ReactElement } from 'react'
import { useCallback, useEffect, useState } from 'react'

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
import { fetchWrapper } from '@/fetchWrapper'
import { errorMessage } from '@/phr/shared'

import { type DeviceKey, DeviceKeyListSchema, DeviceRevokeResponseSchema, deviceStatus, friendlyDate } from './devices'

const STATUS_BADGE: Record<'active' | 'expired' | 'revoked', ReactElement> = {
  active: <Badge variant="secondary">Active</Badge>,
  expired: <Badge variant="destructive">Expired</Badge>,
  revoked: <Badge variant="destructive">Revoked</Badge>,
}

export default function ConnectedDevicesPanel(): ReactElement {
  const [devices, setDevices] = useState<DeviceKey[]>([])
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [actionBusyId, setActionBusyId] = useState<number | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)

  const loadDevices = useCallback(async (): Promise<void> => {
    setLoading(true)
    setLoadError(null)
    try {
      const raw: unknown = await fetchWrapper.get('/api/user/devices')
      setDevices(DeviceKeyListSchema.parse(raw))
    } catch (caught) {
      setLoadError(errorMessage(caught))
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void loadDevices()
  }, [loadDevices])

  async function revoke(device: DeviceKey): Promise<void> {
    setActionError(null)
    setActionBusyId(device.id)
    try {
      const raw: unknown = await fetchWrapper.delete(`/api/user/devices/${device.id}`, {})
      DeviceRevokeResponseSchema.parse(raw)
      await loadDevices()
    } catch (caught) {
      setActionError(errorMessage(caught))
    } finally {
      setActionBusyId(null)
    }
  }

  return (
    <div className="h-full overflow-y-auto p-6">
      <div className="mx-auto max-w-5xl">
        <div>
          <h1 className="text-2xl font-semibold text-foreground">Connected devices</h1>
          <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
            Devices paired through the Sinus Sentinel app&rsquo;s &ldquo;Sign in with PHR&rdquo; flow. Revoking a
            device immediately invalidates its API key.
          </p>
        </div>

        {actionError ? (
          <p role="alert" className="mt-4 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
            {actionError}
          </p>
        ) : null}
        {loadError ? (
          <div role="alert" className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
            <span>{loadError}</span>
            <Button type="button" variant="outline" size="sm" onClick={() => void loadDevices()}>Retry</Button>
          </div>
        ) : null}

        {loading ? <p role="status" aria-live="polite" className="mt-6 text-sm text-muted-foreground">Loading connected devices…</p> : null}

        {!loading && !loadError && devices.length === 0 ? (
          <div className="mt-6 rounded-lg border border-dashed border-border p-10 text-center">
            <Laptop className="mx-auto size-8 text-muted-foreground/60" />
            <p className="mt-3 text-sm text-muted-foreground">
              No devices are paired. Sign in from the Sinus Sentinel app to pair one.
            </p>
          </div>
        ) : null}

        <div className="mt-6 grid gap-4 lg:grid-cols-2">
          {devices.map((device) => {
            const status = deviceStatus(device)
            return (
              <article key={device.id} className="rounded-lg border border-border bg-card p-4 shadow-sm">
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <h2 className="truncate font-semibold text-card-foreground">{device.name}</h2>
                    <p className="mt-1 truncate font-mono text-xs text-muted-foreground">{device.device_id}</p>
                  </div>
                  <div className="flex flex-wrap justify-end gap-1 text-xs">{STATUS_BADGE[status]}</div>
                </div>
                <dl className="mt-4 grid grid-cols-[auto_1fr] gap-x-3 gap-y-2 text-sm">
                  <dt className="text-muted-foreground">Paired</dt>
                  <dd>{friendlyDate(device.created_at, 'Unknown')}</dd>
                  <dt className="text-muted-foreground">Last used</dt>
                  <dd>{friendlyDate(device.last_used_at, 'Never used')}</dd>
                  <dt className="text-muted-foreground">Expires</dt>
                  <dd>{friendlyDate(device.expires_at, 'No expiry')}</dd>
                </dl>

                {status === 'active' ? (
                  <div className="mt-4">
                    <AlertDialog>
                      <AlertDialogTrigger asChild>
                        <Button type="button" variant="destructive" size="sm" disabled={actionBusyId === device.id}>
                          <ShieldOff className="size-4" />
                          {actionBusyId === device.id ? 'Revoking…' : 'Revoke'}
                        </Button>
                      </AlertDialogTrigger>
                      <AlertDialogContent>
                        <AlertDialogHeader>
                          <AlertDialogTitle>Revoke {device.name}?</AlertDialogTitle>
                          <AlertDialogDescription>
                            This immediately invalidates its API key. The device will need to pair again from the
                            Sinus Sentinel app to reconnect.
                          </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                          <AlertDialogCancel>Cancel</AlertDialogCancel>
                          <AlertDialogAction
                            className={buttonVariants({ variant: 'destructive' })}
                            onClick={() => void revoke(device)}
                          >
                            Revoke
                          </AlertDialogAction>
                        </AlertDialogFooter>
                      </AlertDialogContent>
                    </AlertDialog>
                  </div>
                ) : null}
              </article>
            )
          })}
        </div>
      </div>
    </div>
  )
}
