import { marchingCubes } from "@/phr/imaging/explore3d/pipeline/marchingCubes";

const DIM = 32;
const DIMS: readonly [number, number, number] = [DIM, DIM, DIM];
const SPACING: readonly [number, number, number] = [1, 1, 1];
const CENTER: readonly [number, number, number] = [15.5, 15.5, 15.5];
const RADIUS = 10;

/**
 * Synthetic sphere field: value = (distance from center - RADIUS) * 100, so
 * the field is negative inside the sphere and positive outside. Extracting
 * at iso=0 yields the r=RADIUS shell; extracting at iso=(dist-RADIUS)*100
 * for any other dist yields that concentric shell on the same field.
 */
function buildSphereField(): Float32Array {
  const field = new Float32Array(DIM * DIM * DIM);
  for (let z = 0; z < DIM; z++) {
    for (let y = 0; y < DIM; y++) {
      for (let x = 0; x < DIM; x++) {
        const dx = x - CENTER[0];
        const dy = y - CENTER[1];
        const dz = z - CENTER[2];
        const dist = Math.sqrt(dx * dx + dy * dy + dz * dz);
        field[x + y * DIM + z * DIM * DIM] = (dist - RADIUS) * 100;
      }
    }
  }
  return field;
}

describe("marchingCubes", () => {
  const field = buildSphereField();

  it("extracts a non-degenerate, well-formed shell at iso=0", () => {
    const result = marchingCubes(field, DIMS, SPACING, 0);

    expect(result.truncated).toBe(false);
    expect(result.triangleCount).toBeGreaterThan(0);
    expect(result.indices.length).toBe(result.triangleCount * 3);

    const vertexCount = result.positions.length / 3;
    for (let i = 0; i < result.positions.length; i++) {
      expect(Number.isNaN(result.positions[i])).toBe(false);
    }
    for (let i = 0; i < result.normals.length; i++) {
      expect(Number.isNaN(result.normals[i])).toBe(false);
    }
    for (let i = 0; i < result.indices.length; i++) {
      const idx = result.indices[i] as number;
      expect(idx).toBeLessThan(vertexCount);
      expect(idx).toBeGreaterThanOrEqual(0);
    }
  });

  it("places every vertex within RADIUS +/- 1.5 of the sphere center", () => {
    const result = marchingCubes(field, DIMS, SPACING, 0);
    const vertexCount = result.positions.length / 3;

    for (let v = 0; v < vertexCount; v++) {
      const x = result.positions[v * 3] as number;
      const y = result.positions[v * 3 + 1] as number;
      const z = result.positions[v * 3 + 2] as number;
      const dx = x - CENTER[0];
      const dy = y - CENTER[1];
      const dz = z - CENTER[2];
      const dist = Math.sqrt(dx * dx + dy * dy + dz * dz);
      expect(Math.abs(dist - RADIUS)).toBeLessThanOrEqual(1.5);
    }
  });

  it("produces unit normals pointing inward, toward the field minimum", () => {
    // The field INCREASES with distance from the center (dist - RADIUS,
    // scaled), so its gradient points radially OUTWARD (toward higher
    // values). Normals are the NEGATED gradient, so they point radially
    // INWARD (toward the center, where the field is lowest). That means the
    // dot product of a normal with the outward radial direction
    // (vertex - center, normalized) should be strongly NEGATIVE, not
    // positive — this is the inverse of the usual "normals point away from
    // the shape" intuition, and is intentional per the field convention.
    const result = marchingCubes(field, DIMS, SPACING, 0);
    const vertexCount = result.positions.length / 3;

    let passCount = 0;
    for (let v = 0; v < vertexCount; v++) {
      const x = result.positions[v * 3] as number;
      const y = result.positions[v * 3 + 1] as number;
      const z = result.positions[v * 3 + 2] as number;
      const nx = result.normals[v * 3] as number;
      const ny = result.normals[v * 3 + 1] as number;
      const nz = result.normals[v * 3 + 2] as number;

      const normalMagnitude = Math.sqrt(nx * nx + ny * ny + nz * nz);
      const unitLength = Math.abs(normalMagnitude - 1) < 1e-3;

      const dx = x - CENTER[0];
      const dy = y - CENTER[1];
      const dz = z - CENTER[2];
      const radialMagnitude = Math.sqrt(dx * dx + dy * dy + dz * dz);
      const rx = dx / radialMagnitude;
      const ry = dy / radialMagnitude;
      const rz = dz / radialMagnitude;
      const dot = nx * rx + ny * ry + nz * rz;

      if (unitLength && dot < -0.7) {
        passCount += 1;
      }
    }

    expect(passCount / vertexCount).toBeGreaterThanOrEqual(0.95);
  });

  it("winds every triangle to agree with its vertex normals", () => {
    // Double-sided lighting flips the shading normal by geometric facing
    // (winding), not by the vertex normal. If winding and vertex normals
    // disagree, front-facing walls render with inverted normals — ambient-only
    // with bright grazing rims (the regression this guards against).
    const result = marchingCubes(field, DIMS, SPACING, 0);

    for (let t = 0; t < result.triangleCount; t++) {
      const va = result.indices[t * 3] as number;
      const vb = result.indices[t * 3 + 1] as number;
      const vc = result.indices[t * 3 + 2] as number;
      const ax = result.positions[va * 3] as number;
      const ay = result.positions[va * 3 + 1] as number;
      const az = result.positions[va * 3 + 2] as number;
      const abx = (result.positions[vb * 3] as number) - ax;
      const aby = (result.positions[vb * 3 + 1] as number) - ay;
      const abz = (result.positions[vb * 3 + 2] as number) - az;
      const acx = (result.positions[vc * 3] as number) - ax;
      const acy = (result.positions[vc * 3 + 1] as number) - ay;
      const acz = (result.positions[vc * 3 + 2] as number) - az;
      const faceX = aby * acz - abz * acy;
      const faceY = abz * acx - abx * acz;
      const faceZ = abx * acy - aby * acx;
      const normalX =
        (result.normals[va * 3] as number) + (result.normals[vb * 3] as number) + (result.normals[vc * 3] as number);
      const normalY =
        (result.normals[va * 3 + 1] as number) +
        (result.normals[vb * 3 + 1] as number) +
        (result.normals[vc * 3 + 1] as number);
      const normalZ =
        (result.normals[va * 3 + 2] as number) +
        (result.normals[vb * 3 + 2] as number) +
        (result.normals[vc * 3 + 2] as number);
      expect(faceX * normalX + faceY * normalY + faceZ * normalZ).toBeGreaterThanOrEqual(0);
    }
  });

  it("produces no triangles when iso is far below the field minimum", () => {
    const result = marchingCubes(field, DIMS, SPACING, -1e9);
    expect(result.triangleCount).toBe(0);
    expect(result.truncated).toBe(false);
  });

  it("scales triangle count roughly with r^2 between concentric shells", () => {
    // iso=0 is the dist=RADIUS(=10) shell; iso=-500 is the dist=5 shell on
    // the same field ((dist - 10) * 100 == -500 => dist == 5).
    const outer = marchingCubes(field, DIMS, SPACING, 0);
    const inner = marchingCubes(field, DIMS, SPACING, -500);

    expect(inner.triangleCount).toBeGreaterThan(0);
    const ratio = outer.triangleCount / inner.triangleCount;
    expect(ratio).toBeGreaterThanOrEqual(2.5);
    expect(ratio).toBeLessThanOrEqual(6.5);
  });

  it("emits a flat base tint per vertex when classification is off", () => {
    const result = marchingCubes(field, DIMS, SPACING, 0);
    expect(result.colors.length).toBe(result.positions.length);
    // Every vertex shares the same (base) color.
    for (let v = 0; v < result.colors.length; v += 3) {
      expect(result.colors[v]).toBeCloseTo(result.colors[0] as number, 5);
      expect(result.colors[v + 1]).toBeCloseTo(result.colors[1] as number, 5);
      expect(result.colors[v + 2]).toBeCloseTo(result.colors[2] as number, 5);
    }
  });

  it("classifies a wall by the soft-tissue thickness behind it", () => {
    // Layered slabs stacked along +z with air below the surface at z=16.
    // Normals point into air (−z), so classification marches up into the
    // layers: `soft` voxels of soft tissue, then bone (or nothing).
    const D = 40;
    const dims: readonly [number, number, number] = [D, D, D];
    const AIR = -1000;
    const SOFT = 0; // mucosa/fluid band
    const BONE = 800;
    const build = (softLayers: number, boneAfter: boolean): Float32Array => {
      const f = new Float32Array(D * D * D);
      for (let z = 0; z < D; z++) {
        const value = z < 16 ? AIR : z < 16 + softLayers ? SOFT : boneAfter ? BONE : SOFT;
        for (let y = 0; y < D; y++) {
          for (let x = 0; x < D; x++) {
            f[x + y * D + z * D * D] = value;
          }
        }
      }
      return f;
    };
    const dominant = (colors: Float32Array): [number, number, number] => {
      const mid = Math.floor(colors.length / 6) * 3;
      return [colors[mid] as number, colors[mid + 1] as number, colors[mid + 2] as number];
    };

    // Bare bone / thin lining (bone within THIN steps) → pale bone tint.
    const openWall = dominant(marchingCubes(build(1, true), dims, SPACING, -400, 2_000_000, true).colors);
    expect(openWall[2]).toBeGreaterThan(0.9);
    expect(openWall[0]).toBeGreaterThan(0.85);

    // Thick soft tissue then bone (blocked/opacified cavity) → amber.
    const blocked = dominant(marchingCubes(build(6, true), dims, SPACING, -400, 2_000_000, true).colors);
    expect(blocked[0]).toBeGreaterThan(blocked[2]);

    // Bulk soft tissue with no bone behind (exterior skin) → base tint, not amber.
    const skin = dominant(marchingCubes(build(20, false), dims, SPACING, -400, 2_000_000, true).colors);
    expect(skin[0]).toBeGreaterThan(0.8);
    expect(skin[1]).toBeGreaterThan(0.7); // green high → tan, unlike amber (green ~0.44)
  });

  it("truncates cleanly when maxTriangles is hit", () => {
    const result = marchingCubes(field, DIMS, SPACING, 0, 10);
    expect(result.truncated).toBe(true);
    expect(result.triangleCount).toBe(10);
    expect(result.indices.length).toBe(30);
  });
});
