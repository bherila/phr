import type { UseMillerRouteResult } from '@/components/ui/miller'
import { useMillerRoute } from '@/components/ui/miller'

import { PHR_MODULE_IDS_SET, type PhrModuleId } from './phrModuleRegistry'

export function usePhrRoute(): UseMillerRouteResult<PhrModuleId> {
  return useMillerRoute<PhrModuleId>(PHR_MODULE_IDS_SET)
}
