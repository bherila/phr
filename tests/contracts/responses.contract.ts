import { writeFileSync } from 'node:fs'

import { context, loadBundle, requireContract } from '../../scripts/contracts/protocol.mjs'
import { assertResponse, inspectResponse } from './validateResponses.mjs'

const bundle = loadBundle(process.env.PHR_HTTP_CONTRACT_DIR, context())
const results = [...bundle.responses.values()].map(response => ({
  case_id: response.scenario.id,
  schema: response.scenario.schema,
  issues: inspectResponse(response),
}))

afterAll(() => {
  requireContract(typeof process.env.PHR_HTTP_CONTRACT_REPORT === 'string', 'report_missing')
  writeFileSync(process.env.PHR_HTTP_CONTRACT_REPORT, JSON.stringify({
    candidate: process.env.PHR_HTTP_CONTRACT_CANDIDATE,
    generation: process.env.PHR_HTTP_CONTRACT_GENERATION,
    results,
  }))
})

for (const scenario of bundle.catalog.cases) {
  test(`response/${scenario.id}`, () => {
    assertResponse(bundle.responses.get(scenario.id))
  })
}
