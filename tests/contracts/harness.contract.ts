import { cpSync, mkdtempSync, readFileSync, rmSync, symlinkSync, unlinkSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import path from 'node:path'

import { context, digest, loadBundle, readCatalog, requireContract, verifyTests } from '../../scripts/contracts/protocol.mjs'
import { assertResponse } from './validateResponses.mjs'

const expected = context()
const catalog = readCatalog()
const generated = process.env.PHR_HTTP_CONTRACT_DIR!

function altered(change: (manifest: any, directory: string) => void, code: string): void {
  const directory = mkdtempSync(path.join(tmpdir(), 'phr-contract-'))
  try {
    cpSync(generated, directory, { recursive: true })
    const manifest = JSON.parse(readFileSync(path.join(directory, 'manifest.json'), 'utf8'))
    change(manifest, directory)
    writeFileSync(path.join(directory, 'manifest.json'), JSON.stringify(manifest))
    expect(() => loadBundle(directory, expected)).toThrow(`code=${code}`)
  } finally { rmSync(directory, { recursive: true, force: true }) }
}

function replaced(change: (directory: string) => void, code: string): void {
  const directory = mkdtempSync(path.join(tmpdir(), 'phr-contract-'))
  try {
    cpSync(generated, directory, { recursive: true })
    change(directory)
    expect(() => loadBundle(directory, expected)).toThrow(`code=${code}`)
  } finally { rmSync(directory, { recursive: true, force: true }) }
}

function testReport() {
  const names = [...catalog.cases.map((item: { id: string }) => `response/${item.id}`), ...catalog.checks.map((check: string) => `harness/${check}`)]
  return { numTotalTests: names.length, testResults: [{ assertionResults: names.map(fullName => ({ fullName, status: 'passed' })) }] }
}

const checks: Record<string, () => void> = {
  'missing-manifest': () => replaced(directory => unlinkSync(path.join(directory, 'manifest.json')), 'manifest_missing'),
  'zero-catalog': () => expect(() => readCatalog(expected.root, { ...catalog, cases: [] })).toThrow('code=catalog_empty'),
  'duplicate-catalog': () => expect(() => readCatalog(expected.root, { ...catalog, cases: [...catalog.cases, catalog.cases[0]] })).toThrow('code=catalog_duplicate'),
  'zero-fixtures': () => altered(manifest => { manifest.fixtures = [] }, 'fixtures_empty'),
  'missing-case': () => altered(manifest => { manifest.fixtures.pop() }, 'case_missing'),
  'extra-case': () => altered(manifest => { manifest.fixtures.push({ ...manifest.fixtures[0], id: 'unlisted-case', file: 'unlisted-case.json' }) }, 'case_extra'),
  'duplicate-case': () => altered(manifest => { manifest.fixtures.push({ ...manifest.fixtures[0], file: 'duplicate-case.json' }) }, 'case_duplicate'),
  'missing-file': () => replaced(directory => unlinkSync(path.join(directory, catalog.cases[0].id + '.json')), 'file_missing'),
  'extra-file': () => replaced(directory => writeFileSync(path.join(directory, 'unexpected.json'), '{}'), 'file_extra'),
  'duplicate-file': () => altered(manifest => { manifest.fixtures[1].file = manifest.fixtures[0].file }, 'file_duplicate'),
  'unsafe-file': () => altered(manifest => { manifest.fixtures[0].file = '../outside.json' }, 'file_unsafe'),
  'symlink-file': () => replaced(directory => {
    const file = path.join(directory, catalog.cases[0].id + '.json')
    unlinkSync(file)
    symlinkSync(catalog.cases[1].id + '.json', file)
  }, 'file_missing'),
  'stale-candidate': () => altered(manifest => { manifest.candidate = '0'.repeat(40) }, 'candidate_stale'),
  'mixed-candidate': () => altered(manifest => { manifest.fixtures[0].candidate = '0'.repeat(40) }, 'candidate_mixed'),
  'stale-generation': () => altered(manifest => { manifest.generation = '0'.repeat(32) }, 'generation_stale'),
  'mixed-generation': () => altered(manifest => { manifest.fixtures[0].generation = '0'.repeat(32) }, 'generation_mixed'),
  'stale-catalog': () => altered(manifest => { manifest.catalog_sha256 = '0'.repeat(64) }, 'catalog_stale'),
  'stale-generator': () => altered(manifest => { manifest.generator_fingerprints['tests/Support/HttpContractFixtures.php'] = '0'.repeat(64) }, 'generator_stale'),
  'wrong-hash': () => altered(manifest => { manifest.fixtures[0].sha256 = '0'.repeat(64) }, 'hash_mismatch'),
  'wrong-size': () => altered(manifest => { manifest.fixtures[0].bytes += 1 }, 'size_mismatch'),
  'invalid-manifest': () => replaced(directory => writeFileSync(path.join(directory, 'manifest.json'), '{'), 'manifest_invalid'),
  'invalid-json': () => altered((manifest, directory) => {
    const entry = manifest.fixtures[0]
    const raw = Buffer.from('{')
    writeFileSync(path.join(directory, entry.file), raw)
    entry.bytes = raw.length
    entry.sha256 = digest(raw)
  }, 'json_invalid'),
  'unknown-schema': () => altered(manifest => { manifest.fixtures[0].schema = 'UnknownSchema' }, 'schema_unknown'),
  'shared-private-field': () => {
    const response = loadBundle(generated, expected).responses.get('inventory-shared-viewer')!
    response.value.shared_patients[0].record_counts = { synthetic: 1 }
    expect(() => assertResponse(response)).toThrow('path=shared_patients[n].record_counts kind=object code=private_field')
  },
  'zero-tests': () => expect(() => verifyTests({ numTotalTests: 0, testResults: [] }, catalog)).toThrow('code=tests_empty'),
  'missing-test': () => {
    const report = testReport()
    report.testResults[0]!.assertionResults.pop()
    expect(() => verifyTests(report, catalog)).toThrow('code=test_missing')
  },
  'extra-test': () => {
    const report = testReport()
    report.testResults[0]!.assertionResults.push({ fullName: 'response/unlisted-case', status: 'passed' })
    expect(() => verifyTests(report, catalog)).toThrow('code=test_extra')
  },
  'duplicate-test': () => {
    const report = testReport()
    report.testResults[0]!.assertionResults.push(report.testResults[0]!.assertionResults[0]!)
    expect(() => verifyTests(report, catalog)).toThrow('code=test_duplicate')
  },
  'skipped-test': () => {
    const report = testReport()
    report.testResults[0]!.assertionResults[0]!.status = 'pending'
    expect(() => verifyTests(report, catalog)).toThrow('code=test_status')
  },
}

requireContract(JSON.stringify(Object.keys(checks).sort()) === JSON.stringify([...catalog.checks].sort()), 'harness_catalog')
for (const [id, check] of Object.entries(checks)) test(`harness/${id}`, check)
