import { ArrowLeft, X } from 'lucide-react'
import { type ReactElement, type ReactNode, Suspense, useEffect } from 'react'

import { cn } from '@/lib/utils'

import { pushPhrLocation } from './phrLocation'
import { navGroupOf, PHR_DETAILS, PHR_PAGES } from './phrPages'
import { detailPresentation, type PhrNavTarget, type PhrView, phrViewUrl, resolveNavTarget } from './phrRoutes'
import { navigateToView } from './usePhrView'

type PatientView = Extract<PhrView, { kind: 'patient' }>

const LOADING = <div role="status" aria-live="polite" className="p-6 text-sm text-muted-foreground">Loading…</div>

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
  const page = PHR_PAGES[view.page]
  const crumbs: Crumb[] = [{ label: patientName, href: phrViewUrl({ kind: 'patient', patientId: view.patientId, page: 'summary' }) }]
  if (view.page !== 'summary') {
    crumbs.push({ label: navGroupOf(view.page) }, { label: page.label, href: phrViewUrl(listView(view)) })
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

  const fromList = (target: PhrNavTarget): void => navigateToView(resolveNavTarget(view, target, 'list'))
  const fromDetail = (target: PhrNavTarget): void => navigateToView(resolveNavTarget(view, target, 'detail'))

  useEscapeToClose(presentation === 'panel' ? closeUrl : null)

  if (view.detail && presentation === 'page') {
    const detail = PHR_DETAILS[view.detail.module]
    const Detail = detail.component
    return (
      <div className="h-full overflow-y-auto">
        <div className="flex flex-col gap-4 px-4 py-6 md:px-8">
          <Breadcrumb crumbs={[...crumbs, { label: detail.label }]} />
          <Suspense fallback={LOADING}>
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
        <div className="flex flex-col gap-4 px-4 py-6 md:px-8">
          <Breadcrumb crumbs={crumbs} />
          <Suspense fallback={LOADING}>
            <ListPage key={`${view.patientId}-${view.page}`} patientId={view.patientId} onDrill={fromList} />
          </Suspense>
        </div>
      </div>
      {view.detail && (
        <DetailPanel view={view} closeUrl={closeUrl} crumbs={crumbs}>
          <Suspense fallback={LOADING}>
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
      className="flex w-full min-w-0 flex-col overflow-y-auto border-border bg-card lg:w-[380px] lg:shrink-0 lg:border-l xl:w-[420px]"
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
            className="ml-auto flex size-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            <X className="size-4" aria-hidden="true" />
          </a>
        </div>
      </div>
      <div className="p-4">{children}</div>
    </aside>
  )
}

function isEditableTarget(target: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) return false
  return target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)
}

/** Escape closes the detail panel, unless focus is in a field or a dialog is open. */
function useEscapeToClose(closeUrl: string | null): void {
  useEffect(() => {
    if (closeUrl === null) return
    const handler = (event: KeyboardEvent): void => {
      if (event.key !== 'Escape' || event.defaultPrevented || isEditableTarget(event.target)) return
      if (document.querySelector('[role="dialog"][data-open], [role="alertdialog"][data-open]')) return
      event.preventDefault()
      pushPhrLocation(closeUrl)
    }
    window.addEventListener('keydown', handler)
    return () => window.removeEventListener('keydown', handler)
  }, [closeUrl])
}

