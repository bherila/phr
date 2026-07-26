// Jest 30 does not honor a per-project `testTimeout` declared inside `projects[]` (it falls back to
// the 5000ms default), so set the per-test ceiling here where it is reliably applied. Gives the
// shared CI runners headroom to absorb scheduling latency without flaking otherwise-correct tests.
// See jest.setup.ts for why this is 45000ms rather than 20000ms.
jest.setTimeout(45000);
