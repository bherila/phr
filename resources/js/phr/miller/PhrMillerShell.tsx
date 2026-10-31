import type { ReactElement } from 'react'
import { Suspense, useCallback, useEffect, useState } from 'react'

import PhrNavbar from '@/components/phr/PhrNavbar'
import { pushMillerLocation, subscribeToMillerLocation, useMillerApp } from '@/components/ui/miller'
import type { PhrSection } from '@/lib/phrRouteBuilder'
import { patientUrl, phrSectionUrl } from '@/lib/phrRouteBuilder'
import { EhiUploadProvider } from '@/phr/imports/ehi/EhiUploadProvider'
import { PhrPatientsProvider } from '@/phr/patients/usePhrPatients'
import { DicomUploadProvider, DicomUploadTray } from '@/phr/uploads'

import { PhrCommandPalette, usePhrCommandPaletteShortcut } from './PhrCommandPalette'
import { PhrDockHomeView } from './PhrDockHomeView'
import { PHR_MODULE_IDS_SET, type PhrModuleId, type PhrModuleMeta, phrModuleRegistry, type PhrShellState } from './phrModuleRegistry'
import { PhrPatientSearchPalette } from './PhrPatientSearchPalette'

const LOADING = <div role="status" aria-live="polite" className="p-8 text-sm text-muted-foreground">Loading…</div>

const SECTION_TO_MODULE: Record<PhrSection, PhrModuleId> = {
  patients: 'patients',
  'manage-patients': 'patients-manage',
  'data-hub': 'data-hub',
  imports: 'imports',
  config: 'config',
}

const MODULE_TO_SECTION: Partial<Record<PhrModuleId, PhrSection>> = {
  patients: 'patients',
  'patients-manage': 'manage-patients',
  'data-hub': 'data-hub',
  imports: 'imports',
  config: 'config',
}

function patientIdFromPath(): number | undefined {
  if (typeof window === 'undefined') {
    return undefined
  }
  const match = window.location.pathname.match(/^\/phr\/patient\/(\d+)$/)
  return match ? Number.parseInt(match[1]!, 10) : undefined
}

interface PhrMillerShellProps {
  initialPatientId?: number
  backUrl?: string
}

export function PhrMillerShell({ initialPatientId, backUrl }: PhrMillerShellProps): ReactElement {
  const [patientId, setPatientId] = useState<number | undefined>(() => initialPatientId ?? patientIdFromPath())
  const [paletteOpen, setPaletteOpen] = useState(false)
  usePhrCommandPaletteShortcut(paletteOpen, setPaletteOpen)

  // The patient lives in the path (/phr/patient/{id}); like the column stack in the hash, it is
  // derived from the location on every change, so Back and Forward restore both together.
  // useMillerRoute subscribes to the same notifications, and React batches the two updates from
  // one event into one render: the patient and its columns never disagree, even for a frame.
  useEffect(() => subscribeToMillerLocation(() => setPatientId(patientIdFromPath())), [])

  const handlePatientChange = useCallback((nextPatientId: number, hash?: string): void => {
    if (typeof window === 'undefined') {
      return
    }
    // Default (e.g. the navbar patient picker) keeps the current module so switching patients
    // stays on the same view; callers pass an explicit hash to override ('' clears to the dock).
    const nextHash = hash ?? window.location.hash
    pushMillerLocation(`${patientUrl(nextPatientId)}${nextHash}`)
  }, [])

  const state: PhrShellState = { patientId, onPatientChange: handlePatientChange }

  const { shell, route, pushColumn } = useMillerApp<PhrShellState, PhrModuleId, PhrModuleMeta>({
    registry: phrModuleRegistry,
    ids: PHR_MODULE_IDS_SET,
    state,
    homeView: (api) => <PhrDockHomeView patientId={patientId} replaceFrom={api.replaceFrom} />,
    homeColumnSize: 'narrow',
  })

  const leftmostId = route.columns.length > 0 ? route.columns[0]!.id : undefined
  const activeSection = leftmostId ? MODULE_TO_SECTION[leftmostId] : undefined

  const handleSectionChange = useCallback((section: PhrSection): void => {
    // Top-level sections are not patient-scoped: move to the section's own path and clear the
    // patient context so the URL is shareable without requiring access to the previous patient.
    pushMillerLocation(`${phrSectionUrl(section)}#/${SECTION_TO_MODULE[section]}`)
  }, [])

  // The upload providers sit above the column stack, not inside it: the shell is mounted once
  // per page load and its children swap on navigation, so job state (and the beforeunload
  // guard registered from each provider) survives moving between Miller tabs and columns.
  return (
    <PhrPatientsProvider>
      <DicomUploadProvider>
        <EhiUploadProvider>
          <PhrNavbar
            {...(patientId !== undefined ? { patientId } : {})}
            {...(activeSection ? { activeSection } : {})}
            {...(backUrl ? { backUrl } : {})}
            className="flex h-full flex-col"
            onPatientChange={handlePatientChange}
            onSectionChange={handleSectionChange}
            onSearch={() => setPaletteOpen(true)}
          >
            <div className="min-h-0 flex-1 overflow-hidden">
              <Suspense fallback={LOADING}>{shell}</Suspense>
            </div>
            {patientId === undefined ? (
              <PhrCommandPalette
                open={paletteOpen}
                onClose={() => setPaletteOpen(false)}
                onDrill={pushColumn}
                registry={phrModuleRegistry}
              />
            ) : (
              <PhrPatientSearchPalette
                open={paletteOpen}
                patientId={patientId}
                onClose={() => setPaletteOpen(false)}
                onDrill={pushColumn}
              />
            )}
          </PhrNavbar>
        </EhiUploadProvider>
        <DicomUploadTray />
      </DicomUploadProvider>
    </PhrPatientsProvider>
  )
}
