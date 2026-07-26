import { readLocalVolume, writeLocalVolume } from '../pipeline/volumeLocalCache'

/* Node/jsdom test env has no IndexedDB; the cache must degrade to a no-op
 * rather than throwing, so the pipeline still works where storage is absent. */
describe('volumeLocalCache without IndexedDB', () => {
  const original = (globalThis as { indexedDB?: unknown }).indexedDB

  afterEach(() => {
    ;(globalThis as { indexedDB?: unknown }).indexedDB = original
  })

  it('readLocalVolume resolves null when IndexedDB is unavailable', async () => {
    ;(globalThis as { indexedDB?: unknown }).indexedDB = undefined
    await expect(readLocalVolume('1.2.3')).resolves.toBeNull()
  })

  it('writeLocalVolume resolves without throwing when IndexedDB is unavailable', async () => {
    ;(globalThis as { indexedDB?: unknown }).indexedDB = undefined
    await expect(writeLocalVolume('1.2.3', new ArrayBuffer(8))).resolves.toBeUndefined()
  })
})
