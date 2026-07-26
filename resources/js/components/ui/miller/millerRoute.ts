export interface MillerColumnSpec<Id extends string> {
  id: Id
  instance?: string
}

export interface MillerRoute<Id extends string> {
  columns: MillerColumnSpec<Id>[]
}

export const EMPTY_MILLER_ROUTE: MillerRoute<string> = { columns: [] }

export function parseHash<Id extends string>(hash: string, validIds: ReadonlySet<string>): MillerRoute<Id> {
  if (!hash) {
    return { columns: [] }
  }
  const trimmed = hash.startsWith('#') ? hash.slice(1) : hash
  const path = trimmed.startsWith('/') ? trimmed.slice(1) : trimmed
  if (!path) {
    return { columns: [] }
  }
  const segments = path.split('/').filter(Boolean)
  const columns: MillerColumnSpec<Id>[] = []
  for (const segment of segments) {
    const [rawId, rawInstance] = segment.split(':')
    if (rawId === undefined || !validIds.has(rawId)) {
      continue
    }
    const column: MillerColumnSpec<Id> = { id: rawId as Id }
    if (rawInstance) {
      column.instance = decodeURIComponent(rawInstance)
    }
    columns.push(column)
  }
  return { columns }
}

export function serializeRoute<Id extends string>(route: MillerRoute<Id>): string {
  if (route.columns.length === 0) {
    return ''
  }
  const segments = route.columns.map((col) => {
    if (col.instance !== undefined) {
      return `${col.id}:${encodeURIComponent(col.instance)}`
    }
    return col.id
  })
  return `#/${segments.join('/')}`
}

export function pushColumn<Id extends string>(route: MillerRoute<Id>, column: MillerColumnSpec<Id>): MillerRoute<Id> {
  const existing = route.columns.findIndex(
    (c) => c.id === column.id && c.instance === column.instance,
  )
  if (existing !== -1) {
    return { columns: route.columns.slice(0, existing + 1) }
  }
  return { columns: [...route.columns, column] }
}

export function truncateTo<Id extends string>(route: MillerRoute<Id>, depth: number): MillerRoute<Id> {
  if (depth < 0) {
    return { columns: [] }
  }
  if (depth >= route.columns.length) {
    return route
  }
  return { columns: route.columns.slice(0, depth) }
}

export function replaceFrom<Id extends string>(
  route: MillerRoute<Id>,
  depth: number,
  column: MillerColumnSpec<Id>,
): MillerRoute<Id> {
  if (depth < 0) {
    return { columns: [column] }
  }
  return { columns: [...route.columns.slice(0, depth), column] }
}

export function routesEqual<Id extends string>(a: MillerRoute<Id>, b: MillerRoute<Id>): boolean {
  if (a.columns.length !== b.columns.length) {
    return false
  }
  for (let i = 0; i < a.columns.length; i++) {
    const colA = a.columns[i]!
    const colB = b.columns[i]!
    if (colA.id !== colB.id) {
      return false
    }
    if (colA.instance !== colB.instance) {
      return false
    }
  }
  return true
}
