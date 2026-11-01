import { CalendarDays, SlidersHorizontal } from 'lucide-react'
import { type ReactElement, useState } from 'react'

import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { ShowRejectedToggle } from '@/phr/clinical/review'

import { formatFillDate } from './fillTypes'

const SELECT_CLASS = 'flex h-8 min-w-40 max-w-60 rounded-md border border-input bg-transparent px-2 py-1 text-sm shadow-xs'

export interface FillFilterValues {
  from: string
  to: string
  medicationId: string
}

export const NO_FILL_FILTERS: FillFilterValues = { from: '', to: '', medicationId: '' }

interface FillFiltersProps {
  values: FillFilterValues
  onChange: (values: FillFilterValues) => void
  /** [id, name], for the medication select. */
  medications: ReadonlyArray<readonly [number, string]>
  shownCount: number
  showRejected: boolean
  onShowRejectedChange: (showRejected: boolean) => void
  busy: boolean
}

function dateRangeLabel({ from, to }: FillFilterValues): string {
  if (from && to) return `${formatFillDate(from)} to ${formatFillDate(to)}`
  if (from) return `From ${formatFillDate(from)}`
  if (to) return `To ${formatFillDate(to)}`
  return 'Any date'
}

function DateFields({ values, onChange, inputClassName }: Pick<FillFiltersProps, 'values' | 'onChange'> & { inputClassName?: string }): ReactElement {
  return (
    <div className="grid grid-cols-2 gap-3">
      <label className="grid gap-1 text-sm font-medium text-foreground">
        From
        <Input type="date" aria-label="Filled from" className={inputClassName} value={values.from} onChange={(event) => onChange({ ...values, from: event.target.value })} />
      </label>
      <label className="grid gap-1 text-sm font-medium text-foreground">
        To
        <Input type="date" aria-label="Filled to" className={inputClassName} value={values.to} onChange={(event) => onChange({ ...values, to: event.target.value })} />
      </label>
    </div>
  )
}

/**
 * One row of filters while the page has the width. On a narrow page they would take a quarter of
 * the screen, so they collapse to a button that opens them in a sheet, with what is applied beside it.
 */
export function FillFilters({ values, onChange, medications, shownCount, showRejected, onShowRejectedChange, busy }: FillFiltersProps): ReactElement {
  const [sheetOpen, setSheetOpen] = useState(false)
  const filtered = values.from !== '' || values.to !== '' || values.medicationId !== ''
  const medicationName = medications.find(([id]) => String(id) === values.medicationId)?.[1] ?? 'All medications'
  const clear = (): void => onChange(NO_FILL_FILTERS)
  const medicationOptions = (
    <>
      <option value="">All medications</option>
      {medications.map(([id, name]) => <option key={id} value={String(id)}>{name}</option>)}
    </>
  )

  return (
    <>
      <div role="group" aria-label="Filter fills" className="hidden flex-wrap items-center gap-2 @[560px]/fills-page:flex">
        <select aria-label="Medication filter" value={values.medicationId} onChange={(event) => onChange({ ...values, medicationId: event.target.value })} className={SELECT_CLASS}>
          {medicationOptions}
        </select>
        <Popover>
          <PopoverTrigger render={<Button type="button" size="sm" variant="outline" className="font-normal" />}>
            <CalendarDays className="size-4" aria-hidden="true" />
            {dateRangeLabel(values)}
          </PopoverTrigger>
          <PopoverContent className="w-80">
            <DateFields values={values} onChange={onChange} />
          </PopoverContent>
        </Popover>
        <ShowRejectedToggle showRejected={showRejected} onChange={onShowRejectedChange} disabled={busy} />
        {filtered && <Button type="button" size="sm" variant="ghost" onClick={clear}>Clear filters</Button>}
      </div>

      <div className="flex items-center gap-2 @[560px]/fills-page:hidden">
        <Button type="button" variant="outline" className="h-11" aria-haspopup="dialog" onClick={() => setSheetOpen(true)}>
          <SlidersHorizontal className="size-4" aria-hidden="true" />
          Filter
        </Button>
        <span className="min-w-0 text-sm text-muted-foreground">{medicationName}, {dateRangeLabel(values).toLowerCase()}</span>
      </div>

      {sheetOpen && (
        <Dialog open onOpenChange={(open) => { if (!open) setSheetOpen(false) }}>
          <DialogContent className="top-auto right-0 bottom-0 left-0 max-w-full translate-x-0 translate-y-0 gap-4 rounded-t-xl rounded-b-none sm:max-w-full">
            <DialogHeader>
              <DialogTitle>Filter fills</DialogTitle>
            </DialogHeader>
            <label className="grid gap-1 text-sm font-medium text-foreground">
              Medication
              <select aria-label="Medication filter" value={values.medicationId} onChange={(event) => onChange({ ...values, medicationId: event.target.value })} className="flex h-11 w-full rounded-md border border-input bg-transparent px-2 text-base shadow-xs">
                {medicationOptions}
              </select>
            </label>
            <DateFields values={values} onChange={onChange} inputClassName="h-11 text-base" />
            <label className="flex min-h-11 items-center gap-2.5 text-sm text-foreground">
              <input type="checkbox" className="size-5 accent-primary" checked={showRejected} disabled={busy} onChange={(event) => onShowRejectedChange(event.target.checked)} />
              Show rejected fills
            </label>
            <div className="flex gap-2">
              <Button type="button" variant="outline" className="h-11 flex-1" disabled={!filtered} onClick={clear}>Clear</Button>
              <Button type="button" className="h-11 flex-2" onClick={() => setSheetOpen(false)}>Show {shownCount} {shownCount === 1 ? 'fill' : 'fills'}</Button>
            </div>
          </DialogContent>
        </Dialog>
      )}
    </>
  )
}
