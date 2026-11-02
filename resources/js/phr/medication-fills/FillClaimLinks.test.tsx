import { fireEvent, render, screen, waitFor } from '@testing-library/react'

import { fetchWrapper } from '@/fetchWrapper'

import FillClaimLinks from './FillClaimLinks'

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: { get: jest.fn(), post: jest.fn() },
}))

jest.mock('@/phr/review/reviewSummary', () => ({
  ...jest.requireActual('@/phr/review/reviewSummary'),
  reviewStateChanged: jest.fn().mockResolvedValue(undefined),
}))

const get = fetchWrapper.get as jest.Mock
const post = fetchWrapper.post as jest.Mock
const URL = '/api/phr/patients/1/medication-fills/7/eob-link'
const CLAIM = { eob_id: 3, claim_number: 'C-1', service_date: '2026-01-05', total_charges: '100.00', provider_name: null }

describe('FillClaimLinks', () => {
  beforeEach(() => {
    get.mockReset()
    post.mockReset()
    post.mockResolvedValue({ outcome: 'rejected' })
  })

  it('says why an automatic link was made and lets a manager reject it', async () => {
    get.mockResolvedValueOnce({ claims: [{ ...CLAIM, automatic: true, rule: 'sole_pair' }], dismissed: false })
    get.mockResolvedValueOnce({ claims: [], dismissed: true })
    const onChanged = jest.fn()
    render(<FillClaimLinks patientId={1} fillId={7} canManage onChanged={onChanged} />)

    expect(await screen.findByText(/only fill and the only claim/)).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'Not this claim' }))

    await waitFor(() => expect(post).toHaveBeenCalledWith(URL, { action: 'reject' }))
    expect(await screen.findByRole('button', { name: 'Match it again' })).toBeTruthy()
    expect(onChanged).toHaveBeenCalledTimes(1)
  })

  it('undoes a rejection', async () => {
    get.mockResolvedValue({ claims: [], dismissed: true })
    render(<FillClaimLinks patientId={1} fillId={7} canManage onChanged={jest.fn()} />)

    fireEvent.click(await screen.findByRole('button', { name: 'Match it again' }))

    await waitFor(() => expect(post).toHaveBeenCalledWith(URL, { action: 'reset' }))
  })

  it('offers no buttons to someone who can only read, or on a link a person made', async () => {
    get.mockResolvedValue({ claims: [{ ...CLAIM, automatic: false, rule: null }], dismissed: true })
    const { unmount } = render(<FillClaimLinks patientId={1} fillId={7} canManage={false} onChanged={jest.fn()} />)
    expect(await screen.findByText(/Claim C-1/)).toBeTruthy()
    expect(screen.queryByRole('button')).toBeNull()
    unmount()

    get.mockResolvedValue({ claims: [{ ...CLAIM, automatic: false, rule: null }], dismissed: false })
    render(<FillClaimLinks patientId={1} fillId={7} canManage onChanged={jest.fn()} />)
    expect(await screen.findByText(/Claim C-1/)).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'Not this claim' })).toBeNull()
  })

  it('shows nothing for a fill with no claim', async () => {
    get.mockResolvedValue({ claims: [], dismissed: false })
    const { container } = render(<FillClaimLinks patientId={1} fillId={7} canManage onChanged={jest.fn()} />)

    await waitFor(() => expect(get).toHaveBeenCalled())
    expect(container.textContent).toBe('')
  })

  it('keeps the rejection button and shows the refusal when saving fails', async () => {
    get.mockResolvedValue({ claims: [{ ...CLAIM, automatic: true, rule: 'known_charge' }], dismissed: false })
    post.mockRejectedValue(new Error('This fill has no automatic claim to reject.'))
    render(<FillClaimLinks patientId={1} fillId={7} canManage onChanged={jest.fn()} />)

    fireEvent.click(await screen.findByRole('button', { name: 'Not this claim' }))

    expect((await screen.findByRole('alert')).textContent).toContain('no automatic claim')
    expect(screen.getByRole('button', { name: 'Not this claim' })).toBeTruthy()
  })
})
