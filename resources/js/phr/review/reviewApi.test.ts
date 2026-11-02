import { makeEhiProposal } from '@/phr/imports/ehi/__tests__/ehiFixtures'
import { decideReviewItem, type ReviewItem } from '@/phr/review/reviewApi'

const mockPost = jest.fn()

jest.mock('@/fetchWrapper', () => ({
  fetchWrapper: {
    get: jest.fn(),
    post: (...args: unknown[]) => mockPost(...args),
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
