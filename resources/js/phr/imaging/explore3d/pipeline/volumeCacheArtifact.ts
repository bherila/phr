import { PIPELINE_VERSION } from './protocol'
import type { AssembledVolume } from './volumeAssembly'

/**
 * Derived volume-cache artifact: the 2x-downsampled Int16 volume plus its
 * geometry, gzipped. Layout (before gzip):
 *   bytes 0-3   magic "V3D1"
 *   bytes 4-7   uint32 LE header JSON byte length
 *   header JSON (dims, spacing, origin, orientation, pipeline_version)
 *   Int16 LE voxel data
 * The server treats the artifact as opaque (it only checks the gzip magic);
 * this module is the format's single owner.
 */
const MAGIC = 'V3D1'

interface ArtifactHeader {
  pipeline_version: number
  dims: [number, number, number]
  spacing: [number, number, number]
  origin: [number, number, number]
  orientation: [number, number, number, number, number, number]
}

export interface CachedVolume {
  data: Int16Array
  dims: [number, number, number]
  spacing: [number, number, number]
  origin: [number, number, number]
  orientation: [number, number, number, number, number, number]
}

async function pipeThrough(stream: ReadableStream<Uint8Array>, transform: GenericTransformStream): Promise<ArrayBuffer> {
  return new Response(stream.pipeThrough(transform)).arrayBuffer()
}

export async function encodeVolumeCacheArtifact(volume: AssembledVolume): Promise<Blob> {
  const header: ArtifactHeader = {
    pipeline_version: PIPELINE_VERSION,
    dims: volume.dims,
    spacing: volume.spacing,
    origin: volume.origin,
    orientation: volume.orientation,
  }
  const headerBytes = new TextEncoder().encode(JSON.stringify(header))
  const prefix = new Uint8Array(8)
  prefix.set(new TextEncoder().encode(MAGIC), 0)
  new DataView(prefix.buffer).setUint32(4, headerBytes.length, true)
  const raw = new Blob([
    prefix,
    headerBytes,
    new Uint8Array(volume.data.buffer as ArrayBuffer, volume.data.byteOffset, volume.data.byteLength),
  ])
  const gzipped = await pipeThrough(raw.stream(), new CompressionStream('gzip'))
  return new Blob([gzipped], { type: 'application/gzip' })
}

export async function decodeVolumeCacheArtifact(gzipped: ReadableStream<Uint8Array>): Promise<CachedVolume> {
  const buffer = await pipeThrough(gzipped, new DecompressionStream('gzip'))
  const bytes = new Uint8Array(buffer)
  if (bytes.length < 8 || new TextDecoder().decode(bytes.subarray(0, 4)) !== MAGIC) {
    throw new Error('not a volume cache artifact')
  }
  const headerLength = new DataView(buffer).getUint32(4, true)
  const header = JSON.parse(new TextDecoder().decode(bytes.subarray(8, 8 + headerLength))) as ArtifactHeader
  if (header.pipeline_version !== PIPELINE_VERSION) {
    throw new Error(`pipeline version mismatch (artifact v${header.pipeline_version}, expected v${PIPELINE_VERSION})`)
  }
  const [nx, ny, nz] = header.dims
  const expectedBytes = nx * ny * nz * 2
  const dataOffset = 8 + headerLength
  if (bytes.length - dataOffset !== expectedBytes) {
    throw new Error(`artifact data length mismatch (${bytes.length - dataOffset} != ${expectedBytes})`)
  }
  return {
    data: new Int16Array(buffer.slice(dataOffset, dataOffset + expectedBytes)),
    dims: header.dims,
    spacing: header.spacing,
    origin: header.origin,
    orientation: header.orientation,
  }
}
