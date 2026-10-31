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

import type { PhrDetailId, PhrNavTarget, PhrPageId } from './phrRoutes'

export interface PhrListPageProps {
  patientId: number
  onDrill?: (target: PhrNavTarget) => void
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
  medications: { label: 'Medications', icon: Pill, keywords: ['medications', 'prescriptions', 'drugs', 'rx'], component: lazy(() => import('@/phr/medications/MedicationsPage')) },
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
  'condition-detail': { label: 'Condition', component: lazy(() => import('@/phr/conditions/ConditionDetail')) },
  'procedure-detail': { label: 'Procedure', component: lazy(() => import('@/phr/procedures/ProcedureDetail')) },
  'immunization-detail': { label: 'Immunization', component: lazy(() => import('@/phr/immunizations/ImmunizationDetail')) },
  'allergy-detail': { label: 'Allergy', component: lazy(() => import('@/phr/allergies/AllergyDetail')) },
  'document-viewer': { label: 'Document', component: lazy(() => import('@/phr/documents/DocumentViewer')) },
  'access-grant-detail': { label: 'Access Grant', component: lazy(() => import('@/phr/access/AccessGrantDetail')) },
}

export interface PhrNavGroup {
  title: string
  pages: PhrPageId[]
}

/** The patient sidebar, top to bottom. Every page appears exactly once. */
export const PHR_NAV_GROUPS: PhrNavGroup[] = [
  { title: 'Overview', pages: ['review', 'summary', 'health-log'] },
  { title: 'Clinical', pages: ['labs', 'vitals', 'office-visits', 'medications', 'conditions', 'procedures', 'immunizations', 'allergies'] },
  { title: 'Records', pages: ['documents', 'imaging'] },
  { title: 'Sharing', pages: ['access'] },
]

export function navGroupOf(page: PhrPageId): string {
  return PHR_NAV_GROUPS.find((group) => group.pages.includes(page))?.title ?? ''
}

export const PatientsPage = lazy(() => import('@/phr/patients/PatientsPage'))
export const PatientsManagePage = lazy(() => import('@/phr/patients-manage/PatientsManagePage'))
export const DataHubPage = lazy(() => import('@/phr/data-hub/DataHubPage'))
export const ImportsPage = lazy(() => import('@/phr/imports/ImportsPage'))
const AiProviderSettingsPage = lazy(() => import('@/phr/config/AiProviderSettingsPage'))
const ConnectedDevicesPanel = lazy(() => import('@/phr/config/ConnectedDevicesPanel'))
const ConnectedAppsPage = lazy(() => import('@/phr/config/ConnectedAppsPage'))
const ApiAccessPanel = lazy(() => import('@/phr/config/ApiAccessPanel'))

type ConfigTab = 'ai-providers' | 'devices' | 'connected-apps' | 'api-access'

const CONFIG_TABS: { id: ConfigTab, label: string }[] = [
  { id: 'ai-providers', label: 'AI Provider Settings' },
  { id: 'devices', label: 'Connected Devices' },
  { id: 'connected-apps', label: 'Connected apps' },
  { id: 'api-access', label: 'API Access' },
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
      </div>
    </div>
  )
}
