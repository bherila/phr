import { z } from 'zod'

import { fetchWrapper } from '@/fetchWrapper'

const ConnectedAppResourceSchema = z.object({
  resource: z.string(),
  scopes: z.array(z.string()),
  granted_at: z.string().nullable(),
}).strict()

export const ConnectedAppSchema = z.object({
  client_id: z.string(),
  name: z.string(),
  resources: z.array(ConnectedAppResourceSchema),
}).strict()

export const ConnectedAppsPageSchema = z.object({
  data: z.array(ConnectedAppSchema),
  meta: z.object({
    current_page: z.number().int().positive(),
    last_page: z.number().int().positive(),
    per_page: z.number().int().positive(),
    total: z.number().int().nonnegative(),
  }).strict(),
}).strict()

export type ConnectedApp = z.infer<typeof ConnectedAppSchema>
export type ConnectedAppsResult = z.infer<typeof ConnectedAppsPageSchema>

export async function listConnectedApps(page: number): Promise<ConnectedAppsResult> {
  const response: unknown = await fetchWrapper.get(`/api/user/connected-apps?page=${page}`)
  return ConnectedAppsPageSchema.parse(response)
}

export async function revokeConnectedApp(clientId: string): Promise<void> {
  await fetchWrapper.delete(`/api/user/connected-apps/${encodeURIComponent(clientId)}`, {})
}
