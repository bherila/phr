/// <reference types="node" />
import '@testing-library/jest-dom'

import { readdirSync, readFileSync, statSync } from 'node:fs'
import path from 'node:path'

import { act, renderHook, waitFor } from '@testing-library/react'

import { useClinicalCrud } from '@/phr/clinical/crud'
import { recordReviewDecision } from '@/phr/clinical/reviewDecision'
import { createHealthLog, deleteHealthLog } from '@/phr/health-log/healthLogApi'
import { decideAiResult, keepAsDocument, retryAiImport } from '@/phr/imports/ai/aiImportsApi'
import { importStructuredFile, queueEhiPreview, reviewEhiProposal } from '@/phr/imports/ehi/ehiApi'

import { decideReviewItem } from './reviewApi'
import { resetReviewSummaries, setReviewSummary, useReviewSummary } from './reviewSummary'

const mockGet = jest.fn()
const mockPost = jest.fn()
const mockPatch = jest.fn()
const mockDelete = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: (...args: unknown[]) => mockGet(...args),
    post: (...args: unknown[]) => mockPost(...args),
    patch: (...args: unknown[]) => mockPatch(...args),
    delete: (...args: unknown[]) => mockDelete(...args),
  },
  getCsrfToken: () => null,
}))

const PATIENT = 7
const SUMMARY_URL = `/api/phr/patients/${PATIENT}/review/summary`
const SUMMARY = { summary: { total: 3, sections: {}, ai_results: 0 }, can_manage: true }

function summaryRequests(): number {
  return mockGet.mock.calls.filter(([url]) => url === SUMMARY_URL).length
}

function cacheFreshSummary(): void {
  setReviewSummary(PATIENT, { summary: SUMMARY.summary, canManage: true })
}

/** Mount a summary consumer, as a later page view would, and count whether it fetched. */
async function fetchesOnNextView(): Promise<boolean> {
  const before = summaryRequests()
  const view = renderHook(() => useReviewSummary(PATIENT))
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 0))
  })
  view.unmount()
  return summaryRequests() > before
}

const RECORD = { id: 3 }
const ENDPOINT = `/api/phr/patients/${PATIENT}/medications`

function crud() {
  return renderHook(() => useClinicalCrud<{ id: number }, Record<string, never>>({
    endpoint: ENDPOINT,
    emptyForm: {},
    formFromRecord: () => ({}),
    parseItem: () => RECORD,
    parseList: () => ({ records: [RECORD], canManage: true }),
    payloadFromForm: () => ({}),
  })).result
}

/**
 * Every browser path that can change what waits for review (or how many records a
 * section holds) for a patient. Each must mark that patient's cached counts stale, so the
 * next view of the patient fetches them, wherever the change was made.
 */
const PATHS: [string, () => Promise<unknown>][] = [
  ['a clinical record review decision', () => recordReviewDecision(`${ENDPOINT}/3`, 'confirmed')],
  ['crud.reviewRecord (Medications, Conditions, Procedures, Immunizations, Allergies)', async () => {
    const hook = crud()
    await act(async () => {
      await hook.current.reviewRecord(3, 'confirmed')
    })
  }],
  ['crud.addRecord', async () => {
    const hook = crud()
    await act(async () => {
      await hook.current.addRecord({})
    })
  }],
  ['crud.deleteRecord', async () => {
    const hook = crud()
    await act(async () => {
      await hook.current.deleteRecord(3)
    })
  }],
  ['an AI import accept', () => decideAiResult(PATIENT, 5, 31, 'accept')],
  ['an AI import reject', () => decideAiResult(PATIENT, 5, 31, 'reject')],
  ['an EHI proposal review', () => reviewEhiProposal(PATIENT, { id: 41, preview_digest: 'd' }, 'reject')],
  ['a C-CDA or FHIR file import', () => importStructuredFile(PATIENT, new File(['<x/>'], 'summary.xml'))],
  ['an AI import retry, which clears its proposals', () => retryAiImport(PATIENT, 5).catch(() => {})],
  ['keeping a file as a document', () => keepAsDocument(PATIENT, new File(['%PDF'], 'letter.pdf')).catch(() => {})],
  ['a new EHI preview, which replaces its proposals', () => queueEhiPreview(PATIENT, 9).catch(() => {})],
  ['a health log created', () => createHealthLog(PATIENT, { name: 'Synthetic' }).catch(() => {})],
  ['a health log deleted', () => deleteHealthLog(PATIENT, 3)],
  ['a Review page decision', () => decideReviewItem(PATIENT, {
    type: 'record', key: 'record:labs:3', id: 3, section: 'labs', review_segment: 'lab-results', title: 'Sodium',
    detail: null, change: 'new', version: 'a'.repeat(64), updated_at: null,
  }, 'approve')],
]

beforeEach(() => {
  resetReviewSummaries()
  mockGet.mockReset()
  mockPost.mockReset()
  mockPatch.mockReset()
  mockDelete.mockReset()
  mockGet.mockImplementation((url: string) => Promise.resolve(url === SUMMARY_URL ? SUMMARY : { records: [] }))
  mockPatch.mockResolvedValue({})
  mockDelete.mockResolvedValue({})
  mockPost.mockImplementation((url: string) => Promise.resolve(
    url.includes('/ehi/proposals/')
      ? { proposal: { id: 41, package_id: 9, mapping: 'immunization', mapping_version: '1', kind: 'create', status: 'rejected', reviewable: false, allowed_decisions: [], target: null, candidate_ids: [], candidate_total: 0, candidates_truncated: false, proposed: {}, before: null, preview_digest: 'd', decided_at: null }, record: null }
      : url.includes('/imports/structured')
        ? { format: 'ccda', result: { created: 1, updated: 0, unchanged: 0, skipped: 0, warnings: [], documents: 0 } }
        : { outcome: 'accepted', result: { id: 31, status: 'imported' } },
  ))
})

describe('review count invalidation', () => {
  it('a fresh cached summary is reused by the next view', async () => {
    cacheFreshSummary()
    expect(await fetchesOnNextView()).toBe(false)
  })

  it.each(PATHS)('%s marks the cached counts stale for the next view', async (_name, run) => {
    cacheFreshSummary()
    await run()
    expect(await fetchesOnNextView()).toBe(true)
  })

  it.each(PATHS)('%s refetches the counts a mounted view shows', async (_name, run) => {
    mockGet.mockImplementation(() => Promise.resolve(SUMMARY))
    const view = renderHook(() => useReviewSummary(PATIENT))
    await waitFor(() => expect(view.result.current).not.toBeNull())
    const before = summaryRequests()
    await run()
    await waitFor(() => expect(summaryRequests()).toBe(before + 1))
    view.unmount()
  })

  it('counts left by an earlier visit are fetched again after a while', async () => {
    const now = jest.spyOn(Date, 'now')
    now.mockReturnValue(1_000_000)
    cacheFreshSummary()
    now.mockReturnValue(1_000_000 + 60_000)
    expect(await fetchesOnNextView()).toBe(true)
    now.mockRestore()
  })
})

/** Source files under resources/js/phr, tests excluded. */
function sources(directory: string): string[] {
  return readdirSync(directory).flatMap((entry) => {
    const full = path.join(directory, entry)
    if (statSync(full).isDirectory()) return sources(full)
    return /\.tsx?$/.test(entry) && !/\.test\.tsx?$/.test(entry) ? [full] : []
  })
}

describe('review decision paths', () => {
  const root = path.join(process.cwd(), 'resources/js/phr')
  const relative = (file: string): string => path.relative(root, file)

  /**
   * Modules that write without marking review counts stale, each with why its writes cannot
   * change a patient's record counts or what waits for review. Anything else that writes
   * must call reviewStateChanged itself (crud.ts and reviewDecision.ts do, for their callers).
   */
  const NO_COUNT_CHANGE: Record<string, string> = {
    'access/AccessPage.tsx': 'grants and revokes sharing; no record or review state changes',
    'clinical/EncounterEobLinks.tsx': 'links a visit or procedure to a claim; no section count or review state changes',
    'config/StorageSettingsPage.tsx': 'Admin capacity settings do not change clinical review counts.',
  'config/AiProviderSettingsPage.tsx': 'account AI settings, not patient records',
    'config/ConnectedDevicesPanel.tsx': 'account devices, not patient records',
    'config/connectedApps.ts': 'account app consents, not patient records',
    'data-hub/DataHubPage.tsx': 'exports and backups read the record; a deleted patient leaves the list; a native restore creates a new patient, which has nothing cached',
    'documents/DocumentViewer.tsx': 'sends a document to AI; proposals arrive later, and an AI import is counted when its results are read',
    'imaging/explore3d/pipeline/useVolumePipeline.ts': 'stores a derived volume cache for a series; no study is added',
    'patients/PatientForm.tsx': 'creates a patient, which has nothing cached',
    'patients-manage/PatientsManagePage.tsx': 'creates a patient or edits its profile fields; neither is counted',
    'summary/SummaryPage.tsx': 'requests an export, which only reads the record',
    'uploads/dicomUpload.ts': 'sends the files of an unfinished upload or cancels it; the study is added, and counted, at finalize in DicomUploadProvider',
  }

  it('mark the counts stale wherever a module writes, unless its writes cannot change them', () => {
    const write = /fetchWrapper\.(post|put|patch|delete|postRaw|putRaw|deleteRaw)\(|\.open\(\s*'(POST|PUT|PATCH|DELETE)'|method:\s*['"](POST|PUT|PATCH|DELETE)['"]/i
    const writers = sources(root).filter((file) => write.test(readFileSync(file, 'utf8'))).map(relative)
    const missing = writers.filter((file) => !(file in NO_COUNT_CHANGE) && !readFileSync(path.join(root, file), 'utf8').includes('reviewStateChanged('))
    expect(missing).toEqual([])
    // The allowlist names only modules that still write, so it cannot hide a removed one's successor.
    expect(Object.keys(NO_COUNT_CHANGE).filter((file) => !writers.includes(file))).toEqual([])
  })

  it('send every clinical review decision through the one helper', () => {
    // A request body that sets a review decision (schemas that only read the field are fine).
    const direct = sources(root).filter((file) => /review_status\s*:\s*(decision|'confirmed'|'rejected'|\w+ === 'approve')/.test(readFileSync(file, 'utf8')))
    expect(direct.map(relative)).toEqual(['clinical/reviewDecision.ts'])
  })

  it('mark the counts stale wherever an import proposal is decided', () => {
    const deciding = sources(root).filter((file) => /\/results\/\$\{[^}]+\}\/\$\{decision\}|\/proposals\/\$\{[^}]+\}\/review/.test(readFileSync(file, 'utf8')))
    expect(deciding.map(relative).sort()).toEqual(['imports/ai/aiImportsApi.ts', 'imports/ehi/ehiApi.ts'])
    for (const file of deciding) expect(readFileSync(file, 'utf8')).toContain('reviewStateChanged(patientId)')
  })
})
