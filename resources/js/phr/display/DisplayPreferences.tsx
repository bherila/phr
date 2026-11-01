import { createContext, type ReactElement, type ReactNode, useCallback, useContext, useEffect, useMemo, useState } from 'react'

import { Switch } from '@/components/ui/switch'
import { fetchWrapper } from '@/fetchWrapper'
import { forceLowercaseDisplay } from '@/lib/appShell'
import { DisplayPreferencesSchema } from '@/phr/medication-fills/fillTypes'
import { errorMessage } from '@/phr/shared'

/**
 * Per-user display preferences (an accessibility setting). They change how stored data is
 * rendered, never the data. Without a provider (a page rendered on its own) every preference
 * is off.
 */
const ENDPOINT = '/api/user/display-preferences'

interface DisplayPreferencesValue {
  forceLowercase: boolean
  setForceLowercase: (value: boolean) => Promise<void>
}

const DisplayPreferencesContext = createContext<DisplayPreferencesValue>({
  forceLowercase: false,
  setForceLowercase: async () => {},
})

export function DisplayPreferencesProvider({ children }: { children: ReactNode }): ReactElement {
  // The page carries the saved value, so there is no flash; the GET is the fallback.
  const [seeded] = useState(forceLowercaseDisplay)
  const [forceLowercase, setForce] = useState(seeded ?? false)

  useEffect(() => {
    if (seeded !== null) return
    let cancelled = false
    fetchWrapper.get(ENDPOINT).then((raw: unknown) => {
      if (!cancelled) setForce(DisplayPreferencesSchema.parse(raw).force_lowercase)
    }).catch(() => {
      // The preference is a convenience: without it, text renders as stored.
    })
    return () => {
      cancelled = true
    }
  }, [seeded])

  const setForceLowercase = useCallback(async (value: boolean): Promise<void> => {
    const raw: unknown = await fetchWrapper.put(ENDPOINT, { force_lowercase: value })
    setForce(DisplayPreferencesSchema.parse(raw).force_lowercase)
  }, [])

  const value = useMemo(() => ({ forceLowercase, setForceLowercase }), [forceLowercase, setForceLowercase])

  return <DisplayPreferencesContext.Provider value={value}>{children}</DisplayPreferencesContext.Provider>
}

export function useDisplayPreferences(): DisplayPreferencesValue {
  return useContext(DisplayPreferencesContext)
}

/** The CSS class that renders text in small case, or nothing. Display only. */
export function useLowercaseClass(): string {
  return useDisplayPreferences().forceLowercase ? 'lowercase' : ''
}

export default function DisplayPreferencesPanel(): ReactElement {
  const { forceLowercase, setForceLowercase } = useDisplayPreferences()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function toggle(next: boolean): Promise<void> {
    setBusy(true)
    setError(null)
    try {
      await setForceLowercase(next)
    } catch (caught) {
      setError(errorMessage(caught))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="h-full overflow-y-auto p-6">
      <div className="mx-auto max-w-3xl">
        <h1 className="text-2xl font-semibold text-foreground">Display</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Accessibility settings for how records are shown to you. They never change stored data.
        </p>
        {error && (
          <div role="alert" className="mt-4 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
            {error}
          </div>
        )}
        <div className="mt-4 flex items-start gap-3 rounded-md border border-border p-3">
          <Switch
            id="force-lowercase"
            aria-labelledby="force-lowercase-label"
            checked={forceLowercase}
            disabled={busy}
            onCheckedChange={(next: boolean) => void toggle(next)}
          />
          <div>
            <label id="force-lowercase-label" htmlFor="force-lowercase" className="block font-medium text-foreground">
              Force lowercase display
            </label>
            <p className="text-sm text-muted-foreground">
              Show prescription fill drug names, pharmacies, prescribers and directions in small case, including units such as mL (shown as ml).
            </p>
          </div>
        </div>
      </div>
    </div>
  )
}
