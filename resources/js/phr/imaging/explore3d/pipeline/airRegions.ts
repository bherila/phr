/**
 * Connected-component analysis of the air space in a voxel volume, used to
 * derive recommended viewpoints algorithmically. Air voxels (field value
 * below the density threshold) reachable from the volume border are "outside
 * air"; the remaining air components are enclosed pockets — on a sinus CT
 * those are exactly the sealed/blocked cavities worth teleporting into.
 * Pure and worker-friendly (typed arrays only, no DOM).
 */

export interface AirPocket {
  /** Pocket centroid in mesh space (mm = voxel index * spacing). */
  centroid: [number, number, number]
  /** Unit direction along the pocket's longest extent — a natural look direction from the centroid. */
  look: [number, number, number]
  volumeMm3: number
}

export interface AirRegionAnalysis {
  /** Largest enclosed pockets, biggest first. */
  pockets: AirPocket[]
  /** The air voxel nearest the volume center (mesh-space mm), for snapping an "inside" viewpoint into open air. Null when the volume has no air at all. */
  interiorAir: [number, number, number] | null
}

/** Pockets smaller than this are noise (isolated voxels, mastoid cells). */
const MIN_POCKET_MM3 = 500

const NO_LABEL = 0
const OUTSIDE = 1
const FIRST_POCKET_LABEL = 2

interface FloodResult {
  count: number
  sumX: number
  sumY: number
  sumZ: number
  minX: number
  minY: number
  minZ: number
  maxX: number
  maxY: number
  maxZ: number
}

export function analyzeAirRegions(
  field: Int16Array | Float32Array,
  dims: readonly [number, number, number],
  spacing: readonly [number, number, number],
  threshold: number,
  maxPockets = 4,
): AirRegionAnalysis {
  const [nx, ny, nz] = dims
  const [sx, sy, sz] = spacing
  const total = nx * ny * nz
  const labels = new Uint8Array(total)
  const queue = new Uint32Array(total)

  const isAir = (index: number): boolean => (field[index] as number) < threshold

  /* 6-connected BFS from a seed, stamping `label` and accumulating the
   * component's centroid sums and bounding box. */
  function flood(seed: number, label: number): FloodResult {
    let head = 0
    let tail = 0
    queue[tail++] = seed
    labels[seed] = label
    const result: FloodResult = {
      count: 0,
      sumX: 0, sumY: 0, sumZ: 0,
      minX: nx, minY: ny, minZ: nz,
      maxX: -1, maxY: -1, maxZ: -1,
    }
    const plane = nx * ny
    while (head < tail) {
      const index = queue[head++] as number
      const z = Math.floor(index / plane)
      const rem = index - z * plane
      const y = Math.floor(rem / nx)
      const x = rem - y * nx
      result.count += 1
      result.sumX += x
      result.sumY += y
      result.sumZ += z
      if (x < result.minX) result.minX = x
      if (y < result.minY) result.minY = y
      if (z < result.minZ) result.minZ = z
      if (x > result.maxX) result.maxX = x
      if (y > result.maxY) result.maxY = y
      if (z > result.maxZ) result.maxZ = z

      if (x > 0 && labels[index - 1] === NO_LABEL && isAir(index - 1)) { labels[index - 1] = label; queue[tail++] = index - 1 }
      if (x < nx - 1 && labels[index + 1] === NO_LABEL && isAir(index + 1)) { labels[index + 1] = label; queue[tail++] = index + 1 }
      if (y > 0 && labels[index - nx] === NO_LABEL && isAir(index - nx)) { labels[index - nx] = label; queue[tail++] = index - nx }
      if (y < ny - 1 && labels[index + nx] === NO_LABEL && isAir(index + nx)) { labels[index + nx] = label; queue[tail++] = index + nx }
      if (z > 0 && labels[index - plane] === NO_LABEL && isAir(index - plane)) { labels[index - plane] = label; queue[tail++] = index - plane }
      if (z < nz - 1 && labels[index + plane] === NO_LABEL && isAir(index + plane)) { labels[index + plane] = label; queue[tail++] = index + plane }
    }
    return result
  }

  /* Pass 1: everything reachable from border air is outside air. */
  const floodFromBorder = (x: number, y: number, z: number): void => {
    const index = x + y * nx + z * nx * ny
    if (labels[index] === NO_LABEL && isAir(index)) {
      flood(index, OUTSIDE)
    }
  }
  for (let y = 0; y < ny; y++) {
    for (let x = 0; x < nx; x++) {
      floodFromBorder(x, y, 0)
      floodFromBorder(x, y, nz - 1)
    }
  }
  for (let z = 0; z < nz; z++) {
    for (let x = 0; x < nx; x++) {
      floodFromBorder(x, 0, z)
      floodFromBorder(x, ny - 1, z)
    }
    for (let y = 0; y < ny; y++) {
      floodFromBorder(0, y, z)
      floodFromBorder(nx - 1, y, z)
    }
  }

  /* Pass 2: remaining air components are enclosed pockets. Also track the
   * air voxel (any component) nearest the volume center. */
  const voxelMm3 = sx * sy * sz
  const centerX = (nx - 1) / 2
  const centerY = (ny - 1) / 2
  const centerZ = (nz - 1) / 2
  let bestCenterDistance = Infinity
  let interiorAir: [number, number, number] | null = null
  const candidates: FloodResult[] = []

  let index = 0
  for (let z = 0; z < nz; z++) {
    for (let y = 0; y < ny; y++) {
      for (let x = 0; x < nx; x++, index++) {
        if (!isAir(index)) continue
        const dx = (x - centerX) * sx
        const dy = (y - centerY) * sy
        const dz = (z - centerZ) * sz
        const centerDistance = dx * dx + dy * dy + dz * dz
        if (centerDistance < bestCenterDistance) {
          bestCenterDistance = centerDistance
          interiorAir = [x * sx, y * sy, z * sz]
        }
        if (labels[index] === NO_LABEL) {
          candidates.push(flood(index, FIRST_POCKET_LABEL))
        }
      }
    }
  }

  const pockets = candidates
    .map((component) => ({ component, volumeMm3: component.count * voxelMm3 }))
    .filter((entry) => entry.volumeMm3 >= MIN_POCKET_MM3)
    .sort((a, b) => b.volumeMm3 - a.volumeMm3)
    .slice(0, maxPockets)
    .map(({ component, volumeMm3 }): AirPocket => {
      const centroid: [number, number, number] = [
        (component.sumX / component.count) * sx,
        (component.sumY / component.count) * sy,
        (component.sumZ / component.count) * sz,
      ]
      const extentX = (component.maxX - component.minX) * sx
      const extentY = (component.maxY - component.minY) * sy
      const extentZ = (component.maxZ - component.minZ) * sz
      let look: [number, number, number] = [0, 0, 1]
      if (extentX >= extentY && extentX >= extentZ) {
        look = [1, 0, 0]
      } else if (extentY >= extentZ) {
        look = [0, 1, 0]
      }
      return { centroid, look, volumeMm3 }
    })

  return { pockets, interiorAir }
}
