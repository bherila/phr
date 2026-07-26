/**
 * Nearest-neighbour sampler over the reduced Int16 density volume, used for
 * density-gated collision ("pass through mucosa, stop at bone"). Kept
 * three.js-free and pure so the sampling and blocking logic is unit-testable
 * in node. Coordinates are mesh-space millimetres (voxel index * spacing),
 * matching the mesh geometry the camera flies through.
 */

export interface DensityField {
  data: Int16Array
  dims: readonly [number, number, number]
  spacing: readonly [number, number, number]
}

/** HU at/above which a voxel is treated as bone and blocks movement. */
export const BONE_COLLISION_HU = 200

/** Density outside the volume reads as air (passable), so edges never trap the camera. */
const OUT_OF_BOUNDS_HU = -1000

/** Samples HU at a mesh-space point (mm), nearest voxel; out-of-bounds reads as air. */
export function sampleDensityMm(field: DensityField, x: number, y: number, z: number): number {
  const [nx, ny, nz] = field.dims
  const [sx, sy, sz] = field.spacing
  const vx = Math.round(x / sx)
  const vy = Math.round(y / sy)
  const vz = Math.round(z / sz)
  if (vx < 0 || vy < 0 || vz < 0 || vx >= nx || vy >= ny || vz >= nz) {
    return OUT_OF_BOUNDS_HU
  }
  return field.data[vx + vy * nx + vz * nx * ny] as number
}

/** True if the mesh-space point sits in bone-density tissue (movement should stop). */
export function isBoneAt(field: DensityField, x: number, y: number, z: number): boolean {
  return sampleDensityMm(field, x, y, z) >= BONE_COLLISION_HU
}
