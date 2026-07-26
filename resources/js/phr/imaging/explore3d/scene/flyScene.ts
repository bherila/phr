import * as THREE from 'three'
import { PointerLockControls } from 'three/examples/jsm/controls/PointerLockControls.js'

import type { PipelineMesh } from '../pipeline/useVolumePipeline'
import { type DensityField, isBoneAt } from './densityField'
import { type ImageOrientationPatient, meshToDisplayBasis } from './patientFrame'
import { pocketStationLabel, VIEW_STATIONS } from './viewStations'
import { wheelScaleRatio } from './wheelScale'

export interface ViewpointThumbnail {
  id: string
  label: string
  /** PNG data URL snapshot rendered from the viewpoint. */
  image: string
}

export interface FlyScene {
  setMesh: (mesh: PipelineMesh) => void
  lock: () => void
  isLocked: () => boolean
  onLockChange: (listener: (locked: boolean) => void) => void
  /** Fires when a pointer-lock request is rejected (with the DOMException name). */
  onLockError: (listener: (reason: string) => void) => void
  /** Renders a small snapshot from every viewpoint station. Call after setMesh. */
  captureViewpoints: () => ViewpointThumbnail[]
  /** Moves the camera to a viewpoint station by id. */
  teleport: (id: string) => void
  /** Supplies the density volume that powers bone-collision (CT only). */
  setDensityField: (field: DensityField) => void
  /** Fires when collision is toggled (C key), with the new enabled state. */
  onCollisionChange: (listener: (enabled: boolean) => void) => void
  /** Sets the viewer scale (>1 enlarges the anatomy so you feel smaller). */
  setScale: (scale: number) => void
  /** Fires when the viewer scale changes (+/- keys), with the new factor. */
  onScaleChange: (listener: (scale: number) => void) => void
  dispose: () => void
}

interface WorldStation {
  id: string
  label: string
  /* Stored in mesh-local space so teleport/thumbnail resolution respects the
   * current viewer scale (world.scale) rather than baking in scale = 1. */
  localPosition: THREE.Vector3
  localTarget: THREE.Vector3
}

const SPRINT_MULTIPLIER = 4
/** Viewer-scale bounds and per-step factor for the shrink/grow control. */
const MIN_VIEWER_SCALE = 0.5
const MAX_VIEWER_SCALE = 24
const VIEWER_SCALE_STEP = 1.4
const THUMB_WIDTH = 128
const THUMB_HEIGHT = 80
/** Chrome refuses pointer-lock requests for ~1.25s after a user-initiated exit (Esc). */
const POINTER_LOCK_COOLDOWN_MS = 1400

/**
 * First-person fly-through of the extracted isosurface. Movement is
 * deliberately collision-free (flying through walls is a feature: it is how
 * you get inside closed anatomy). The mesh lives in a world group carrying
 * the full DICOM-orientation → display rotation (patient left = +x,
 * superior = +y, anterior = +z), keeping the camera rig XR-compatible: a
 * future WebXR mode swaps the control scheme without touching the mesh
 * transform.
 */
export function createFlyScene(canvas: HTMLCanvasElement, orientation: ImageOrientationPatient): FlyScene {
  const renderer = new THREE.WebGLRenderer({ canvas, antialias: true })
  renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2))

  const scene = new THREE.Scene()
  scene.background = new THREE.Color(0x0b0f14)

  /* Near plane at 0.2mm so walls of millimeter-scale passages (sinus ostia)
   * don't clip away while squeezing through them. */
  const camera = new THREE.PerspectiveCamera(70, 1, 0.2, 3000)
  const controls = new PointerLockControls(camera, canvas)
  /* We drive look rotation ourselves (see onPointerMove) so drift-cancellation
   * can run; PointerLockControls stays only for the lock/unlock lifecycle. */
  controls.enabled = false

  const world = new THREE.Group()
  const basis = meshToDisplayBasis(orientation)
  world.quaternion.setFromRotationMatrix(
    new THREE.Matrix4().set(
      basis[0] ?? 1, basis[1] ?? 0, basis[2] ?? 0, 0,
      basis[3] ?? 0, basis[4] ?? 1, basis[5] ?? 0, 0,
      basis[6] ?? 0, basis[7] ?? 0, basis[8] ?? 1, 0,
      0, 0, 0, 1,
    ),
  )
  scene.add(world)

  /* Decay 0 keeps the headlamp distance-independent: with three's physical
   * falloff (r155+), any decay > 0 makes a unit light invisible at this
   * scene's millimeter scale (1/d^decay with d in the hundreds). */
  const headlamp = new THREE.PointLight(0xffffff, 2, 0, 0)
  camera.add(headlamp)
  scene.add(camera)
  scene.add(new THREE.AmbientLight(0xffffff, 0.12))

  /* White base so the per-vertex classification colors (tan wall, amber
   * mucosa/fluid, pale bone) show at full strength — vertexColors multiplies
   * the material color. */
  const material = new THREE.MeshStandardMaterial({
    color: 0xffffff,
    roughness: 0.9,
    metalness: 0,
    side: THREE.DoubleSide,
    vertexColors: true,
  })

  let meshObject: THREE.Mesh | null = null
  let moveSpeed = 100
  let stations: WorldStation[] = []
  let relockTimer: number | null = null
  let viewerScale = 1
  let baseDiagonal = 0
  const scaleListeners: ((scale: number) => void)[] = []
  let densityField: DensityField | null = null
  /* Off by default: the airways are a tortuous maze of bony walls, so
   * collision-on obstructs the free repositioning needed to find a channel.
   * Enable it with C once you're lined up and want to stay inside a passage. */
  let collisionEnabled = false
  const pressed = new Set<string>()
  const lockListeners: ((locked: boolean) => void)[] = []
  const lockErrorListeners: ((reason: string) => void)[] = []
  const collisionListeners: ((enabled: boolean) => void)[] = []

  /* Geometric stations around/inside the bounding box, refined by the air
   * analysis: "inside" snaps to real open air near the volume center, and
   * each major enclosed air pocket (a sealed cavity — e.g. a blocked sinus)
   * gets its own station at its centroid looking down its long axis. */
  function buildStations(mesh: PipelineMesh, worldCenter: THREE.Vector3, diagonal: number): WorldStation[] {
    interface WorldSpaceStation {
      id: string
      label: string
      position: THREE.Vector3
      target: THREE.Vector3
    }
    const list: WorldSpaceStation[] = VIEW_STATIONS.map((station) => ({
      id: station.id,
      label: station.label,
      position: worldCenter.clone().add(new THREE.Vector3(...station.offset).multiplyScalar(diagonal)),
      target: worldCenter.clone().add(new THREE.Vector3(...station.targetOffset).multiplyScalar(diagonal)),
    }))
    const interior = mesh.airRegions.interiorAir
    if (interior) {
      const inside = list.find((station) => station.id === 'inside')
      if (inside) {
        inside.position = world.localToWorld(new THREE.Vector3(...interior))
        inside.target = inside.position.clone().add(new THREE.Vector3(0, 0, -0.4 * diagonal))
      }
    }
    mesh.airRegions.pockets.forEach((pocket, index) => {
      const position = world.localToWorld(new THREE.Vector3(...pocket.centroid))
      const lookDirection = new THREE.Vector3(...pocket.look).applyQuaternion(world.quaternion)
      list.push({
        id: `pocket-${index}`,
        label: pocketStationLabel(index, pocket.volumeMm3),
        position,
        target: position.clone().addScaledVector(lookDirection, Math.max(10, diagonal * 0.2)),
      })
    })
    /* Freeze each station into mesh-local space; world.localToWorld at teleport
     * time then reapplies whatever scale is active. */
    return list.map((station) => ({
      id: station.id,
      label: station.label,
      localPosition: world.worldToLocal(station.position.clone()),
      localTarget: world.worldToLocal(station.target.clone()),
    }))
  }

  function resolveStation(station: WorldStation): { position: THREE.Vector3; target: THREE.Vector3 } {
    return {
      position: world.localToWorld(station.localPosition.clone()),
      target: world.localToWorld(station.localTarget.clone()),
    }
  }

  function teleport(id: string): void {
    const station = stations.find((candidate) => candidate.id === id)
    if (!station) return
    const resolved = resolveStation(station)
    camera.position.copy(resolved.position)
    camera.lookAt(resolved.target)
  }

  /* Push the current viewer scale into the scene: the anatomy grows/shrinks via
   * world.scale, and the headlamp cutoff and camera far plane track it so a
   * scaled-up head neither goes dark nor clips against the far plane. */
  function applyScaleDerived(): void {
    world.scale.setScalar(viewerScale)
    headlamp.distance = baseDiagonal * 2 * viewerScale
    camera.far = Math.max(3000, baseDiagonal * 4 * viewerScale)
    camera.updateProjectionMatrix()
    world.updateMatrixWorld()
  }

  /* Scaling the anatomy about the world origin; the camera scales its distance
   * from that origin by the same factor so it stays put relative to the
   * anatomy (you appear to shrink/grow rather than being flung around). */
  function setScale(next: number): void {
    const clamped = Math.max(MIN_VIEWER_SCALE, Math.min(MAX_VIEWER_SCALE, next))
    if (clamped === viewerScale) return
    const ratio = clamped / viewerScale
    viewerScale = clamped
    camera.position.multiplyScalar(ratio)
    applyScaleDerived()
    for (const listener of scaleListeners) listener(viewerScale)
  }

  function setMesh(mesh: PipelineMesh): void {
    const geometry = new THREE.BufferGeometry()
    geometry.setAttribute('position', new THREE.BufferAttribute(mesh.positions, 3))
    geometry.setAttribute('normal', new THREE.BufferAttribute(mesh.normals, 3))
    geometry.setAttribute('color', new THREE.BufferAttribute(mesh.colors, 3))
    geometry.setIndex(new THREE.BufferAttribute(mesh.indices, 1))

    const firstMesh = meshObject === null
    if (meshObject) {
      meshObject.geometry.dispose()
      meshObject.geometry = geometry
    } else {
      meshObject = new THREE.Mesh(geometry, material)
      world.add(meshObject)
    }

    geometry.computeBoundingBox()
    const bounds = geometry.boundingBox
    if (bounds) {
      const center = bounds.getCenter(new THREE.Vector3())
      const size = bounds.getSize(new THREE.Vector3())
      const diagonal = size.length()
      baseDiagonal = diagonal
      /* Movement speed stays fixed in world units, so scaling the anatomy up
       * makes each step finer relative to it — the point of "shrinking" to
       * explore small passages. */
      moveSpeed = Math.max(20, diagonal / 8)
      applyScaleDerived()
      world.updateMatrixWorld()
      const worldCenter = world.localToWorld(center.clone())
      stations = buildStations(mesh, worldCenter, diagonal)
      if (firstMesh) {
        /* +z is patient-anterior, so the spawn always faces the patient
         * head-on — the natural place to find airway openings. */
        teleport('front')
      }
    }
  }

  function captureViewpoints(): ViewpointThumbnail[] {
    if (!meshObject || stations.length === 0) return []
    /* Render targets have no MSAA; supersample 2x and let the 2D downscale
     * smooth the thumbnails. */
    const scale = 2
    const width = THUMB_WIDTH * scale
    const height = THUMB_HEIGHT * scale
    const supersampled = document.createElement('canvas')
    supersampled.width = width
    supersampled.height = height
    const superContext = supersampled.getContext('2d')
    if (!superContext) return []
    const target = new THREE.WebGLRenderTarget(width, height)
    const pixels = new Uint8Array(width * height * 4)

    /* The headlamp is parented to the camera, so thumbnails reuse the main
     * camera (moved per station, restored synchronously before the next
     * visible frame) rather than a separate camera that would render dark. */
    const savedPosition = camera.position.clone()
    const savedQuaternion = camera.quaternion.clone()
    const savedAspect = camera.aspect
    camera.aspect = THUMB_WIDTH / THUMB_HEIGHT
    camera.updateProjectionMatrix()

    const thumbnails: ViewpointThumbnail[] = []
    try {
      for (const station of stations) {
        const resolved = resolveStation(station)
        camera.position.copy(resolved.position)
        camera.lookAt(resolved.target)
        renderer.setRenderTarget(target)
        renderer.render(scene, camera)
        renderer.readRenderTargetPixels(target, 0, 0, width, height, pixels)
        const image = superContext.createImageData(width, height)
        for (let row = 0; row < height; row++) {
          /* readRenderTargetPixels returns bottom-up rows; flip for 2D canvas. */
          image.data.set(pixels.subarray((height - 1 - row) * width * 4, (height - row) * width * 4), row * width * 4)
        }
        superContext.putImageData(image, 0, 0)
        const thumb = document.createElement('canvas')
        thumb.width = THUMB_WIDTH
        thumb.height = THUMB_HEIGHT
        const thumbContext = thumb.getContext('2d')
        if (thumbContext) {
          thumbContext.drawImage(supersampled, 0, 0, THUMB_WIDTH, THUMB_HEIGHT)
          thumbnails.push({ id: station.id, label: station.label, image: thumb.toDataURL() })
        }
      }
    } finally {
      /* Restore unconditionally: leaving the render target bound would
       * silently redirect every subsequent frame offscreen — a permanently
       * frozen canvas. */
      renderer.setRenderTarget(null)
      target.dispose()
      camera.position.copy(savedPosition)
      camera.quaternion.copy(savedQuaternion)
      camera.aspect = savedAspect
      camera.updateProjectionMatrix()
    }
    return thumbnails
  }

  function setCollision(enabled: boolean): void {
    if (enabled === collisionEnabled) return
    collisionEnabled = enabled
    for (const listener of collisionListeners) listener(collisionEnabled)
  }

  function onKeyDown(event: KeyboardEvent): void {
    /* C toggles bone-collision; it is not a movement key, so consume it here
     * rather than adding it to the held-key set. */
    if (event.code === 'KeyC' && !event.metaKey && !event.ctrlKey && !event.altKey) {
      setCollision(!collisionEnabled)
      return
    }
    /* +/- (and numpad) scale the anatomy so tight passages are navigable:
     * bigger anatomy = finer movement + less near-plane clipping. */
    if (!event.metaKey && !event.ctrlKey && !event.altKey) {
      if (event.code === 'Equal' || event.code === 'NumpadAdd') {
        setScale(viewerScale * VIEWER_SCALE_STEP)
        event.preventDefault()
        return
      }
      if (event.code === 'Minus' || event.code === 'NumpadSubtract') {
        setScale(viewerScale / VIEWER_SCALE_STEP)
        event.preventDefault()
        return
      }
    }
    /* Arrow keys drive vertical movement; stop them scrolling the page. */
    if (event.code === 'ArrowUp' || event.code === 'ArrowDown') {
      event.preventDefault()
    }
    pressed.add(event.code)
  }
  function onKeyUp(event: KeyboardEvent): void {
    pressed.delete(event.code)
  }
  document.addEventListener('keydown', onKeyDown)
  document.addEventListener('keyup', onKeyUp)

  /* Scroll wheel / two-finger trackpad scale continuously: up = larger anatomy
   * (shrink into it), down = smaller. wheelScaleRatio normalises delta units so
   * a mouse and a macOS trackpad feel the same. preventDefault stops the page
   * scrolling underneath. */
  function onWheel(event: WheelEvent): void {
    event.preventDefault()
    setScale(viewerScale * wheelScaleRatio(event.deltaY, event.deltaMode, VIEWER_SCALE_STEP))
  }
  canvas.addEventListener('wheel', onWheel, { passive: false })

  /*
   * Custom pointer-look with drift cancellation. Some setups (notably macOS
   * trackpads and certain mice under Chrome/Safari) emit a stream of
   * pointer-lock mousemove events carrying a small, roughly constant movementY
   * even when the pointer is still — the "view keeps sliding down" bug that
   * unadjustedMovement alone didn't cure. We estimate that DC bias from the
   * small-magnitude events (real look gestures are larger and transient) with
   * a slow EMA and subtract it from every event, so a steady drift is removed
   * while intentional looking is untouched.
   */
  const LOOK_SENSITIVITY = 0.002
  const HALF_PI = Math.PI / 2
  const DRIFT_SAMPLE_MAX = 2.5 // px: events at or below this feed the bias estimate
  const DRIFT_EMA_ALPHA = 0.02
  const lookEuler = new THREE.Euler(0, 0, 0, 'YXZ')
  let biasX = 0
  let biasY = 0
  let calibrated = false
  let logMovement = false

  function resetLookState(): void {
    /* Re-seed the estimator on each lock so a new device/session recalibrates,
     * and drop the first event (often a large spurious delta right after lock). */
    biasX = 0
    biasY = 0
    calibrated = false
  }

  function onPointerMove(event: MouseEvent): void {
    if (document.pointerLockElement !== canvas) return
    const rawX = event.movementX
    const rawY = event.movementY
    if (!calibrated) {
      calibrated = true
      return
    }
    if (Math.abs(rawX) <= DRIFT_SAMPLE_MAX) biasX += (rawX - biasX) * DRIFT_EMA_ALPHA
    if (Math.abs(rawY) <= DRIFT_SAMPLE_MAX) biasY += (rawY - biasY) * DRIFT_EMA_ALPHA
    const moveX = rawX - biasX
    const moveY = rawY - biasY
    if (logMovement) {
      console.warn(`[explore3d] look raw=(${rawX.toFixed(2)}, ${rawY.toFixed(2)}) bias=(${biasX.toFixed(3)}, ${biasY.toFixed(3)})`)
    }
    lookEuler.setFromQuaternion(camera.quaternion)
    lookEuler.y -= moveX * LOOK_SENSITIVITY
    lookEuler.x -= moveY * LOOK_SENSITIVITY
    lookEuler.x = Math.max(-HALF_PI, Math.min(HALF_PI, lookEuler.x))
    lookEuler.z = 0
    camera.quaternion.setFromEuler(lookEuler)
  }
  document.addEventListener('mousemove', onPointerMove)

  /* Console escape hatch: `__explore3dLogLook(true)` streams raw movement and
   * the estimated bias, so a persistent drift can be diagnosed from a real
   * device without a code change. */
  const debugGlobal = window as unknown as { __explore3dLogLook?: (on: boolean) => void }
  debugGlobal.__explore3dLogLook = (on: boolean) => {
    logMovement = on
  }

  function notifyLock(): void {
    /* Derived from the document, not PointerLockControls state, so the UI can
     * never wedge on a stale "locked" flag (which hides the re-entry overlay
     * and makes the whole viewer feel frozen). */
    const locked = document.pointerLockElement === canvas
    if (locked && relockTimer !== null) {
      window.clearTimeout(relockTimer)
      relockTimer = null
    }
    if (locked) resetLookState()
    for (const listener of lockListeners) listener(locked)
    if (!locked) pressed.clear()
  }
  controls.addEventListener('lock', notifyLock)
  controls.addEventListener('unlock', notifyLock)

  const resizeObserver = new ResizeObserver(() => {
    const { clientWidth, clientHeight } = canvas
    if (clientWidth === 0 || clientHeight === 0) return
    renderer.setSize(clientWidth, clientHeight, false)
    camera.aspect = clientWidth / clientHeight
    camera.updateProjectionMatrix()
  })
  resizeObserver.observe(canvas)

  /* Frame delta from performance.now() rather than the deprecated THREE.Clock. */
  let lastFrameMs = performance.now()
  const forward = new THREE.Vector3()
  const right = new THREE.Vector3()
  const desired = new THREE.Vector3()
  const meshPoint = new THREE.Vector3()

  /* How far ahead of the camera (mm) collision probes, so we stop just shy of
   * bone rather than clipping into it. */
  const COLLISION_SKIN_MM = 1.5

  /* True if moving the camera by `delta` (world space) would put it — or the
   * skin margin ahead of it — inside bone. Air/mucosa/fluid never block. */
  function blockedBy(delta: THREE.Vector3): boolean {
    if (!collisionEnabled || !densityField || delta.lengthSq() === 0) return false
    const probe = COLLISION_SKIN_MM / Math.max(delta.length(), 1e-6)
    meshPoint
      .copy(camera.position)
      .addScaledVector(delta, 1)
      .addScaledVector(delta, probe)
    world.worldToLocal(meshPoint)
    return isBoneAt(densityField, meshPoint.x, meshPoint.y, meshPoint.z)
  }

  /* Applies one movement axis with wall-sliding: if the move lands in bone it
   * is dropped, leaving the other axes free so the camera glides along the
   * wall instead of sticking. */
  function tryMove(direction: THREE.Vector3, distance: number): void {
    desired.copy(direction).multiplyScalar(distance)
    if (!blockedBy(desired)) camera.position.add(desired)
  }

  const worldUp = new THREE.Vector3(0, 1, 0)

  renderer.setAnimationLoop(() => {
    const now = performance.now()
    const delta = Math.min((now - lastFrameMs) / 1000, 0.1)
    lastFrameMs = now
    if (controls.isLocked) {
      const speed = moveSpeed * delta * (pressed.has('ShiftLeft') || pressed.has('ShiftRight') ? SPRINT_MULTIPLIER : 1)
      camera.getWorldDirection(forward)
      /* Camera-matrix column, not forward x up: the cross product degenerates
       * to NaN when looking straight up or down (pointer lock allows ±90°). */
      right.setFromMatrixColumn(camera.matrix, 0)
      if (pressed.has('KeyW')) tryMove(forward, speed)
      if (pressed.has('KeyS')) tryMove(forward, -speed)
      if (pressed.has('KeyD')) tryMove(right, speed)
      if (pressed.has('KeyA')) tryMove(right, -speed)
      /* E/Q and Up/Down arrows both slide vertically; W/S stay forward/back. */
      if (pressed.has('KeyE') || pressed.has('ArrowUp')) tryMove(worldUp, speed)
      if (pressed.has('KeyQ') || pressed.has('ArrowDown')) tryMove(worldUp, -speed)
    }
    renderer.render(scene, camera)
  })

  /* unadjustedMovement bypasses OS mouse acceleration, which on macOS Chrome
   * otherwise injects spurious movementY into pointer-locked mousemove events
   * (a steady look-down drift); NotSupportedError falls back to an adjusted
   * lock. Any other rejection is almost always Chrome's post-Esc cooldown —
   * without the delayed retry, clicking "Click to fly" again right after Esc
   * silently does nothing and the viewer feels stuck. */
  function requestLock(raw: boolean, retryOnCooldown: boolean): void {
    const request = canvas.requestPointerLock({ unadjustedMovement: raw }) as Promise<void> | undefined
    request?.catch((error: unknown) => {
      if (raw && error instanceof DOMException && error.name === 'NotSupportedError') {
        requestLock(false, retryOnCooldown)
        return
      }
      const reason = error instanceof DOMException ? error.name : String(error)
      console.warn(`[explore3d] pointer lock rejected (${reason})`, error)
      if (retryOnCooldown) {
        relockTimer = window.setTimeout(() => requestLock(raw, false), POINTER_LOCK_COOLDOWN_MS)
      } else {
        for (const listener of lockErrorListeners) listener(reason)
      }
    })
  }

  function lock(): void {
    if (relockTimer !== null) window.clearTimeout(relockTimer)
    requestLock(true, true)
  }

  return {
    setMesh,
    lock,
    captureViewpoints,
    teleport,
    setDensityField: (field) => {
      densityField = field
    },
    isLocked: () => controls.isLocked,
    onLockChange: (listener) => {
      lockListeners.push(listener)
    },
    onLockError: (listener) => {
      lockErrorListeners.push(listener)
    },
    onCollisionChange: (listener) => {
      collisionListeners.push(listener)
    },
    setScale,
    onScaleChange: (listener) => {
      scaleListeners.push(listener)
    },
    dispose: () => {
      if (relockTimer !== null) window.clearTimeout(relockTimer)
      renderer.setAnimationLoop(null)
      resizeObserver.disconnect()
      document.removeEventListener('keydown', onKeyDown)
      document.removeEventListener('keyup', onKeyUp)
      document.removeEventListener('mousemove', onPointerMove)
      canvas.removeEventListener('wheel', onWheel)
      delete debugGlobal.__explore3dLogLook
      if (controls.isLocked) controls.unlock()
      controls.disconnect()
      meshObject?.geometry.dispose()
      material.dispose()
      renderer.dispose()
    },
  }
}
