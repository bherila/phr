import type { AirRegionAnalysis } from './airRegions'
import type { SliceGeometry } from './volumeAssembly'

/**
 * Version stamp for the derived volume-cache artifact. Bump whenever the
 * artifact format or any upstream computation (decode, assembly, downsample)
 * changes meaningfully; the backend GC deletes artifacts from older versions.
 * Must match config('phr.volume_cache_pipeline_version') on the server.
 */
export const PIPELINE_VERSION = 1

/** Volumes larger than this are refused client-side to bound browser memory use. */
export const MAX_SLICES = 400

export const DEFAULT_MESH_DOWNSAMPLE = 2
export const MAX_MESH_TRIANGLES = 2_000_000

export interface DecodeRequestMessage {
  type: 'decode'
  instanceId: number
  url: string
  transferSyntaxUid: string
}

export interface SliceDecodedMessage {
  type: 'slice-decoded'
  instanceId: number
  /** Int16 HU/density values, rows*columns, transferred. */
  pixels: ArrayBuffer
  rows: number
  columns: number
  geom: SliceGeometry
}

export interface SliceErrorMessage {
  type: 'slice-error'
  instanceId: number
  message: string
}

export type DecodeWorkerRequest = DecodeRequestMessage
export type DecodeWorkerResponse = SliceDecodedMessage | SliceErrorMessage

export interface SetVolumeMessage {
  type: 'set-volume'
  /** Int16 volume data, transferred; worker downsamples and drops it. */
  volume: ArrayBuffer
  dims: [number, number, number]
  spacing: [number, number, number]
  downsample: number
  /** Classify surfaces by the tissue behind them (CT-only; HU-calibrated bands). */
  classify: boolean
}

export interface ExtractMessage {
  type: 'extract'
  requestId: number
  threshold: number
}

export interface VolumeReadyMessage {
  type: 'volume-ready'
  reducedDims: [number, number, number]
  /** Density histogram summary for picking MR defaults: [min, max, suggestedThreshold]. */
  suggestedThreshold: number
  /**
   * A copy of the reduced Int16 density volume (transferred), so the main
   * thread can sample HU at the camera for density-gated collision without a
   * per-frame worker round-trip. Threshold-independent, hence sent once here.
   */
  density: ArrayBuffer
  spacing: [number, number, number]
}

export interface MeshMessage {
  type: 'mesh'
  requestId: number
  positions: ArrayBuffer
  normals: ArrayBuffer
  colors: ArrayBuffer
  indices: ArrayBuffer
  triangleCount: number
  truncated: boolean
  /** Enclosed-cavity viewpoint analysis for the same threshold (mesh-space mm). */
  airRegions: AirRegionAnalysis
}

export interface MeshErrorMessage {
  type: 'mesh-error'
  requestId: number | null
  message: string
}

export type MeshWorkerRequest = SetVolumeMessage | ExtractMessage
export type MeshWorkerResponse = VolumeReadyMessage | MeshMessage | MeshErrorMessage
