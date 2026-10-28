import type { ReactElement } from 'react'

import type { ImportResult } from './ehi/ehiApi'

const count = new Intl.NumberFormat('en-US')

/**
 * What a direct import wrote: records added or updated (each marked for review on its
 * own page), those already in PHR as the file has them, and those skipped, with why.
 */
export function ImportSummary({ result }: { result: ImportResult }): ReactElement {
  const rows = [
    { label: 'Added as new records', note: 'Marked for your review on their pages', value: result.created },
    { label: 'Updated', note: 'Changed records are marked for review again', value: result.updated },
    { label: 'Already in PHR', note: 'Nothing changed; your earlier review stands', value: result.unchanged },
    { label: 'Skipped', note: 'Could not be read as a record', value: result.skipped },
  ]
  const total = rows.reduce((sum, row) => sum + row.value, 0)
  const omitted = result.warnings_omitted ?? 0

  return (
    <div className="flex flex-col gap-3">
      <dl className="grid grid-cols-[minmax(0,1fr)_auto] gap-x-4 text-sm">
        {rows.map((row) => (
          <div key={row.label} className="contents">
            <dt className="border-t border-border py-2"><span className="text-foreground">{row.label}</span><span className="block text-xs text-muted-foreground">{row.note}</span></dt>
            <dd className="border-t border-border py-2 text-right tabular-nums">{count.format(row.value)}</dd>
          </div>
        ))}
        <div className="contents font-semibold">
          <dt className="border-t-2 border-border py-2">Entries read</dt>
          <dd className="border-t-2 border-border py-2 text-right tabular-nums">{count.format(total)}</dd>
        </div>
      </dl>
      {result.warnings.length > 0 && (
        <details className="text-sm">
          <summary className="cursor-pointer text-muted-foreground">Why {result.warnings.length + omitted === 1 ? 'one entry was' : `${count.format(result.warnings.length + omitted)} entries were`} skipped</summary>
          <ul className="mt-2 list-disc space-y-1 pl-5 text-muted-foreground">
            {/* Warnings are unique per skipped file, so the text is a stable key. */}
            {[...new Set(result.warnings)].map((warning) => <li key={warning}>{warning}</li>)}
            {omitted > 0 && <li>and {count.format(omitted)} more</li>}
          </ul>
        </details>
      )}
      <p className="text-sm text-muted-foreground">New and updated records are marked for review on their own pages, such as Immunizations.</p>
    </div>
  )
}
