/**
 * The PHR URL model. Every view the shell can show has exactly one path, and the shell renders
 * whatever the location says, so a URL is shareable and Back/Forward restore the whole view.
 *
 * - `/phr/patients`, `/phr/patients/manage`, `/phr/imports`, `/phr/data-hub`, `/phr/config`:
 *   section pages with no patient context. Their query string (e.g. `?patient_id=` on Imports)
 *   is part of the view and is kept as-is.
 * - `/phr/patient/{id}`: the patient's Summary.
 * - `/phr/patient/{id}/{page}`: one of the patient's record pages (`labs`, `conditions`, …).
 * - `/phr/patient/{id}/{page}/{recordId}`: that page with a record's detail open.
 * - `/phr/patient/{id}/{page}/{kind}/{recordId}`: a detail the page hosts that is not its own
 *   record type, e.g. `/office-visits/procedure/7` or `/vitals/trend/weight`.
 * - `/phr/patient/{id}/office-visits/{visitId}/imaging/{studyId}`: a visit's linked imaging
 *   study, shown in place of the visit with a link back to it.
 *
 * The old Miller shell kept its columns in the hash (`/phr/patient/12#/labs/lab-panel-detail:5`).
 * {@link legacyHashUrl} maps those onto the paths above so bookmarks keep working.
 */
export type PhrSection = 'patients' | 'manage-patients' | 'data-hub' | 'imports' | 'config'

export type PhrPageId =
  | 'summary'
  | 'health-log'
  | 'labs'
  | 'vitals'
  | 'office-visits'
  | 'medications'
  | 'conditions'
  | 'procedures'
  | 'immunizations'
  | 'allergies'
  | 'documents'
  | 'imaging'
  | 'access'

export type PhrDetailId =
  | 'lab-panel-detail'
  | 'vitals-reading-detail'
  | 'vitals-trend'
  | 'imaging-study-detail'
  | 'office-visit-detail'
  | 'medication-detail'
  | 'condition-detail'
  | 'procedure-detail'
  | 'immunization-detail'
  | 'allergy-detail'
  | 'document-viewer'
  | 'access-grant-detail'

/** The old column shell's ids for the top-level sections, still used by navigation requests. */
export type PhrSectionModuleId = 'patients' | 'patients-manage' | 'data-hub' | 'imports' | 'config'

/** Anything a page can ask to open: a section, a patient page, or a record's detail. */
export type PhrModuleId = PhrPageId | PhrDetailId | PhrSectionModuleId

export interface PhrDetailRef {
  module: PhrDetailId
  id: string
}

export type PhrView =
  | { kind: 'section', section: PhrSection, search: string }
  | { kind: 'patient', patientId: number, page: PhrPageId, detail?: PhrDetailRef, parent?: PhrDetailRef }

/** A navigation request from a page: open a module, optionally at one of its records. */
export interface PhrNavTarget {
  id: PhrModuleId
  instance?: string
}

export const PHR_PAGE_IDS: readonly PhrPageId[] = [
  'summary',
  'health-log',
  'labs',
  'vitals',
  'office-visits',
  'medications',
  'conditions',
  'procedures',
  'immunizations',
  'allergies',
  'documents',
  'imaging',
  'access',
]

const SECTION_PATHS: Record<PhrSection, string> = {
  patients: '/phr/patients',
  'manage-patients': '/phr/patients/manage',
  'data-hub': '/phr/data-hub',
  imports: '/phr/imports',
  config: '/phr/config',
}

/** The old shell's column id for each section, used only to read legacy hashes. */
const SECTION_MODULES: Record<string, PhrSection> = {
  patients: 'patients',
  'patients-manage': 'manage-patients',
  'data-hub': 'data-hub',
  imports: 'imports',
  config: 'config',
}

/** Detail views that open full-width under the sidebar instead of in the side panel. */
export type PhrDetailPresentation = 'panel' | 'page'

interface DetailRoute {
  /** The page that owns this record type. */
  home: PhrPageId
  presentation: PhrDetailPresentation
}

export const PHR_DETAIL_ROUTES: Record<PhrDetailId, DetailRoute> = {
  'lab-panel-detail': { home: 'labs', presentation: 'panel' },
  'vitals-reading-detail': { home: 'vitals', presentation: 'panel' },
  'vitals-trend': { home: 'vitals', presentation: 'page' },
  'imaging-study-detail': { home: 'imaging', presentation: 'page' },
  'office-visit-detail': { home: 'office-visits', presentation: 'panel' },
  'medication-detail': { home: 'medications', presentation: 'panel' },
  'condition-detail': { home: 'conditions', presentation: 'panel' },
  'procedure-detail': { home: 'procedures', presentation: 'panel' },
  'immunization-detail': { home: 'immunizations', presentation: 'panel' },
  'allergy-detail': { home: 'allergies', presentation: 'panel' },
  'document-viewer': { home: 'documents', presentation: 'page' },
  'access-grant-detail': { home: 'access', presentation: 'panel' },
}

/**
 * Details a page shows although they are not its own record type, keyed by the path segment
 * that names them. The Visits timeline lists procedures and immunizations; Vitals opens trends.
 */
const HOSTED_DETAILS: Partial<Record<PhrPageId, Record<string, PhrDetailId>>> = {
  'office-visits': { procedure: 'procedure-detail', immunization: 'immunization-detail' },
  vitals: { trend: 'vitals-trend' },
}

/** A detail may open one linked record in its place, with a link back to it. */
const CHILD_DETAILS: Partial<Record<PhrDetailId, Record<string, PhrDetailId>>> = {
  'office-visit-detail': { imaging: 'imaging-study-detail' },
}

const PAGE_IDS = new Set<string>(PHR_PAGE_IDS)

export function isPhrPageId(value: string | undefined): value is PhrPageId {
  return value !== undefined && PAGE_IDS.has(value)
}

export function isPhrDetailId(value: string | undefined): value is PhrDetailId {
  return value !== undefined && Object.hasOwn(PHR_DETAIL_ROUTES, value)
}

export function isPhrSectionModule(value: string | undefined): boolean {
  return value !== undefined && Object.hasOwn(SECTION_MODULES, value)
}

function segmentFor(table: Record<string, PhrDetailId> | undefined, module: PhrDetailId): string | undefined {
  return table ? Object.keys(table).find((segment) => table[segment] === module) : undefined
}

function primaryDetail(page: PhrPageId): PhrDetailId | undefined {
  return (Object.keys(PHR_DETAIL_ROUTES) as PhrDetailId[]).find(
    (module) => PHR_DETAIL_ROUTES[module].home === page && segmentFor(HOSTED_DETAILS[page], module) === undefined,
  )
}

/** Whether `page` can show `module` beside its list (its own records or a hosted kind). */
function pageHosts(page: PhrPageId, module: PhrDetailId): boolean {
  return primaryDetail(page) === module || segmentFor(HOSTED_DETAILS[page], module) !== undefined
}

/** How a detail is shown: a linked child always takes the parent's panel. */
export function detailPresentation(view: Extract<PhrView, { kind: 'patient' }>): PhrDetailPresentation | null {
  if (!view.detail) return null
  if (view.parent) return 'panel'
  return PHR_DETAIL_ROUTES[view.detail.module].presentation
}

function decodeSegment(segment: string): string | null {
  try {
    return decodeURIComponent(segment)
  } catch {
    return null
  }
}

export function patientPath(patientId: number): string {
  return `/phr/patient/${patientId}`
}

export function sectionPath(section: PhrSection): string {
  return SECTION_PATHS[section]
}

/**
 * Whether the server answers `pathname` with this shell, so the client can navigate to it in
 * place. Other PHR pages under a patient (EOBs, the 3D explorer) are separate documents.
 */
export function isPhrShellPath(pathname: string): boolean {
  if (parsePhrLocation(pathname) === null) return false
  const match = pathname.match(/^\/phr\/patient\/\d+\/([^/]+)(\/.*)?$/)
  if (!match) return true
  return isPhrPageId(match[1]) && !/\/explore-3d\/?$/.test(match[2] ?? '')
}

/** Parse a location into a view, or null when the path is not a PHR shell route. */
export function parsePhrLocation(pathname: string, search = ''): PhrView | null {
  const path = pathname.length > 1 ? pathname.replace(/\/+$/, '') : pathname
  for (const section of Object.keys(SECTION_PATHS) as PhrSection[]) {
    if (SECTION_PATHS[section] === path) {
      return { kind: 'section', section, search }
    }
  }

  const match = path.match(/^\/phr\/patient\/(\d+)(?:\/(.*))?$/)
  if (!match) return null
  const patientId = Number.parseInt(match[1]!, 10)
  const segments = (match[2] ?? '').split('/').filter(Boolean).map(decodeSegment)
  const [pageSegment, ...rest] = segments
  if (!pageSegment || !isPhrPageId(pageSegment)) {
    return { kind: 'patient', patientId, page: 'summary' }
  }

  const page = pageSegment
  const view: Extract<PhrView, { kind: 'patient' }> = { kind: 'patient', patientId, page }
  const first = readDetail(rest, 0, HOSTED_DETAILS[page], primaryDetail(page))
  if (!first) return view
  const child = readDetail(rest, first.next, CHILD_DETAILS[first.ref.module], undefined)
  if (child) {
    return { ...view, parent: first.ref, detail: child.ref }
  }
  return { ...view, detail: first.ref }
}

function readDetail(
  segments: (string | null)[],
  at: number,
  named: Record<string, PhrDetailId> | undefined,
  primary: PhrDetailId | undefined,
): { ref: PhrDetailRef, next: number } | null {
  const head = segments[at]
  if (head === undefined || head === null) return null
  const namedModule = named?.[head]
  if (namedModule) {
    const id = segments[at + 1]
    return id ? { ref: { module: namedModule, id }, next: at + 2 } : null
  }
  return primary ? { ref: { module: primary, id: head }, next: at + 1 } : null
}

function detailSegments(page: PhrPageId, ref: PhrDetailRef, named: Record<string, PhrDetailId> | undefined): string[] {
  const segment = segmentFor(named, ref.module)
  const id = encodeURIComponent(ref.id)
  if (segment) return [segment, id]
  return primaryDetail(page) === ref.module ? [id] : []
}

/** The canonical URL (path and query) of a view. */
export function phrViewUrl(view: PhrView): string {
  if (view.kind === 'section') {
    return `${SECTION_PATHS[view.section]}${view.search}`
  }
  if (view.page === 'summary' && !view.detail) {
    return patientPath(view.patientId)
  }
  const segments: string[] = [view.page]
  if (view.parent && view.detail) {
    segments.push(...detailSegments(view.page, view.parent, HOSTED_DETAILS[view.page]))
    const child = segmentFor(CHILD_DETAILS[view.parent.module], view.detail.module)
    if (child) segments.push(child, encodeURIComponent(view.detail.id))
  } else if (view.detail) {
    segments.push(...detailSegments(view.page, view.detail, HOSTED_DETAILS[view.page]))
  }
  return `${patientPath(view.patientId)}/${segments.join('/')}`
}

/** URL of a patient page, optionally with one of its own records open. */
export function patientPageUrl(patientId: number, page: PhrPageId, recordId?: string | number): string {
  const detail = recordId === undefined ? undefined : primaryDetail(page)
  return phrViewUrl({
    kind: 'patient',
    patientId,
    page,
    ...(detail ? { detail: { module: detail, id: String(recordId) } } : {}),
  })
}

/**
 * Where a navigation request from the current view leads.
 *
 * `from` says which pane asked: a detail that links to a record it can show in its own place
 * (a visit's imaging study) keeps itself as the parent; the list just swaps the panel.
 * Records a page cannot show beside its list open on their own page instead.
 */
export function resolveNavTarget(view: PhrView, target: PhrNavTarget, from: 'list' | 'detail' = 'list'): PhrView {
  const section = SECTION_MODULES[target.id]
  if (section) {
    if (view.kind === 'section' && view.section === section) return view
    const search = section === 'imports' && view.kind === 'patient' ? `?patient_id=${view.patientId}` : ''
    return { kind: 'section', section, search }
  }
  if (view.kind !== 'patient') {
    return { kind: 'section', section: 'patients', search: '' }
  }
  const { patientId } = view
  if (isPhrPageId(target.id)) {
    return { kind: 'patient', patientId, page: target.id }
  }
  if (!isPhrDetailId(target.id)) {
    return view
  }
  const module = target.id
  if (target.instance === undefined || target.instance === '') {
    return { kind: 'patient', patientId, page: PHR_DETAIL_ROUTES[module].home }
  }
  const detail: PhrDetailRef = { module, id: target.instance }
  if (from === 'detail' && view.detail && !view.parent && segmentFor(CHILD_DETAILS[view.detail.module], module)) {
    return { kind: 'patient', patientId, page: view.page, parent: view.detail, detail }
  }
  if (pageHosts(view.page, module)) {
    return { kind: 'patient', patientId, page: view.page, detail }
  }
  return { kind: 'patient', patientId, page: PHR_DETAIL_ROUTES[module].home, detail }
}

/**
 * The path a legacy Miller hash (`#/labs/lab-panel-detail:5`) now lives at, or null when the
 * hash is not one. Each column is replayed as the navigation it stood for, so a stack such as
 * visits → visit → imaging study lands on the visit's linked study.
 */
export function legacyHashUrl(pathname: string, search: string, hash: string): string | null {
  if (!hash.startsWith('#/')) return null
  const base = parsePhrLocation(pathname, search)
  if (!base) return null

  let view: PhrView = base
  let from: 'list' | 'detail' = 'list'
  for (const segment of hash.slice(2).split('/').filter(Boolean)) {
    const [rawId, rawInstance] = segment.split(':')
    if (!rawId || !(isPhrPageId(rawId) || isPhrDetailId(rawId) || isPhrSectionModule(rawId))) continue
    const instance = rawInstance ? decodeSegment(rawInstance) : null
    view = resolveNavTarget(view, { id: rawId as PhrModuleId, ...(instance ? { instance } : {}) }, from)
    from = isPhrDetailId(rawId) ? 'detail' : 'list'
  }
  return phrViewUrl(view)
}
