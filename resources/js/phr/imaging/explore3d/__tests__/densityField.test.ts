import { BONE_COLLISION_HU, type DensityField, isBoneAt, sampleDensityMm } from '../scene/densityField'

function makeField(): DensityField {
  // 4x4x4 volume, 2mm isotropic spacing. One bone voxel at (2,1,1).
  const dims: [number, number, number] = [4, 4, 4]
  const data = new Int16Array(4 * 4 * 4).fill(-1000) // air everywhere
  data[2 + 1 * 4 + 1 * 16] = 900 // bone voxel
  data[1 + 1 * 4 + 1 * 16] = 60 // mucosa/soft voxel next to it
  return { data, dims, spacing: [2, 2, 2] }
}

describe('sampleDensityMm', () => {
  const field = makeField()

  it('maps millimetre coordinates to the nearest voxel', () => {
    // Voxel (2,1,1) centre is at mm (4,2,2).
    expect(sampleDensityMm(field, 4, 2, 2)).toBe(900)
    // Rounds to the nearest voxel: 3.2mm/2 = 1.6 -> voxel 2 in x.
    expect(sampleDensityMm(field, 3.2, 2, 2)).toBe(900)
    // The soft-tissue voxel (1,1,1) at mm (2,2,2).
    expect(sampleDensityMm(field, 2, 2, 2)).toBe(60)
  })

  it('reads out-of-bounds as air so edges never trap the camera', () => {
    expect(sampleDensityMm(field, -5, 0, 0)).toBe(-1000)
    expect(sampleDensityMm(field, 1000, 0, 0)).toBe(-1000)
  })
})

describe('isBoneAt', () => {
  const field = makeField()

  it('blocks in bone but not in air or soft tissue', () => {
    expect(isBoneAt(field, 4, 2, 2)).toBe(true) // bone voxel
    expect(isBoneAt(field, 2, 2, 2)).toBe(false) // soft tissue (60 HU < threshold)
    expect(isBoneAt(field, 0, 0, 0)).toBe(false) // air
  })

  it('uses the documented HU threshold as the boundary', () => {
    const data = new Int16Array(1).fill(BONE_COLLISION_HU)
    const atThreshold: DensityField = { data, dims: [1, 1, 1], spacing: [1, 1, 1] }
    expect(isBoneAt(atThreshold, 0, 0, 0)).toBe(true)
    data[0] = BONE_COLLISION_HU - 1
    expect(isBoneAt(atThreshold, 0, 0, 0)).toBe(false)
  })
})
