import { PIPELINE_VERSION } from '../pipeline/protocol'
import type { AssembledVolume } from '../pipeline/volumeAssembly'
import { decodeVolumeCacheArtifact, encodeVolumeCacheArtifact } from '../pipeline/volumeCacheArtifact'

function makeVolume(): AssembledVolume {
  const data = new Int16Array(2 * 3 * 4)
  for (let index = 0; index < data.length; index++) {
    data[index] = index * 7 - 100
  }
  return {
    data,
    dims: [2, 3, 4],
    spacing: [0.5, 0.5, 1.25],
    origin: [-10, 20, 30],
    orientation: [1, 0, 0, 0, 0, -1],
    droppedInstanceIds: [],
    warnings: [],
  }
}

describe('volumeCacheArtifact', () => {
  it('round-trips a volume through encode/decode', async () => {
    const volume = makeVolume()
    const blob = await encodeVolumeCacheArtifact(volume)

    const gzipMagic = new Uint8Array(await blob.slice(0, 2).arrayBuffer())
    expect([gzipMagic[0], gzipMagic[1]]).toEqual([0x1f, 0x8b])

    const decoded = await decodeVolumeCacheArtifact(blob.stream())
    expect(decoded.dims).toEqual(volume.dims)
    expect(decoded.spacing).toEqual(volume.spacing)
    expect(decoded.origin).toEqual(volume.origin)
    expect(decoded.orientation).toEqual(volume.orientation)
    expect(Array.from(decoded.data)).toEqual(Array.from(volume.data))
  })

  it('rejects non-artifact bytes', async () => {
    const junk = new Blob([new Uint8Array([1, 2, 3, 4, 5])])
    const gzipped = new Response(junk.stream().pipeThrough(new CompressionStream('gzip')))
    const stream = (await gzipped.blob()).stream()
    await expect(decodeVolumeCacheArtifact(stream)).rejects.toThrow(/not a volume cache artifact/)
  })

  it('rejects artifacts from a different pipeline version', async () => {
    const volume = makeVolume()
    const blob = await encodeVolumeCacheArtifact(volume)
    const bytes = new Uint8Array(
      await new Response(blob.stream().pipeThrough(new DecompressionStream('gzip'))).arrayBuffer(),
    )
    const headerLength = new DataView(bytes.buffer).getUint32(4, true)
    const headerText = new TextDecoder().decode(bytes.subarray(8, 8 + headerLength))
    expect(JSON.parse(headerText).pipeline_version).toBe(PIPELINE_VERSION)

    /* Corrupt the version in place: same length replacement keeps offsets valid. */
    const tampered = headerText.replace(`"pipeline_version":${PIPELINE_VERSION}`, `"pipeline_version":${PIPELINE_VERSION + 8}`)
    const tamperedBytes = new Uint8Array(bytes)
    tamperedBytes.set(new TextEncoder().encode(tampered), 8)
    const regzipped = new Blob([tamperedBytes]).stream().pipeThrough(new CompressionStream('gzip'))
    const stream = (await new Response(regzipped).blob()).stream()
    await expect(decodeVolumeCacheArtifact(stream)).rejects.toThrow(/pipeline version mismatch/)
  })
})
