import { ArrowLeft, X } from 'lucide-react'
import { type ReactElement, type ReactNode, Suspense } from 'react'

import { DetailSkeleton, PageSkeleton } from '@/components/record-list/Skeleton'
import { cn } from '@/lib/utils'

import { replacePhrLocation } from './phrLocation'
import { PHR_DETAILS, PHR_PAGES, type PhrDrillOptions, sidebarPageOf } from './phrPages'
import { detailPresentation, type PhrNavTarget, type PhrView, phrViewUrl, resolveNavTarget } from './phrRoutes'
import { navigateToView } from './usePhrView'

type PatientView = Extract<PhrView, { kind: 'patient' }>

/** List pages stop growing here, aligned to the sidebar; full-width viewers are exempt. */
export const PAGE_CONTENT_MAX_WIDTH = 'max-w-[80rem]'

/**
 * The detail panel scales with the window, so its tables have room on wide screens; the list
 * beside it gives up width first.
 */
export const DETAIL_PANEL_WIDTH = 'lg:w-[clamp(400px,32vw,640px)]'

/** The page's own header shape while its code loads, so the real header lands in place. */
function pageFallback(label: string): ReactElement {
  const title = (
    <h1 className="flex items-center gap-2 text-2xl font-semibold text-foreground">
      <span aria-hidden="true" className="size-6 rounded bg-muted" />
      {label}
    </h1>
  )
  return <PageSkeleton title={title} label={`Loading ${label.toLowerCase()}`} />
}

const DETAIL_LOADING = <DetailSkeleton />

interface Crumb {
  label: string
  href?: string
}

export function Breadcrumb({ crumbs, className }: { crumbs: Crumb[], className?: string }): ReactElement {
  return (
    <nav aria-label="Breadcrumb" className={cn('text-xs text-muted-foreground', className)}>
      <ol className="flex flex-wrap items-center gap-1">
        {crumbs.map((crumb, index) => {
          const last = index === crumbs.length - 1
          return (
            <li key={`${crumb.label}|${crumb.href ?? ""}`} className="flex items-center gap-1">
              {index > 0 && <span aria-hidden="true">/</span>}
              {last ? (
                <span aria-current="page" className="text-foreground">{crumb.label}</span>
              ) : crumb.href ? (
                <a href={crumb.href} className="rounded-sm hover:text-foreground hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">{crumb.label}</a>
              ) : (
                <span>{crumb.label}</span>
              )}
            </li>
          )
        })}
      </ol>
    </nav>
  )
}

function listView(view: PatientView): PatientView {
  return { kind: 'patient', patientId: view.patientId, page: view.page }
}

function pageCrumbs(view: PatientView, patientName: string): Crumb[] {
  const crumbs: Crumb[] = [{ label: patientName, href: phrViewUrl({ kind: 'patient', patientId: view.patientId, page: 'summary' }) }]
  if (view.page === 'summary') return crumbs
  const home = sidebarPageOf(view.page)
  const meta = PHR_PAGES[home]
  crumbs.push({ label: meta.navLabel ?? meta.label, href: phrViewUrl({ kind: 'patient', patientId: view.patientId, page: home }) })
  if (home !== view.page) {
    crumbs.push({ label: PHR_PAGES[view.page].crumbLabel ?? PHR_PAGES[view.page].label, href: phrViewUrl(listView(view)) })
  }
  return crumbs
}

interface PhrPatientViewProps {
  view: PatientView
  patientName: string
}

/**
 * A patient page: its breadcrumb and list at full width, and, when a record is selected, that
 * record's detail in a panel on the right. Below `lg` the detail takes the page instead. Document
 * and imaging viewers (and a vitals trend) open as their own full-width page.
 */
export function PhrPatientView({ view, patientName }: PhrPatientViewProps): ReactElement {
  const presentation = detailPresentation(view)
  const page = PHR_PAGES[view.page]
  const ListPage = page.component
  const crumbs = pageCrumbs(view, patientName)
  const closeUrl = phrViewUrl(listView(view))

  const fromList = (target: PhrNavTarget, options?: PhrDrillOptions): void => {
    const next = resolveNavTarget(view, target, 'list')
    if (options?.replace) replacePhrLocation(phrViewUrl(next))
    else navigateToView(next)
  }
  const fromDetail = (target: PhrNavTarget): void => navigateToView(resolveNavTarget(view, target, 'detail'))

  if (view.detail && presentation === 'page') {
    const detail = PHR_DETAILS[view.detail.module]
    const Detail = detail.component
    return (
      <div className="h-full overflow-y-auto">
        <div className="flex flex-col gap-4 px-4 py-6 md:px-8">
          <Breadcrumb crumbs={[...crumbs, { label: detail.label }]} />
          <Suspense fallback={DETAIL_LOADING}>
            <Detail key={phrViewUrl(view)} patientId={view.patientId} recordId={view.detail.id} onDrill={fromDetail} />
          </Suspense>
        </div>
      </div>
    )
  }

  return (
    <div className="flex h-full min-h-0">
      <div
        className={cn('min-w-0 flex-1 overflow-y-auto', view.detail && 'hidden lg:block')}
        data-pane="list"
      >
        <div className={cn('flex flex-col gap-4 px-4 py-6 md:px-8', PAGE_CONTENT_MAX_WIDTH)} data-testid="page-content">
          {/* On a phone the bar names the patient and the page's title follows; a record's page keeps its trail. */}
          <Breadcrumb crumbs={crumbs} className="max-md:hidden" />
          <Suspense fallback={pageFallback(PHR_PAGES[sidebarPageOf(view.page)].navLabel ?? page.label)}>
            <ListPage key={`${view.patientId}-${view.page}`} patientId={view.patientId} onDrill={fromList} openRecord={view.parent ?? view.detail} />
          </Suspense>
        </div>
      </div>
      {view.detail && (
        <DetailPanel view={view} closeUrl={closeUrl} crumbs={crumbs}>
          <Suspense fallback={DETAIL_LOADING}>
            {(() => {
              const Detail = PHR_DETAILS[view.detail.module].component
              return <Detail key={phrViewUrl(view)} patientId={view.patientId} recordId={view.detail.id} onDrill={fromDetail} />
            })()}
          </Suspense>
        </DetailPanel>
      )}
    </div>
  )
}

function DetailPanel({ view, closeUrl, crumbs, children }: { view: PatientView, closeUrl: string, crumbs: Crumb[], children: ReactNode }): ReactElement {
  const detail = PHR_DETAILS[view.detail!.module]
  const parent = view.parent ? PHR_DETAILS[view.parent.module] : null
  const parentUrl = view.parent ? phrViewUrl({ ...listView(view), detail: view.parent }) : null
  return (
    <aside
      aria-label={`${detail.label} detail`}
      data-pane="detail"
      className={cn('@container flex w-full min-w-0 flex-col overflow-y-auto border-border bg-card lg:shrink-0 lg:border-l', DETAIL_PANEL_WIDTH)}
    >
      <div className="flex flex-col gap-2 border-b border-border px-4 py-3">
        <Breadcrumb crumbs={[...crumbs, { label: detail.label }]} className="lg:hidden" />
        <div className="flex items-center gap-2">
          {parentUrl && parent ? (
            <a href={parentUrl} className="flex items-center gap-1 rounded-sm text-sm font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
              <ArrowLeft className="size-4" aria-hidden="true" />
              {parent.label}
            </a>
          ) : (
            <span className="text-xs font-medium text-muted-foreground">{detail.label}</span>
          )}
          <a
            href={closeUrl}
            aria-label="Close detail"
            className="ml-auto flex size-8 items-center justify-center rounded-md text-muted-foreground max-lg:size-11 transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            <X className="size-4" aria-hidden="true" />
          </a>
        </div>
      </div>
      <div className="flex flex-1 flex-col p-4">{children}</div>
    </aside>
  )
}
