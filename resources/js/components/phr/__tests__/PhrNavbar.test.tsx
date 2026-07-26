import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'

describe('PhrNavbar', () => {
  let fetchSpy: jest.SpiedFunction<typeof fetch>

  beforeEach(() => {
    fetchSpy = jest.spyOn(globalThis, 'fetch').mockImplementation(async (input: RequestInfo | URL) => {
      const url = String(input)

      if (url.includes('/api/phr/patients')) {
        const payload = {
          patients: [
            {
              id: 1,
              owner_user_id: 1,
              display_name: 'Alice',
              relationship: 'self',
              birth_date: null,
              sex_at_birth: null,
              notes: null,
              archived_at: null,
              created_at: null,
              updated_at: null,
              access_level: 'owner',
              can_manage: true,
              can_share: true,
              access_grants: [],
            },
            {
              id: 2,
              owner_user_id: 2,
              display_name: 'Bob',
              relationship: 'spouse',
              birth_date: null,
              sex_at_birth: null,
              notes: null,
              archived_at: null,
              created_at: null,
              updated_at: null,
              access_level: 'viewer',
              can_manage: false,
              can_share: false,
              access_grants: [],
            },
          ],
        }

        return {
          ok: true,
          text: async () => JSON.stringify(payload),
        } as Response
      }

      return { ok: true, text: async () => '{}' } as Response
    })
  })

  afterEach(() => {
    fetchSpy.mockRestore()
    jest.clearAllMocks()
  })

  it('renders PHR branding and section links', async () => {
    const PhrNavbar = (await import('@/components/phr/PhrNavbar')).default

    await act(async () => {
      render(<PhrNavbar activeSection="patients" />)
    })

    expect(screen.getByLabelText('PHR section')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Patients' })).toHaveAttribute('aria-current', 'page')
    expect(screen.getByRole('link', { name: 'Manage Patients' })).toBeInTheDocument()
  })

  it('loads patient combobox when patientId is provided', async () => {
    const PhrNavbar = (await import('@/components/phr/PhrNavbar')).default

    await act(async () => {
      render(<PhrNavbar patientId={1} />)
    })

    await waitFor(() => {
      expect(screen.getByRole('combobox')).toBeInTheDocument()
    })
  })

  it('patient swap notifies the shell without assigning a new document URL', async () => {
    const PhrNavbar = (await import('@/components/phr/PhrNavbar')).default
    const onPatientChange = jest.fn()

    await act(async () => {
      render(<PhrNavbar patientId={1} onPatientChange={onPatientChange} />)
    })

    await waitFor(() => {
      expect(screen.getByRole('combobox')).toBeInTheDocument()
    })

    fireEvent.focus(screen.getByRole('combobox'))
    fireEvent.click(await screen.findByText('Bob'))

    expect(onPatientChange).toHaveBeenCalledWith(2)
  })

  it('section links can be handled by the client shell', async () => {
    const PhrNavbar = (await import('@/components/phr/PhrNavbar')).default
    const onSectionChange = jest.fn()

    await act(async () => {
      render(<PhrNavbar activeSection="patients" onSectionChange={onSectionChange} />)
    })

    fireEvent.click(screen.getByRole('link', { name: 'Imports' }))

    expect(onSectionChange).toHaveBeenCalledWith('imports')
  })
})
