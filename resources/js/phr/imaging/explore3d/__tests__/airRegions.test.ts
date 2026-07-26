import { analyzeAirRegions } from '../pipeline/airRegions'

const AIR = -1000
const TISSUE = 100
const THRESHOLD = -400

function makeVolume(dims: [number, number, number], fill: number): Int16Array {
  return new Int16Array(dims[0] * dims[1] * dims[2]).fill(fill)
}

function setBox(
  data: Int16Array,
  dims: [number, number, number],
  value: number,
  [x0, x1]: [number, number],
  [y0, y1]: [number, number],
  [z0, z1]: [number, number],
): void {
  const [nx, ny] = dims
  for (let z = z0; z <= z1; z++) {
    for (let y = y0; y <= y1; y++) {
      for (let x = x0; x <= x1; x++) {
        data[x + y * nx + z * nx * ny] = value
      }
    }
  }
}

describe('analyzeAirRegions', () => {
  it('finds an enclosed pocket inside solid tissue', () => {
    const dims: [number, number, number] = [20, 20, 20]
    const data = makeVolume(dims, TISSUE)
    setBox(data, dims, AIR, [5, 13], [5, 13], [5, 13]) // 9^3 = 729 mm^3

    const { pockets, interiorAir } = analyzeAirRegions(data, dims, [1, 1, 1], THRESHOLD)

    expect(pockets).toHaveLength(1)
    expect(pockets[0]?.volumeMm3).toBe(729)
    expect(pockets[0]?.centroid).toEqual([9, 9, 9])
    expect(interiorAir).not.toBeNull()
    for (const coordinate of interiorAir ?? []) {
      expect(coordinate).toBeGreaterThanOrEqual(5)
      expect(coordinate).toBeLessThanOrEqual(13)
    }
  })

  it('does not report air connected to the volume border as a pocket', () => {
    const dims: [number, number, number] = [20, 20, 20]
    const data = makeVolume(dims, TISSUE)
    setBox(data, dims, AIR, [9, 10], [9, 10], [0, 19]) // open tunnel through the volume

    const { pockets, interiorAir } = analyzeAirRegions(data, dims, [1, 1, 1], THRESHOLD)

    expect(pockets).toHaveLength(0)
    expect(interiorAir).not.toBeNull() // still snaps into the open tunnel
  })

  it('separates outside air from a pocket enclosed in an interior object', () => {
    const dims: [number, number, number] = [20, 20, 20]
    const data = makeVolume(dims, AIR) // scanner air everywhere
    setBox(data, dims, TISSUE, [2, 17], [2, 17], [2, 17]) // solid block
    setBox(data, dims, AIR, [6, 13], [6, 13], [6, 13]) // 8^3 = 512 mm^3 cavity

    const { pockets } = analyzeAirRegions(data, dims, [1, 1, 1], THRESHOLD)

    expect(pockets).toHaveLength(1)
    expect(pockets[0]?.volumeMm3).toBe(512)
    expect(pockets[0]?.centroid).toEqual([9.5, 9.5, 9.5])
  })

  it('filters pockets below the noise floor and sorts the rest by volume', () => {
    const dims: [number, number, number] = [40, 20, 20]
    const data = makeVolume(dims, TISSUE)
    setBox(data, dims, AIR, [2, 11], [2, 11], [2, 11]) // 1000 mm^3
    setBox(data, dims, AIR, [20, 28], [2, 10], [2, 9]) // 648 mm^3
    setBox(data, dims, AIR, [34, 36], [2, 4], [2, 4]) // 27 mm^3, below MIN_POCKET_MM3

    const { pockets } = analyzeAirRegions(data, dims, [1, 1, 1], THRESHOLD)

    expect(pockets.map((pocket) => pocket.volumeMm3)).toEqual([1000, 648])
  })

  it('reports null interiorAir when the volume has no air at all', () => {
    const dims: [number, number, number] = [8, 8, 8]
    const data = makeVolume(dims, TISSUE)

    const { pockets, interiorAir } = analyzeAirRegions(data, dims, [1, 1, 1], THRESHOLD)

    expect(pockets).toHaveLength(0)
    expect(interiorAir).toBeNull()
  })
})
