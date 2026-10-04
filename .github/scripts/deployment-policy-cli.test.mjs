import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
import { mkdtempSync, writeFileSync, readFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import test from 'node:test'

const policy = new URL('./deployment-policy.mjs', import.meta.url).pathname
const a = 'a'.repeat(40), b = 'b'.repeat(40)

test('CLI metadata gates preserve source/artifact identity and terminal cancellation evidence', () => {
  const dir = mkdtempSync(join(tmpdir(), 'phr-policy-'))
  try {
    const stub = (name, source) => writeFileSync(join(dir, name), '#!/usr/bin/env node\n' + source, { mode: 0o700 })
    stub('gh', `const fs = require('fs'); const data = JSON.parse(fs.readFileSync(process.env.FIXTURE_API)); const key = process.argv[3]; if (!(key in data)) process.exit(2); console.log(JSON.stringify(data[key]));`)
    stub('git', `if (process.argv[2] === 'rev-parse') console.log(process.env.GITHUB_SHA); else if (process.argv[2] === 'merge-base') process.exit(process.argv[4] === process.argv[5] || process.argv[4] === '${a}' && process.argv[5] === '${b}' ? 0 : 1); else process.exit(2);`)
    stub('ssh', `console.log(JSON.stringify({state:'selected',commit:'${a}',release:'live-1'}));`)
    const env = { ...process.env, PATH: dir + ':' + process.env.PATH, GITHUB_REPOSITORY: 'synthetic/phr', GITHUB_SHA: a, GITHUB_RUN_ID: '1', OHIF_SSH_TARGET: 'fixture', FIXTURE_API: join(dir, 'api'), GITHUB_OUTPUT: join(dir, 'output'), GITHUB_STEP_SUMMARY: join(dir, 'summary'), GITHUB_EVENT_PATH: join(dir, 'event') }
    const data = {
      'repos/synthetic/phr/commits/main': { sha: b },
      'repos/synthetic/phr/actions/workflows/ci.yml/runs?branch=main&per_page=100': { workflow_runs: [{ id: 2, head_sha: b, head_branch: 'main' }] },
      'repos/synthetic/phr/actions/runs/2/jobs?filter=latest&per_page=100': { total_count: 1, jobs: [{name:'Run Tests',conclusion:'success'}] },
      'repos/synthetic/phr/actions/artifacts?name=ohif-dist&per_page=100': { artifacts: [{ name: 'ohif-dist', id: 20, expired: false, digest: 'sha256:' + 'a'.repeat(64), workflow_run: {id: 2, head_sha: b, head_branch: 'main'} }] },
      'repos/synthetic/phr/actions/workflows/ohif-dist.yml': {id: 9},
      'repos/synthetic/phr/actions/runs/2': {workflow_id: 9, head_sha: b, head_branch: 'main'},
    }
    const invoke = mode => {
      writeFileSync(env.FIXTURE_API, JSON.stringify(data))
      writeFileSync(env.GITHUB_OUTPUT, '')
      return JSON.parse(execFileSync(process.execPath, [policy, mode], {env,cwd:dir,encoding:'utf8'}))
    }
    assert.equal(invoke('app').superseded_by_commit, b)
    assert.match(readFileSync(env.GITHUB_OUTPUT,'utf8'), /proceed=false\n/)
    data['repos/synthetic/phr/actions/runs/2/jobs?filter=latest&per_page=100'].jobs[0].conclusion = 'failure'
    assert.equal(invoke('app').proceed, true)
    assert.equal(invoke('ohif').artifact_id, '20')
    data['repos/synthetic/phr/actions/runs/2'].workflow_id = 10
    assert.throws(() => invoke('ohif'))
    data['repos/synthetic/phr/actions/runs/2'].workflow_id = 9
    writeFileSync(env.GITHUB_EVENT_PATH, JSON.stringify({workflow_run: {id:1,run_attempt:1,head_sha:a,head_branch:'main',name:'CI',conclusion:'cancelled'}}))
    assert.equal(invoke('audit').conclusion, 'cancelled')
    const audit = JSON.parse(readFileSync(join(dir,'deployment-request.json'),'utf8'))
    assert.equal(audit.desired_main_commit, b)
    assert.equal(audit.desired_ohif.artifact_id, '20')
    assert.match(readFileSync(env.GITHUB_STEP_SUMMARY,'utf8'), /superseded_by_commit/)
  } finally { rmSync(dir, { recursive:true,force:true }) }
})
