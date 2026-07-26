import { parseHash, serializeRoute } from '@/components/ui/miller'
import { PHR_MODULE_IDS_SET, type PhrModuleId } from '@/phr/miller/phrModuleRegistry'

describe('PHR route parsing', () => {
  it('accepts all valid PHR module IDs', () => {
    for (const id of PHR_MODULE_IDS_SET) {
      const hash = `#/${id}`
      const result = parseHash<PhrModuleId>(hash, PHR_MODULE_IDS_SET)
      expect(result.columns).toHaveLength(1)
      expect(result.columns[0]!.id).toBe(id)
    }
  })

  it('rejects unknown IDs', () => {
    const hash = '#/unknown-module'
    const result = parseHash<PhrModuleId>(hash, PHR_MODULE_IDS_SET)
    expect(result.columns).toHaveLength(0)
  })

  it('parses a module with an instance', () => {
    const hash = '#/labs/lab-panel-detail:42'
    const result = parseHash<PhrModuleId>(hash, PHR_MODULE_IDS_SET)
    expect(result.columns).toHaveLength(2)
    expect(result.columns[0]).toEqual({ id: 'labs' })
    expect(result.columns[1]).toEqual({ id: 'lab-panel-detail', instance: '42' })
  })

  it('ignores invalid segments but keeps valid ones', () => {
    const hash = '#/labs/bad-id/imaging'
    const result = parseHash<PhrModuleId>(hash, PHR_MODULE_IDS_SET)
    expect(result.columns).toHaveLength(2)
    expect(result.columns[0]!.id).toBe('labs')
    expect(result.columns[1]!.id).toBe('imaging')
  })

  it('returns empty route for empty hash', () => {
    const result = parseHash<PhrModuleId>('', PHR_MODULE_IDS_SET)
    expect(result.columns).toHaveLength(0)
  })

  it('serializes single module column', () => {
    const route = { columns: [{ id: 'labs' as PhrModuleId }] }
    expect(serializeRoute(route)).toBe('#/labs')
  })

  it('serializes multiple columns with instance', () => {
    const route = {
      columns: [{ id: 'labs' as PhrModuleId }, { id: 'lab-panel-detail' as PhrModuleId, instance: '99' }],
    }
    expect(serializeRoute(route)).toBe('#/labs/lab-panel-detail:99')
  })

  it('round-trips hash through parse and serialize', () => {
    const original = '#/medications/medication-detail:7'
    const parsed = parseHash<PhrModuleId>(original, PHR_MODULE_IDS_SET)
    const serialized = serializeRoute(parsed)
    expect(serialized).toBe(original)
  })

  it('patient swap strips instances from columns', () => {
    const hash = '#/labs/lab-panel-detail:42'
    const parsed = parseHash<PhrModuleId>(hash, PHR_MODULE_IDS_SET)
    const stripped = { columns: parsed.columns.map((col) => ({ id: col.id })) }
    const newHash = serializeRoute(stripped)
    expect(newHash).toBe('#/labs/lab-panel-detail')
  })
})
