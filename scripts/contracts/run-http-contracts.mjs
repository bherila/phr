import { execFileSync, spawnSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { existsSync, lstatSync, mkdirSync, readFileSync, realpathSync, writeFileSync } from 'node:fs'
import path from 'node:path'

import { ContractFailure, digest, fingerprints, readCatalog, requireContract, ROOT, verifyTests } from './protocol.mjs'

let stage = 'preflight'
let proofFile
let catalog
let originalSources
let candidate
const proof = { version: 1, status: 'running', mutants: [] }
const options = process.argv.slice(2)
if (options[0] === '--') options.shift()

function readReport(file) {
  try { return JSON.parse(readFileSync(file, 'utf8')) } catch { throw new ContractFailure('report_missing') }
}

function runGeneration(label, { responsesOnly = false, expectedFailures = [] } = {}) {
  const generation = randomBytes(16).toString('hex')
  const directory = path.join(ROOT, 'build/http-contracts/run-' + generation)
  mkdirSync(directory, { mode: 0o700 })
  const fixtures = path.join(directory, 'fixtures')
  mkdirSync(fixtures, { mode: 0o700 })
  const reportFile = path.join(directory, 'responses.json')
  const jestFile = path.join(directory, 'jest.json')
  const env = { ...process.env, PHR_TEST_DATABASE: '', PHR_TEST_S3_ENDPOINT: '',
    APP_KEY: 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', APP_DEBUG: 'false', APP_URL: 'http://localhost',
    PHR_HTTP_CONTRACT_CANDIDATE: candidate, PHR_HTTP_CONTRACT_GENERATION: generation,
    PHR_HTTP_CONTRACT_DIR: fixtures, PHR_HTTP_CONTRACT_REPORT: reportFile,
  }
  stage = 'producer'
  const producer = spawnSync('php', ['-d', 'memory_limit=1G', 'vendor/bin/phpunit', '--no-progress', 'tests/Contracts/HttpResponseFixturesTest.php'], {
    cwd: ROOT, env, encoding: 'utf8', maxBuffer: 4 * 1024 * 1024, timeout: 120000,
  })
  // These private logs are never uploaded or printed. PHPUnit/Jest can include observed values.
  writeFileSync(path.join(directory, 'producer.log'), (producer.stdout ?? '') + (producer.stderr ?? ''), { mode: 0o600 })
  if (producer.status !== 0) {
    const diagnostic = ((producer.stdout ?? '') + (producer.stderr ?? '')).match(/http-contract case=([a-z0-9-]+) code=([a-z0-9_]+)/)
    const allowedCases = new Set(['generation', 'catalog', ...catalog.cases.map(item => item.id)])
    const allowedCodes = new Set(['candidate', 'directory', 'case', 'empty', 'unknown_case', 'duplicate', 'http_status', 'content_type', 'body_size', 'incomplete', 'inventory', 'category_keys', 'medication_fill_count', 'role', 'private_field', 'state'])
    if (diagnostic && allowedCases.has(diagnostic[1]) && allowedCodes.has(diagnostic[2])) {
      proof.producer_failure = { case_id: diagnostic[1], code: diagnostic[2] }
    }
    throw new ContractFailure('producer_failed')
  }
  stage = 'consumer'
  const args = ['node_modules/jest/bin/jest.js', '--config', 'jest.contract.config.cjs', '--runInBand', '--no-cache', '--json', '--outputFile', jestFile]
  if (responsesOnly) args.push('--runTestsByPath', 'tests/contracts/responses.contract.ts')
  const consumer = spawnSync(process.execPath, args, { cwd: ROOT, env, encoding: 'utf8', maxBuffer: 4 * 1024 * 1024, timeout: 120000 })
  writeFileSync(path.join(directory, 'consumer.log'), (consumer.stdout ?? '') + (consumer.stderr ?? ''), { mode: 0o600 })
  const jest = readReport(jestFile)
  verifyTests(jest, catalog, { responsesOnly, failures: expectedFailures.map(item => `response/${item.case_id}`) })
  requireContract(expectedFailures.length ? consumer.status !== 0 : consumer.status === 0, 'consumer_status')
  const report = readReport(reportFile)
  requireContract(report.candidate === candidate && report.generation === generation, 'report_provenance')
  requireContract(Array.isArray(report.results) && report.results.length === catalog.cases.length
    && new Set(report.results.map(result => result.case_id)).size === catalog.cases.length
    && report.results.every(result => catalog.cases.some(item => item.id === result.case_id && item.schema === result.schema)), 'report_cases')
  const issues = report.results.flatMap(result => result.issues)
  const normalize = values => JSON.stringify([...values].sort((left, right) => left.case_id.localeCompare(right.case_id)))
  requireContract(normalize(issues) === normalize(expectedFailures), 'unintended_failure')
  return { label, generation, cases: catalog.cases.length, checks: responsesOnly ? 0 : catalog.checks.length, issues }
}

const mutations = [
  {
    id: 'historical-inventory-enum', target: 'resources/js/phr/data-hub/dataHub.ts',
    before: "  'medication_fills',\n", after: '',
    failures: ['inventory-owned-empty', 'inventory-owned-populated'].map(case_id => ({
      case_id, schema: 'DataHubResponseSchema', path: 'owned_patients[n].record_counts', kind: 'object', code: 'unrecognized_keys',
    })),
  },
  {
    id: 'historical-restore-map', target: 'app/Services/PHR/NativeBackup/PhrNativeRestorePresenter.php',
    before: "'tables' => (object) ($attempt->plan_counts_json['tables'] ?? []),",
    after: "'tables' => $attempt->plan_counts_json['tables'] ?? [],",
    failures: [
      { case_id: 'restore-uploading', schema: 'NativeRestoreResponseSchema', path: 'restore.tables', kind: 'array', code: 'invalid_type' },
      { case_id: 'restore-index-uploading', schema: 'NativeRestoresResponseSchema', path: 'restores[n].tables', kind: 'array', code: 'invalid_type' },
    ],
  },
]

try {
  catalog = readCatalog()
  originalSources = fingerprints()
  candidate = execFileSync('git', ['rev-parse', 'HEAD'], { encoding: 'utf8' }).trim()
  proof.candidate = candidate
  requireContract(/^[a-f0-9]{40}$/.test(candidate) && (!process.env.PHR_HTTP_CONTRACT_CANDIDATE || process.env.PHR_HTTP_CONTRACT_CANDIDATE === candidate), 'candidate_stale')
  requireContract(execFileSync('git', ['status', '--porcelain', '--untracked-files=normal'], { encoding: 'utf8' }).trim() === '', 'working_tree_dirty')
  requireContract(options.every(arg => arg === '--prove-historical-mutations') && options.length <= 1, 'arguments')
  const output = path.join(ROOT, 'build/http-contracts')
  mkdirSync(output, { recursive: true, mode: 0o700 })
  requireContract(!lstatSync(path.join(ROOT, 'build')).isSymbolicLink() && !lstatSync(output).isSymbolicLink() && realpathSync(output) === output, 'directory_invalid')
  proofFile = path.join(output, 'proof.json')
  requireContract(!existsSync(proofFile) || !lstatSync(proofFile).isSymbolicLink(), 'report_invalid')
  writeFileSync(proofFile, JSON.stringify(proof), { mode: 0o600 })
  proof.pristine_before = runGeneration('pristine-before')
  if (options.includes('--prove-historical-mutations')) {
    // Deliberate application mutations run only in the disposable CI checkout.
    requireContract(process.env.CI === 'true', 'mutations_ci_only')
    for (const mutation of mutations) {
      stage = 'historical-proof'
      const file = path.join(ROOT, mutation.target)
      const original = readFileSync(file)
      const text = original.toString('utf8')
      requireContract(text.split(mutation.before).length === 2, 'mutation_target_changed')
      const changed = text.replace(mutation.before, mutation.after)
      try {
        writeFileSync(file, changed)
        // A fresh producer and fresh transpiling Jest process bind both sides to this deliberate
        // source snapshot. No typecheck failure or stale pristine fixtures can count as a kill.
        const result = runGeneration(mutation.id, { responsesOnly: true, expectedFailures: mutation.failures })
        proof.mutants.push({ ...result, source_before: digest(original), source_mutated: digest(changed) })
      } finally { writeFileSync(file, original) }
      requireContract(JSON.stringify(fingerprints()) === JSON.stringify(originalSources), 'sources_not_restored')
      proof[mutation.id === 'historical-inventory-enum' ? 'restored_after_enum' : 'pristine_after'] = runGeneration('restored-' + mutation.id,
        { responsesOnly: mutation.id === 'historical-inventory-enum' })
    }
  }
  requireContract(JSON.stringify(fingerprints()) === JSON.stringify(originalSources), 'sources_not_restored')
  proof.source_hashes_restored = true
  proof.status = 'passed'
  writeFileSync(proofFile, JSON.stringify(proof, null, 2) + '\n', { mode: 0o600 })
  console.log(`http-contract status=passed cases=${catalog.cases.length} checks=${catalog.checks.length} historical_proofs=${proof.mutants.length}`)
} catch (error) {
  proof.status = 'failed'
  proof.stage = stage
  proof.code = error instanceof ContractFailure ? error.code : 'unexpected_failure'
  if (proofFile) writeFileSync(proofFile, JSON.stringify(proof, null, 2) + '\n', { mode: 0o600 })
  console.error(`http-contract stage=${stage} code=${proof.code}`)
  process.exitCode = 1
}
