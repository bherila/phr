import { fireEvent, render, screen, waitFor } from '@testing-library/react'

import { fetchWrapper } from '@/fetchWrapper'

import StorageSettingsPage from './StorageSettingsPage'

jest.mock('@/fetchWrapper', () => ({ fetchWrapper: { get: jest.fn(), put: jest.fn() } }))
const get = jest.mocked(fetchWrapper.get)
const put = jest.mocked(fetchWrapper.put)

beforeEach(() => {
  jest.resetAllMocks()
  get.mockResolvedValue({ global_limit_bytes: null, default_user_limit_bytes: 1048576, used_bytes: 2097152 })
})

test('zero blocks new uploads and a blank default saves unlimited', async () => {
  put.mockResolvedValue({ global_limit_bytes: 0, default_user_limit_bytes: null, used_bytes: 2097152 })
  render(<StorageSettingsPage />)
  const global = await screen.findByLabelText('Global limit (MiB)')
  fireEvent.change(global, { target: { value: '0' } })
  fireEvent.change(screen.getByLabelText('Default per-user limit (MiB)'), { target: { value: '' } })
  fireEvent.click(screen.getByRole('button', { name: 'Save storage limits' }))
  await screen.findByRole('status')
  expect(put).toHaveBeenCalledWith('/api/admin/storage', { global_limit_bytes: 0, default_user_limit_bytes: null })
})

test('a user override can inherit the default without enabling unlimited', async () => {
  render(<StorageSettingsPage />)
  await screen.findByLabelText('Global limit (MiB)')
  get.mockResolvedValueOnce({ users: [{ id: 12, name: 'Synthetic Account', email: 'user@example.test' }] })
  fireEvent.click(screen.getByRole('button', { name: 'Find accounts' }))
  const select = await screen.findByRole('button', { name: 'Synthetic Account (user@example.test)' })
  await waitFor(() => expect(select).not.toBeDisabled())
  get.mockResolvedValueOnce({ limit_bytes: 1048576, unlimited: false, effective_limit_bytes: 1048576, used_bytes: 1 })
  fireEvent.click(select)
  const limit = await screen.findByLabelText('User limit (MiB)')
  fireEvent.change(limit, { target: { value: '' } })
  put.mockResolvedValue({ limit_bytes: null, unlimited: false, effective_limit_bytes: 1048576, used_bytes: 1 })
  fireEvent.click(screen.getByRole('button', { name: 'Save user limit' }))
  await screen.findByRole('status')
  expect(put).toHaveBeenCalledWith('/api/admin/storage/users/12', { limit_bytes: null, unlimited: false })
})
