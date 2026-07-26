import {
  parseHash,
  pushColumn,
  replaceFrom,
  routesEqual,
  serializeRoute,
  truncateTo,
} from '../millerRoute'

const VALID_IDS: ReadonlySet<string> = new Set(['home', 'form-1040', 'sch-1', 'sch-2', 'form-1116', 'tax-lot-reconciliation'])
const EMPTY_ROUTE = { columns: [] as { id: string; instance?: string }[] }

describe('millerRoute', () => {
  describe('parseHash', () => {
    it('returns empty route for empty hash', () => {
      expect(parseHash('', VALID_IDS)).toEqual(EMPTY_ROUTE)
      expect(parseHash('#', VALID_IDS)).toEqual(EMPTY_ROUTE)
      expect(parseHash('#/', VALID_IDS)).toEqual(EMPTY_ROUTE)
    })

    it('parses a single column', () => {
      expect(parseHash('#/form-1040', VALID_IDS)).toEqual({
        columns: [{ id: 'form-1040' }],
      })
    })

    it('parses a multi-column drill-down', () => {
      expect(parseHash('#/form-1040/sch-1/form-1116', VALID_IDS)).toEqual({
        columns: [{ id: 'form-1040' }, { id: 'sch-1' }, { id: 'form-1116' }],
      })
    })

    it('parses an instance key from the id:instance suffix', () => {
      expect(parseHash('#/form-1040/form-1116:passive', VALID_IDS)).toEqual({
        columns: [{ id: 'form-1040' }, { id: 'form-1116', instance: 'passive' }],
      })
    })

    it('decodes URI-encoded instance keys', () => {
      expect(parseHash('#/sch-1:rental%20property', VALID_IDS)).toEqual({
        columns: [{ id: 'sch-1', instance: 'rental property' }],
      })
    })

    it('drops unknown ids silently', () => {
      expect(parseHash('#/form-1040/unknown-form/sch-1', VALID_IDS)).toEqual({
        columns: [{ id: 'form-1040' }, { id: 'sch-1' }],
      })
    })

    it('handles trailing slashes and empty segments', () => {
      expect(parseHash('#/form-1040///sch-1/', VALID_IDS)).toEqual({
        columns: [{ id: 'form-1040' }, { id: 'sch-1' }],
      })
    })
  })

  describe('serializeRoute', () => {
    it('serializes the empty route to empty string', () => {
      expect(serializeRoute(EMPTY_ROUTE)).toBe('')
    })

    it('serializes a single column', () => {
      expect(serializeRoute({ columns: [{ id: 'form-1040' }] })).toBe('#/form-1040')
    })

    it('serializes id:instance segments', () => {
      expect(
        serializeRoute({
          columns: [{ id: 'form-1040' }, { id: 'form-1116', instance: 'passive' }],
        }),
      ).toBe('#/form-1040/form-1116:passive')
    })

    it('URI-encodes instance keys with special characters', () => {
      expect(
        serializeRoute({
          columns: [{ id: 'sch-1', instance: 'rental property' }],
        }),
      ).toBe('#/sch-1:rental%20property')
    })
  })

  describe('pushColumn', () => {
    it('appends a column to the end', () => {
      const before = { columns: [{ id: 'form-1040' as const }] }
      const after = pushColumn(before, { id: 'sch-1' })
      expect(after.columns).toEqual([{ id: 'form-1040' }, { id: 'sch-1' }])
    })

    it('deduplicates existing id + instance pair by truncating', () => {
      const before = { columns: [{ id: 'form-1040' as const }, { id: 'sch-1' as const }] }
      const after = pushColumn(before, { id: 'form-1040' })
      expect(after.columns).toEqual([{ id: 'form-1040' }])
    })
  })

  describe('truncateTo', () => {
    const route = {
      columns: [{ id: 'form-1040' as const }, { id: 'sch-1' as const }, { id: 'form-1116' as const }],
    }

    it('keeps the first N columns', () => {
      expect(truncateTo(route, 1).columns).toEqual([{ id: 'form-1040' }])
      expect(truncateTo(route, 2).columns).toEqual([{ id: 'form-1040' }, { id: 'sch-1' }])
    })

    it('returns empty for depth 0', () => {
      expect(truncateTo(route, 0)).toEqual(EMPTY_ROUTE)
    })

    it('returns empty for negative depth', () => {
      expect(truncateTo(route, -1)).toEqual(EMPTY_ROUTE)
    })

    it('returns the full route when depth exceeds length', () => {
      expect(truncateTo(route, 99)).toEqual(route)
    })
  })

  describe('replaceFrom', () => {
    const route = {
      columns: [{ id: 'form-1040' as const }, { id: 'sch-1' as const }, { id: 'form-1116' as const }],
    }

    it('replaces the column at depth and drops the right side', () => {
      expect(replaceFrom(route, 1, { id: 'sch-2' }).columns).toEqual([
        { id: 'form-1040' },
        { id: 'sch-2' },
      ])
    })

    it('treats negative depth as a full reset', () => {
      expect(replaceFrom(route, -1, { id: 'home' }).columns).toEqual([{ id: 'home' }])
    })
  })

  describe('routesEqual', () => {
    it('returns true for identical routes', () => {
      expect(
        routesEqual(
          { columns: [{ id: 'form-1040' }, { id: 'form-1116', instance: 'passive' }] },
          { columns: [{ id: 'form-1040' }, { id: 'form-1116', instance: 'passive' }] },
        ),
      ).toBe(true)
    })

    it('returns false when columns differ', () => {
      expect(
        routesEqual(
          { columns: [{ id: 'form-1040' }] },
          { columns: [{ id: 'form-1040' }, { id: 'sch-1' }] },
        ),
      ).toBe(false)
    })
  })
})
