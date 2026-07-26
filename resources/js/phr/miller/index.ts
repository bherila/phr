/**
 * Detail pane API convention:
 * - Route: GET /api/phr/patients/{patientId}/{module}/{id}
 *   (for example: /api/phr/patients/42/labs/7)
 * - Auth: enforce PhrPatientAccessService::accessiblePatient gating, same as list endpoints
 * - 404: when the record does not exist, or exists but does not belong to the selected patient
 *   (detail panes treat both cases uniformly via <PhrNotFoundColumn />)
 * - Response: validate with a zod schema sibling to the module's list-response schema in `types.ts`
 */

export { PhrDockHomeView } from './PhrDockHomeView'
export { PhrMillerShell } from './PhrMillerShell'
export type {
  PhrListPageProps,
  PhrModuleCategory,
  PhrModuleDefinition,
  PhrModuleId,
  PhrModuleMeta,
  PhrRegistryEntry,
  PhrRenderProps,
  PhrShellState,
} from './phrModuleRegistry'
export { getPhrModuleMeta, PHR_DETAIL_MODULES, PHR_LIST_MODULES, PHR_MODULE_IDS, PHR_MODULE_IDS_SET, phrModuleRegistry } from './phrModuleRegistry'
export { PhrNotFoundColumn } from './PhrNotFoundColumn'
export { usePhrDockPrefs } from './usePhrDockPrefs'
export { usePhrRoute } from './usePhrRoute'
