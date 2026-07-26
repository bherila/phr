import { useCallback, useEffect, useRef, useState } from 'react'

import { fetchWrapper } from '@/fetchWrapper'
import type { PhrDicomVolumeManifestResponse } from '@/phr/types'

import type { DensityField } from '../scene/densityField'
import type { AirRegionAnalysis } from './airRegions'
import { createDecodeWorker, createMeshWorker, decodePoolSize } from './createWorkers'
import {
  type DecodeWorkerResponse,
  DEFAULT_MESH_DOWNSAMPLE,
  MAX_SLICES,
  type MeshWorkerResponse,
  PIPELINE_VERSION,
} from './protocol'
import { type AssembledVolume, assembleVolume, type DecodedSlice,downsample2x } from './volumeAssembly'
import { decodeVolumeCacheArtifact, encodeVolumeCacheArtifact } from './volumeCacheArtifact'
import { readLocalVolume, writeLocalVolume } from './volumeLocalCache'

export type PipelinePhase = 'idle' | 'downloading' | 'assembling' | 'meshing' | 'ready' | 'error'

export interface PipelineMesh {
  positions: Float32Array
  normals: Float32Array
  colors: Float32Array
  indices: Uint32Array
  triangleCount: number
  truncated: boolean
  airRegions: AirRegionAnalysis
}

export interface VolumePipelineState {
  phase: PipelinePhase
  /** 0..1 slice download/decode progress while `downloading`. */
  progress: number
  mesh: PipelineMesh | null
  /** True while a re-mesh for a new threshold is in flight (phase stays `ready`). */
  remeshing: boolean
  error: string | null
  suggestedThreshold: number | null
  usedCache: boolean
  /** Reduced density volume for bone-collision; arrives once with `volume-ready`. */
  density: DensityField | null
  start: () => void
  extractAt: (threshold: number) => void
}

/**
 * Orchestrates the client-side scan-to-3D pipeline: cache-or-download,
 * decode-pool fan-out, volume assembly + downsample, mesh extraction, and
 * fire-and-forget cache upload. All heavy work runs in workers; this hook
 * only routes Transferable buffers between them.
 */
export function useVolumePipeline(
  patientId: number,
  manifest: PhrDicomVolumeManifestResponse | null,
  /** Modality-aware initial threshold (e.g. -400 HU for CT); null defers to the volume's suggested value (MR). */
  defaultThreshold: number | null,
): VolumePipelineState {
  const [phase, setPhase] = useState<PipelinePhase>('idle')
  const [progress, setProgress] = useState(0)
  const [mesh, setMesh] = useState<PipelineMesh | null>(null)
  const [remeshing, setRemeshing] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [suggestedThreshold, setSuggestedThreshold] = useState<number | null>(null)
  const [usedCache, setUsedCache] = useState(false)
  const [density, setDensity] = useState<DensityField | null>(null)

  const decodeWorkersRef = useRef<Worker[]>([])
  const meshWorkerRef = useRef<Worker | null>(null)
  const latestExtractRef = useRef(0)
  const startedRef = useRef(false)
  const disposedRef = useRef(false)

  const disposeWorkers = useCallback(() => {
    for (const worker of decodeWorkersRef.current) {
      worker.terminate()
    }
    decodeWorkersRef.current = []
    meshWorkerRef.current?.terminate()
    meshWorkerRef.current = null
  }, [])

  useEffect(() => {
    disposedRef.current = false
    return () => {
      disposedRef.current = true
      disposeWorkers()
    }
  }, [disposeWorkers])

  const fail = useCallback((message: string) => {
    if (disposedRef.current) return
    setError(message)
    setPhase('error')
    disposeWorkers()
  }, [disposeWorkers])

  const requestExtract = useCallback((threshold: number) => {
    const worker = meshWorkerRef.current
    if (!worker) return
    latestExtractRef.current += 1
    setRemeshing(true)
    worker.postMessage({ type: 'extract', requestId: latestExtractRef.current, threshold })
  }, [])

  const sendVolumeToMeshWorker = useCallback((reduced: AssembledVolume, initialThreshold: number | null) => {
    if (disposedRef.current) return
    setPhase('meshing')
    const worker = createMeshWorker()
    meshWorkerRef.current = worker
    worker.onmessage = (event: MessageEvent<MeshWorkerResponse>) => {
      if (disposedRef.current) return
      const message = event.data
      if (message.type === 'volume-ready') {
        setSuggestedThreshold(message.suggestedThreshold)
        setDensity({
          data: new Int16Array(message.density),
          dims: reduced.dims,
          spacing: message.spacing,
        })
        requestExtract(initialThreshold ?? message.suggestedThreshold)
        return
      }
      if (message.type === 'mesh') {
        if (message.requestId !== latestExtractRef.current) return
        setMesh({
          positions: new Float32Array(message.positions),
          normals: new Float32Array(message.normals),
          colors: new Float32Array(message.colors),
          indices: new Uint32Array(message.indices),
          triangleCount: message.triangleCount,
          truncated: message.truncated,
          airRegions: message.airRegions,
        })
        setRemeshing(false)
        setPhase('ready')
        return
      }
      if (message.requestId === null || message.requestId === latestExtractRef.current) {
        fail(message.message)
      }
    }
    worker.onerror = (event: ErrorEvent) => fail(event.message || 'mesh worker crashed')
    /*
     * Cloned (not transferred): the reduced volume stays on the main thread so
     * a cache-miss run can gzip and upload it after meshing starts.
     */
    worker.postMessage({
      type: 'set-volume',
      volume: reduced.data.buffer,
      dims: reduced.dims,
      spacing: reduced.spacing,
      downsample: 1,
      /* Only CT is HU-calibrated, so the tissue-band tints are meaningful only there. */
      classify: manifest?.series.modality === 'CT',
    })
  }, [fail, manifest, requestExtract])

  const persistArtifact = useCallback((reduced: AssembledVolume) => {
    if (!manifest) return
    void (async () => {
      try {
        const blob = await encodeVolumeCacheArtifact(reduced)
        const bytes = await blob.arrayBuffer()
        void writeLocalVolume(manifest.series.series_instance_uid, bytes)
        const form = new FormData()
        form.append('file', new Blob([bytes], { type: 'application/gzip' }), 'volume-cache.bin.gz')
        form.append('pipeline_version', String(PIPELINE_VERSION))
        await fetchWrapper.post(
          `/api/phr/patients/${patientId}/dicom/series/${manifest.series.id}/volume-cache`,
          form,
        )
      } catch {
        /* Cache persistence is best-effort; the next viewer just recomputes. */
      }
    })()
  }, [manifest, patientId])

  const runFullPipeline = useCallback((initialThreshold: number | null) => {
    if (!manifest?.volume) return
    setPhase('downloading')
    setProgress(0)

    const queue = [...manifest.instances]
    const total = queue.length
    const slices: DecodedSlice[] = []
    let settled = 0

    const finish = () => {
      if (disposedRef.current) return
      setPhase('assembling')
      for (const worker of decodeWorkersRef.current) worker.terminate()
      decodeWorkersRef.current = []
      /* setTimeout lets React paint the phase change before the ~100ms memcpy. */
      setTimeout(() => {
        if (disposedRef.current) return
        try {
          const assembled = assembleVolume(slices)
          let reduced = assembled
          for (let pass = 1; pass < DEFAULT_MESH_DOWNSAMPLE; pass *= 2) {
            reduced = downsample2x(reduced)
          }
          sendVolumeToMeshWorker(reduced, initialThreshold)
          persistArtifact(reduced)
        } catch (caught: unknown) {
          fail(caught instanceof Error ? caught.message : String(caught))
        }
      }, 0)
    }

    const workers = Array.from({ length: Math.min(decodePoolSize(), total) }, () => createDecodeWorker())
    decodeWorkersRef.current = workers

    const dispatchNext = (worker: Worker) => {
      const next = queue.shift()
      if (next) {
        worker.postMessage({
          type: 'decode',
          instanceId: next.id,
          url: next.url,
          transferSyntaxUid: next.transfer_syntax_uid,
        })
      }
    }

    for (const worker of workers) {
      worker.onmessage = (event: MessageEvent<DecodeWorkerResponse>) => {
        if (disposedRef.current) return
        const message = event.data
        if (message.type === 'slice-error') {
          fail(`slice ${message.instanceId}: ${message.message}`)
          return
        }
        slices.push({
          instanceId: message.instanceId,
          pixels: new Int16Array(message.pixels),
          rows: message.rows,
          columns: message.columns,
          geom: message.geom,
        })
        settled += 1
        setProgress(settled / total)
        if (settled === total) {
          finish()
        } else {
          dispatchNext(worker)
        }
      }
      worker.onerror = (event: ErrorEvent) => fail(event.message || 'decode worker crashed')
      dispatchNext(worker)
    }
  }, [fail, manifest, sendVolumeToMeshWorker, persistArtifact])

  const meshFromArtifact = useCallback(
    async (stream: ReadableStream<Uint8Array>): Promise<void> => {
      const cached = await decodeVolumeCacheArtifact(stream)
      if (disposedRef.current) return
      setUsedCache(true)
      sendVolumeToMeshWorker(
        {
          data: cached.data,
          dims: cached.dims,
          spacing: cached.spacing,
          origin: cached.origin,
          orientation: cached.orientation,
          droppedInstanceIds: [],
          warnings: [],
        },
        defaultThreshold,
      )
    },
    [defaultThreshold, sendVolumeToMeshWorker],
  )

  const start = useCallback(() => {
    if (startedRef.current || !manifest?.volume) return
    startedRef.current = true
    setError(null)

    if (manifest.volume.slice_count > MAX_SLICES) {
      fail(`This series has ${manifest.volume.slice_count} slices, more than the ${MAX_SLICES} this viewer can hold in browser memory.`)
      return
    }

    const seriesUid = manifest.series.series_instance_uid
    const remoteCacheUrl =
      manifest.cache.available && manifest.cache.pipeline_version === PIPELINE_VERSION ? manifest.cache.url : null

    /* Tiered fast path: this browser's IndexedDB cache → the R2 artifact
     * (any device) → full download+decode. Each tier falls through on miss. */
    setPhase('downloading')
    void (async () => {
      try {
        const localBytes = await readLocalVolume(seriesUid)
        if (disposedRef.current) return
        if (localBytes) {
          await meshFromArtifact(new Blob([localBytes]).stream())
          return
        }
        if (remoteCacheUrl) {
          const response = await fetch(remoteCacheUrl, { credentials: 'include' })
          if (disposedRef.current) return
          if (response.ok && response.body) {
            const bytes = await response.arrayBuffer()
            void writeLocalVolume(seriesUid, bytes)
            await meshFromArtifact(new Blob([bytes]).stream())
            return
          }
        }
        runFullPipeline(defaultThreshold)
      } catch {
        if (!disposedRef.current) runFullPipeline(defaultThreshold)
      }
    })()
  }, [defaultThreshold, fail, manifest, meshFromArtifact, runFullPipeline])

  return { phase, progress, mesh, remeshing, error, suggestedThreshold, usedCache, density, start, extractAt: requestExtract }
}
