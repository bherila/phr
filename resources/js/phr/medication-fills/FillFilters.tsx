import { Search, SlidersHorizontal, X } from 'lucide-react'
import { type ReactElement, useState } from 'react'

import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import type { FillColumn } from '@/lib/displayPreferences'

export interface FillFilterValues {
  query: string
  from: string
  to: string
  medicationId: string
}

export const NO_FILL_FILTERS: FillFilterValues = { query: '', from: '', to: '', medicationId: '' }

const COLUMN_CHOICES: ReadonlyArray<{ column: FillColumn, label: string }> = [
  { column: 'no_insurance', label: 'No insurance' },
  { column: 'prescriber', label: 'Prescriber' },
  { column: 'pharmacy', label: 'Pharmacy' },
]

interface FillFiltersProps {
  values: FillFilterValues
  onChange: (values: FillFilterValues) => void
  /** The name of the medication a link narrowed the list to, when a loaded fill still names it. */
  medicationName: string | null
  shownCount: number
  hiddenColumns: readonly FillColumn[]
  onColumnChange: (column: FillColumn, shown: boolean) => void
}

function ColumnChoice({ column, label, hiddenColumns, onColumnChange, className }: Pick<FillFiltersProps, 'hiddenColumns' | 'onColumnChange'> & { column: FillColumn, label: string, className: string }): ReactElement {
  return (
    <label className={className}>
      <input type="checkbox" className="size-4 accent-primary" checked={!hiddenColumns.includes(column)} onChange={(event) => onColumnChange(column, event.target.checked)} />
      {label}
    </label>
  )
}

/**
 * Search, dates and which columns show, all in one row while the page has the width. On a phone
 * the search stays and the rest move to a sheet. The columns only choose from what the list has
 * room for, so their choices appear at the width that shows them; a phone's rows show the pharmacy
 * alone, and the fill's own page has the rest.
 */
export function FillFilters({ values, onChange, medicationName, shownCount, hiddenColumns, onColumnChange }: FillFiltersProps): ReactElement {
  const [sheetOpen, setSheetOpen] = useState(false)
  const datesSet = values.from !== '' || values.to !== ''

  return (
    <>
      <div role="group" aria-label="Filter fills" className="flex flex-wrap items-center gap-x-3 gap-y-2">
        <div className="relative min-w-40 flex-1 @[560px]/fills-page:max-w-60 @[560px]/fills-page:flex-none @[560px]/fills-page:basis-60">
          <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
          <Input type="search" aria-label="Search by name" placeholder="Search by name" className="h-11 pl-8 text-base @[560px]/fills-page:h-8 @[560px]/fills-page:text-sm" value={values.query} onChange={(event) => onChange({ ...values, query: event.target.value })} />
        </div>

        <Button type="button" variant="outline" className="h-11 @[560px]/fills-page:hidden" aria-haspopup="dialog" onClick={() => setSheetOpen(true)}>
          <SlidersHorizontal className="size-4" aria-hidden="true" />
          Filter
        </Button>

        <div className="hidden items-center gap-3 @[560px]/fills-page:flex">
          <label className="flex items-center gap-1.5 text-sm text-muted-foreground">
            From
            <Input type="date" aria-label="Filled from" className="h-8 w-auto text-foreground" value={values.from} onChange={(event) => onChange({ ...values, from: event.target.value })} />
          </label>
          <label className="flex items-center gap-1.5 text-sm text-muted-foreground">
            To
            <Input type="date" aria-label="Filled to" className="h-8 w-auto text-foreground" value={values.to} onChange={(event) => onChange({ ...values, to: event.target.value })} />
          </label>
          {datesSet && <Button type="button" size="sm" variant="ghost" onClick={() => onChange({ ...values, from: '', to: '' })}>Clear dates</Button>}
        </div>

        {/* Shown for any narrowed list: its last fill may be gone, and with it the name. */}
        {values.medicationId !== '' && (
          <Button type="button" size="sm" variant="secondary" className="max-w-full max-md:h-11" aria-label={`Show all fills, not only ${medicationName ?? 'this medication'}`} onClick={() => onChange({ ...values, medicationId: '' })}>
            <span className="truncate">Only {medicationName ?? 'this medication'}</span>
            <X className="size-3.5" aria-hidden="true" />
          </Button>
        )}

        <div role="group" aria-label="Columns to show" className="ml-auto hidden flex-wrap items-center gap-x-3 gap-y-1 text-sm @[960px]/fills-page:flex">
          <span className="text-muted-foreground">Show</span>
          {COLUMN_CHOICES.map((choice) => (
            <ColumnChoice key={choice.column} {...choice} hiddenColumns={hiddenColumns} onColumnChange={onColumnChange} className="flex items-center gap-1.5 whitespace-nowrap text-foreground" />
          ))}
        </div>
      </div>

      {sheetOpen && (
        <Dialog open onOpenChange={(open) => { if (!open) setSheetOpen(false) }}>
          <DialogContent className="top-auto right-0 bottom-0 left-0 max-w-full translate-x-0 translate-y-0 gap-4 rounded-t-xl rounded-b-none sm:max-w-full">
            <DialogHeader>
              <DialogTitle>Filter fills</DialogTitle>
            </DialogHeader>
            <div className="grid grid-cols-2 gap-3">
              <label className="grid gap-1 text-sm font-medium text-foreground">
                From
                <Input type="date" aria-label="Filled from" className="h-11 text-base" value={values.from} onChange={(event) => onChange({ ...values, from: event.target.value })} />
              </label>
              <label className="grid gap-1 text-sm font-medium text-foreground">
                To
                <Input type="date" aria-label="Filled to" className="h-11 text-base" value={values.to} onChange={(event) => onChange({ ...values, to: event.target.value })} />
              </label>
            </div>
            <ColumnChoice column="pharmacy" label="Show the pharmacy on each row" hiddenColumns={hiddenColumns} onColumnChange={onColumnChange} className="flex min-h-11 items-center gap-2.5 text-sm text-foreground" />
            <div className="flex gap-2">
              <Button type="button" variant="outline" className="h-11 flex-1" disabled={!datesSet} onClick={() => onChange({ ...values, from: '', to: '' })}>Clear dates</Button>
              <Button type="button" className="h-11 flex-2" onClick={() => setSheetOpen(false)}>Show {shownCount} {shownCount === 1 ? 'fill' : 'fills'}</Button>
            </div>
          </DialogContent>
        </Dialog>
      )}
    </>
  )
}
