import { type ReactElement, useState } from 'react'

import { DATE_FORMATS } from '@/lib/dateFormat'
import { type DateFormat, type DisplayPreferences, saveDisplayPreferences, useDisplayPreferences } from '@/lib/displayPreferences'
import { errorMessage } from '@/phr/shared'

/** Settings that follow the logged-in user across patients and change only what they see. */
export default function DisplaySettingsPage(): ReactElement {
  const preferences = useDisplayPreferences()
  const [error, setError] = useState<string | null>(null)

  function save(change: Partial<DisplayPreferences>): void {
    setError(null)
    saveDisplayPreferences(change).catch((caught: unknown) => setError(errorMessage(caught)))
  }

  return (
    <div className="flex max-w-2xl flex-col gap-4">
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold text-foreground">Display</h1>
        <p className="text-sm text-muted-foreground">These apply to you on every patient you can see. They change what you see, not what is stored.</p>
      </div>
      {error && <div role="alert" className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">{error}</div>}
      <section aria-label="Dates" className="flex flex-col gap-2 rounded-lg border border-border bg-card p-4">
        <h2 className="text-sm font-semibold text-card-foreground">Dates</h2>
        <label className="flex max-w-xs flex-col gap-1 text-sm text-foreground">
          Date format
          <select className="flex h-9 w-full rounded-md border border-input bg-transparent px-2 text-sm shadow-xs max-md:h-11" value={preferences.date_format} onChange={(event) => save({ date_format: event.target.value as DateFormat })}>
            {DATE_FORMATS.map((format) => <option key={format.value} value={format.value}>{format.value} ({format.example})</option>)}
          </select>
        </label>
        <p className="text-xs text-muted-foreground">Date fields in forms follow your browser&apos;s own format.</p>
      </section>
      <section aria-label="Medications" className="flex flex-col gap-1 rounded-lg border border-border bg-card p-4">
        <h2 className="text-sm font-semibold text-card-foreground">Medications</h2>
        <label className="flex min-h-11 items-start gap-2.5 py-1.5 text-sm text-foreground">
          <input type="checkbox" className="mt-0.5 size-4 shrink-0 accent-primary" checked={preferences.hide_medication_list} onChange={(event) => save({ hide_medication_list: event.target.checked })} />
          <span className="flex flex-col gap-0.5">
            Hide the Medications list
            <span className="text-xs text-muted-foreground">Show fills only, with no tabs. Medications stay in the record and still open from links to them.</span>
          </span>
        </label>
        <label className="flex min-h-11 items-start gap-2.5 py-1.5 text-sm text-foreground">
          <input type="checkbox" className="mt-0.5 size-4 shrink-0 accent-primary" checked={preferences.separate_columns} onChange={(event) => save({ separate_columns: event.target.checked })} />
          <span className="flex flex-col gap-0.5">
            Never merge columns
            <span className="text-xs text-muted-foreground">On a wide list, give a fill&apos;s strength, form, route, prescriber and pharmacy their own columns instead of sharing cells to save space. The list may scroll sideways.</span>
          </span>
        </label>
      </section>
    </div>
  )
}
