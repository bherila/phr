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
import { readFileSync, statSync } from 'node:fs'
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
    ignore: /@(?:bherila\.net|users\.noreply\.github\.com|sentry\.io|.*\.example)\b|\{|\}|\$|:[a-z]/i,
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

const stagedOnly = process.argv.includes('--staged')
const allow = loadAllowlist()
const findings = []

for (const file of trackedFiles(stagedOnly)) {
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
  - or add a 'sensitive-scan-ignore' comment on that line

Do NOT allowlist a value that is real. Committing it to a public repo cannot be undone by
deleting it later — the value stays in history, and in every fork and mirror.
`)
process.exit(1)
