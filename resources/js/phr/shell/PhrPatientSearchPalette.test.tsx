import '@testing-library/jest-dom'

import { fireEvent, render, screen, waitFor } from '@testing-library/react'

import { PhrPatientSearchPalette } from './PhrPatientSearchPalette'

const mockGet = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: { get: (...args: unknown[]) => mockGet(...args) },
}))

beforeEach(() => {
  mockGet.mockReset()
})

it('searches the selected patient and drills into the selected record', async () => {
  mockGet.mockResolvedValue({
    results: [{
      id: 73,
      category: 'Imaging',
      label: 'Example scan',
      description: 'CT',
      date: '2030-04-15',
      module_id: 'imaging-study-detail',
    }],
  })
  const onClose = jest.fn()
  const onDrill = jest.fn()

  render(<PhrPatientSearchPalette open patientId={42} onClose={onClose} onDrill={onDrill} />)

  fireEvent.change(screen.getByPlaceholderText(/search visits/i), { target: { value: 'scan' } })

  await waitFor(() => expect(mockGet).toHaveBeenCalledWith('/api/phr/patients/42/search?q=scan'))
  fireEvent.click(await screen.findByRole('option', { name: /example scan/i }))

  expect(onClose).toHaveBeenCalled()
  expect(onDrill).toHaveBeenCalledWith({ id: 'imaging-study-detail', instance: '73' })
})

describe('prescription fills', () => {
  const fillResponse = {
    results: [{
      id: 9,
      category: 'Fill',
      label: 'Atorvastatin Calcium 20 mg tablet',
      description: '26.10.28 · Example Pharmacy · Qty 90 tablet',
      date: '2026-10-28',
      module_id: 'medication-fills',
    }],
  }

  it('shows title and subtitle and opens the fills page', async () => {
    mockGet.mockResolvedValue(fillResponse)
    const onDrill = jest.fn()
    render(<PhrPatientSearchPalette open patientId={42} onClose={jest.fn()} onDrill={onDrill} />)

    fireEvent.change(screen.getByPlaceholderText(/search visits/i), { target: { value: 'atorva' } })
    const option = await screen.findByRole('option', { name: /atorvastatin calcium 20 mg tablet/i })
    expect(option).toHaveTextContent('26.10.28 · Example Pharmacy · Qty 90 tablet')
    expect(option).not.toHaveTextContent('2026-10-28')
    expect(option.querySelector('.lowercase')).toBeNull()

    fireEvent.click(option)
    expect(onDrill).toHaveBeenCalledWith({ id: 'medication-fills', instance: '9' })
  })
})
