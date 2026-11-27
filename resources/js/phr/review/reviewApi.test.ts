import { makeEhiProposal } from '@/phr/imports/ehi/__tests__/ehiFixtures'
import { decideReviewItem, decideReviewItems, type ReviewItem } from '@/phr/review/reviewApi'

const mockPost = jest.fn()
const mockPatch = jest.fn()
const mockReviewStateChanged = jest.fn()

jest.mock('@/phr/review/reviewSummary', () => ({ reviewStateChanged: (...args: unknown[]) => mockReviewStateChanged(...args) }))

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: jest.fn(),
    post: (...args: unknown[]) => mockPost(...args),
    patch: (...args: unknown[]) => mockPatch(...args),
  },
  getCsrfToken: () => null,
}))

function ehiItem(overrides: Parameters<typeof makeEhiProposal>[0]): ReviewItem {
  const proposal = makeEhiProposal({ kind: 'update', allowed_decisions: ['approve_update', 'reject', 'retain_evidence'], ...overrides })
  return { type: 'ehi_proposal', key: `ehi_proposal:${proposal.id}`, id: proposal.id, package_id: proposal.package_id, section: 'immunizations', proposal } as ReviewItem
}

describe('decideReviewItem', () => {
  beforeEach(() => mockPost.mockReset())

  it('refuses to approve an EHI update that shows no difference, without asking the server', async () => {
    await expect(decideReviewItem(1, ehiItem({ before: { lot_number: 'A' }, proposed: { lot_number: 'A' } }), 'approve')).rejects.toThrow(/no difference/)
    expect(mockPost).not.toHaveBeenCalled()
  })

  it('still lets a person reject one, and approves one with a visible change', async () => {
    const proposal = { proposal: { ...makeEhiProposal(), status: 'accepted', reviewable: false }, record: null }
    mockPost.mockResolvedValue(proposal)

    await decideReviewItem(1, ehiItem({ before: { lot_number: 'A' }, proposed: { lot_number: 'A' } }), 'reject')
    expect(mockPost).toHaveBeenLastCalledWith(expect.stringContaining('/review'), expect.objectContaining({ decision: 'reject' }))

    await decideReviewItem(1, ehiItem({ before: { raw_text: 'DOSE: 0.5 mL' }, proposed: { raw_text: 'DOSE: 1.0 mL' } }), 'approve')
    expect(mockPost).toHaveBeenLastCalledWith(expect.stringContaining('/review'), expect.objectContaining({ decision: 'approve_update' }))
  })
})

function recordItem(id: number): ReviewItem {
  return { type: 'record', key: `record:labs:${id}`, id, section: 'labs', review_segment: 'lab-results', title: 'Synthetic lab', detail: null, change: 'new', version: 'a'.repeat(64), updated_at: null }
}

describe('decideReviewItems', () => {
  beforeEach(() => {
    mockPost.mockReset()
    mockPatch.mockReset()
    mockReviewStateChanged.mockReset()
  })

  it('approves 100 records in one request and invalidates counts once', async () => {
    const items = Array.from({ length: 100 }, (_, index) => recordItem(index + 1))
    mockPost.mockResolvedValue({ done: items.map((item) => ({ segment: 'lab-results', id: item.id })), failed: [] })
    expect(await decideReviewItems(4, items, 'approve')).toEqual({ done: items.map((item) => item.key), failed: {} })
    expect(mockPost).toHaveBeenCalledTimes(1)
    expect(mockPost).toHaveBeenCalledWith('/api/phr/patients/4/review/records', { review_status: 'confirmed', items: items.map((item) => ({ segment: 'lab-results', id: item.id, version: 'a'.repeat(64) })) })
    expect(mockPatch).not.toHaveBeenCalled()
    expect(mockReviewStateChanged).toHaveBeenCalledTimes(1)
    expect(mockReviewStateChanged).toHaveBeenCalledWith(4)
  })

  it('chunks large selections, continues after a refused chunk, and maps failures to their own rows', async () => {
    const items = Array.from({ length: 202 }, (_, index) => recordItem(index + 1))
    mockPost.mockRejectedValueOnce('Not available')
      .mockResolvedValueOnce({ done: items.slice(100, 200).map((item) => ({ segment: 'lab-results', id: item.id })), failed: [] })
      .mockResolvedValueOnce({ done: [{ segment: 'lab-results', id: 202 }], failed: [{ segment: 'lab-results', id: 201, error: 'Changed since review' }] })
    const result = await decideReviewItems(4, items, 'reject')
    expect(mockPost.mock.calls.map((call) => call[1].items.length)).toEqual([100, 100, 2])
    expect(mockPost.mock.calls.every((call) => call[1].review_status === 'rejected')).toBe(true)
    expect(result.done).toHaveLength(101)
    expect(result.failed['record:labs:1']).toBe('Not available')
    expect(result.failed['record:labs:201']).toBe('Changed since review')
    expect(mockReviewStateChanged).toHaveBeenCalledTimes(3)
  })

  it('leaves import proposals on their own path, and single records unchanged', async () => {
    mockPatch.mockResolvedValue({})
    expect(await decideReviewItems(4, [recordItem(1)], 'approve')).toEqual({ done: ['record:labs:1'], failed: {} })
    expect(mockPatch).toHaveBeenCalledWith('/api/phr/patients/4/lab-results/1/review', { review_status: 'confirmed', version: 'a'.repeat(64) })
    expect(mockPost).not.toHaveBeenCalled()
    mockPost.mockResolvedValueOnce({ done: [{ segment: 'lab-results', id: 1 }, { segment: 'lab-results', id: 2 }], failed: [] })
      .mockResolvedValueOnce({ proposal: { ...makeEhiProposal(), status: 'accepted', reviewable: false }, record: null })
    const proposal = ehiItem({ before: { lot_number: 'A' }, proposed: { lot_number: 'B' } })
    const result = await decideReviewItems(4, [recordItem(1), proposal, recordItem(2)], 'approve')
    expect(result.failed).toEqual({})
    expect(result.done).toEqual(['record:labs:1', 'record:labs:2', proposal.key])
    expect(mockPost.mock.calls[0][1].items).toHaveLength(2)
    expect(mockPost).toHaveBeenLastCalledWith(expect.stringContaining('/ehi/proposals/'), expect.objectContaining({ decision: 'approve_update' }))
  })

  it('never treats an omitted result as a successful decision', async () => {
    mockPost.mockResolvedValue({ done: [{ segment: 'lab-results', id: 1 }], failed: [] })
    const outcome = await decideReviewItems(4, [recordItem(1), recordItem(2)], 'approve')
    expect(outcome.done).toEqual(['record:labs:1'])
    expect(outcome.failed['record:labs:2']).toMatch(/not reviewed/)
  })
})
