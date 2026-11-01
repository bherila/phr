import { Menu } from 'lucide-react'
import { type ReactElement, Suspense, useState } from 'react'

import PhrNavbar, { PhrSectionLinks, sectionLabel } from '@/components/phr/PhrNavbar'
import { PageSkeleton } from '@/components/record-list/Skeleton'
import { ShortcutsDialog } from '@/components/ShortcutsDialog'
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog'
import { cn } from '@/lib/utils'
import { EhiUploadProvider } from '@/phr/imports/ehi/EhiUploadProvider'
import { PhrPatientsProvider, usePhrPatients } from '@/phr/patients/usePhrPatients'
import { DicomUploadProvider, DicomUploadTray } from '@/phr/uploads'

import { PhrJumpPalette } from './PhrJumpPalette'
import { pushPhrLocation } from './phrLocation'
import { ConfigPage, DataHubPage, ImportsPage, PatientsManagePage, PatientsPage } from './phrPages'
import { PhrPatientSearchPalette } from './PhrPatientSearchPalette'
import { PAGE_CONTENT_MAX_WIDTH, PhrPatientView } from './PhrPatientView'
import { detailPresentation, patientPageUrl, patientPath, type PhrView, phrViewUrl, resolveNavTarget } from './phrRoutes'
import { patientDisplayName, PhrSidebar } from './PhrSidebar'
import { usePhrShortcuts } from './usePhrShortcuts'
import { navigateToView, useLegacyHashRedirect, usePhrView, useShellLinkInterception } from './usePhrView'
import { useSidebarMode } from './useSidebarMode'

const LOADING = <PageSkeleton />

const NO_VIEW: PhrView = { kind: 'section', section: 'patients', search: '' }

/**
 * The PHR application shell: a top bar, then the patient sidebar (inside a patient's record),
 * the page, and an optional detail panel. Mounted once per page load; what it shows is derived
 * from the location on every change, so Back and Forward restore patient, page and detail.
 */
export function PhrShell(): ReactElement {
  // The upload providers sit above everything that navigation swaps, so job state (and the
  // beforeunload guard each provider registers) survives moving between pages.
  return (
    <PhrPatientsProvider>
      <DicomUploadProvider>
        <EhiUploadProvider>
          <ShellLayout />
        </EhiUploadProvider>
        <DicomUploadTray />
      </DicomUploadProvider>
    </PhrPatientsProvider>
  )
}

function ShellLayout(): ReactElement {
  useLegacyHashRedirect()
  const view = usePhrView() ?? NO_VIEW
  const url = phrViewUrl(view)
  const interceptLinks = useShellLinkInterception()
  const { patients } = usePhrPatients()
  const [paletteOpen, setPaletteOpen] = useState(false)
  const [helpOpen, setHelpOpen] = useState(false)
  // The small-screen sidebar sheet belongs to the view it was opened on, so it closes by
  // itself once you have gone somewhere.
  const [sheetFor, setSheetFor] = useState<string | null>(null)
  const sheetOpen = sheetFor === url
  const presentation = view.kind === 'patient' ? detailPresentation(view) : null
  const sidebar = useSidebarMode(presentation === 'page' ? 'full-width' : 'page', presentation === 'panel')
  const rail = sidebar.mode === 'rail'
  const shortcuts = usePhrShortcuts({ view, paletteOpen, setPaletteOpen, setHelpOpen, sidebarMode: sidebar.mode, setSidebarMode: sidebar.setMode })

  const patientId = view.kind === 'patient' ? view.patientId : undefined
  const patientName = patientId === undefined
    ? ''
    : patientDisplayName(patients.find((patient) => patient.id === patientId)?.display_name, patientId)

  return (
    // Delegated link handling: keyboards activate links with a click event too.
    <div className="flex h-full min-h-0 flex-col bg-background" onClick={interceptLinks}>
      <PhrNavbar
        {...(view.kind === 'section' ? { activeSection: view.section } : { inPatient: true })}
        title={view.kind === 'section' ? sectionLabel(view.section) : patientName}
        searchLabel={patientId === undefined ? 'Jump to…' : 'Search this patient…'}
        onSearch={() => setPaletteOpen(true)}
        leading={(
          <button
            type="button"
            aria-label="Open menu"
            aria-expanded={sheetOpen}
            onClick={() => setSheetFor(url)}
            className="flex size-11 shrink-0 items-center justify-center rounded-md text-foreground hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring md:hidden"
          >
            <Menu className="size-5" aria-hidden="true" />
          </button>
        )}
      />

      <Dialog open={sheetOpen} onOpenChange={(open) => setSheetFor(open ? url : null)}>
        <DialogContent
          className="top-0 left-0 h-dvh max-h-dvh w-72 max-w-[85vw] translate-x-0 translate-y-0 grid-cols-[minmax(0,1fr)] content-start gap-0 [&>[data-slot=dialog-close]]:top-0.5 [&>[data-slot=dialog-close]]:right-0.5 [&>[data-slot=dialog-close]]:flex [&>[data-slot=dialog-close]]:size-11 [&>[data-slot=dialog-close]]:items-center [&>[data-slot=dialog-close]]:justify-center overflow-y-auto rounded-none border-0 border-r p-0 pt-12 data-[closed]:zoom-out-100 data-[open]:zoom-in-100 sm:max-w-72"
        >
          <DialogTitle className="sr-only">Menu</DialogTitle>
          {view.kind === 'patient' && (
            <PhrSidebar patientId={view.patientId} page={view.page} rail={false} className="w-full overflow-visible border-r-0 [&_nav_a]:min-h-11" />
          )}
          <PhrSectionLinks
            {...(view.kind === 'section' ? { activeSection: view.section } : { inPatient: true })}
            className={cn('p-3', view.kind === 'patient' && 'border-t border-border')}
          />
        </DialogContent>
      </Dialog>

      <div className="flex min-h-0 flex-1">
        {view.kind === 'patient' && (
          <PhrSidebar patientId={view.patientId} page={view.page} rail={rail} onRailChange={(next) => sidebar.setMode(next ? 'rail' : 'expanded')} className="hidden md:flex" />
        )}

        <main className="min-w-0 flex-1 overflow-hidden" data-view={url}>
          {view.kind === 'patient' ? (
            <PhrPatientView view={view} patientName={patientName} />
          ) : (
            <div className="h-full overflow-y-auto">
              <div className={cn('px-4 py-6 md:px-8', PAGE_CONTENT_MAX_WIDTH)}>
                <Suspense fallback={LOADING}>
                  <SectionContent view={view} />
                </Suspense>
              </div>
            </div>
          )}
        </main>
      </div>

      {patientId === undefined ? (
        <PhrJumpPalette open={paletteOpen} onClose={() => setPaletteOpen(false)} />
      ) : (
        <PhrPatientSearchPalette
          open={paletteOpen}
          patientId={patientId}
          onClose={() => setPaletteOpen(false)}
          onDrill={(target) => navigateToView(resolveNavTarget(view, target))}
        />
      )}
      <ShortcutsDialog open={helpOpen} onOpenChange={setHelpOpen} bindings={shortcuts} />
    </div>
  )
}

function SectionContent({ view }: { view: Extract<PhrView, { kind: 'section' }> }): ReactElement {
  switch (view.section) {
    case 'patients':
      return (
        <PatientsPage
          onPatientSelect={(patientId, page) => pushPhrLocation(page ? patientPageUrl(patientId, page) : patientPath(patientId))}
        />
      )
    case 'manage-patients':
      return <PatientsManagePage />
    case 'data-hub':
      return <DataHubPage />
    case 'imports':
      return <ImportsPage />
    case 'config':
      return <ConfigPage />
  }
}
