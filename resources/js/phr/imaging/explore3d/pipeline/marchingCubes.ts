import { EDGE_TABLE, TRI_TABLE } from "./marchingCubesTables";

/**
 * Result of a {@link marchingCubes} extraction. Vertex-parallel arrays
 * (`positions`/`normals`) are indexed by `indices`, which lists triangles as
 * flat vertex-index triples.
 */
export interface MarchingCubesResult {
  /** xyz triples, in millimeters (grid index * spacing). */
  positions: Float32Array;
  /**
   * Unit normals. Computed as the NEGATED central-difference gradient of the
   * field, so they point from high field values toward low field values.
   * With CT data (iso around -400 HU, air around -1000, tissue >= 0) that
   * makes normals point out of tissue into air cavities, which is what a
   * fly-through viewer needs to light interior walls.
   */
  normals: Float32Array;
  /** Triangle indices into `positions`/`normals` (three per triangle). */
  indices: Uint32Array;
  /**
   * Per-vertex rgb (0..1 triples), classifying the material on the solid side
   * of each surface point: air-wall (bone-tan), mucosa/fluid (amber), or bone
   * (pale). Present only when `classify` is set; otherwise a flat base tint.
   */
  colors: Float32Array;
  triangleCount: number;
  /** True if `maxTriangles` was hit and extraction stopped early. */
  truncated: boolean;
}

/** Surface tint when classification is off, or the material behind is still air-like. */
const BASE_COLOR: readonly [number, number, number] = [0.85, 0.79, 0.66];
/** Mucosa / fluid / soft-tissue band (roughly -150..+200 HU behind the wall). */
const MUCOSA_COLOR: readonly [number, number, number] = [0.85, 0.44, 0.19];
/** Bone / dense band (> ~+200 HU behind the wall). */
const BONE_COLOR: readonly [number, number, number] = [0.9, 0.92, 0.97];
/** HU thresholds separating the classification bands (see colors above). */
const MUCOSA_MIN_HU = -150;
const BONE_MIN_HU = 200;
/**
 * Wall classification marches inward along the normal and keys on how much
 * soft tissue sits between the air and the bone behind it:
 *   - bone within THIN steps (bare bone or thin normal mucosa) → open wall (bone tint)
 *   - soft tissue thicker than that, but bone still found within MAX steps
 *     → thickened mucosa / fluid over bone, i.e. a blocked/opacified cavity (amber)
 *   - soft tissue with no bone within MAX steps → bulk soft tissue such as the
 *     exterior skin/face, which is not a cavity lining (base tint)
 * Steps are in grid units; on this ~1mm-voxel data MAX ≈ 1cm of tissue depth.
 */
const CLASSIFY_MAX_STEPS = 12;
const CLASSIFY_THIN_WALL_STEPS = 3;

/** Local unit-cube corner offsets, indexed by corner id 0-7 (see marchingCubesTables.ts). */
const CORNER_OFFSETS: readonly (readonly [number, number, number])[] = [
  [0, 0, 0],
  [1, 0, 0],
  [0, 1, 0],
  [1, 1, 0],
  [0, 0, 1],
  [1, 0, 1],
  [0, 1, 1],
  [1, 1, 1],
];

/** The two corner ids each of the 12 cube edges connects (see marchingCubesTables.ts). */
const EDGE_CORNERS: readonly (readonly [number, number])[] = [
  [0, 1],
  [1, 3],
  [3, 2],
  [2, 0],
  [4, 5],
  [5, 7],
  [7, 6],
  [6, 4],
  [0, 4],
  [1, 5],
  [3, 7],
  [2, 6],
];

interface EdgeGeometry {
  /** Which axis (0=x, 1=y, 2=z) this edge runs along. */
  readonly axis: 0 | 1 | 2;
  /** The edge's lower-corner local offset, used to canonicalize the edge across adjacent cells. */
  readonly lowOffset: readonly [number, number, number];
}

/**
 * Indexes an array with a runtime-checked bounds guard, avoiding both `any`
 * and non-null assertions while satisfying `noUncheckedIndexedAccess`.
 */
function at<T>(arr: readonly T[], index: number): T {
  const value = arr[index];
  if (value === undefined) {
    throw new RangeError(`marchingCubes: index ${index} out of bounds (length ${arr.length})`);
  }
  return value;
}

function computeEdgeGeometry(
  cornerA: readonly [number, number, number],
  cornerB: readonly [number, number, number],
): EdgeGeometry {
  const [ax, ay, az] = cornerA;
  const [bx, by, bz] = cornerB;
  let axis: 0 | 1 | 2;
  if (ax !== bx) {
    axis = 0;
  } else if (ay !== by) {
    axis = 1;
  } else {
    axis = 2;
  }
  const lowOffset: readonly [number, number, number] = [Math.min(ax, bx), Math.min(ay, by), Math.min(az, bz)];
  return { axis, lowOffset };
}

/** Derived (not transcribed) per-edge axis + canonical lower-corner offset, from CORNER_OFFSETS/EDGE_CORNERS above. */
const EDGE_GEOMETRY: readonly EdgeGeometry[] = EDGE_CORNERS.map(([cornerAId, cornerBId]) =>
  computeEdgeGeometry(at(CORNER_OFFSETS, cornerAId), at(CORNER_OFFSETS, cornerBId)),
);

function fieldIndex(x: number, y: number, z: number, nx: number, ny: number): number {
  return x + y * nx + z * nx * ny;
}

function readField(field: Int16Array | Float32Array, index: number): number {
  const value = field[index];
  if (value === undefined) {
    throw new RangeError(`marchingCubes: field index ${index} out of bounds (length ${field.length})`);
  }
  return value;
}

/** Reads the field at (x, y, z), clamping out-of-range coordinates to the volume border. */
function sampleField(
  field: Int16Array | Float32Array,
  dims: readonly [number, number, number],
  x: number,
  y: number,
  z: number,
): number {
  const [nx, ny, nz] = dims;
  const cx = Math.min(Math.max(x, 0), nx - 1);
  const cy = Math.min(Math.max(y, 0), ny - 1);
  const cz = Math.min(Math.max(z, 0), nz - 1);
  return readField(field, fieldIndex(cx, cy, cz, nx, ny));
}

/**
 * Central-difference gradient of the field at integer grid point (x, y, z),
 * in field-units per millimeter (divided by spacing so anisotropic voxels
 * still produce a physically correct direction). Border samples clamp into
 * the volume, which yields a one-sided (still valid) difference there.
 */
function sampleGradient(
  field: Int16Array | Float32Array,
  dims: readonly [number, number, number],
  x: number,
  y: number,
  z: number,
  spacing: readonly [number, number, number],
): readonly [number, number, number] {
  const [sx, sy, sz] = spacing;
  const gx = (sampleField(field, dims, x + 1, y, z) - sampleField(field, dims, x - 1, y, z)) / (2 * sx);
  const gy = (sampleField(field, dims, x, y + 1, z) - sampleField(field, dims, x, y - 1, z)) / (2 * sy);
  const gz = (sampleField(field, dims, x, y, z + 1) - sampleField(field, dims, x, y, z - 1)) / (2 * sz);
  return [gx, gy, gz];
}

const EDGE_INTERP_EPSILON = 1e-8;
const NORMAL_MIN_MAGNITUDE = 1e-12;

/**
 * Classic marching cubes surface extraction.
 *
 * A corner is "inside" the surface when its field value is less than `iso`
 * (e.g. for CT data with iso around -400 HU, air at ~-1000 is inside and
 * tissue at 0+ is outside). Normals are the negated central-difference
 * gradient of the field, normalized — see {@link MarchingCubesResult.normals}.
 *
 * Vertex normals are computed by sampling the (unnormalized) gradient at the
 * two grid-point endpoints of each crossed edge, linearly interpolating by
 * the same `t` used for the vertex position, then negating and normalizing
 * the result. This is simpler than resampling the gradient via trilinear
 * interpolation at the exact interpolated vertex position, and is accurate
 * enough for the piecewise-linear surface marching cubes already produces.
 */
export function marchingCubes(
  field: Int16Array | Float32Array,
  dims: readonly [number, number, number],
  spacing: readonly [number, number, number],
  iso: number,
  maxTriangles = 2_000_000,
  classify = false,
): MarchingCubesResult {
  const [nx, ny, nz] = dims;
  const [spacingX, spacingY, spacingZ] = spacing;

  const positions: number[] = [];
  const normals: number[] = [];
  const colors: number[] = [];
  const indices: number[] = [];
  const vertexMap = new Map<number, number>();

  let vertexCount = 0;
  let triangleCount = 0;
  let truncated = false;

  const cornerFieldValues = new Array<number>(8).fill(0);
  const cornerGridX = new Array<number>(8).fill(0);
  const cornerGridY = new Array<number>(8).fill(0);
  const cornerGridZ = new Array<number>(8).fill(0);

  function getOrCreateVertex(edgeId: number, cellX: number, cellY: number, cellZ: number): number {
    const geometry = at(EDGE_GEOMETRY, edgeId);
    const [cornerAId, cornerBId] = at(EDGE_CORNERS, edgeId);

    const ax = at(cornerGridX, cornerAId);
    const ay = at(cornerGridY, cornerAId);
    const az = at(cornerGridZ, cornerAId);
    const bx = at(cornerGridX, cornerBId);
    const by = at(cornerGridY, cornerBId);
    const bz = at(cornerGridZ, cornerBId);

    const lowX = cellX + geometry.lowOffset[0];
    const lowY = cellY + geometry.lowOffset[1];
    const lowZ = cellZ + geometry.lowOffset[2];
    const key = geometry.axis + 3 * (lowX + lowY * nx + lowZ * nx * ny);

    const existing = vertexMap.get(key);
    if (existing !== undefined) {
      return existing;
    }

    const fa = at(cornerFieldValues, cornerAId);
    const fb = at(cornerFieldValues, cornerBId);
    const denom = fb - fa;
    let t = Math.abs(denom) < EDGE_INTERP_EPSILON ? 0.5 : (iso - fa) / denom;
    t = Math.min(1, Math.max(0, t));

    const vx = ax + (bx - ax) * t;
    const vy = ay + (by - ay) * t;
    const vz = az + (bz - az) * t;
    positions.push(vx * spacingX, vy * spacingY, vz * spacingZ);

    const [gax, gay, gaz] = sampleGradient(field, dims, ax, ay, az, spacing);
    const [gbx, gby, gbz] = sampleGradient(field, dims, bx, by, bz, spacing);
    const gx = gax + (gbx - gax) * t;
    const gy = gay + (gby - gay) * t;
    const gz = gaz + (gbz - gaz) * t;

    let nxOut = -gx;
    let nyOut = -gy;
    let nzOut = -gz;
    const magnitude = Math.sqrt(nxOut * nxOut + nyOut * nyOut + nzOut * nzOut);
    if (magnitude < NORMAL_MIN_MAGNITUDE) {
      nxOut = 0;
      nyOut = 0;
      nzOut = 1;
    } else {
      nxOut /= magnitude;
      nyOut /= magnitude;
      nzOut /= magnitude;
    }
    normals.push(nxOut, nyOut, nzOut);

    /*
     * Classify the material on the solid side of this wall. Normals point into
     * air (negated gradient), so stepping along -normal moves into the denser
     * tissue; the density sampled there picks the tint. A degenerate gradient
     * (flat region) can't be classified, so it keeps the base tint.
     */
    let color = BASE_COLOR;
    if (classify && magnitude >= NORMAL_MIN_MAGNITUDE) {
      let boneStep = Infinity;
      let sawSoftTissue = false;
      for (let step = 1; step <= CLASSIFY_MAX_STEPS; step += 1) {
        const tissue = sampleField(
          field,
          dims,
          Math.round(vx - nxOut * step),
          Math.round(vy - nyOut * step),
          Math.round(vz - nzOut * step),
        );
        if (tissue >= BONE_MIN_HU) {
          boneStep = step;
          break;
        }
        if (tissue >= MUCOSA_MIN_HU) {
          sawSoftTissue = true;
        }
      }
      if (boneStep <= CLASSIFY_THIN_WALL_STEPS) {
        color = BONE_COLOR;
      } else if (sawSoftTissue && Number.isFinite(boneStep)) {
        color = MUCOSA_COLOR;
      }
    }
    colors.push(color[0], color[1], color[2]);

    const newIndex = vertexCount;
    vertexCount += 1;
    vertexMap.set(key, newIndex);
    return newIndex;
  }

  cellLoop: for (let cz = 0; cz < nz - 1; cz++) {
    for (let cy = 0; cy < ny - 1; cy++) {
      for (let cx = 0; cx < nx - 1; cx++) {
        let caseIndex = 0;
        for (let cornerId = 0; cornerId < 8; cornerId++) {
          const offset = at(CORNER_OFFSETS, cornerId);
          const gx = cx + offset[0];
          const gy = cy + offset[1];
          const gz = cz + offset[2];
          cornerGridX[cornerId] = gx;
          cornerGridY[cornerId] = gy;
          cornerGridZ[cornerId] = gz;
          const value = readField(field, fieldIndex(gx, gy, gz, nx, ny));
          cornerFieldValues[cornerId] = value;
          if (value < iso) {
            caseIndex |= 1 << cornerId;
          }
        }

        if (at(EDGE_TABLE, caseIndex) === 0) {
          continue;
        }

        const row = at(TRI_TABLE, caseIndex);
        for (let i = 0; i < row.length; i += 3) {
          if (triangleCount >= maxTriangles) {
            truncated = true;
            break cellLoop;
          }

          const edgeA = at(row, i);
          const edgeB = at(row, i + 1);
          const edgeC = at(row, i + 2);
          const va = getOrCreateVertex(edgeA, cx, cy, cz);
          const vb = getOrCreateVertex(edgeB, cx, cy, cz);
          const vc = getOrCreateVertex(edgeC, cx, cy, cz);

          /*
           * Orient winding to agree with the gradient-derived vertex normals.
           * Renderers that light double-sided surfaces flip the shading normal
           * by geometric facing (winding), not by the vertex normal, so a
           * mismatch renders every front-facing wall with an inverted normal
           * (ambient-only, bright only at grazing rims). The classic tables
           * wind triangles around the "value < iso" region, which for a
           * fly-through (air inside) is the opposite of the into-air normals.
           */
          const ax = at(positions, va * 3);
          const ay = at(positions, va * 3 + 1);
          const az = at(positions, va * 3 + 2);
          const abx = at(positions, vb * 3) - ax;
          const aby = at(positions, vb * 3 + 1) - ay;
          const abz = at(positions, vb * 3 + 2) - az;
          const acx = at(positions, vc * 3) - ax;
          const acy = at(positions, vc * 3 + 1) - ay;
          const acz = at(positions, vc * 3 + 2) - az;
          const faceX = aby * acz - abz * acy;
          const faceY = abz * acx - abx * acz;
          const faceZ = abx * acy - aby * acx;
          const normalSumX = at(normals, va * 3) + at(normals, vb * 3) + at(normals, vc * 3);
          const normalSumY = at(normals, va * 3 + 1) + at(normals, vb * 3 + 1) + at(normals, vc * 3 + 1);
          const normalSumZ = at(normals, va * 3 + 2) + at(normals, vb * 3 + 2) + at(normals, vc * 3 + 2);
          if (faceX * normalSumX + faceY * normalSumY + faceZ * normalSumZ < 0) {
            indices.push(va, vc, vb);
          } else {
            indices.push(va, vb, vc);
          }
          triangleCount += 1;
        }
      }
    }
  }

  return {
    positions: Float32Array.from(positions),
    normals: Float32Array.from(normals),
    colors: Float32Array.from(colors),
    indices: Uint32Array.from(indices),
    triangleCount,
    truncated,
  };
}
