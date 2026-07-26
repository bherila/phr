import {
  assembleVolume,
  type DecodedSlice,
  downsample2x,
  orientationKey,
  projectionAlongNormal,
  sliceNormal,
  smoothField,
} from '@/phr/imaging/explore3d/pipeline/volumeAssembly';

const AXIAL_ORIENTATION: readonly [number, number, number, number, number, number] = [1, 0, 0, 0, 1, 0];
const CORONAL_ORIENTATION: readonly [number, number, number, number, number, number] = [1, 0, 0, 0, 0, -1];
const OTHER_ORIENTATION: readonly [number, number, number, number, number, number] = [0, 1, 0, 1, 0, 0];
const AXIAL_ORIENTATION_MUT: [number, number, number, number, number, number] = [...AXIAL_ORIENTATION];

function makeSlice(overrides: Partial<DecodedSlice> & { instanceId: number }): DecodedSlice {
  const rows = overrides.rows ?? 2;
  const columns = overrides.columns ?? 2;
  return {
    instanceId: overrides.instanceId,
    pixels: overrides.pixels ?? new Int16Array(rows * columns),
    rows,
    columns,
    geom: overrides.geom ?? {
      position: [0, 0, 0],
      orientation: AXIAL_ORIENTATION,
      pixelSpacing: [1, 1],
    },
  };
}

function axialSlice(instanceId: number, z: number, rows = 2, columns = 2): DecodedSlice {
  return makeSlice({
    instanceId,
    rows,
    columns,
    pixels: new Int16Array(rows * columns).fill(instanceId),
    geom: {
      position: [0, 0, z],
      orientation: AXIAL_ORIENTATION,
      pixelSpacing: [1, 1],
    },
  });
}

describe('orientationKey', () => {
  it('rounds to 4 decimal places so near-identical orientations collide', () => {
    const a = orientationKey([0.42773400001, 0, 0, 0, 1, 0]);
    const b = orientationKey([0.42773399, 0, 0, 0, 1, 0]);
    expect(a).toBe(b);
  });

  it('produces distinct keys for genuinely different orientations', () => {
    expect(orientationKey(AXIAL_ORIENTATION)).not.toBe(orientationKey(CORONAL_ORIENTATION));
  });
});

describe('sliceNormal', () => {
  it('computes the axial normal as row x col = [0,0,1]', () => {
    expect(sliceNormal(AXIAL_ORIENTATION)).toEqual([0, 0, 1]);
  });

  it('computes the coronal normal as row x col, verifying cross-product order', () => {
    // rowDir=[1,0,0], colDir=[0,0,-1] => rowDir x colDir = [0,1,0] (posterior),
    // NOT colDir x rowDir = [0,0,-1] (which would fail the axial case above) or
    // the naive negation [0,-1,0]. This pins down which operand order the
    // implementation must use.
    expect(sliceNormal(CORONAL_ORIENTATION)).toEqual([0, 1, 0]);
  });
});

describe('projectionAlongNormal', () => {
  it('computes the dot product of position and normal', () => {
    expect(projectionAlongNormal([1, 2, 3], [0, 0, 1])).toBe(3);
    expect(projectionAlongNormal([1, 2, 3], [1, 1, 1])).toBe(6);
  });
});

describe('assembleVolume', () => {
  it('sorts shuffled slices by projection along the normal, not array order or instanceId', () => {
    const slices = [
      axialSlice(99, 2),
      axialSlice(5, 0),
      axialSlice(42, 3),
      axialSlice(1, 1),
    ];
    const volume = assembleVolume(slices);
    expect(volume.dims).toEqual([2, 2, 4]);
    expect(volume.origin).toEqual([0, 0, 0]);
    expect(volume.droppedInstanceIds).toEqual([]);
    // z=0 slice in the output must be instanceId=5 (position z=0, smallest projection),
    // verified via the fill value baked into each slice's pixels.
    expect(volume.data[0]).toBe(5);
    expect(volume.data[1 * 2 * 2]).toBe(1);
    expect(volume.data[2 * 2 * 2]).toBe(99);
    expect(volume.data[3 * 2 * 2]).toBe(42);
  });

  it('drops a slice with an outlier orientation, keeping the majority group', () => {
    const slices = [
      axialSlice(1, 0),
      axialSlice(2, 1),
      axialSlice(3, 2),
      makeSlice({
        instanceId: 999,
        geom: { position: [0, 0, 1.5], orientation: OTHER_ORIENTATION, pixelSpacing: [1, 1] },
      }),
      axialSlice(4, 3),
    ];
    const volume = assembleVolume(slices);
    expect(volume.dims[2]).toBe(4);
    expect(volume.droppedInstanceIds).toEqual([999]);
  });

  it('drops slices with null orientation or pixel spacing', () => {
    const slices = [
      axialSlice(1, 0),
      axialSlice(2, 1),
      axialSlice(3, 2),
      makeSlice({
        instanceId: 998,
        geom: { position: [0, 0, 3], orientation: null, pixelSpacing: [1, 1] },
      }),
      makeSlice({
        instanceId: 997,
        geom: { position: [0, 0, 4], orientation: AXIAL_ORIENTATION, pixelSpacing: null },
      }),
    ];
    const volume = assembleVolume(slices);
    expect(volume.dims[2]).toBe(3);
    expect(volume.droppedInstanceIds.sort()).toEqual([997, 998]);
  });

  it('drops a slice whose dimensions do not match the group', () => {
    const slices = [
      axialSlice(1, 0),
      axialSlice(2, 1),
      axialSlice(3, 2, 4, 4),
    ];
    const volume = assembleVolume(slices);
    expect(volume.dims[2]).toBe(2);
    expect(volume.droppedInstanceIds).toEqual([3]);
  });

  it('drops the later of two slices with (near-)duplicate projections without crashing', () => {
    const slices = [
      axialSlice(1, 0),
      axialSlice(2, 1),
      axialSlice(3, 1 + 1e-5),
      axialSlice(4, 2),
    ];
    const volume = assembleVolume(slices);
    expect(volume.dims[2]).toBe(3);
    expect(volume.droppedInstanceIds).toEqual([3]);
  });

  it('flags non-uniform spacing when consecutive deltas diverge from the median by more than 10%', () => {
    const slices = [axialSlice(1, 0), axialSlice(2, 1), axialSlice(3, 2), axialSlice(4, 3.5)];
    const volume = assembleVolume(slices);
    expect(volume.warnings).toContain('non_uniform_spacing');
  });

  it('does not flag uniform spacing', () => {
    const slices = [axialSlice(1, 0), axialSlice(2, 1), axialSlice(3, 2)];
    const volume = assembleVolume(slices);
    expect(volume.warnings).not.toContain('non_uniform_spacing');
  });

  it('throws when fewer than 2 slices survive filtering', () => {
    expect(() => assembleVolume([axialSlice(1, 0)])).toThrow();
    expect(() => assembleVolume([])).toThrow();
  });

  it('addresses voxels as data[x + y*nx + z*nx*ny] without transposing rows and columns', () => {
    // 3 columns x 2 rows per slice, row-major source pixels.
    const slice0 = makeSlice({
      instanceId: 1,
      rows: 2,
      columns: 3,
      pixels: Int16Array.from([0, 1, 2, 3, 4, 5]), // row0=[0,1,2], row1=[3,4,5]
      geom: { position: [0, 0, 0], orientation: AXIAL_ORIENTATION, pixelSpacing: [1, 1] },
    });
    const slice1 = makeSlice({
      instanceId: 2,
      rows: 2,
      columns: 3,
      pixels: Int16Array.from([10, 11, 12, 13, 14, 15]),
      geom: { position: [0, 0, 1], orientation: AXIAL_ORIENTATION, pixelSpacing: [1, 1] },
    });
    const volume = assembleVolume([slice0, slice1]);
    expect(volume.dims).toEqual([3, 2, 2]);

    const nx = 3;
    const ny = 2;
    const at = (x: number, y: number, z: number): number => volume.data[x + y * nx + z * nx * ny] ?? NaN;

    // slice 0 (z=0): row-major [row0: 0,1,2][row1: 3,4,5]
    expect(at(0, 0, 0)).toBe(0);
    expect(at(1, 0, 0)).toBe(1);
    expect(at(2, 0, 0)).toBe(2);
    expect(at(0, 1, 0)).toBe(3);
    expect(at(2, 1, 0)).toBe(5);

    // slice 1 (z=1)
    expect(at(0, 0, 1)).toBe(10);
    expect(at(2, 1, 1)).toBe(15);
  });

  it('reports origin, orientation, and column/row spacing from the first sorted slice', () => {
    const slices = [
      makeSlice({
        instanceId: 1,
        geom: { position: [10, 20, 5], orientation: AXIAL_ORIENTATION, pixelSpacing: [1.5, 2.5] },
      }),
      makeSlice({
        instanceId: 2,
        geom: { position: [10, 20, 6], orientation: AXIAL_ORIENTATION, pixelSpacing: [1.5, 2.5] },
      }),
    ];
    const volume = assembleVolume(slices);
    expect(volume.origin).toEqual([10, 20, 5]);
    expect(volume.orientation).toEqual(AXIAL_ORIENTATION);
    // pixelSpacing is [rowSpacing, colSpacing]; output spacing is [colSpacing, rowSpacing, sliceSpacing].
    expect(volume.spacing).toEqual([2.5, 1.5, 1]);
  });
});

describe('downsample2x', () => {
  it('computes exact 2x2x2 means and doubles spacing for an even-dimensioned volume', () => {
    const nx = 4;
    const ny = 4;
    const nz = 4;
    const data = new Int16Array(nx * ny * nz);
    for (let z = 0; z < nz; z += 1) {
      for (let y = 0; y < ny; y += 1) {
        for (let x = 0; x < nx; x += 1) {
          data[x + y * nx + z * nx * ny] = x + y * 4 + z * 16;
        }
      }
    }
    const volume = {
      data,
      dims: [nx, ny, nz] as [number, number, number],
      spacing: [1, 2, 3] as [number, number, number],
      origin: [0, 0, 0] as [number, number, number],
      orientation: AXIAL_ORIENTATION_MUT,
      droppedInstanceIds: [7],
      warnings: ['non_uniform_spacing'],
    };

    const down = downsample2x(volume);
    expect(down.dims).toEqual([2, 2, 2]);
    expect(down.spacing).toEqual([2, 4, 6]);
    expect(down.origin).toEqual([0, 0, 0]);
    expect(down.orientation).toEqual(AXIAL_ORIENTATION);
    expect(down.droppedInstanceIds).toEqual([7]);
    expect(down.warnings).toEqual(['non_uniform_spacing']);

    // (0,0,0): values 0,1,4,5,16,17,20,21 => mean 10.5 => rounds to 11
    expect(down.data[0]).toBe(11);
  });

  it('computes partial-cell means at the edges of an odd-dimensioned volume', () => {
    const nx = 3;
    const ny = 3;
    const nz = 3;
    const data = new Int16Array(nx * ny * nz);
    for (let z = 0; z < nz; z += 1) {
      for (let y = 0; y < ny; y += 1) {
        for (let x = 0; x < nx; x += 1) {
          data[x + y * nx + z * nx * ny] = x + y * 3 + z * 9;
        }
      }
    }
    const volume = {
      data,
      dims: [nx, ny, nz] as [number, number, number],
      spacing: [1, 1, 1] as [number, number, number],
      origin: [0, 0, 0] as [number, number, number],
      orientation: AXIAL_ORIENTATION_MUT,
      droppedInstanceIds: [],
      warnings: [],
    };

    const down = downsample2x(volume);
    expect(down.dims).toEqual([2, 2, 2]);

    const nx2 = 2;
    const ny2 = 2;
    const at = (x: number, y: number, z: number): number => down.data[x + y * nx2 + z * nx2 * ny2] ?? NaN;

    // (0,0,0): full 2x2x2 cell => values 0,1,3,4,9,10,12,13 => mean 6.5 => rounds to 7
    expect(at(0, 0, 0)).toBe(7);
    // (1,1,1): single contributing voxel (2,2,2) => value 2+6+18=26
    expect(at(1, 1, 1)).toBe(26);
  });
});

describe('smoothField', () => {
  const dims: [number, number, number] = [5, 5, 5];

  it('preserves dimensions and leaves a uniform field unchanged', () => {
    const uniform = new Int16Array(5 * 5 * 5).fill(42);
    const result = smoothField(uniform, dims);
    expect(result.length).toBe(uniform.length);
    for (const value of result) {
      expect(value).toBe(42);
    }
  });

  it('attenuates an isolated spike (de-speckle) while conserving it locally', () => {
    const data = new Int16Array(5 * 5 * 5);
    const center = 2 + 2 * 5 + 2 * 25;
    data[center] = 2700; // a lone bright speckle in a field of zeros
    const result = smoothField(data, dims);
    // The spike is spread over its 3x3x3 neighborhood: the peak drops sharply...
    expect(result[center]).toBeLessThan(200);
    expect(result[center]).toBeGreaterThan(0);
    // ...and immediate neighbors pick up a share instead of staying zero.
    expect(result[center - 1] as number).toBeGreaterThan(0);
  });

  it('does not shift the position of a smooth step edge on average', () => {
    // Half zeros, half 1000 along x; the midpoint value should stay near 500.
    const data = new Int16Array(5 * 5 * 5);
    for (let z = 0; z < 5; z++) {
      for (let y = 0; y < 5; y++) {
        for (let x = 0; x < 5; x++) {
          data[x + y * 5 + z * 25] = x >= 3 ? 1000 : 0;
        }
      }
    }
    const result = smoothField(data, dims);
    // Column x=2 (last "low" column) and x=3 (first "high") straddle the edge;
    // their smoothed values should bracket the midpoint symmetrically.
    const low = result[2 + 2 * 5 + 2 * 25] as number;
    const high = result[3 + 2 * 5 + 2 * 25] as number;
    expect(low + high).toBeGreaterThan(800);
    expect(low + high).toBeLessThan(1200);
  });
});
