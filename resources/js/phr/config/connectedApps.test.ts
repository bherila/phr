import { ConnectedAppsPageSchema, listConnectedApps, revokeConnectedApp } from './connectedApps'

const mockGet = jest.fn()
const mockDelete = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: (...args: unknown[]) => mockGet(...args),
    delete: (...args: unknown[]) => mockDelete(...args),
  },
}))

function response() {
  return {
    data: [{ client_id: 'app-1', name: 'My app', resources: [{ resource: 'https://phr.example/api/v1/mcp', scopes: ['mcp:use'], granted_at: null }] }],
    meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
  }
}

beforeEach(() => {
  mockGet.mockReset()
  mockDelete.mockReset()
})

it('validates the paginated public shape and requests the selected page', async () => {
  mockGet.mockResolvedValueOnce(response())
  await expect(listConnectedApps(2)).resolves.toEqual(response())
  expect(mockGet).toHaveBeenCalledWith('/api/user/connected-apps?page=2')
})

it('rejects leaked credential fields rather than rendering them', () => {
  const raw = response()
  expect(() => ConnectedAppsPageSchema.parse({ ...raw, data: [{ ...raw.data[0], client_secret: 'must-not-leak' }] })).toThrow()
})

it('rejects incomplete resources and invalid pagination', () => {
  const raw = response()
  expect(() => ConnectedAppsPageSchema.parse({ ...raw, meta: { ...raw.meta, current_page: 0 } })).toThrow()
  expect(() => ConnectedAppsPageSchema.parse({ ...raw, data: [{ ...raw.data[0], resources: [{ resource: 'x' }] }] })).toThrow()
})

it('encodes the client id as one path segment and accepts an empty revoke response', async () => {
  mockDelete.mockResolvedValueOnce(null)
  await expect(revokeConnectedApp('app/with ?#')).resolves.toBeUndefined()
  expect(mockDelete).toHaveBeenCalledWith('/api/user/connected-apps/app%2Fwith%20%3F%23', {})
})
