import {
  Activity,
  Ban,
  CalendarDays,
  ClipboardCheck,
  FileText,
  FlaskConical,
  HeartPulse,
  Image,
  type LucideIcon,
  Pill,
  Scissors,
  Shield,
  Syringe,
  TextAlignStart,
  TriangleAlert,
} from 'lucide-react'
import { type ComponentType, lazy, type ReactElement, useState } from 'react'

import { Button } from '@/components/ui/button'

import type { PhrDetailId, PhrDetailRef, PhrNavTarget, PhrPageId } from './phrRoutes'

export interface PhrDrillOptions {
  /**
   * Replace the current history entry instead of pushing one: the detail panel following the
   * active row, so Back does not replay every arrow press.
   */
  replace?: boolean
}

export interface PhrListPageProps {
  patientId: number
  onDrill?: (target: PhrNavTarget, options?: PhrDrillOptions) => void
  /** The record open beside the list (a visit, when its linked study replaced it in the panel). */
  openRecord?: PhrDetailRef | undefined
}

/**
 * A record list's `onOpen` for a page: opens the row's target through `onDrill`, replacing the
 * history entry only when the open panel follows the active row.
 */
export function drillOpener<Row>(
  onDrill: PhrListPageProps['onDrill'],
  target: (row: Row) => PhrNavTarget,
): ((row: Row, options: { replace: boolean }) => void) | undefined {
  if (!onDrill) return undefined
  return (row, { replace }) => {
    if (replace) onDrill(target(row), { replace })
    else onDrill(target(row))
  }
}

export interface PhrDetailPageProps {
  patientId: number
  recordId: string
  onDrill?: (target: PhrNavTarget) => void
}

export interface PhrPageMeta {
  label: string
  keywords: string[]
  icon: LucideIcon
  component: ComponentType<PhrListPageProps>
  /** The sidebar's name for this page, where a section is named differently from its default view. */
  navLabel?: string
  /** A view inside another page's section: it has no sidebar item of its own and marks that page's. */
  sectionPage?: PhrPageId
  /** Its list has no breadcrumb: the sidebar names the page, and its tabs say which view. */
  noListBreadcrumb?: boolean
  /** The breadcrumb's name for a view inside a section, whose own label may repeat the section's. */
  crumbLabel?: string
}

export interface PhrDetailMeta {
  /** Names one record, as in the breadcrumb and the panel heading ("Condition", "Visit"). */
  label: string
  component: ComponentType<PhrDetailPageProps>
}

export const PHR_PAGES: Record<PhrPageId, PhrPageMeta> = {
  review: { label: 'Review', icon: ClipboardCheck, keywords: ['review', 'pending', 'approve', 'proposed', 'waiting'], component: lazy(() => import('@/phr/review/ReviewPage')) },
  summary: { label: 'Summary', icon: TextAlignStart, keywords: ['summary', 'overview', 'health'], component: lazy(() => import('@/phr/summary/SummaryPage')) },
  'health-log': { label: 'Health Log', icon: Activity, keywords: ['health log', 'journal', 'meal', 'snack', 'symptom', 'congestion'], component: lazy(() => import('@/phr/health-log/HealthLogPage')) },
  labs: { label: 'Labs', icon: FlaskConical, keywords: ['labs', 'laboratory', 'results', 'bloodwork'], component: lazy(() => import('@/phr/labs/LabsPage')) },
  vitals: { label: 'Vitals', icon: HeartPulse, keywords: ['vitals', 'blood pressure', 'weight', 'height'], component: lazy(() => import('@/phr/vitals/VitalsPage')) },
  'office-visits': { label: 'Visits', icon: CalendarDays, keywords: ['visits', 'appointments', 'encounters', 'allergy shots', 'injections', 'immunotherapy'], component: lazy(() => import('@/phr/office-visits/OfficeVisitsPage')) },
  medications: { label: 'Medications', sectionPage: 'medication-fills', crumbLabel: 'Medication list', noListBreadcrumb: true, icon: Pill, keywords: ['medications', 'prescriptions', 'drugs', 'rx'], component: lazy(() => import('@/phr/medications/MedicationsPage')) },
  'medication-fills': { label: 'Fills', navLabel: 'Medications', noListBreadcrumb: true, icon: Pill, keywords: ['fills', 'prescription fills', 'dispensed', 'pharmacy', 'ndc', 'refills', 'receipts'], component: lazy(() => import('@/phr/medication-fills/MedicationFillsPage')) },
  conditions: { label: 'Conditions', icon: TriangleAlert, keywords: ['conditions', 'diagnoses', 'problems'], component: lazy(() => import('@/phr/conditions/ConditionsPage')) },
  procedures: { label: 'Procedures', icon: Scissors, keywords: ['procedures', 'surgery', 'treatments'], component: lazy(() => import('@/phr/procedures/ProceduresPage')) },
  immunizations: { label: 'Immunizations', icon: Syringe, keywords: ['immunizations', 'vaccines', 'shots'], component: lazy(() => import('@/phr/immunizations/ImmunizationsPage')) },
  allergies: { label: 'Allergies', icon: Ban, keywords: ['allergies', 'reactions'], component: lazy(() => import('@/phr/allergies/AllergiesPage')) },
  documents: { label: 'Documents', icon: FileText, keywords: ['documents', 'files', 'records', 'upload'], component: lazy(() => import('@/phr/documents/DocumentsPage')) },
  imaging: { label: 'Imaging', icon: Image, keywords: ['imaging', 'radiology', 'xray', 'mri', 'ct'], component: lazy(() => import('@/phr/imaging/ImagingPage')) },
  access: { label: 'Access', icon: Shield, keywords: ['access', 'sharing', 'permissions', 'caregivers'], component: lazy(() => import('@/phr/access/AccessPage')) },
}

export const PHR_DETAILS: Record<PhrDetailId, PhrDetailMeta> = {
  'lab-panel-detail': { label: 'Lab Panel', component: lazy(() => import('@/phr/labs/LabPanelDetail')) },
  'vitals-reading-detail': { label: 'Vital Reading', component: lazy(() => import('@/phr/vitals/VitalsReadingDetail')) },
  'vitals-trend': { label: 'Trend', component: lazy(() => import('@/phr/vitals/VitalsTrend')) },
  'imaging-study-detail': { label: 'Study', component: lazy(() => import('@/phr/imaging/ImagingStudyDetail')) },
  'office-visit-detail': { label: 'Visit', component: lazy(() => import('@/phr/office-visits/OfficeVisitDetail')) },
  'medication-detail': { label: 'Medication', component: lazy(() => import('@/phr/medications/MedicationDetail')) },
  'medication-fill-detail': { label: 'Fill', component: lazy(() => import('@/phr/medication-fills/MedicationFillDetail')) },
  'condition-detail': { label: 'Condition', component: lazy(() => import('@/phr/conditions/ConditionDetail')) },
  'procedure-detail': { label: 'Procedure', component: lazy(() => import('@/phr/procedures/ProcedureDetail')) },
  'immunization-detail': { label: 'Immunization', component: lazy(() => import('@/phr/immunizations/ImmunizationDetail')) },
  'allergy-detail': { label: 'Allergy', component: lazy(() => import('@/phr/allergies/AllergyDetail')) },
  'document-viewer': { label: 'Document', component: lazy(() => import('@/phr/documents/DocumentViewer')) },
  'access-grant-detail': { label: 'Access Grant', component: lazy(() => import('@/phr/access/AccessGrantDetail')) },
}

/**
 * The patient sidebar, top to bottom, in groups set apart by a gap. Every page appears once, or
 * under its `sectionPage`.
 */
export const PHR_NAV_GROUPS: PhrPageId[][] = [
  ['review', 'summary', 'health-log'],
  ['labs', 'vitals', 'office-visits', 'medication-fills', 'conditions', 'procedures', 'immunizations', 'allergies'],
  ['documents', 'imaging'],
  ['access'],
]

/** The page whose sidebar item stands for `page`. */
export function sidebarPageOf(page: PhrPageId): PhrPageId {
  return PHR_PAGES[page].sectionPage ?? page
}

export const PatientsPage = lazy(() => import('@/phr/patients/PatientsPage'))
export const PatientsManagePage = lazy(() => import('@/phr/patients-manage/PatientsManagePage'))
export const DataHubPage = lazy(() => import('@/phr/data-hub/DataHubPage'))
export const ImportsPage = lazy(() => import('@/phr/imports/ImportsPage'))
const AiProviderSettingsPage = lazy(() => import('@/phr/config/AiProviderSettingsPage'))
const ConnectedDevicesPanel = lazy(() => import('@/phr/config/ConnectedDevicesPanel'))
const ConnectedAppsPage = lazy(() => import('@/phr/config/ConnectedAppsPage'))
const ApiAccessPanel = lazy(() => import('@/phr/config/ApiAccessPanel'))
const DisplaySettingsPage = lazy(() => import('@/phr/config/DisplaySettingsPage'))

type ConfigTab = 'ai-providers' | 'devices' | 'connected-apps' | 'api-access' | 'display'

const CONFIG_TABS: { id: ConfigTab, label: string }[] = [
  { id: 'ai-providers', label: 'AI Provider Settings' },
  { id: 'devices', label: 'Connected Devices' },
  { id: 'connected-apps', label: 'Connected apps' },
  { id: 'api-access', label: 'API Access' },
  { id: 'display', label: 'Display' },
]

export function ConfigPage(): ReactElement {
  const [tab, setTab] = useState<ConfigTab>('ai-providers')
  return (
    <div className="flex flex-col gap-4">
      <div role="tablist" aria-label="PHR configuration" className="flex shrink-0 gap-2 overflow-x-auto border-b border-border">
        {CONFIG_TABS.map((candidate) => (
          <Button
            key={candidate.id}
            type="button"
            role="tab"
            aria-selected={tab === candidate.id}
            variant={tab === candidate.id ? 'default' : 'ghost'}
            size="sm"
            className="rounded-b-none"
            onClick={() => setTab(candidate.id)}
          >
            {candidate.label}
          </Button>
        ))}
      </div>
      <div className="min-h-0 flex-1">
        {tab === 'ai-providers' ? <AiProviderSettingsPage /> : null}
        {tab === 'devices' ? <ConnectedDevicesPanel /> : null}
        {tab === 'connected-apps' ? <ConnectedAppsPage /> : null}
        {tab === 'api-access' ? <ApiAccessPanel /> : null}
        {tab === 'display' ? <DisplaySettingsPage /> : null}
      </div>
    </div>
  )
}
