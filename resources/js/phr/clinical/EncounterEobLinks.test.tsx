import '@testing-library/jest-dom'

import { fireEvent, render, screen, waitFor } from '@testing-library/react'

import EncounterEobLinks from './EncounterEobLinks'

const mockGet = jest.fn()
const mockPost = jest.fn()
const mockDelete = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: (...args: unknown[]) => mockGet(...args),
    post: (...args: unknown[]) => mockPost(...args),
    delete: (...args: unknown[]) => mockDelete(...args),
  },
}))

const EOB = {
  id: 91,
  claim_number: 'EXAMPLE-001',
  claim_type: 'medical',
  provider_name: 'Example Clinic',
  administrator: 'Example Plan',
  service_start: '2030-04-15',
  service_end: '2030-04-15',
  processed_date: '2030-04-20',
  source_document_id: 31,
  source_document_url: '/api/phr/patients/42/documents/31/file',
}

beforeEach(() => {
  mockGet.mockReset()
  mockPost.mockReset()
  mockDelete.mockReset()
})

it('shows linked EOB documents and exposes unlink only while editing', async () => {
  mockDelete.mockResolvedValue(null)
  const onChange = jest.fn()

  render(
    <EncounterEobLinks
      patientId={42}
      recordType="office-visits"
      recordId="18"
      serviceDate="2030-04-15"
      eobs={[EOB]}
      canManage
      onChange={onChange}
    />,
  )

  expect(screen.getByRole('link', { name: /view eob/i })).toHaveAttribute(
    'href',
    '/api/phr/patients/42/documents/31/file',
  )
  expect(screen.queryByRole('button', { name: /^unlink$/i })).not.toBeInTheDocument()

  fireEvent.click(screen.getByRole('button', { name: /edit eob links/i }))
  fireEvent.click(screen.getByRole('button', { name: /^unlink$/i }))

  await waitFor(() => expect(mockDelete).toHaveBeenCalledWith(
    '/api/phr/patients/42/office-visits/18/eobs/91',
    {},
  ))
  expect(onChange).toHaveBeenCalledWith([])
})

it('searches by date of service and links a candidate to a procedure', async () => {
  mockGet.mockResolvedValue({ eobs: [EOB], can_manage: true })
  mockPost.mockResolvedValue({ eob: EOB })
  const onChange = jest.fn()

  render(
    <EncounterEobLinks
      patientId={42}
      recordType="procedures"
      recordId="22"
      serviceDate="2030-04-15"
      eobs={[]}
      canManage
      onChange={onChange}
    />,
  )

  fireEvent.click(screen.getByRole('button', { name: /edit eob links/i }))
  fireEvent.click(screen.getByRole('button', { name: /search for eob/i }))

  await waitFor(() => expect(mockGet).toHaveBeenCalledWith(
    '/api/phr/patients/42/eobs?service_date=2030-04-15',
  ))
  fireEvent.click(await screen.findByRole('button', { name: /link eob/i }))

  await waitFor(() => expect(mockPost).toHaveBeenCalledWith(
    '/api/phr/patients/42/procedures/22/eobs/91',
    {},
  ))
  expect(onChange).toHaveBeenCalledWith([EOB])
})

it('keeps link editing unavailable to read-only viewers', () => {
  render(
    <EncounterEobLinks
      patientId={42}
      recordType="office-visits"
      recordId="18"
      serviceDate="2030-04-15"
      eobs={[EOB]}
      canManage={false}
      onChange={jest.fn()}
    />,
  )

  expect(screen.getByRole('link', { name: /view eob/i })).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: /edit eob links/i })).not.toBeInTheDocument()
})
