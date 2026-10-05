import assert from 'node:assert/strict'
import test from 'node:test'

import { chooseAppPlan, chooseArtifact, inventoryArtifacts, validatedAttempts } from './deployment-policy.mjs'

const a = 'a'.repeat(40), b = 'b'.repeat(40), c = 'c'.repeat(40), fork = 'd'.repeat(40)
const chain = [a, b, c]
const ancestor = async (left, right) => left === right || (chain.includes(left) && chain.includes(right) && chain.indexOf(left) <= chain.indexOf(right))
const request = (id, head_sha, pass = true) => ({ id, head_sha, head_branch: 'main', event: 'push', repository: {full_name:'synthetic/phr'}, head_repository: {full_name:'synthetic/phr'}, pass })
const plan = overrides => chooseAppPlan({ repo: 'synthetic/phr', candidate: request(10, b), live: { state: 'selected', commit: a, release: 'live-9' }, main: c, runs: [], ancestor, validated: async r => r.pass, ...overrides })

test('newer validated main supersedes a queued app request with traceable identity', async () => {
  assert.deepEqual(await plan({ runs: [request(11, c)] }), { proceed: false, reason: 'validated-newer', source_commit: b, superseded_by_commit: c, superseded_by_run: '11' })
})
test('a delayed or manually rerun older source cannot replace newer live code', async () => {
  const result = await plan({ live: { state: 'selected', commit: c, release: 'live-11' } })
  assert.equal(result.proceed, false)
  assert.equal(result.superseded_by_release, 'live-11')
})
test('unvalidated newer main does not displace the latest validated request', async () => {
  assert.equal((await plan({ runs: [request(11, c, false)] })).proceed, true)
})
test('a newer run id carrying older source never supersedes newer source', async () => {
  assert.equal((await plan({ runs: [request(99, a)] })).proceed, true)
})
test('forward revert commits are ordinary validated descendants', async () => {
  assert.equal((await plan({ candidate: request(12, c), live: { state: 'selected', commit: b } })).proceed, true)
})
test('divergent live history and source outside main fail closed', async () => {
  await assert.rejects(plan({ live: { commit: fork } }), /diverges/)
  await assert.rejects(plan({ candidate: request(12, fork) }), /outside/)
})
test('old requests outside a full bounded inventory fail closed', async () => {
  await assert.rejects(plan({ runs: Array.from({ length: 100 }, (_, i) => request(i + 100, a)) }), /bounded/)
})
test('fresh installs are identified without inventing a live commit', async () => {
  assert.equal((await plan({ live: { state: 'absent', commit: '' } })).bootstrap, true)
})
const artifact = (run, artifactId, extra = {}) => ({ name: 'ohif-dist', id: artifactId, expired: false, digest: 'sha256:' + 'a'.repeat(64), workflow_run: { id: run, head_sha: b, head_branch: 'main' }, ...extra })
test('OHIF desired state uses source-run ordering rather than artifact upload order', () => {
  assert.equal(chooseArtifact([artifact(10, 100), artifact(11, 90)]).run_id, '11')
  assert.equal(chooseArtifact([artifact(11, 90), artifact(11, 91)]).artifact_id, '91')
})
test('canceled OHIF deploys retain eligible built artifacts; expired and non-main artifacts do not', () => {
  assert.equal(chooseArtifact([artifact(10, 1, { conclusion: 'cancelled' }), artifact(12, 3, { expired: true })]).run_id, '10')
  assert.equal(chooseArtifact([artifact(10, 1, { workflow_run: { id: 10, head_branch: 'feature' } })]), null)
})
test('incomplete OHIF artifact identity fails closed', () => {
  assert.throws(() => chooseArtifact([artifact(10, 1, { digest: null })]), /identity/)
})

test('a failed rerun does not hide a previously validated run for the same source', async () => {
  assert.equal((await plan({ runs: [request(13, c, false), request(12, c)] })).superseded_by_run, '12')
})


test('artifact pagination discovers a higher source run hidden behind newer uploads', () => {
  const page1 = Array.from({length:100}, (_, i) => artifact(10, i+100))
  const all = inventoryArtifacts(page => ({total_count:101,artifacts:page === 1 ? page1 : [artifact(11,1)]}))
  assert.equal(chooseArtifact(all).run_id, '11')
})
test('truncated, oversized and changing artifact inventories fail closed', () => {
  assert.throws(() => inventoryArtifacts(() => ({total_count:501,artifacts:[]})), /bound/)
  assert.throws(() => inventoryArtifacts(() => ({total_count:101,artifacts:[artifact(10,1)]})), /incomplete/)
  assert.throws(() => inventoryArtifacts(page => ({total_count:101,artifacts:page === 1 ? Array.from({length:100},(_,i)=>artifact(10,i+1)) : [artifact(10,1)]})), /changed/)
})


test('fork and pull-request main runs never enter ancestry or validation probes', async () => {
  const untrusted = [ {...request(14, fork), event:'pull_request'}, {...request(15, fork), head_repository:{full_name:'fork/phr'}} ]
  assert.equal((await plan({runs:untrusted, ancestor:async (a,b) => {assert.notEqual(a,fork);assert.notEqual(b,fork);return ancestor(a,b)}, validated:async () => {throw new Error('untrusted validation')}})).proceed,true)
})
test('validation preserves a successful prior attempt of the same run', () => {
  assert.equal(validatedAttempts(() => ({total_count:2,jobs:[{id:1,name:'Run Tests',run_attempt:1,conclusion:'success'},{id:2,name:'Run Tests',run_attempt:2,conclusion:'failure'}]})),true)
  assert.equal(validatedAttempts(() => ({total_count:2,jobs:[{id:1,name:'Run Tests',run_attempt:1,conclusion:'success'},{id:2,name:'Run Tests',run_attempt:1,conclusion:'failure'}]})),false)
  const jobs = Array.from({length:100},(_,i)=>({id:i+1,name:'other',run_attempt:2}))
  assert.equal(validatedAttempts(page => ({total_count:101,jobs:page===1?jobs:[{id:101,name:'Run Tests',run_attempt:1,conclusion:'success'}]})),true)
  assert.throws(() => validatedAttempts(() => ({total_count:501,jobs:[]})), /bound/)
  assert.throws(() => validatedAttempts(() => ({total_count:101,jobs:[]})), /incomplete/)
})
