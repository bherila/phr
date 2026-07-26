/**
 * Pure orientation math mapping volume mesh coordinates into a patient-true
 * display frame, kept three.js-free so it is unit-testable in node.
 *
 * Mesh coordinates are DICOM index space scaled to millimeters: x along the
 * row direction (IOP first triplet), y along the column direction (second
 * triplet), z along the slice normal (row x column). The display frame is
 * chosen so anatomy reads naturally in a y-up viewer regardless of how the
 * series was acquired (axial/coronal/sagittal/oblique):
 *   +x = patient left, +y = superior, +z = anterior.
 * A camera on +z therefore always faces the patient head-on.
 */

export type ImageOrientationPatient = readonly [number, number, number, number, number, number]

/** Fallback when a manifest carries no orientation: axial identity IOP. */
export const IDENTITY_IOP: ImageOrientationPatient = [1, 0, 0, 0, 1, 0]

type Vec3 = readonly [number, number, number]

function cross(a: Vec3, b: Vec3): Vec3 {
  return [a[1] * b[2] - a[2] * b[1], a[2] * b[0] - a[0] * b[2], a[0] * b[1] - a[1] * b[0]]
}

/** LPS -> display: (Left, Posterior, Superior) -> (Left, Superior, Anterior). */
function lpsToDisplay(v: Vec3): Vec3 {
  // Normalize -0 to 0 so downstream equality checks and formatted output are stable.
  return [v[0], v[2], v[1] === 0 ? 0 : -v[1]]
}

/**
 * Row-major 3x3 rotation taking mesh coordinates to the display frame. Both
 * factors are proper rotations (the slice normal is row x column), so the
 * result has determinant +1 and preserves triangle winding and lengths.
 */
export function meshToDisplayBasis(iop: ImageOrientationPatient): number[] {
  const row: Vec3 = [iop[0], iop[1], iop[2]]
  const column: Vec3 = [iop[3], iop[4], iop[5]]
  const xAxis = lpsToDisplay(row)
  const yAxis = lpsToDisplay(column)
  const zAxis = lpsToDisplay(cross(row, column))
  return [xAxis[0], yAxis[0], zAxis[0], xAxis[1], yAxis[1], zAxis[1], xAxis[2], yAxis[2], zAxis[2]]
}

/** Applies the basis returned by {@link meshToDisplayBasis} to a mesh-space vector. */
export function applyBasis(basis: readonly number[], v: Vec3): Vec3 {
  const [m00, m01, m02, m10, m11, m12, m20, m21, m22] = basis as readonly [
    number, number, number, number, number, number, number, number, number,
  ]
  return [
    m00 * v[0] + m01 * v[1] + m02 * v[2],
    m10 * v[0] + m11 * v[1] + m12 * v[2],
    m20 * v[0] + m21 * v[1] + m22 * v[2],
  ]
}
