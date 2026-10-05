import { execFileSync } from 'node:child_process'
import { appendFileSync, readFileSync, writeFileSync } from 'node:fs'
import { fileURLToPath, pathToFileURL } from 'node:url'

const sha = value => typeof value === 'string' && /^[a-f0-9]{40}$/.test(value)
const id = value => /^[1-9][0-9]{0,15}$/.test(String(value))

export function chooseArtifact(artifacts) {
  const eligible = artifacts.filter(a => a.name === 'ohif-dist' && a.expired === false && a.workflow_run?.head_branch === 'main')
  if (eligible.some(a => !id(a.id) || !id(a.workflow_run.id))) throw new Error('OHIF artifact ordering identity invalid')
  const descending = (a, b) => BigInt(a) === BigInt(b) ? 0 : BigInt(a) > BigInt(b) ? -1 : 1
  eligible.sort((a, b) => descending(a.workflow_run.id, b.workflow_run.id) || descending(a.id, b.id))
  const artifact = eligible[0]
  if (!artifact) return null
  if (!id(artifact.id) || !id(artifact.workflow_run.id) || !sha(artifact.workflow_run.head_sha) || !/^sha256:[a-f0-9]{64}$/.test(artifact.digest)) {
    throw new Error('OHIF artifact identity is incomplete')
  }
  return { run_id: String(artifact.workflow_run.id), artifact_id: String(artifact.id), artifact_digest: artifact.digest, source_commit: artifact.workflow_run.head_sha }
}

export function inventoryArtifacts(fetchPage) {
  const artifacts = []
  let total
  for (let page = 1; page <= 5; page++) {
    const response = fetchPage(page)
    if (!Array.isArray(response.artifacts) || !Number.isSafeInteger(response.total_count) || response.total_count < 0) throw new Error('OHIF artifact inventory invalid')
    total ??= response.total_count
    if (total > 500) throw new Error('OHIF artifact inventory exceeds the 500-artifact bound')
    if (response.total_count !== total) throw new Error('OHIF artifact inventory changed during pagination')
    artifacts.push(...response.artifacts)
    if (new Set(artifacts.map(a => String(a.id))).size !== artifacts.length) throw new Error('OHIF artifact inventory changed during pagination')
    if (artifacts.length === total) return artifacts
    if (response.artifacts.length !== 100 || artifacts.length > total) throw new Error('OHIF artifact inventory is incomplete')
  }
  throw new Error('OHIF artifact inventory exceeds the page bound')
}

export function trustedMainRun(run, repo) {
  return run.head_branch === 'main' && ['push', 'workflow_dispatch'].includes(run.event)
    && run.repository?.full_name === repo && run.head_repository?.full_name === repo
}

export function validatedAttempts(fetchPage) {
  const jobs = []
  let total
  for (let page = 1; page <= 5; page++) {
    const response = fetchPage(page)
    if (!Array.isArray(response.jobs) || !Number.isSafeInteger(response.total_count) || response.total_count < 0) throw new Error('Validation job inventory invalid')
    total ??= response.total_count
    if (total > 500 || total !== response.total_count) throw new Error('Validation job inventory exceeds its bound or changed')
    jobs.push(...response.jobs)
    if (new Set(jobs.map(job => String(job.id))).size !== jobs.length) throw new Error('Validation job inventory changed')
    if (jobs.length === total) {
      const attempts = new Map()
      for (const job of jobs.filter(job => job.name === 'Run Tests')) {
        if (!id(job.run_attempt)) throw new Error('Validation attempt identity invalid')
        const gates = attempts.get(String(job.run_attempt)) ?? []
        gates.push(job)
        attempts.set(String(job.run_attempt), gates)
      }
      return [...attempts.values()].some(gates => gates.length === 1 && gates[0].conclusion === 'success')
    }
    if (response.jobs.length !== 100 || jobs.length > total) throw new Error('Validation job inventory incomplete')
  }
  throw new Error('Validation job inventory exceeds its page bound')
}

export async function chooseAppPlan({ candidate, live, main, runs, ancestor, validated, repo }) {
  if (!sha(candidate.head_sha) || !sha(main) || !id(candidate.id)) throw new Error('Application request identity invalid')
  if (!await ancestor(candidate.head_sha, main)) throw new Error('Application source is outside current main history')
  if (live.commit) {
    if (!sha(live.commit)) throw new Error('Live commit identity invalid')
    if (!await ancestor(live.commit, candidate.head_sha)) {
      if (await ancestor(candidate.head_sha, live.commit)) return { proceed: false, reason: 'live-newer', source_commit: candidate.head_sha, superseded_by_commit: live.commit, superseded_by_release: live.release }
      throw new Error('Application source diverges from the live release; use a reviewed forward revert on main')
    }
  }
  if (runs.length >= 100 && !runs.some(run => String(run.id) === String(candidate.id))) throw new Error('Application request is outside the bounded run inventory')
  let desired = candidate
  const seen = new Set([candidate.head_sha])
  for (const run of runs) {
    if (!trustedMainRun(run, repo) || !sha(run.head_sha) || !id(run.id) || seen.has(run.head_sha)) continue
    if (!await ancestor(candidate.head_sha, run.head_sha) || !await ancestor(run.head_sha, main) || !await validated(run)) continue
    seen.add(run.head_sha)
    if (await ancestor(desired.head_sha, run.head_sha)) desired = run
    else if (!await ancestor(run.head_sha, desired.head_sha)) throw new Error('Validated main history is ambiguous')
  }
  if (desired.head_sha !== candidate.head_sha) return { proceed: false, reason: 'validated-newer', source_commit: candidate.head_sha, superseded_by_commit: desired.head_sha, superseded_by_run: String(desired.id) }
  return { proceed: true, reason: 'validated-forward', source_commit: candidate.head_sha, bootstrap: live.state === 'absent' }
}

function run(command, args, options = {}) {
  try { return execFileSync(command, args, { encoding: 'utf8', maxBuffer: 2 * 1024 * 1024, timeout: 30_000, stdio: ['pipe', 'pipe', 'pipe'], ...options }).trim() }
  catch { throw new Error('Deployment metadata probe failed or exceeded its bound') }
}

async function cli(mode) {
  const repo = process.env.GITHUB_REPOSITORY
  if (!/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/.test(repo ?? '')) throw new Error('Repository identity invalid')
  const deadline = Date.now() + 120_000
  const api = path => {
    if (Date.now() > deadline) throw new Error('Deployment metadata inventory exceeded its bound')
    return JSON.parse(run('gh', ['api', `repos/${repo}/${path}`], { timeout: Math.min(30_000, Math.max(1, deadline - Date.now())) }))
  }
  const resolve = () => {
    const artifact = chooseArtifact(inventoryArtifacts(page => api(`actions/artifacts?name=ohif-dist&per_page=100&page=${page}`)))
    if (artifact) {
      const workflow = api('actions/workflows/ohif-dist.yml')
      const source = api(`actions/runs/${artifact.run_id}`)
      if (source.workflow_id !== workflow.id || source.head_sha !== artifact.source_commit || source.head_branch !== 'main') throw new Error('OHIF artifact workflow identity mismatches')
    }
    return artifact
  }
  let result
  if (mode === 'app') {
    const candidate = { id: process.env.GITHUB_RUN_ID, head_sha: process.env.GITHUB_SHA }
    if (run('git', ['rev-parse', 'HEAD']) !== candidate.head_sha) throw new Error('Checkout differs from its frontend artifact source')
    const target = process.env.OHIF_SSH_TARGET
    if (!/^[A-Za-z0-9][A-Za-z0-9._-]*(@[A-Za-z0-9][A-Za-z0-9.-]*)?$/.test(target ?? '')) throw new Error('Deployment SSH target invalid')
    const script = readFileSync(new URL('./read-phr-live-identity.sh', import.meta.url), 'utf8')
    const live = JSON.parse(run('timeout', ['--kill-after=5s', '60s', process.env.PHR_DEPLOY_SSH_BIN ?? 'ssh', target, 'bash -s'], { input: script, timeout: 65_000 }))
    const known = new Set()
    const ensureCommit = commit => {
      if (!sha(commit)) throw new Error('Commit identity invalid')
      if (known.has(commit)) return
      try { execFileSync('git', ['cat-file', '-e', `${commit}^{commit}`], { stdio: 'ignore', timeout: 10_000 }) }
      catch {
        if (Date.now() >= deadline) throw new Error('Commit inventory exceeded its bound')
        run('git', ['fetch', '--no-tags', 'origin', commit], { timeout: Math.min(30_000, Math.max(1, deadline - Date.now())) })
        run('git', ['cat-file', '-e', `${commit}^{commit}`])
      }
      known.add(commit)
    }
    const ancestor = async (a, b) => {
      ensureCommit(a)
      ensureCommit(b)
      try { execFileSync('git', ['merge-base', '--is-ancestor', a, b], { stdio: 'ignore', timeout: 10_000 }); return true }
      catch (error) { if (error.status === 1) return false; throw new Error('Commit ancestry could not be proven', { cause: error }) }
    }
    result = await chooseAppPlan({ candidate, live, repo, main: api('commits/main').sha, runs: api('actions/workflows/ci.yml/runs?branch=main&per_page=100').workflow_runs ?? [], ancestor,
      validated: async request => {
        return validatedAttempts(page => api(`actions/runs/${request.id}/jobs?filter=all&per_page=100&page=${page}`))
      },
    })
  } else if (mode === 'ohif') result = resolve() ?? { run_id: '', artifact_id: '', artifact_digest: '', source_commit: '' }
  else if (mode === 'audit') {
    const request = JSON.parse(readFileSync(process.env.GITHUB_EVENT_PATH, 'utf8')).workflow_run
    if (!request || !id(request.id) || !sha(request.head_sha) || request.head_branch !== 'main' || !['CI', 'OHIF Dist'].includes(request.name) || !id(request.run_attempt) || !['success', 'failure', 'cancelled', 'skipped', 'timed_out', 'action_required', 'neutral', 'stale', 'startup_failure'].includes(request.conclusion)) throw new Error('Completed request identity invalid')
    const desiredMain = api('commits/main').sha
    if (!sha(desiredMain)) throw new Error('Desired application identity invalid')
    result = { request_run: String(request.id), request_attempt: request.run_attempt, source_commit: request.head_sha, conclusion: request.conclusion,
      desired_main_commit: desiredMain, desired_ohif: resolve(),
      note: 'Observed terminal request and desired sources; GitHub does not identify the cause of cancellation.' }
    writeFileSync('deployment-request.json', JSON.stringify(result, null, 2) + '\n')
  } else throw new Error('Deployment policy mode invalid')
  if (process.env.GITHUB_OUTPUT && mode !== 'audit') {
    for (const [key, value] of Object.entries(result)) appendFileSync(process.env.GITHUB_OUTPUT, `${key}=${value}\n`)
  }
  if (process.env.GITHUB_STEP_SUMMARY) appendFileSync(process.env.GITHUB_STEP_SUMMARY, `Deployment request (${mode}):\n\n\`\`\`json\n${JSON.stringify(result, null, 2)}\n\`\`\`\n`)
  process.stdout.write(JSON.stringify(result) + '\n')
}

if (process.argv[1] && fileURLToPath(import.meta.url) === fileURLToPath(pathToFileURL(process.argv[1]))) {
  cli(process.argv[2]).catch(error => { process.stderr.write(`${error.message}\n`); process.exitCode = 1 })
}
