import { ChevronsUpDown, PanelLeftClose, PanelLeftOpen } from 'lucide-react'
import type { ReactElement } from 'react'

import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import { usePhrPatients } from '@/phr/patients/usePhrPatients'
import { type ReviewSummary, useReviewSummary } from '@/phr/review/reviewSummary'

import { PHR_NAV_GROUPS, PHR_PAGES, sidebarPageOf } from './phrPages'
import { patientPageUrl, PHR_PAGE_IDS, type PhrPageId } from './phrRoutes'

export function patientDisplayName(name: string | null | undefined, patientId: number): string {
  return name?.trim() ? name : `Patient ${patientId}`
}

function initials(name: string): string {
  const letters = name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]!.toUpperCase())
  return letters.join('') || '?'
}

interface PhrSidebarProps {
  patientId: number
  page: PhrPageId
  /** Icon-only rail. Labels move to each item's accessible name and a tooltip. */
  rail: boolean
  /** Omitted inside the small-screen sheet, which has its own close control. */
  onRailChange?: (rail: boolean) => void
  className?: string
}

export function PhrSidebar({ patientId, page, rail, onRailChange, className }: PhrSidebarProps): ReactElement {
  const summary = useReviewSummary(patientId)?.summary ?? null
  return (
    <aside
      aria-label="Patient record"
      data-rail={rail ? 'true' : 'false'}
      className={cn(
        'flex shrink-0 flex-col gap-3 overflow-y-auto border-r border-border bg-card p-3',
        rail ? 'w-16 items-center' : 'w-62',
        className,
      )}
    >
      {onRailChange && (
        <RailToggle rail={rail} onRailChange={onRailChange} />
      )}
      <PatientSwitcher patientId={patientId} page={page} rail={rail} />
      <nav aria-label="Record sections" className="flex w-full flex-col gap-3">
        {PHR_NAV_GROUPS.map((pages) => (
          <div key={pages[0]} className="flex flex-col gap-0.5">
            {pages.map((id) => (
              <SidebarItem key={id} patientId={patientId} page={id} current={id === sidebarPageOf(page)} rail={rail} summary={summary} />
            ))}
          </div>
        ))}
      </nav>
    </aside>
  )
}

function RailToggle({ rail, onRailChange }: { rail: boolean, onRailChange: (rail: boolean) => void }): ReactElement {
  const label = rail ? 'Expand sidebar' : 'Collapse sidebar'
  const Icon = rail ? PanelLeftOpen : PanelLeftClose
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <button
          type="button"
          aria-label={label}
          aria-expanded={!rail}
          onClick={() => onRailChange(!rail)}
          className={cn(
            'flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
            !rail && 'self-end',
          )}
        >
          <Icon className="size-4" aria-hidden="true" />
        </button>
      </TooltipTrigger>
      <TooltipContent side="right">{label}</TooltipContent>
    </Tooltip>
  )
}

/**
 * What the item shows beside its label: Review, how many items wait in all; a section, how
 * many records it holds and a dot when some of them wait for review. Both come from the one
 * summary request the sidebar shares. A view inside the section adds to the dot, not the count:
 * Medications counts fills, its default view, and its dot also covers medications waiting for review.
 */
export function sidebarAmounts(page: PhrPageId, summary: ReviewSummary | null): { count: number | null, pending: number } {
  if (summary === null) return { count: null, pending: 0 }
  if (page === 'review') return { count: summary.total > 0 ? summary.total : null, pending: 0 }
  const section = summary.sections[page]
  const pending = PHR_PAGE_IDS
    .filter((id) => id === page || PHR_PAGES[id].sectionPage === page)
    .reduce((sum, id) => sum + (summary.sections[id]?.pending ?? 0), 0)
  return { count: section && section.records > 0 ? section.records : null, pending }
}

function SidebarItem({ patientId, page, current, rail, summary }: { patientId: number, page: PhrPageId, current: boolean, rail: boolean, summary: ReviewSummary | null }): ReactElement {
  const meta = PHR_PAGES[page]
  const Icon = meta.icon
  const { count, pending } = sidebarAmounts(page, summary)
  const described = page === 'review'
    ? (count !== null ? `${count} waiting for review` : '')
    : [count !== null ? `${count} ${count === 1 ? 'record' : 'records'}` : null, pending > 0 ? `${pending} waiting for review` : null].filter(Boolean).join(', ')
  const label = meta.navLabel ?? meta.label
  const name = described === '' ? label : `${label}, ${described}`
  const link = (
    <a
      href={patientPageUrl(patientId, page)}
      aria-current={current ? 'page' : undefined}
      // The visible label first, then what the count and dot say (they are hidden from
      // assistive technology), so the name still matches what is on screen.
      {...(rail || described !== '' ? { 'aria-label': name } : {})}
      className={cn(
        'relative flex min-h-9 items-center gap-2.5 rounded-md px-2.5 text-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
        rail && 'w-10 justify-center px-0',
        current
          ? 'bg-accent font-semibold text-accent-foreground'
          : 'text-foreground hover:bg-muted',
      )}
    >
      <Icon className="size-4 shrink-0" aria-hidden="true" />
      {rail && (page === 'review' ? count !== null : pending > 0) && <span aria-hidden="true" data-pending-dot="" className="absolute top-1.5 right-1.5 size-1.5 rounded-full bg-warning" />}
      {!rail && (
        <>
          <span className="flex-1 truncate">{label}</span>
          {pending > 0 && <span aria-hidden="true" data-pending-dot="" className="size-[7px] shrink-0 rounded-full bg-warning" />}
          {count !== null && (
            <span aria-hidden="true" className={cn('text-xs font-medium tabular-nums', current ? 'text-accent-foreground' : 'text-muted-foreground')}>{count}</span>
          )}
        </>
      )}
    </a>
  )
  if (!rail) return link
  return (
    <Tooltip>
      <TooltipTrigger asChild>{link}</TooltipTrigger>
      <TooltipContent side="right">{name}</TooltipContent>
    </Tooltip>
  )
}

function PatientSwitcher({ patientId, page, rail }: { patientId: number, page: PhrPageId, rail: boolean }): ReactElement {
  const { patients } = usePhrPatients()
  const name = patientDisplayName(patients.find((patient) => patient.id === patientId)?.display_name, patientId)
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <button
          type="button"
          aria-label={`Switch patient: ${name}`}
          className={cn(
            'flex min-h-12 w-full items-center gap-2.5 rounded-lg border border-border bg-background px-2 py-1.5 text-left text-foreground transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
            rail && 'w-10 justify-center border-0 bg-transparent px-0',
          )}
        >
          <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-accent text-xs font-bold text-accent-foreground" aria-hidden="true">
            {initials(name)}
          </span>
          {!rail && (
            <>
              <span className="flex min-w-0 flex-1 flex-col">
                <span className="truncate text-sm font-semibold">{name}</span>
                <span className="text-xs text-muted-foreground">Switch patient</span>
              </span>
              <ChevronsUpDown className="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
            </>
          )}
        </button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start" side={rail ? 'right' : 'bottom'} className="w-64">
        <DropdownMenuGroup>
          <DropdownMenuLabel>Patients</DropdownMenuLabel>
          {patients.map((patient) => (
          <DropdownMenuItem key={patient.id} asChild>
            {/* Switching keeps the page you are on, without the previous patient's record. */}
            <a
              href={patientPageUrl(patient.id, page)}
              aria-current={patient.id === patientId ? 'true' : undefined}
              className={cn(patient.id === patientId && 'font-semibold')}
            >
              {patientDisplayName(patient.display_name, patient.id)}
            </a>
          </DropdownMenuItem>
          ))}
        </DropdownMenuGroup>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
