import '@testing-library/jest-dom'

import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'

import type { ConnectedApp, ConnectedAppsResult } from './connectedApps'
import ConnectedAppsPage from './ConnectedAppsPage'

const mockGet = jest.fn()
const mockDelete = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: (...args: unknown[]) => mockGet(...args),
    delete: (...args: unknown[]) => mockDelete(...args),
  },
}))

function app(overrides: Partial<ConnectedApp> = {}): ConnectedApp {
  return {
    client_id: 'app-1',
    name: 'My health assistant',
    resources: [
      { resource: 'https://phr.example/api/v1/mcp', scopes: ['mcp:use', 'patients:read'], granted_at: '2026-06-01T12:00:00Z' },
      { resource: 'https://phr.example/api/v1', scopes: ['identity:read'], granted_at: null },
    ],
    ...overrides,
  }
}

function page(data: ConnectedApp[] = [app()], meta: Partial<ConnectedAppsResult['meta']> = {}): ConnectedAppsResult {
  return { data, meta: { current_page: 1, last_page: 1, per_page: 20, total: data.length, ...meta } }
}

async function openConfirmation(): Promise<HTMLElement> {
  const card = (await screen.findByRole('heading', { name: 'My health assistant', level: 2 })).closest('article')!
  fireEvent.click(within(card).getByRole('button', { name: 'Revoke access' }))
  return screen.findByRole('alertdialog')
}

beforeEach(() => {
  mockGet.mockReset()
  mockDelete.mockReset()
})

it('announces loading and shows a useful empty state', async () => {
  mockGet.mockResolvedValueOnce(page([]))
  render(<ConnectedAppsPage />)
  expect(screen.getByRole('status')).toHaveTextContent('Loading connected apps…')
  expect(await screen.findByText('No apps are connected. Apps appear here after you approve their access to PHR.')).toBeInTheDocument()
  expect(screen.queryByRole('navigation')).not.toBeInTheDocument()
})

it('shows each resource with its permissions and grant date', async () => {
  mockGet.mockResolvedValueOnce(page())
  render(<ConnectedAppsPage />)
  const card = (await screen.findByRole('heading', { name: 'My health assistant', level: 2 })).closest('article')!
  expect(within(card).getByText('MCP')).toBeInTheDocument()
  expect(within(card).getByText('REST API')).toBeInTheDocument()
  for (const scope of ['mcp:use', 'patients:read', 'identity:read']) expect(within(card).getByText(scope)).toBeInTheDocument()
  expect(within(card).getByText('https://phr.example/api/v1/mcp')).toBeInTheDocument()
  expect(within(card).getAllByText('Approved')).toHaveLength(2)
  expect(within(card).getByText('Unknown')).toBeInTheDocument()
  expect(within(card).queryByRole('link')).not.toBeInTheDocument()
})

it('renders untrusted app and resource metadata only as text', async () => {
  const maliciousName = '<img src=x onerror=alert(1)>'
  const resource = 'javascript:alert(1)'
  mockGet.mockResolvedValueOnce(page([app({ name: maliciousName, resources: [{ resource, scopes: ['<script>alert(1)</script>'], granted_at: 'invalid' }] })]))
  const { container } = render(<ConnectedAppsPage />)
  expect(await screen.findByRole('heading', { name: maliciousName, level: 2 })).toBeInTheDocument()
  expect(screen.getByText(resource)).toBeInTheDocument()
  expect(screen.getByText('<script>alert(1)</script>')).toBeInTheDocument()
  expect(screen.getByText('Unknown')).toBeInTheDocument()
  expect(container.querySelector('img, script, a')).toBeNull()
})

it('allows retrying a failed load without presenting a false empty state', async () => {
  mockGet.mockRejectedValueOnce('Please try again.').mockResolvedValueOnce(page([]))
  render(<ConnectedAppsPage />)
  expect(await screen.findByRole('alert')).toHaveTextContent('Could not load connected apps. Please try again.')
  expect(screen.queryByText(/No apps are connected/)).not.toBeInTheDocument()
  fireEvent.click(screen.getByRole('button', { name: 'Retry' }))
  expect(await screen.findByText(/No apps are connected/)).toBeInTheDocument()
  expect(mockGet).toHaveBeenCalledTimes(2)
})

it('paginates between apps and disables navigation while loading', async () => {
  let finishNext!: (value: ConnectedAppsResult) => void
  mockGet.mockResolvedValueOnce(page([app()], { last_page: 2, total: 21 }))
    .mockImplementationOnce(() => new Promise<ConnectedAppsResult>((resolve) => { finishNext = resolve }))
    .mockResolvedValueOnce(page([app()], { last_page: 2, total: 21 }))
  render(<ConnectedAppsPage />)
  await screen.findByText('Page 1 of 2')
  expect(screen.getByRole('button', { name: 'Previous' })).toBeDisabled()
  fireEvent.click(screen.getByRole('button', { name: 'Next' }))
  expect(screen.getByRole('button', { name: 'Next' })).toBeDisabled()
  expect(screen.getByRole('button', { name: 'Revoke access' })).toBeDisabled()
  await act(async () => finishNext(page([app({ client_id: 'app-2', name: 'Second app' })], { current_page: 2, last_page: 2, total: 21 })))
  expect(await screen.findByText('Page 2 of 2')).toBeInTheDocument()
  expect(screen.queryByRole('heading', { name: 'My health assistant' })).not.toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Next' })).toBeDisabled()
  fireEvent.click(screen.getByRole('button', { name: 'Previous' }))
  expect(await screen.findByText('Page 1 of 2')).toBeInTheDocument()
  expect(mockGet.mock.calls.map((call: unknown[]) => call[0])).toEqual([
    '/api/user/connected-apps?page=1', '/api/user/connected-apps?page=2', '/api/user/connected-apps?page=1',
  ])
})

it('cancels without revoking or changing the app', async () => {
  mockGet.mockResolvedValueOnce(page())
  render(<ConnectedAppsPage />)
  const dialog = await openConfirmation()
  expect(within(dialog).getByText('Revoke access for My health assistant?')).toBeInTheDocument()
  expect(within(dialog).getByText(/across all resources/)).toHaveTextContent('approve access again')
  fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }))
  await waitFor(() => expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument())
  expect(mockDelete).not.toHaveBeenCalled()
  expect(screen.getByRole('heading', { name: 'My health assistant' })).toBeInTheDocument()
})

it('confirms revocation, prevents repeat actions and refreshes the apps', async () => {
  let finishRevoke!: () => void
  mockGet.mockResolvedValueOnce(page()).mockResolvedValueOnce(page([]))
  mockDelete.mockImplementationOnce(() => new Promise<void>((resolve) => { finishRevoke = resolve }))
  render(<ConnectedAppsPage />)
  const dialog = await openConfirmation()
  fireEvent.click(within(dialog).getByRole('button', { name: 'Revoke access' }))
  expect(await screen.findByRole('button', { name: 'Revoking…' })).toBeDisabled()
  await act(async () => finishRevoke())
  expect(await screen.findByText(/No apps are connected/)).toBeInTheDocument()
  expect(screen.getByRole('status')).toHaveTextContent('Access revoked for My health assistant.')
  expect(mockDelete).toHaveBeenCalledTimes(1)
  expect(mockDelete).toHaveBeenCalledWith('/api/user/connected-apps/app-1', {})
  expect(mockGet).toHaveBeenCalledTimes(2)
})

it('retains the app after a failed revocation and supports trying again', async () => {
  mockGet.mockResolvedValueOnce(page()).mockResolvedValueOnce(page([]))
  mockDelete.mockRejectedValueOnce('Server unavailable.').mockResolvedValueOnce(null)
  render(<ConnectedAppsPage />)
  let dialog = await openConfirmation()
  fireEvent.click(within(dialog).getByRole('button', { name: 'Revoke access' }))
  expect(await screen.findByRole('alert')).toHaveTextContent('Could not revoke access. Server unavailable.')
  expect(screen.getByRole('heading', { name: 'My health assistant' })).toBeInTheDocument()
  expect(mockGet).toHaveBeenCalledTimes(1)
  dialog = await openConfirmation()
  fireEvent.click(within(dialog).getByRole('button', { name: 'Revoke access' }))
  expect(await screen.findByText(/No apps are connected/)).toBeInTheDocument()
  expect(screen.queryByRole('alert')).not.toBeInTheDocument()
})

it('returns to the previous page after revoking the last app on a later page', async () => {
  mockGet.mockResolvedValueOnce(page([app({ name: 'First page app' })], { last_page: 2, total: 21 }))
    .mockResolvedValueOnce(page([app()], { current_page: 2, last_page: 2, total: 21 }))
    .mockResolvedValueOnce(page([app({ name: 'First page app' })], { total: 20 }))
  mockDelete.mockResolvedValueOnce(null)
  render(<ConnectedAppsPage />)
  await screen.findByText('Page 1 of 2')
  fireEvent.click(screen.getByRole('button', { name: 'Next' }))
  const dialog = await openConfirmation()
  fireEvent.click(within(dialog).getByRole('button', { name: 'Revoke access' }))
  expect(await screen.findByRole('heading', { name: 'First page app' })).toBeInTheDocument()
  expect(mockGet).toHaveBeenLastCalledWith('/api/user/connected-apps?page=1')
  expect(screen.queryByRole('navigation')).not.toBeInTheDocument()
})


it('recovers when another window removes the final page before navigation completes', async () => {
  mockGet.mockResolvedValueOnce(page([app()], { last_page: 2, total: 21 }))
    .mockResolvedValueOnce(page([], { current_page: 2, last_page: 1, total: 20 }))
    .mockResolvedValueOnce(page([app({ name: 'Remaining app' })], { total: 20 }))
  render(<ConnectedAppsPage />)
  await screen.findByText('Page 1 of 2')
  fireEvent.click(screen.getByRole('button', { name: 'Next' }))
  expect(await screen.findByRole('heading', { name: 'Remaining app' })).toBeInTheDocument()
  expect(mockGet).toHaveBeenLastCalledWith('/api/user/connected-apps?page=1')
  expect(screen.queryByText(/No apps are connected/)).not.toBeInTheDocument()
  expect(screen.queryByRole('navigation')).not.toBeInTheDocument()
})
