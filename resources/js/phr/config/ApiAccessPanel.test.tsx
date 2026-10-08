import '@testing-library/jest-dom'

import { render, screen } from '@testing-library/react'

import ApiAccessPanel, { connectorLinks } from './ApiAccessPanel'

const index = {
  data: {
    scopes: [{ id: 'patients:read', description: 'List and read patients you can access' }],
    token_lifetimes: ['PT4H', 'P30D'],
    issue_token_href: '/account/api-credentials/tokens',
    register_app_href: '/account/api-credentials/apps',
    tokens: [],
    apps: [],
  },
}
const mockFetch = jest.fn()
const originalFetch = globalThis.fetch

beforeEach(() => {
  mockFetch.mockReset()
  mockFetch.mockResolvedValue({ ok: true, status: 200, json: async () => index })
  globalThis.fetch = mockFetch as unknown as typeof fetch
})

afterEach(() => {
  globalThis.fetch = originalFetch
})

it('derives every connector URL from the installation origin', () => {
  expect(connectorLinks('https://phr.example.test').map((link) => link.url)).toEqual([
    'https://phr.example.test/api/openapi.json',
    'https://phr.example.test/api/v1',
    'https://phr.example.test/oauth/authorize',
    'https://phr.example.test/oauth/token',
    'https://phr.example.test/api/v1/mcp',
  ])
})

it('loads the credential index and offers the server-provided scopes', async () => {
  render(<ApiAccessPanel />)

  expect(await screen.findAllByText('List and read patients you can access')).not.toHaveLength(0)
  expect(mockFetch).toHaveBeenCalledWith('/account/api-credentials', expect.objectContaining({ credentials: 'same-origin' }))
})
