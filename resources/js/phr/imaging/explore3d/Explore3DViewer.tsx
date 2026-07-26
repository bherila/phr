import { MousePointerClick, X } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'

import type { PhrDicomVolumeManifestResponse } from '@/phr/types'

import { ProgressOverlay } from './hud/ProgressOverlay'
import { ThresholdControls } from './hud/ThresholdControls'
import { resolveThresholdKey } from './hud/thresholdPresets'
import { useVolumePipeline } from './pipeline/useVolumePipeline'
import { createFlyScene, type FlyScene, type ViewpointThumbnail } from './scene/flyScene'
import { IDENTITY_IOP } from './scene/patientFrame'

const CT_DEFAULT_THRESHOLD = -400
const EXTRACT_DEBOUNCE_MS = 200

interface Explore3DViewerProps {
  patientId: number
  manifest: PhrDicomVolumeManifestResponse
  onClose: () => void
}

export function Explore3DViewer({ patientId, manifest, onClose }: Explore3DViewerProps) {
  const isCt = manifest.series.modality === 'CT'
  const defaultThreshold = isCt ? CT_DEFAULT_THRESHOLD : null
  const pipeline = useVolumePipeline(patientId, manifest, defaultThreshold)
  const { start, extractAt } = pipeline

  const canvasRef = useRef<HTMLCanvasElement | null>(null)
  const sceneRef = useRef<FlyScene | null>(null)
  const [locked, setLocked] = useState(false)
  const [lockError, setLockError] = useState<string | null>(null)
  const [collisionEnabled, setCollisionEnabled] = useState(false)
  const [viewerScale, setViewerScale] = useState(1)
  const [viewpoints, setViewpoints] = useState<ViewpointThumbnail[]>([])
  const [threshold, setThreshold] = useState(defaultThreshold ?? 0)
  const [adoptedSuggestion, setAdoptedSuggestion] = useState<number | null>(null)

  const thresholdMin = isCt ? -1000 : -32000
  const thresholdMax = isCt ? 1500 : 32000

  const extractDebounceRef = useRef<number | null>(null)
  const thresholdRef = useRef(threshold)
  useEffect(() => {
    thresholdRef.current = threshold
  }, [threshold])

  /* Slider, presets, and keyboard all funnel through here: clamp, update the
   * displayed value immediately, and coalesce re-mesh requests. The pipeline
   * itself drops stale results, so the debounce is only to avoid wasted work. */
  const applyThreshold = useCallback(
    (next: number) => {
      const clamped = Math.max(thresholdMin, Math.min(thresholdMax, Math.round(next)))
      if (clamped === thresholdRef.current) return
      setThreshold(clamped)
      if (extractDebounceRef.current !== null) window.clearTimeout(extractDebounceRef.current)
      extractDebounceRef.current = window.setTimeout(() => extractAt(clamped), EXTRACT_DEBOUNCE_MS)
    },
    [extractAt, thresholdMax, thresholdMin],
  )

  /* MR has no calibrated scale, so the initial threshold comes from the
   * volume's histogram once the mesh worker reports it (render-time state
   * adjustment; effects would double-render). */
  if (!isCt && pipeline.suggestedThreshold !== null && pipeline.suggestedThreshold !== adoptedSuggestion) {
    setAdoptedSuggestion(pipeline.suggestedThreshold)
    setThreshold(pipeline.suggestedThreshold)
  }

  useEffect(() => {
    start()
  }, [start])

  useEffect(() => {
    return () => {
      if (extractDebounceRef.current !== null) window.clearTimeout(extractDebounceRef.current)
    }
  }, [])

  const orientation = manifest.volume?.orientation
  useEffect(() => {
    const canvas = canvasRef.current
    if (!canvas) return
    const scene = createFlyScene(canvas, orientation ?? IDENTITY_IOP)
    scene.onLockChange((nextLocked) => {
      setLocked(nextLocked)
      if (nextLocked) setLockError(null)
    })
    scene.onLockError(setLockError)
    scene.onCollisionChange(setCollisionEnabled)
    scene.onScaleChange(setViewerScale)
    sceneRef.current = scene
    return () => {
      sceneRef.current = null
      scene.dispose()
    }
  }, [orientation])

  useEffect(() => {
    if (pipeline.mesh) {
      sceneRef.current?.setMesh(pipeline.mesh)
    }
  }, [pipeline.mesh])

  /* Bone-collision is HU-gated, so it's only meaningful on CT. */
  const collisionField = isCt ? pipeline.density : null
  useEffect(() => {
    if (collisionField) sceneRef.current?.setDensityField(collisionField)
  }, [collisionField])

  /* Thumbnails render every viewpoint station once — a real GPU stall on
   * large meshes — so capture only while the user is out of fly mode (the
   * only time the panel is visible), and once per mesh. */
  const capturedMeshRef = useRef<typeof pipeline.mesh>(null)
  useEffect(() => {
    if (locked || !pipeline.mesh || capturedMeshRef.current === pipeline.mesh) return
    const mesh = pipeline.mesh
    const capture = window.setTimeout(() => {
      capturedMeshRef.current = mesh
      setViewpoints(sceneRef.current?.captureViewpoints() ?? [])
    }, 50)
    return () => window.clearTimeout(capture)
  }, [locked, pipeline.mesh])

  const ready = pipeline.phase === 'ready'

  /* Density shortcuts, chosen to not collide with movement (WASD/EQ/Shift) or
   * pointer-lock (Esc): [ and ] step the threshold (Shift = coarse); 1–3 pick
   * CT presets. Active whenever the mesh is ready, including while flying. */
  useEffect(() => {
    if (!ready) return
    function onKeyDown(event: KeyboardEvent): void {
      if (event.metaKey || event.ctrlKey || event.altKey) return
      const next = resolveThresholdKey(event, isCt, thresholdRef.current)
      if (next === null) return
      applyThreshold(next)
      event.preventDefault()
    }
    document.addEventListener('keydown', onKeyDown)
    return () => document.removeEventListener('keydown', onKeyDown)
  }, [applyThreshold, isCt, ready])

  return (
    <div className="fixed inset-0 z-50 bg-black">
      <canvas ref={canvasRef} className="h-full w-full" />

      <ProgressOverlay phase={pipeline.phase} progress={pipeline.progress} />

      {pipeline.phase === 'error' && (
        <div className="absolute inset-0 flex items-center justify-center">
          <div className="w-80 space-y-3 rounded-lg bg-black/80 p-6 text-white backdrop-blur">
            <p className="text-sm font-medium">Couldn’t build the 3D view</p>
            <p className="text-xs text-white/70">{pipeline.error}</p>
          </div>
        </div>
      )}

      {ready && !locked && (
        <button
          type="button"
          onClick={() => sceneRef.current?.lock()}
          className="absolute inset-0 flex items-center justify-center bg-black/40"
        >
          <span className="flex flex-col items-center gap-2 rounded-lg bg-black/80 p-6 text-white backdrop-blur">
            <MousePointerClick className="size-6" />
            <span className="text-sm font-medium">Click to fly</span>
            <span className="text-xs text-white/70">
              Move: W A S D · Up/Down: E Q or ↑ ↓ · Faster: Shift · Look: mouse
            </span>
            <span className="text-xs text-white/70">Density: [ ] · Presets: 1 2 3 · Scale: scroll or + −</span>
            {isCt && (
              <span className="text-xs text-white/70">
                C: collision {collisionEnabled ? 'on — bone is solid' : 'off — fly through everything'}
              </span>
            )}
            <span className="text-xs text-white/70">Esc releases the mouse.</span>
            {lockError && (
              <span className="text-xs text-amber-300">
                Mouse capture was blocked ({lockError}) — click again to retry.
              </span>
            )}
          </span>
        </button>
      )}

      <div className="pointer-events-none absolute inset-x-0 top-0 flex items-start justify-between p-4">
        <div className="pointer-events-auto rounded-lg bg-black/70 px-3 py-2 text-white backdrop-blur">
          <p className="text-sm font-medium">{manifest.series.description || 'Explore in 3D'}</p>
          {ready && (
            <div className="mt-1 flex items-center gap-1.5 text-xs text-white/70">
              <span>Scale</span>
              <button
                type="button"
                onClick={() => sceneRef.current?.setScale(viewerScale / 1.4)}
                className="flex size-5 items-center justify-center rounded bg-white/10 leading-none hover:bg-white/20"
                aria-label="Zoom out (smaller anatomy)"
              >
                −
              </button>
              <span className="w-10 text-center tabular-nums text-white">{viewerScale.toFixed(1)}×</span>
              <button
                type="button"
                onClick={() => sceneRef.current?.setScale(viewerScale * 1.4)}
                className="flex size-5 items-center justify-center rounded bg-white/10 leading-none hover:bg-white/20"
                aria-label="Zoom in (larger anatomy, explore small passages)"
              >
                +
              </button>
            </div>
          )}
          {ready && isCt && (
            <p className="mt-1 text-xs text-white/70">
              Collision:{' '}
              <span className={collisionEnabled ? 'text-sky-300' : 'text-white/50'}>
                {collisionEnabled ? 'bone is solid' : 'off'}
              </span>{' '}
              <span className="text-white/40">· C</span>
            </p>
          )}
          {pipeline.mesh?.truncated && (
            <p className="mt-1 text-xs text-amber-300">Surface too detailed to show fully — raise the threshold.</p>
          )}
        </div>
        <button
          type="button"
          onClick={onClose}
          className="pointer-events-auto flex items-center gap-1.5 rounded-lg bg-black/70 px-3 py-2 text-sm text-white backdrop-blur hover:bg-black/90"
        >
          <X className="size-4" />
          Close
        </button>
      </div>

      {ready && isCt && (
        <div className="pointer-events-none absolute inset-x-0 top-16 flex justify-center">
          <div className="flex items-center gap-4 rounded-lg bg-black/70 px-3 py-1.5 text-xs text-white/80 backdrop-blur">
            <span className="flex items-center gap-1.5">
              <span className="inline-block size-3 rounded-sm" style={{ backgroundColor: 'rgb(217,201,168)' }} />
              Airway wall
            </span>
            <span className="flex items-center gap-1.5">
              <span className="inline-block size-3 rounded-sm" style={{ backgroundColor: 'rgb(217,112,48)' }} />
              Mucosa / fluid
            </span>
            <span className="flex items-center gap-1.5">
              <span className="inline-block size-3 rounded-sm" style={{ backgroundColor: 'rgb(230,235,247)' }} />
              Bone
            </span>
          </div>
        </div>
      )}

      {ready && !locked && viewpoints.length > 0 && (
        <div className="absolute right-4 bottom-4 max-w-[560px]">
          <div className="flex flex-wrap justify-end gap-2 rounded-lg bg-black/70 p-2 backdrop-blur">
            {viewpoints.map((viewpoint) => (
              <button
                key={viewpoint.id}
                type="button"
                onClick={() => sceneRef.current?.teleport(viewpoint.id)}
                className="group w-32"
                title={`Teleport to ${viewpoint.label}`}
              >
                <img
                  src={viewpoint.image}
                  alt={viewpoint.label}
                  className="h-20 w-32 rounded object-cover ring-1 ring-white/20 group-hover:ring-sky-400"
                />
                <span className="mt-1 block text-center text-[11px] leading-tight text-white/80">
                  {viewpoint.label}
                </span>
              </button>
            ))}
          </div>
        </div>
      )}

      {ready && (
        <div className="absolute bottom-4 left-4">
          <ThresholdControls
            modality={manifest.series.modality}
            value={threshold}
            min={thresholdMin}
            max={thresholdMax}
            disabled={pipeline.remeshing}
            onChange={applyThreshold}
          />
        </div>
      )}
    </div>
  )
}
