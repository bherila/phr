#!/usr/bin/env node
/**
 * Sensitive-data scanner for a PUBLIC repository holding health-application code.
 *
 * The threat this defends against is not a hostile actor — it is an ordinary commit that
 * pastes a real record into a fixture, a debug dump, or a doc while reproducing a bug. In a
 * public repo that is unrecoverable: deleting the file does not remove it from history, and
 * forks and mirrors may already have it.
 *
 * Two design rules follow from that:
 *
 * 1. **Never print the matched text.** CI logs for a public repo are public. A scanner that
 *    echoes the PII it found leaks exactly what it exists to prevent. Matches are reported as
 *    file, line, and rule name, with the value redacted to a length and a shape.
 * 2. **Prefer precision over recall.** A noisy scanner gets disabled, and a disabled scanner
 *    catches nothing. Every rule below targets a shape that is nearly always real when it
 *    appears. Broad heuristics (any name-like string, any date) are deliberately absent —
 *    they belong in review, not in a blocking gate.
 *
 * Usage:
 *   node scripts/scan-sensitive.mjs            # scan all tracked files
 *   node scripts/scan-sensitive.mjs --staged   # scan staged changes only (pre-commit)
 *
 * Exit code 1 on any finding.
 */

import { execFileSync } from 'node:child_process'
import { closeSync, constants, fstatSync, lstatSync, openSync, readFileSync, readSync, statSync } from 'node:fs'
import { inspectSchemaDump, MAX_SCHEMA_BYTES, SCHEMA_PATHS } from './schema-dump-scan.mjs'
import path from 'node:path'

/** Files whose contents are never scanned — generated, vendored, or binary by nature. */
const SKIP_DIRS = new Set(['node_modules', 'vendor', '.git', 'dist', 'build', 'coverage'])
const SKIP_FILES = new Set([
  'pnpm-lock.yaml',
  'package-lock.json',
  'composer.lock',
  'scripts/scan-sensitive.mjs',
  'scripts/sensitive-scan-allow.txt',
])
const SKIP_EXT = new Set([
  '.png', '.jpg', '.jpeg', '.gif', '.webp', '.ico', '.svg',
  '.woff', '.woff2', '.ttf', '.eot', '.mp3', '.mp4', '.wav',
])

/**
 * Health/clinical payload formats. These should never be committed at all — real or
 * synthetic — because a reviewer cannot tell which is which by looking, and the cost of
 * being wrong is unrecoverable. Test fixtures should be generated at runtime instead.
 */
const FORBIDDEN_EXT = new Set(['.dcm', '.dicom', '.hl7', '.ccda', '.ccd', '.cda'])

/**
 * Each rule is deliberately shaped so that a match is almost certainly a real identifier
 * rather than a coincidence. `context` rules require a nearby keyword, which is what keeps
 * the false-positive rate low enough for a blocking gate.
 */
const RULES = [
  {
    // A public repository must not map the private estate it shares
    // infrastructure with. These strings are not secrets, but together they
    // tell a reader which private repositories exist and how to find them.
    //
    // Only the GitHub path is forbidden, never a bare product name: a private
    // repository and a public product can share a word, and forbidding the
    // word would make the product's own name unwritable here.
    //
    // `mcp-laravel-bridge` is deliberately NOT matched yet. It is a private
    // repository named in two public composer manifests as a hard `require`
    // over SSH, which is a structural problem rather than a stray string:
    // those repositories cannot be built by anyone outside the account. Add it
    // to this pattern once the dependency is published, vendored, or removed.
    name: 'private-repo-reference',
    why: 'Names a private repository this public repository must not map.',
    re: /\b2025-website\b|github\.com[/:]bherila\/(?:2025-website|planfina)\b/gi,
  },
  {
    // Provenance narration. Phrased narrowly on purpose: a bare "extracted
    // from" is ordinary English about parsed documents, so only the framings
    // that describe this repository's own origin are matched.
    name: 'repository-provenance',
    why: "Narrates this repository's own origin in a private system.",
    re: /\bprivate monorepo\b|\bformerly part of\b|\bpredecessor repo(?:sitory)?\b|\bextracted from (?:a|the) (?:private|monorepo)\b|\bmoved out of the monorepo\b/gi,
  },
  {
    name: 'us-ssn',
    why: 'Looks like a US Social Security Number.',
    // Excludes the reserved/invalid ranges the SSA never issues, which are what most
    // synthetic fixtures use (000-, 666-, 9xx-).
    re: /\b(?!000|666|9\d{2})\d{3}-(?!00)\d{2}-(?!0000)\d{4}\b/g,
  },
  {
    name: 'medical-record-number',
    why: 'A medical record number next to an MRN label.',
    re: /\bMRN\b[^A-Za-z0-9]{0,4}([A-Z]{0,3}\d{6,12})\b/gi,
  },
  {
    name: 'national-provider-id',
    why: 'A 10-digit NPI next to an NPI label.',
    re: /\bNPI\b[^A-Za-z0-9]{0,4}(\d{10})\b/gi,
  },
  {
    name: 'date-of-birth',
    why: 'A date of birth next to a DOB label.',
    re: /\b(?:DOB|date[ _-]?of[ _-]?birth|birth[ _-]?date)\b[^A-Za-z0-9]{0,4}(\d{1,4}[/-]\d{1,2}[/-]\d{1,4})/gi,
  },
  {
    name: 'insurance-member-id',
    why: 'A member/policy/subscriber id next to its label.',
    re: /\b(?:member|policy|subscriber|insurance)[ _-]?(?:id|number|no)\b[^A-Za-z0-9]{0,4}([A-Z0-9]{8,})/gi,
  },
  {
    name: 'real-email',
    why: 'An email address on a domain that is not a documentation/test domain.',
    // RFC 2606 reserves example.* and .test/.invalid/.localhost for exactly this purpose.
    re: /\b[A-Za-z0-9._%+-]+@(?!example\.(?:com|org|net)\b)(?!.*\.(?:test|invalid|localhost)\b)(?!localhost\b)[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/g,
    // Placeholders and the app's own public domains are not personal data.
    ignore: /^git@github\.com$|@(?:bherila\.net|users\.noreply\.github\.com|sentry\.io|.*\.example)\b|\{|\}|\$|:[a-z]/i,
  },
]

function redact(value) {
  const s = String(value)
  if (s.length <= 4) return `<${s.length} chars>`
  return `${s.slice(0, 2)}${'*'.repeat(Math.max(1, s.length - 4))}${s.slice(-2)} <${s.length} chars>`
}

function loadAllowlist() {
  try {
    return new Set(
      readFileSync(path.join(process.cwd(), 'scripts/sensitive-scan-allow.txt'), 'utf8')
        .split('\n')
        .map((l) => l.trim())
        .filter((l) => l && !l.startsWith('#')),
    )
  } catch {
    return new Set()
  }
}

function trackedFiles(stagedOnly) {
  const args = stagedOnly
    ? ['diff', '--cached', '--name-only', '--diff-filter=ACMR']
    : ['ls-files']
  return execFileSync('git', args, { encoding: 'utf8' })
    .split('\n')
    .map((f) => f.trim())
    .filter(Boolean)
}

function shouldScan(file) {
  if (SKIP_FILES.has(file)) return false
  if (file.split('/').some((seg) => SKIP_DIRS.has(seg))) return false
  if (SKIP_EXT.has(path.extname(file).toLowerCase())) return false
  try {
    if (statSync(file).size > 2 * 1024 * 1024) return false
  } catch {
    return false
  }
  return true
}

/** Known dumps are bounded inputs, never generic size/binary/ignore exclusions. */
function readSchema(file, stagedOnly) {
  let bytes
  if (stagedOnly) {
    const git = (args, maxBuffer = MAX_SCHEMA_BYTES + 1) => execFileSync('git', args, {
      stdio: ['ignore', 'pipe', 'pipe'], maxBuffer, timeout: 5000,
    })
    const entry = git(['ls-files', '--stage', '--', file], 1024).toString('utf8')
    if (!/^(100644|100755) [a-f0-9]{40,64} 0\t/.test(entry) || entry.split('\n').filter(Boolean).length !== 1) throw new Error('schema-input')
    const size = git(['cat-file', '-s', `:${file}`], 64).toString('ascii').trim()
    if (!/^\d+$/.test(size) || Number(size) > MAX_SCHEMA_BYTES) throw new Error('schema-size')
    bytes = git(['show', `:${file}`])
  } else {
    const parts = file.split('/')
    for (let i = 1; i < parts.length; i++) {
      const dir = lstatSync(parts.slice(0, i).join('/'))
      if (!dir.isDirectory() || dir.isSymbolicLink()) throw new Error('schema-input')
    }
    const fd = openSync(file, constants.O_RDONLY | constants.O_NOFOLLOW | constants.O_NONBLOCK)
    try {
      const stat = fstatSync(fd)
      if (!stat.isFile()) throw new Error('schema-input')
      if (stat.size > MAX_SCHEMA_BYTES) throw new Error('schema-size')
      const buffer = Buffer.alloc(MAX_SCHEMA_BYTES + 1)
      let length = 0
      while (length < buffer.length) {
        const count = readSync(fd, buffer, length, buffer.length - length, null)
        if (count === 0) break
        length += count
      }
      bytes = buffer.subarray(0, length)
    } finally { closeSync(fd) }
  }
  if (bytes.length > MAX_SCHEMA_BYTES) throw new Error('schema-size')
  const text = new TextDecoder('utf-8', { fatal: true }).decode(bytes)
  if (text.includes('\u0000')) throw new Error('schema-input')
  return text
}

function scanSchema(file, stagedOnly, allow) {
  const results = []
  const add = (rule, line, why) => {
    if (results.length < 100 && !results.some((f) => f.rule === rule && f.line === line)) results.push({ file, line, rule, why, shown: '<redacted>' })
  }
  let text
  try { text = readSchema(file, stagedOnly) } catch (error) {
    add(error.message === 'schema-size' ? 'schema-size' : 'schema-input', 1, 'Known schema dump must be a readable regular UTF-8 file without NUL, at most 2 MiB.')
    return results
  }
  const inspection = inspectSchemaDump(text, SCHEMA_PATHS.get(file))
  if (inspection.error) add(inspection.error.rule, inspection.error.line, 'Schema dump contains data, unsupported SQL, or malformed generated DDL; only migration-ledger rows are permitted.')
  const scan = (content, line, raw = false) => {
    for (const rule of RULES) {
      rule.re.lastIndex = 0
      let match
      while (results.length < 100 && (match = rule.re.exec(content)) !== null) {
        const value = match[1] ?? match[0]
        if (allow.has(value) || (rule.ignore && rule.ignore.test(match[0]))) continue
        const valueOffset = match.index + match[0].lastIndexOf(value)
        if (raw && rule.name === 'insurance-member-id' && value.toLowerCase() === 'references' && inspection.references.has(valueOffset)) continue
        add(rule.name, line + (content.slice(0, match.index).match(/\n/g) ?? []).length, rule.why)
      }
    }
  }
  // No inline-ignore escape. Also inspect decoded literals and labelled defaults;
  // SQL escaping or line breaks must not obscure an existing disclosure pattern.
  scan(text, 1, true)
  for (const segment of inspection.segments) scan(segment.text, segment.line)
  return results
}

const stagedOnly = process.argv.includes('--staged')
const allow = loadAllowlist()
const findings = []

for (const file of trackedFiles(stagedOnly)) {
  if (SCHEMA_PATHS.has(file)) {
    findings.push(...scanSchema(file, stagedOnly, allow))
    continue
  }
  const ext = path.extname(file).toLowerCase()
  if (FORBIDDEN_EXT.has(ext)) {
    findings.push({ file, line: 0, rule: 'clinical-payload-file', why: `A ${ext} file must never be committed; generate fixtures at runtime instead.`, shown: ext })
    continue
  }
  if (!shouldScan(file)) continue

  let text
  try {
    text = readFileSync(file, 'utf8')
  } catch {
    continue
  }
  // A raw NUL byte here would be silently dropped by a formatter or a copy-paste,
  // turning this into includes('') - always true - which would skip every file and
  // make the scanner a silent no-op. Keep it as an escape.
  if (text.includes('\u0000')) continue // binary

  const lines = text.split('\n')
  for (let i = 0; i < lines.length; i++) {
    const line = lines[i]
    if (line.includes('sensitive-scan-ignore')) continue
    for (const rule of RULES) {
      rule.re.lastIndex = 0
      let m
      while ((m = rule.re.exec(line)) !== null) {
        const value = m[1] ?? m[0]
        if (allow.has(value)) continue
        if (rule.ignore && rule.ignore.test(m[0])) continue
        findings.push({ file, line: i + 1, rule: rule.name, why: rule.why, shown: redact(value) })
      }
    }
  }
}

if (findings.length === 0) {
  console.log(`sensitive-scan: clean (${stagedOnly ? 'staged changes' : 'all tracked files'})`)
  process.exit(0)
}

console.error(`\nsensitive-scan: ${findings.length} potential disclosure(s). Values are redacted on purpose — CI logs for a public repo are public.\n`)
for (const f of findings) {
  console.error(`  ${f.file}:${f.line}  [${f.rule}]  ${f.shown}`)
  console.error(`      ${f.why}`)
}
console.error(`
If a match is synthetic and safe:
  - prefer changing the fixture to an obviously-fake value (Faker, 000-00-0000, user@example.com)
  - or append the exact value to scripts/sensitive-scan-allow.txt
  - or add a 'sensitive-scan-ignore' comment on that line (never for known schema dumps)

Do NOT allowlist a value that is real. Committing it to a public repo cannot be undone by
deleting it later — the value stays in history, and in every fork and mirror.
`)
process.exit(1)
