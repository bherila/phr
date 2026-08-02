import '@testing-library/jest-dom'

import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'

import type { AiConfiguration } from './aiPrefs'
import AiProviderSettingsPage from './AiProviderSettingsPage'

const mockGet = jest.fn()
const mockPost = jest.fn()
const mockPut = jest.fn()
const mockDelete = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: (...args: unknown[]) => mockGet(...args),
    post: (...args: unknown[]) => mockPost(...args),
    put: (...args: unknown[]) => mockPut(...args),
    delete: (...args: unknown[]) => mockDelete(...args),
  },
}))

function configuration(overrides: Partial<AiConfiguration> = {}): AiConfiguration {
  return {
    id: 10,
    name: 'Gemini primary',
    provider: 'gemini',
    model: 'gemini-2.5-flash',
    masked_key: '••••alue',
    has_api_key: true,
    has_session_token: false,
    region: null,
    is_active: true,
    is_expired: false,
    expires_at: null,
    has_invalid_api_key: false,
    api_key_invalid_at: null,
    created_at: '2026-07-01T00:00:00Z',
    usage: {
      this_month: { input_tokens: 10, output_tokens: 20 },
      total: { input_tokens: 100, output_tokens: 200 },
    },
    ...overrides,
  }
}

beforeEach(() => {
  mockGet.mockReset()
  mockPost.mockReset()
  mockPut.mockReset()
  mockDelete.mockReset()
})

it('loads redacted configuration state and updates without sending stored secrets', async () => {
  const original = configuration()
  const updated = configuration({ name: 'Renamed primary' })
  mockGet.mockResolvedValueOnce([original]).mockResolvedValueOnce([updated])
  mockPost.mockResolvedValue({ models: ['gemini-2.5-flash', 'gemini-2.5-pro'] })
  mockPut.mockResolvedValue(updated)

  render(<AiProviderSettingsPage />)

  expect(await screen.findByText('Gemini primary')).toBeInTheDocument()
  expect(screen.getByText('••••alue')).toBeInTheDocument()
  expect(document.body.textContent).not.toContain('stored-unit-test-key')

  fireEvent.click(screen.getByRole('button', { name: 'Edit' }))
  const keyInput = screen.getByLabelText(/^API key/)
  expect(keyInput).toHaveValue('')
  expect(screen.getByText(/enter a value only to replace it/i)).toBeInTheDocument()

  fireEvent.click(screen.getByRole('button', { name: 'Load models' }))
  await waitFor(() => {
    expect(mockPost).toHaveBeenCalledWith('/api/user/ai-prefs/models', {
      provider: 'gemini',
      config_id: 10,
    })
  })

  fireEvent.change(screen.getByLabelText('Configuration name'), { target: { value: 'Renamed primary' } })
  fireEvent.change(screen.getByLabelText('Model'), { target: { value: 'gemini-2.5-pro' } })
  fireEvent.click(screen.getByRole('button', { name: 'Save configuration' }))

  await waitFor(() => expect(mockPut).toHaveBeenCalledTimes(1))
  const payload = mockPut.mock.calls[0]?.[1] as Record<string, unknown>
  expect(payload).not.toHaveProperty('api_key')
  expect(payload).not.toHaveProperty('session_token')
  expect(payload).toMatchObject({ name: 'Renamed primary', model: 'gemini-2.5-pro' })
  expect(await screen.findByText('Renamed primary was updated.')).toBeInTheDocument()
})

it('resets provider-specific fields, loads Bedrock models, and creates a configuration', async () => {
  const created = configuration({
    id: 20,
    name: 'Bedrock backup',
    provider: 'bedrock',
    model: 'us.anthropic.test-model',
    region: 'us-west-2',
    is_active: true,
    has_session_token: true,
  })
  mockGet.mockResolvedValueOnce([]).mockResolvedValueOnce([created])
  mockPost.mockImplementation((url: string) => {
    if (url.endsWith('/models')) return Promise.resolve({ models: ['us.anthropic.test-model'] })
    return Promise.resolve(created)
  })

  render(<AiProviderSettingsPage />)
  await screen.findByText('No AI provider configured')
  fireEvent.click(screen.getByRole('button', { name: 'Add configuration' }))

  fireEvent.change(screen.getByLabelText(/^API key/), { target: { value: 'discarded-placeholder' } })
  fireEvent.change(screen.getByLabelText('Provider'), { target: { value: 'bedrock' } })
  expect(screen.queryByLabelText(/^API key/)).not.toBeInTheDocument()
  expect(screen.getByLabelText('Bedrock bearer token')).toHaveValue('')
  expect(screen.getByLabelText(/STS session token/)).toBeInTheDocument()
  expect(screen.getByLabelText('AWS region')).toHaveValue('us-east-1')

  fireEvent.change(screen.getByLabelText('Configuration name'), { target: { value: 'Bedrock backup' } })
  fireEvent.change(screen.getByLabelText('Bedrock bearer token'), { target: { value: 'not-a-real-bearer-token' } })
  fireEvent.change(screen.getByLabelText('AWS region'), { target: { value: 'us-west-2' } })
  fireEvent.change(screen.getByLabelText(/STS session token/), { target: { value: 'not-a-real-session-token' } })
  fireEvent.click(screen.getByRole('button', { name: 'Load models' }))

  await screen.findByRole('option', { name: 'us.anthropic.test-model' })
  fireEvent.click(screen.getByRole('button', { name: 'Save configuration' }))

  await waitFor(() => {
    expect(mockPost).toHaveBeenCalledWith('/api/user/ai-prefs', expect.objectContaining({
      provider: 'bedrock',
      region: 'us-west-2',
      model: 'us.anthropic.test-model',
    }))
  })
  expect(await screen.findByText('Bedrock backup was added.')).toBeInTheDocument()
})

it('explicitly clears a stored Bedrock session token without redisplaying it', async () => {
  const stored = configuration({
    name: 'Bedrock primary',
    provider: 'bedrock',
    model: 'us.anthropic.test-model',
    region: 'us-west-2',
    has_session_token: true,
  })
  const cleared = configuration({ ...stored, has_session_token: false })
  mockGet.mockResolvedValueOnce([stored]).mockResolvedValueOnce([cleared])
  mockPut.mockResolvedValue(cleared)

  render(<AiProviderSettingsPage />)
  await screen.findByText('Bedrock primary')
  fireEvent.click(screen.getByRole('button', { name: 'Edit' }))

  const sessionTokenInput = screen.getByLabelText(/STS session token/)
  expect(sessionTokenInput).toHaveValue('')
  fireEvent.click(screen.getByRole('checkbox', { name: 'Remove stored session token' }))
  expect(sessionTokenInput).toBeDisabled()
  fireEvent.click(screen.getByRole('button', { name: 'Save configuration' }))

  await waitFor(() => expect(mockPut).toHaveBeenCalledTimes(1))
  const payload = mockPut.mock.calls[0]?.[1] as Record<string, unknown>
  expect(payload).toMatchObject({ clear_session_token: true })
  expect(payload).not.toHaveProperty('session_token')
})

it('activates and removes configurations while disabling invalid or expired choices', async () => {
  const active = configuration()
  const backup = configuration({ id: 11, name: 'Anthropic backup', provider: 'anthropic', model: 'claude-test', is_active: false })
  const invalid = configuration({ id: 12, name: 'Invalid key', is_active: false, has_invalid_api_key: true })
  const expired = configuration({ id: 13, name: 'Expired key', is_active: false, is_expired: true })
  mockGet
    .mockResolvedValueOnce([active, backup, invalid, expired])
    .mockResolvedValueOnce([configuration({ is_active: false }), configuration({ ...backup, is_active: true }), invalid, expired])
    .mockResolvedValueOnce([configuration({ is_active: false }), invalid, expired])
  mockPost.mockResolvedValue(configuration({ ...backup, is_active: true }))
  mockDelete.mockResolvedValue({ success: true })

  render(<AiProviderSettingsPage />)
  const backupCard = (await screen.findByText('Anthropic backup')).closest('article')!
  fireEvent.click(within(backupCard).getByRole('button', { name: 'Make active' }))
  expect(await screen.findByText('Anthropic backup is now active.')).toBeInTheDocument()

  const invalidCard = screen.getByText('Invalid key').closest('article')!
  const expiredCard = screen.getByText('Expired key').closest('article')!
  expect(within(invalidCard).getByRole('button', { name: 'Make active' })).toBeDisabled()
  expect(within(expiredCard).getByRole('button', { name: 'Make active' })).toBeDisabled()

  const refreshedBackupCard = screen.getByText('Anthropic backup').closest('article')!
  fireEvent.click(within(refreshedBackupCard).getByRole('button', { name: 'Remove' }))
  fireEvent.click(within(refreshedBackupCard).getByRole('button', { name: 'Remove' }))
  await waitFor(() => expect(mockDelete).toHaveBeenCalledWith('/api/user/ai-prefs/11', {}))
  expect(await screen.findByText('Anthropic backup was removed.')).toBeInTheDocument()
})

it('announces loading and request errors and allows retrying', async () => {
  mockGet.mockRejectedValueOnce('Unable to load configurations.').mockResolvedValueOnce([])

  render(<AiProviderSettingsPage />)
  expect(screen.getByRole('status')).toHaveTextContent('Loading AI configurations…')
  expect(await screen.findByRole('alert')).toHaveTextContent('Unable to load configurations.')

  fireEvent.click(screen.getByRole('button', { name: 'Retry' }))
  expect(await screen.findByText('No AI provider configured')).toBeInTheDocument()
})
