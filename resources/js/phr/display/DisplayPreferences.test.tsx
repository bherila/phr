import '@testing-library/jest-dom'

import { fireEvent, render, screen, waitFor } from '@testing-library/react'

import DisplayPreferencesPanel, { DisplayPreferencesProvider } from './DisplayPreferences'

const mockGet = jest.fn()
const mockPut = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: (...args: unknown[]) => mockGet(...args),
    put: (...args: unknown[]) => mockPut(...args),
  },
}))

function renderPanel() {
  return render(<DisplayPreferencesProvider><DisplayPreferencesPanel /></DisplayPreferencesProvider>)
}

beforeEach(() => {
  mockGet.mockReset()
  mockPut.mockReset()
})

describe('DisplayPreferencesPanel', () => {
  it('loads the saved preference', async () => {
    mockGet.mockResolvedValue({ force_lowercase: true })
    renderPanel()

    await waitFor(() => expect(screen.getByRole('switch', { name: 'Force lowercase display' })).toBeChecked())
    expect(mockGet).toHaveBeenCalledWith('/api/user/display-preferences')
  })

  it('saves the toggle through PUT', async () => {
    mockGet.mockResolvedValue({ force_lowercase: false })
    mockPut.mockResolvedValue({ force_lowercase: true })
    renderPanel()

    const toggle = await screen.findByRole('switch', { name: 'Force lowercase display' })
    expect(toggle).not.toBeChecked()
    fireEvent.click(toggle)

    await waitFor(() => expect(mockPut).toHaveBeenCalledWith('/api/user/display-preferences', { force_lowercase: true }))
    await waitFor(() => expect(screen.getByRole('switch', { name: 'Force lowercase display' })).toBeChecked())
  })

  it('shows an error and keeps the old value when saving fails', async () => {
    mockGet.mockResolvedValue({ force_lowercase: false })
    mockPut.mockRejectedValue('Could not save')
    renderPanel()

    fireEvent.click(await screen.findByRole('switch', { name: 'Force lowercase display' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Could not save')
    expect(screen.getByRole('switch', { name: 'Force lowercase display' })).not.toBeChecked()
  })
})
