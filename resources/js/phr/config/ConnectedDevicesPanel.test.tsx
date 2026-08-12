import '@testing-library/jest-dom'

import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'

import ConnectedDevicesPanel from './ConnectedDevicesPanel'
import type { DeviceKey } from './devices'

const mockGet = jest.fn()
const mockDelete = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: (...args: unknown[]) => mockGet(...args),
    delete: (...args: unknown[]) => mockDelete(...args),
  },
}))

function device(overrides: Partial<DeviceKey> = {}): DeviceKey {
  return {
    id: 1,
    device_id: 'AB12-CD34-EF56',
    name: "Ben's MacBook Pro",
    created_at: '2026-06-01T12:00:00.000000Z',
    last_used_at: '2026-08-01T09:30:00.000000Z',
    expires_at: '2099-09-01T00:00:00.000000Z',
    revoked_at: null,
    ...overrides,
  }
}

beforeEach(() => {
  mockGet.mockReset()
  mockDelete.mockReset()
})

it('shows the empty state when no devices are paired', async () => {
  mockGet.mockResolvedValueOnce([])

  render(<ConnectedDevicesPanel />)

  expect(screen.getByRole('status')).toHaveTextContent('Loading connected devices…')
  expect(await screen.findByText('No devices are paired. Sign in from the Sinus Sentinel app to pair one.')).toBeInTheDocument()
})

it('lists devices with status badges, friendly dates, and a mono device id', async () => {
  mockGet.mockResolvedValueOnce([
    device(),
    device({ id: 2, name: 'Kitchen Mac mini', revoked_at: '2026-08-05T00:00:00.000000Z' }),
    device({ id: 3, name: 'Old work laptop', expires_at: '2026-01-01T00:00:00.000000Z' }),
  ])

  render(<ConnectedDevicesPanel />)

  const activeCard = (await screen.findByText("Ben's MacBook Pro")).closest('article')!
  expect(within(activeCard).getByText('AB12-CD34-EF56')).toBeInTheDocument()
  expect(within(activeCard).getByText('Active')).toBeInTheDocument()
  expect(within(activeCard).getByRole('button', { name: 'Revoke' })).toBeInTheDocument()

  const revokedCard = screen.getByText('Kitchen Mac mini').closest('article')!
  expect(within(revokedCard).getByText('Revoked')).toBeInTheDocument()
  expect(within(revokedCard).queryByRole('button', { name: 'Revoke' })).not.toBeInTheDocument()

  const expiredCard = screen.getByText('Old work laptop').closest('article')!
  expect(within(expiredCard).getByText('Expired')).toBeInTheDocument()
  expect(within(expiredCard).queryByRole('button', { name: 'Revoke' })).not.toBeInTheDocument()
})

it('revokes a device after confirming and refreshes the list', async () => {
  mockGet
    .mockResolvedValueOnce([device()])
    .mockResolvedValueOnce([device({ revoked_at: '2026-08-11T00:00:00.000000Z' })])
  mockDelete.mockResolvedValue({ success: true })

  render(<ConnectedDevicesPanel />)

  const card = (await screen.findByText("Ben's MacBook Pro")).closest('article')!
  fireEvent.click(within(card).getByRole('button', { name: 'Revoke' }))

  const dialog = await screen.findByRole('alertdialog')
  expect(within(dialog).getByText("Revoke Ben's MacBook Pro?")).toBeInTheDocument()
  fireEvent.click(within(dialog).getByRole('button', { name: 'Revoke' }))

  await waitFor(() => expect(mockDelete).toHaveBeenCalledWith('/api/user/devices/1', {}))
  await waitFor(() => expect(mockGet).toHaveBeenCalledTimes(2))
  expect(await screen.findByText('Revoked')).toBeInTheDocument()
})

it('does not revoke when the confirmation is cancelled', async () => {
  mockGet.mockResolvedValueOnce([device()])

  render(<ConnectedDevicesPanel />)

  const card = (await screen.findByText("Ben's MacBook Pro")).closest('article')!
  fireEvent.click(within(card).getByRole('button', { name: 'Revoke' }))

  const dialog = await screen.findByRole('alertdialog')
  fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }))

  await waitFor(() => expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument())
  expect(mockDelete).not.toHaveBeenCalled()
})

it('announces load errors and allows retrying', async () => {
  mockGet.mockRejectedValueOnce('Unable to load devices.').mockResolvedValueOnce([])

  render(<ConnectedDevicesPanel />)

  expect(await screen.findByRole('alert')).toHaveTextContent('Unable to load devices.')
  fireEvent.click(screen.getByRole('button', { name: 'Retry' }))

  expect(await screen.findByText('No devices are paired. Sign in from the Sinus Sentinel app to pair one.')).toBeInTheDocument()
})
