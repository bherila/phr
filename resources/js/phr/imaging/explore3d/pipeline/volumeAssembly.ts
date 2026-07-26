/**
 * Pure, dependency-free assembly of a 3D voxel volume from a set of decoded
 * DICOM slices. Mirrors server-side slice-eligibility rules (orientation
 * grouping, dimension consistency, spacing uniformity) as defense in depth
 * before voxel data ever reaches the renderer.
 */

export interface SliceGeometry {
  /** ImagePositionPatient (mm). */
  position: readonly [number, number, number];
  /** IOP row direction cosines followed by column direction cosines. */
  orientation: readonly [number, number, number, number, number, number] | null;
  /** [rowSpacing, colSpacing] mm. */
  pixelSpacing: readonly [number, number] | null;
}

export interface DecodedSlice {
  instanceId: number;
  /** rows*columns, row-major, already rescaled to HU/density. */
  pixels: Int16Array;
  rows: number;
  columns: number;
  geom: SliceGeometry;
}

export interface AssembledVolume {
  /** x + y*nx + z*nx*ny; x=column, y=row, z=slice. */
  data: Int16Array;
  /** [columns, rows, sliceCount]. */
  dims: [number, number, number];
  /** [colSpacing, rowSpacing, sliceSpacing] mm. */
  spacing: [number, number, number];
  /** ImagePositionPatient of the first sorted slice. */
  origin: [number, number, number];
  orientation: [number, number, number, number, number, number];
  /** Slices excluded (outlier orientation / missing geometry / dimension mismatch / duplicate projection). */
  droppedInstanceIds: number[];
  warnings: string[];
}

interface GeometricSlice extends DecodedSlice {
  geom: SliceGeometry & {
    orientation: readonly [number, number, number, number, number, number];
    pixelSpacing: readonly [number, number];
  };
}

const DUPLICATE_PROJECTION_EPSILON = 1e-3;
const NON_UNIFORM_SPACING_TOLERANCE = 0.1;

function at<T>(items: readonly T[], index: number): T {
  const value = items[index];
  if (value === undefined) {
    throw new Error(`volumeAssembly: index ${index} out of range`);
  }
  return value;
}

function hasGeometry(slice: DecodedSlice): slice is GeometricSlice {
  return slice.geom.orientation !== null && slice.geom.pixelSpacing !== null;
}

function median(values: readonly number[]): number {
  if (values.length === 0) {
    return 1;
  }
  const sorted = [...values].sort((a, b) => a - b);
  const mid = Math.floor(sorted.length / 2);
  return sorted.length % 2 === 0 ? (at(sorted, mid - 1) + at(sorted, mid)) / 2 : at(sorted, mid);
}

export function orientationKey(orientation: readonly number[]): string {
  return orientation.map((value) => value.toFixed(4)).join(',');
}

export function sliceNormal(
  orientation: readonly [number, number, number, number, number, number],
): [number, number, number] {
  const [r1, r2, r3, c1, c2, c3] = orientation;
  const normal: [number, number, number] = [r2 * c3 - r3 * c2, r3 * c1 - r1 * c3, r1 * c2 - r2 * c1];
  // Normalize -0 to 0 so downstream equality checks and formatted output are stable.
  return normal.map((value) => (value === 0 ? 0 : value)) as [number, number, number];
}

export function projectionAlongNormal(
  position: readonly [number, number, number],
  normal: readonly [number, number, number],
): number {
  return position[0] * normal[0] + position[1] * normal[1] + position[2] * normal[2];
}

export function assembleVolume(slices: readonly DecodedSlice[]): AssembledVolume {
  const droppedInstanceIds: number[] = [];
  const warnings: string[] = [];

  const withGeometry: GeometricSlice[] = [];
  for (const slice of slices) {
    if (hasGeometry(slice)) {
      withGeometry.push(slice);
    } else {
      droppedInstanceIds.push(slice.instanceId);
    }
  }

  const groups = new Map<string, GeometricSlice[]>();
  for (const slice of withGeometry) {
    const key = orientationKey(slice.geom.orientation);
    const group = groups.get(key);
    if (group) {
      group.push(slice);
    } else {
      groups.set(key, [slice]);
    }
  }

  const firstSurvivingKey =
    withGeometry.length > 0 ? orientationKey(at(withGeometry, 0).geom.orientation) : null;

  let keptKey: string | null = null;
  let keptGroup: GeometricSlice[] = [];
  for (const [key, group] of groups) {
    if (
      group.length > keptGroup.length ||
      (group.length === keptGroup.length && key === firstSurvivingKey)
    ) {
      keptKey = key;
      keptGroup = group;
    }
  }

  for (const [key, group] of groups) {
    if (key !== keptKey) {
      for (const slice of group) {
        droppedInstanceIds.push(slice.instanceId);
      }
    }
  }

  if (keptGroup.length === 0) {
    throw new Error('assembleVolume: fewer than 2 slices survive filtering');
  }

  const reference = at(keptGroup, 0);
  const referenceOrientation = reference.geom.orientation;
  const referencePixelSpacing = reference.geom.pixelSpacing;

  const dimensionMatched: GeometricSlice[] = [];
  for (const slice of keptGroup) {
    if (slice.rows === reference.rows && slice.columns === reference.columns) {
      dimensionMatched.push(slice);
    } else {
      droppedInstanceIds.push(slice.instanceId);
    }
  }

  const normal = sliceNormal(referenceOrientation);
  const sorted = [...dimensionMatched].sort(
    (a, b) =>
      projectionAlongNormal(a.geom.position, normal) - projectionAlongNormal(b.geom.position, normal),
  );

  const deduped: GeometricSlice[] = [];
  let lastProjection: number | null = null;
  for (const slice of sorted) {
    const projection = projectionAlongNormal(slice.geom.position, normal);
    if (lastProjection !== null && Math.abs(projection - lastProjection) < DUPLICATE_PROJECTION_EPSILON) {
      droppedInstanceIds.push(slice.instanceId);
      continue;
    }
    deduped.push(slice);
    lastProjection = projection;
  }

  if (deduped.length < 2) {
    throw new Error('assembleVolume: fewer than 2 slices survive filtering');
  }

  const projections = deduped.map((slice) => projectionAlongNormal(slice.geom.position, normal));
  const deltas: number[] = [];
  for (let i = 1; i < projections.length; i += 1) {
    deltas.push(at(projections, i) - at(projections, i - 1));
  }
  const sliceSpacing = median(deltas);
  if (deltas.some((delta) => Math.abs(delta - sliceSpacing) > sliceSpacing * NON_UNIFORM_SPACING_TOLERANCE)) {
    warnings.push('non_uniform_spacing');
  }

  const columns = reference.columns;
  const rows = reference.rows;
  const sliceCount = deduped.length;
  const sliceVoxelCount = columns * rows;
  const data = new Int16Array(columns * rows * sliceCount);
  deduped.forEach((slice, z) => {
    data.set(slice.pixels, z * sliceVoxelCount);
  });

  const originPosition = at(deduped, 0).geom.position;
  const [o1, o2, o3, o4, o5, o6] = referenceOrientation;

  return {
    data,
    dims: [columns, rows, sliceCount],
    spacing: [referencePixelSpacing[1], referencePixelSpacing[0], sliceSpacing],
    origin: [originPosition[0], originPosition[1], originPosition[2]],
    orientation: [o1, o2, o3, o4, o5, o6],
    droppedInstanceIds,
    warnings,
  };
}

/**
 * Separable 3x3x3 box blur of a scalar field, returning a new Int16Array of
 * the same dimensions. Applied before iso-surfacing to suppress the "shattered
 * shell" that thresholding produces where density hovers around the iso value
 * (partial-volume air/mucosa/fluid): speckle voxels that individually cross
 * the threshold get averaged into a coherent surface. Three separable passes
 * (x, then y, then z) are equivalent to one 27-tap blur at a fraction of the
 * cost. Border samples clamp to the edge voxel.
 */
export function smoothField(data: Int16Array, dims: readonly [number, number, number]): Int16Array {
  const [nx, ny, nz] = dims
  const clampX = (x: number): number => (x < 0 ? 0 : x >= nx ? nx - 1 : x)
  const clampY = (y: number): number => (y < 0 ? 0 : y >= ny ? ny - 1 : y)
  const clampZ = (z: number): number => (z < 0 ? 0 : z >= nz ? nz - 1 : z)

  const passX = new Int16Array(data.length)
  for (let z = 0; z < nz; z += 1) {
    for (let y = 0; y < ny; y += 1) {
      const base = (z * ny + y) * nx
      for (let x = 0; x < nx; x += 1) {
        const a = data[base + clampX(x - 1)] as number
        const b = data[base + x] as number
        const c = data[base + clampX(x + 1)] as number
        passX[base + x] = Math.round((a + b + c) / 3)
      }
    }
  }

  const passY = new Int16Array(data.length)
  for (let z = 0; z < nz; z += 1) {
    for (let y = 0; y < ny; y += 1) {
      for (let x = 0; x < nx; x += 1) {
        const a = passX[(z * ny + clampY(y - 1)) * nx + x] as number
        const b = passX[(z * ny + y) * nx + x] as number
        const c = passX[(z * ny + clampY(y + 1)) * nx + x] as number
        passY[(z * ny + y) * nx + x] = Math.round((a + b + c) / 3)
      }
    }
  }

  const passZ = new Int16Array(data.length)
  for (let z = 0; z < nz; z += 1) {
    for (let y = 0; y < ny; y += 1) {
      for (let x = 0; x < nx; x += 1) {
        const a = passY[(clampZ(z - 1) * ny + y) * nx + x] as number
        const b = passY[(z * ny + y) * nx + x] as number
        const c = passY[(clampZ(z + 1) * ny + y) * nx + x] as number
        passZ[(z * ny + y) * nx + x] = Math.round((a + b + c) / 3)
      }
    }
  }
  return passZ
}

export function downsample2x(volume: AssembledVolume): AssembledVolume {
  const [nx, ny, nz] = volume.dims;
  const nx2 = Math.ceil(nx / 2);
  const ny2 = Math.ceil(ny / 2);
  const nz2 = Math.ceil(nz / 2);
  const data = new Int16Array(nx2 * ny2 * nz2);

  for (let oz = 0; oz < nz2; oz += 1) {
    for (let oy = 0; oy < ny2; oy += 1) {
      for (let ox = 0; ox < nx2; ox += 1) {
        let sum = 0;
        let count = 0;
        for (let dz = 0; dz < 2; dz += 1) {
          const z = oz * 2 + dz;
          if (z >= nz) {
            continue;
          }
          for (let dy = 0; dy < 2; dy += 1) {
            const y = oy * 2 + dy;
            if (y >= ny) {
              continue;
            }
            for (let dx = 0; dx < 2; dx += 1) {
              const x = ox * 2 + dx;
              if (x >= nx) {
                continue;
              }
              sum += volume.data[x + y * nx + z * nx * ny] ?? 0;
              count += 1;
            }
          }
        }
        data[ox + oy * nx2 + oz * nx2 * ny2] = Math.round(sum / count);
      }
    }
  }

  return {
    data,
    dims: [nx2, ny2, nz2],
    spacing: [volume.spacing[0] * 2, volume.spacing[1] * 2, volume.spacing[2] * 2],
    origin: volume.origin,
    orientation: volume.orientation,
    droppedInstanceIds: volume.droppedInstanceIds,
    warnings: volume.warnings,
  };
}
