import { analyzeAirRegions } from './airRegions'
import { marchingCubes } from './marchingCubes'
import { MAX_MESH_TRIANGLES, type MeshWorkerRequest, type MeshWorkerResponse } from './protocol'
import { type AssembledVolume,downsample2x, smoothField } from './volumeAssembly'

const scope = self as unknown as {
  onmessage: ((event: MessageEvent<MeshWorkerRequest>) => void) | null
  postMessage: (message: MeshWorkerResponse, transfer?: Transferable[]) => void
}

let reduced: AssembledVolume | null = null
/** Smoothed copy of the volume used for meshing (de-shattered); air analysis uses the raw data. */
let meshField: Int16Array | null = null
let classify = false

/**
 * Suggested threshold for scans without a calibrated scale (MR): the midpoint
 * between the volume's 5th and 95th percentile, which lands between
 * background/air and tissue for typical MR intensity histograms.
 */
function suggestThreshold(data: Int16Array): number {
  const sample = new Int16Array(Math.min(data.length, 262144))
  const stride = Math.max(1, Math.floor(data.length / sample.length))
  for (let index = 0; index < sample.length; index++) {
    sample[index] = data[index * stride] as number
  }
  sample.sort()
  const low = sample[Math.floor(sample.length * 0.05)] as number
  const high = sample[Math.floor(sample.length * 0.95)] as number
  return Math.round((low + high) / 2)
}

scope.onmessage = (event: MessageEvent<MeshWorkerRequest>) => {
  const request = event.data

  if (request.type === 'set-volume') {
    try {
      let volume: AssembledVolume = {
        data: new Int16Array(request.volume),
        dims: request.dims,
        spacing: request.spacing,
        origin: [0, 0, 0],
        orientation: [1, 0, 0, 0, 1, 0],
        droppedInstanceIds: [],
        warnings: [],
      }
      for (let pass = 1; pass < request.downsample; pass *= 2) {
        volume = downsample2x(volume)
      }
      reduced = volume
      classify = request.classify
      meshField = smoothField(volume.data, volume.dims)
      /* Copy (not the retained buffer) so collision sampling on the main thread
       * doesn't detach the volume the worker keeps for re-meshing. */
      const densityCopy = new Int16Array(volume.data)
      scope.postMessage(
        {
          type: 'volume-ready',
          reducedDims: volume.dims,
          suggestedThreshold: suggestThreshold(volume.data),
          density: densityCopy.buffer as ArrayBuffer,
          spacing: volume.spacing,
        },
        [densityCopy.buffer as ArrayBuffer],
      )
    } catch (caught: unknown) {
      reduced = null
      meshField = null
      scope.postMessage({
        type: 'mesh-error',
        requestId: null,
        message: caught instanceof Error ? caught.message : String(caught),
      })
    }
    return
  }

  if (request.type === 'extract') {
    if (!reduced || !meshField) {
      scope.postMessage({ type: 'mesh-error', requestId: request.requestId, message: 'volume not loaded' })
      return
    }
    try {
      const mesh = marchingCubes(meshField, reduced.dims, reduced.spacing, request.threshold, MAX_MESH_TRIANGLES, classify)
      const airRegions = analyzeAirRegions(reduced.data, reduced.dims, reduced.spacing, request.threshold)
      scope.postMessage(
        {
          type: 'mesh',
          requestId: request.requestId,
          positions: mesh.positions.buffer as ArrayBuffer,
          normals: mesh.normals.buffer as ArrayBuffer,
          colors: mesh.colors.buffer as ArrayBuffer,
          indices: mesh.indices.buffer as ArrayBuffer,
          triangleCount: mesh.triangleCount,
          truncated: mesh.truncated,
          airRegions,
        },
        [mesh.positions.buffer as ArrayBuffer, mesh.normals.buffer as ArrayBuffer, mesh.colors.buffer as ArrayBuffer, mesh.indices.buffer as ArrayBuffer],
      )
    } catch (caught: unknown) {
      scope.postMessage({
        type: 'mesh-error',
        requestId: request.requestId,
        message: caught instanceof Error ? caught.message : String(caught),
      })
    }
  }
}
