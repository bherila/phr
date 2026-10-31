import { act, fireEvent, render, screen } from '@testing-library/react'

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
    expect(screen.getByRole('link', { name: 'Data Hub' })).toHaveAttribute('href', '/phr/data-hub')
  })

  it('marks Patients as the current location, not the current page, inside a patient record', async () => {
    const PhrNavbar = (await import('@/components/phr/PhrNavbar')).default

    await act(async () => {
      render(<PhrNavbar inPatient />)
    })

    // The sidebar item for the open record page carries aria-current="page".
    expect(screen.getByRole('link', { name: 'Patients' })).toHaveAttribute('aria-current', 'true')
    expect(screen.queryByRole('combobox')).not.toBeInTheDocument()
  })

  it('opens search from the bar', async () => {
    const PhrNavbar = (await import('@/components/phr/PhrNavbar')).default
    const onSearch = jest.fn()

    await act(async () => {
      render(<PhrNavbar inPatient searchLabel="Search this patient…" onSearch={onSearch} />)
    })

    fireEvent.click(screen.getByRole('button', { name: /Search this patient/ }))
    expect(onSearch).toHaveBeenCalled()
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

  it('has no back control and links only within PHR', async () => {
    const PhrNavbar = (await import('@/components/phr/PhrNavbar')).default

    await act(async () => {
      render(<PhrNavbar inPatient />)
    })

    // Browser Back navigates within PHR; the bar must not offer a way out to another site.
    expect(screen.queryByRole('link', { name: /back/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /back/i })).not.toBeInTheDocument()
    const hrefs = screen.getAllByRole('link').map((link) => link.getAttribute('href') ?? '')
    expect(hrefs.length).toBeGreaterThan(0)
    for (const href of hrefs) {
      expect(href).toMatch(/^\/phr(\/|$)/)
    }
  })
})
