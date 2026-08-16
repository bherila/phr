import { render, screen } from '@testing-library/react'
import type React from 'react'
import { Suspense } from 'react'

import type { MillerDrillTarget } from '@/components/ui/miller'

import type { PhrModuleCategory, PhrModuleId } from './phrModuleRegistry'
import { getPhrModuleMeta, PHR_DETAIL_MODULES, PHR_LIST_MODULES, phrModuleRegistry } from './phrModuleRegistry'

interface MockListPageProps {
  patientId: number
  onDrill?: (target: MillerDrillTarget<PhrModuleId>) => void
}

const capturedListProps: Partial<Record<PhrModuleId, MockListPageProps>> = {}

function makeMockListPage(moduleId: PhrModuleId) {
  return function MockListPage({ patientId, onDrill }: MockListPageProps): React.ReactElement {
    if (onDrill === undefined) {
      capturedListProps[moduleId] = { patientId }
    } else {
      capturedListProps[moduleId] = { patientId, onDrill }
    }
    return <div data-testid={`mock-${moduleId}`}>{moduleId}</div>
  }
}

jest.mock('@/phr/labs/LabsPage', () => ({ __esModule: true, default: makeMockListPage('labs') }))
jest.mock('@/phr/health-log/HealthLogPage', () => ({ __esModule: true, default: makeMockListPage('health-log') }))
jest.mock('@/phr/vitals/VitalsPage', () => ({ __esModule: true, default: makeMockListPage('vitals') }))
jest.mock('@/phr/imaging/ImagingPage', () => ({ __esModule: true, default: makeMockListPage('imaging') }))
jest.mock('@/phr/office-visits/OfficeVisitsPage', () => ({ __esModule: true, default: makeMockListPage('office-visits') }))
jest.mock('@/phr/medications/MedicationsPage', () => ({ __esModule: true, default: makeMockListPage('medications') }))
jest.mock('@/phr/conditions/ConditionsPage', () => ({ __esModule: true, default: makeMockListPage('conditions') }))
jest.mock('@/phr/procedures/ProceduresPage', () => ({ __esModule: true, default: makeMockListPage('procedures') }))
jest.mock('@/phr/immunizations/ImmunizationsPage', () => ({ __esModule: true, default: makeMockListPage('immunizations') }))
jest.mock('@/phr/allergies/AllergiesPage', () => ({ __esModule: true, default: makeMockListPage('allergies') }))
jest.mock('@/phr/documents/DocumentsPage', () => ({ __esModule: true, default: makeMockListPage('documents') }))
jest.mock('@/phr/access/AccessPage', () => ({ __esModule: true, default: makeMockListPage('access') }))

const LIST_MODULES: PhrModuleId[] = [
  'health-log',
  'labs',
  'vitals',
  'imaging',
  'office-visits',
  'medications',
  'conditions',
  'procedures',
  'immunizations',
  'allergies',
  'documents',
  'access',
]

interface ExpectedModuleMetadata {
  category: PhrModuleCategory
  keywords: string[]
}

// Section modules (patients, patients-manage, imports, config) are intentionally absent —
// they are not part of the per-patient dock or detail panes, so they carry no dock metadata.
const EXPECTED_MODULE_METADATA: Partial<Record<PhrModuleId, ExpectedModuleMetadata>> = {
  summary: { category: 'Clinical', keywords: ['summary', 'overview', 'health'] },
  'health-log': { category: 'Clinical', keywords: ['health log', 'journal', 'meal', 'snack', 'symptom', 'congestion'] },
  labs: { category: 'Clinical', keywords: ['labs', 'laboratory', 'results', 'bloodwork'] },
  'lab-panel-detail': { category: 'Clinical', keywords: ['labs', 'laboratory', 'results', 'bloodwork'] },
  vitals: { category: 'Clinical', keywords: ['vitals', 'blood pressure', 'weight', 'height'] },
  'vitals-reading-detail': { category: 'Clinical', keywords: ['vitals', 'blood pressure', 'weight', 'height'] },
  'vitals-trend': { category: 'Clinical', keywords: ['vitals', 'blood pressure', 'weight', 'height'] },
  imaging: { category: 'Documents & Imaging', keywords: ['imaging', 'radiology', 'xray', 'mri', 'ct'] },
  'imaging-study-detail': { category: 'Documents & Imaging', keywords: ['imaging', 'radiology', 'xray', 'mri', 'ct'] },
  'office-visits': { category: 'Clinical', keywords: ['visits', 'appointments', 'encounters', 'allergy shots', 'injections', 'immunotherapy', 'immunizations', 'vaccines'] },
  'office-visit-detail': { category: 'Clinical', keywords: ['visits', 'appointments', 'encounters', 'allergy shots', 'injections', 'immunotherapy', 'immunizations', 'vaccines'] },
  medications: { category: 'Clinical', keywords: ['medications', 'prescriptions', 'drugs', 'rx'] },
  'medication-detail': { category: 'Clinical', keywords: ['medications', 'prescriptions', 'drugs', 'rx'] },
  conditions: { category: 'Clinical', keywords: ['conditions', 'diagnoses', 'problems'] },
  'condition-detail': { category: 'Clinical', keywords: ['conditions', 'diagnoses', 'problems'] },
  procedures: { category: 'Clinical', keywords: ['procedures', 'surgery', 'treatments'] },
  'procedure-detail': { category: 'Clinical', keywords: ['procedures', 'surgery', 'treatments'] },
  immunizations: { category: 'Clinical', keywords: ['immunizations', 'vaccines', 'shots'] },
  'immunization-detail': { category: 'Clinical', keywords: ['immunizations', 'vaccines', 'shots'] },
  allergies: { category: 'Clinical', keywords: ['allergies', 'reactions'] },
  'allergy-detail': { category: 'Clinical', keywords: ['allergies', 'reactions'] },
  documents: { category: 'Documents & Imaging', keywords: ['documents', 'files', 'records', 'upload'] },
  'document-viewer': { category: 'Documents & Imaging', keywords: ['documents', 'files', 'records', 'upload'] },
  access: { category: 'Admin', keywords: ['access', 'sharing', 'permissions', 'caregivers'] },
  'access-grant-detail': { category: 'Admin', keywords: ['access', 'sharing', 'permissions', 'caregivers'] },
}

describe('phrModuleRegistry', () => {
  beforeEach(() => {
    for (const id of LIST_MODULES) {
      delete capturedListProps[id]
    }
  })

  it('forwards onDrill to list pages', async () => {
    const onDrill = jest.fn((_: MillerDrillTarget<PhrModuleId>) => {})

    for (const id of LIST_MODULES) {
      const ListColumn = phrModuleRegistry[id].component
      const rendered = render(
        <Suspense fallback={null}>
          <ListColumn state={{ patientId: 123 }} onDrill={onDrill} />
        </Suspense>,
      )
      await screen.findByTestId(`mock-${id}`)
      expect(capturedListProps[id]).toMatchObject({
        patientId: 123,
        onDrill,
      })
      rendered.unmount()
    }
  })

  it('registers data-rich list columns and visual detail columns with wider layouts', () => {
    expect(phrModuleRegistry['health-log'].size).toBe('full')
    expect(phrModuleRegistry['office-visits'].size).toBeUndefined()
    expect(phrModuleRegistry.labs.size).toBe('wide')
    expect(phrModuleRegistry.vitals.size).toBe('wide')
    expect(phrModuleRegistry.documents.size).toBe('full')
    expect(phrModuleRegistry['vitals-trend'].size).toBe('wide')
    expect(phrModuleRegistry['document-viewer'].size).toBe('wide')
  })

  it('registers category and keyword metadata for every module', () => {
    const configuredModuleIds = new Set([...PHR_LIST_MODULES, ...PHR_DETAIL_MODULES].map((module) => module.id))

    expect(configuredModuleIds).toEqual(new Set(Object.keys(EXPECTED_MODULE_METADATA)))

    for (const module of [...PHR_LIST_MODULES, ...PHR_DETAIL_MODULES]) {
      expect(module).toMatchObject(EXPECTED_MODULE_METADATA[module.id]!)
    }

    for (const [id, expectedMetadata] of Object.entries(EXPECTED_MODULE_METADATA) as [PhrModuleId, ExpectedModuleMetadata][]) {
      expect(phrModuleRegistry[id]).toMatchObject(expectedMetadata)
      expect(phrModuleRegistry[id].meta).toMatchObject(expectedMetadata)
      expect(getPhrModuleMeta(phrModuleRegistry[id])).toMatchObject(expectedMetadata)
    }
  })
})
