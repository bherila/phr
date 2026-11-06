import { execFileSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { lstatSync, readdirSync, readFileSync, realpathSync } from 'node:fs'
import path from 'node:path'

export const ROOT = process.cwd()
export const SCHEMA_IDS = ['DataHubResponseSchema', 'NativeRestoreResponseSchema', 'NativeRestoresResponseSchema', 'NativeBackupResponseSchema', 'NativeBackupsResponseSchema']
const ID = /^[a-z][a-z0-9-]{1,80}$/
const SHA = /^[a-f0-9]{64}$/
const own = (value, key) => Object.hasOwn(value, key)
const object = value => value !== null && typeof value === 'object' && !Array.isArray(value)
export const compareText = (left, right) => left < right ? -1 : left > right ? 1 : 0
const sorted = values => [...values].sort(compareText)
const same = (left, right) => JSON.stringify(sorted(left)) === JSON.stringify(sorted(right))
export const digest = bytes => createHash('sha256').update(bytes).digest('hex')

/** Never attach raw JSON, observed values, parser messages or dynamic keys to errors. */
export class ContractFailure extends Error {
  constructor(code, caseId = 'bundle', schemaPath = '$', kind = 'metadata') {
    super(`http-contract case=${caseId} path=${schemaPath} kind=${kind} code=${code}`)
    this.code = code
    this.case_id = caseId
    this.path = schemaPath
    this.kind = kind
  }
}

export function requireContract(condition, code, caseId) {
  if (!condition) throw new ContractFailure(code, caseId)
}

function exactKeys(value, keys, code) {
  requireContract(object(value) && same(Object.keys(value), keys), code)
}

function regularFile(file, code) {
  try {
    const stat = lstatSync(file)
    requireContract(stat.isFile() && !stat.isSymbolicLink() && stat.size <= 2 * 1024 * 1024, code)
    return readFileSync(file)
  } catch { throw new ContractFailure(code) }
}

function json(bytes, code, id) {
  try { return JSON.parse(bytes.toString('utf8')) } catch { throw new ContractFailure(code, id) }
}

export function readCatalog(root = ROOT, supplied) {
  const catalog = supplied ?? json(regularFile(path.join(root, 'tests/contracts/catalog.json'), 'catalog_invalid'), 'catalog_invalid')
  exactKeys(catalog, ['version', 'cases', 'inventory_keys', 'checks', 'http_assertions'], 'catalog_invalid')
  requireContract(catalog.version === 1 && Array.isArray(catalog.cases) && catalog.cases.length > 0, 'catalog_empty')
  requireContract(catalog.cases.length <= 200, 'catalog_invalid')
  const seen = new Set()
  for (const item of catalog.cases) {
    exactKeys(item, ['id', 'schema', 'role', 'state', 'status'], 'catalog_invalid')
    requireContract(typeof item.id === 'string' && ID.test(item.id), 'catalog_invalid')
    requireContract(!seen.has(item.id), 'catalog_duplicate')
    seen.add(item.id)
    requireContract(SCHEMA_IDS.includes(item.schema) && typeof item.role === 'string' && ID.test(item.role)
      && typeof item.state === 'string' && ID.test(item.state) && [200, 201, 202].includes(item.status), 'catalog_invalid')
  }
  requireContract(Array.isArray(catalog.inventory_keys) && catalog.inventory_keys.length === 24
    && new Set(catalog.inventory_keys).size === 24 && catalog.inventory_keys.every(key => typeof key === 'string' && /^[a-z_]+$/.test(key)), 'catalog_invalid')
  requireContract(Array.isArray(catalog.checks) && catalog.checks.length > 0 && new Set(catalog.checks).size === catalog.checks.length
    && catalog.checks.every(check => typeof check === 'string' && ID.test(check)), 'catalog_invalid')
  requireContract(Array.isArray(catalog.http_assertions) && catalog.http_assertions.length > 0 && catalog.http_assertions.length <= 200, 'assertions_empty')
  for (const item of catalog.http_assertions) {
    exactKeys(item, ['id', 'role', 'state', 'status', 'error'], 'catalog_invalid')
    requireContract(typeof item.id === 'string' && ID.test(item.id) && !seen.has(item.id), 'assertion_catalog_duplicate')
    seen.add(item.id)
    requireContract(typeof item.role === 'string' && ID.test(item.role) && typeof item.state === 'string' && ID.test(item.state)
      && [200, 403, 404, 409, 410, 422].includes(item.status)
      && (item.error === null || typeof item.error === 'string' && /^[a-z_]+$/.test(item.error)), 'catalog_invalid')
  }
  return catalog
}

export function fingerprints(root = ROOT) {
  const sources = json(regularFile(path.join(root, 'tests/contracts/sources.json'), 'sources_invalid'), 'sources_invalid')
  requireContract(Array.isArray(sources) && sources.length > 0 && sources.length <= 100
    && new Set(sources).size === sources.length, 'sources_invalid')
  return Object.fromEntries(sorted(sources).map(source => {
    requireContract(typeof source === 'string' && /^[A-Za-z0-9/._-]+$/.test(source)
      && !source.split('/').some(part => ['', '.', '..'].includes(part)), 'sources_invalid')
    return [source, digest(regularFile(path.join(root, source), 'source_missing'))]
  }))
}

export function context(root = ROOT) {
  const candidate = execFileSync('git', ['rev-parse', 'HEAD'], { cwd: root, encoding: 'utf8' }).trim()
  requireContract(/^[a-f0-9]{40}$/.test(candidate), 'candidate_invalid')
  requireContract(!process.env.PHR_HTTP_CONTRACT_CANDIDATE || process.env.PHR_HTTP_CONTRACT_CANDIDATE === candidate, 'candidate_stale')
  const generation = process.env.PHR_HTTP_CONTRACT_GENERATION
  requireContract(typeof generation === 'string' && /^[a-f0-9]{32}$/.test(generation), 'generation_invalid')
  return { root, candidate, generation }
}

export function loadBundle(directory, expected) {
  const catalog = readCatalog(expected.root)
  try {
    requireContract(lstatSync(directory).isDirectory() && !lstatSync(directory).isSymbolicLink()
      && realpathSync(directory) === path.resolve(directory), 'directory_invalid')
  } catch { throw new ContractFailure('directory_invalid') }
  const manifest = json(regularFile(path.join(directory, 'manifest.json'), 'manifest_missing'), 'manifest_invalid')
  exactKeys(manifest, ['version', 'candidate', 'generation', 'catalog_sha256', 'generator_fingerprints', 'fixtures', 'http_assertions'], 'manifest_invalid')
  requireContract(manifest.version === 1 && Array.isArray(manifest.fixtures), 'manifest_invalid')
  requireContract(manifest.fixtures.length > 0, 'fixtures_empty')
  requireContract(manifest.fixtures.length <= 200, 'manifest_invalid')
  requireContract(manifest.candidate === expected.candidate, 'candidate_stale')
  requireContract(manifest.generation === expected.generation, 'generation_stale')
  requireContract(manifest.catalog_sha256 === digest(regularFile(path.join(expected.root, 'tests/contracts/catalog.json'), 'catalog_invalid')), 'catalog_stale')
  requireContract(JSON.stringify(manifest.generator_fingerprints) === JSON.stringify(fingerprints(expected.root)), 'generator_stale')
  requireContract(Array.isArray(manifest.http_assertions) && manifest.http_assertions.length > 0, 'assertions_empty')
  const assertionIds = new Set()
  for (const entry of manifest.http_assertions) {
    exactKeys(entry, ['id', 'role', 'state', 'status', 'error', 'candidate', 'generation'], 'manifest_invalid')
    requireContract(!assertionIds.has(entry.id), 'assertion_duplicate')
    assertionIds.add(entry.id)
    const scenario = catalog.http_assertions.find(item => item.id === entry.id)
    requireContract(scenario, 'assertion_extra')
    requireContract(entry.candidate === expected.candidate, 'assertion_candidate_mixed', scenario.id)
    requireContract(entry.generation === expected.generation, 'assertion_generation_mixed', scenario.id)
    requireContract(entry.role === scenario.role && entry.state === scenario.state && entry.status === scenario.status, 'assertion_metadata', scenario.id)
    requireContract(entry.error === scenario.error, 'assertion_error', scenario.id)
  }
  requireContract(same([...assertionIds], catalog.http_assertions.map(item => item.id)), 'assertion_missing')
  const ids = new Set()
  const files = new Set()
  for (const entry of manifest.fixtures) {
    exactKeys(entry, ['id', 'schema', 'role', 'state', 'candidate', 'generation', 'status', 'content_type', 'file', 'bytes', 'sha256'], 'manifest_invalid')
    requireContract(!ids.has(entry.id), 'case_duplicate')
    requireContract(!files.has(entry.file), 'file_duplicate')
    ids.add(entry.id)
    files.add(entry.file)
  }
  const expectedIds = catalog.cases.map(item => item.id)
  requireContract([...ids].every(id => expectedIds.includes(id)), 'case_extra')
  requireContract(same([...ids], expectedIds), 'case_missing')
  const responses = new Map()
  for (const entry of manifest.fixtures) {
    const scenario = catalog.cases.find(item => item.id === entry.id)
    requireContract(entry.candidate === expected.candidate, 'candidate_mixed', scenario.id)
    requireContract(entry.generation === expected.generation, 'generation_mixed', scenario.id)
    requireContract(SCHEMA_IDS.includes(entry.schema), 'schema_unknown', scenario.id)
    requireContract(entry.schema === scenario.schema && entry.role === scenario.role && entry.state === scenario.state
      && entry.status === scenario.status && entry.content_type === 'application/json', 'case_metadata', scenario.id)
    requireContract(typeof entry.file === 'string' && entry.file === scenario.id + '.json', 'file_unsafe', scenario.id)
    requireContract(Number.isSafeInteger(entry.bytes) && entry.bytes > 0 && entry.bytes <= 1024 * 1024
      && typeof entry.sha256 === 'string' && SHA.test(entry.sha256), 'manifest_invalid')
    const raw = regularFile(path.join(directory, entry.file), 'file_missing')
    requireContract(raw.length === entry.bytes, 'size_mismatch', scenario.id)
    requireContract(digest(raw) === entry.sha256, 'hash_mismatch', scenario.id)
    responses.set(scenario.id, { scenario, value: json(raw, 'json_invalid', scenario.id) })
  }
  requireContract(same(readdirSync(directory), ['manifest.json', ...files]), 'file_extra')
  return { catalog, responses }
}

/** Actual Jest results must contain every catalog test once, with no skip/pass-with-no-tests escape. */
export function verifyTests(report, catalog, { responsesOnly = false, failures = [] } = {}) {
  const wanted = catalog.cases.map(item => `response/${item.id}`)
  if (!responsesOnly) wanted.push(...catalog.checks.map(check => `harness/${check}`))
  const assertions = (report.testResults ?? []).flatMap(result => result.assertionResults ?? [])
  requireContract(assertions.length > 0 && report.numTotalTests > 0, 'tests_empty')
  const names = assertions.map(test => test.fullName)
  requireContract(new Set(names).size === names.length, 'test_duplicate')
  requireContract(names.every(name => wanted.includes(name)), 'test_extra')
  requireContract(same(names, wanted), 'test_missing')
  for (const test of assertions) {
    requireContract(test.status === (failures.includes(test.fullName) ? 'failed' : 'passed'), 'test_status')
  }
  requireContract((report.numRuntimeErrorTestSuites ?? 0) === 0 && report.numTotalTests === assertions.length, 'test_runtime')
}
