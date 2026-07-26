import { applyBasis, IDENTITY_IOP, type ImageOrientationPatient, meshToDisplayBasis } from '../scene/patientFrame'

const X_MESH = [1, 0, 0] as const
const Y_MESH = [0, 1, 0] as const
const Z_MESH = [0, 0, 1] as const

function determinant(m: readonly number[]): number {
  const [a, b, c, d, e, f, g, h, i] = m as readonly [
    number, number, number, number, number, number, number, number, number,
  ]
  return a * (e * i - f * h) - b * (d * i - f * g) + c * (d * h - e * g)
}

describe('meshToDisplayBasis', () => {
  it('maps an axial volume so superior is up and the face looks toward +z', () => {
    const basis = meshToDisplayBasis(IDENTITY_IOP)
    expect(applyBasis(basis, X_MESH)).toEqual([1, 0, 0]) // columns run toward patient left
    expect(applyBasis(basis, Y_MESH)).toEqual([0, 0, -1]) // rows run posterior, away from the viewer
    expect(applyBasis(basis, Z_MESH)).toEqual([0, 1, 0]) // slices stack superior, upward
  })

  it('maps a coronal volume (rows toward inferior) so anatomy is upright', () => {
    const coronal: ImageOrientationPatient = [1, 0, 0, 0, 0, -1]
    const basis = meshToDisplayBasis(coronal)
    expect(applyBasis(basis, X_MESH)).toEqual([1, 0, 0])
    expect(applyBasis(basis, Y_MESH)).toEqual([0, -1, 0]) // image rows go down the patient
    expect(applyBasis(basis, Z_MESH)).toEqual([0, 0, -1]) // slices stack anterior-to-posterior
  })

  it('maps a sagittal volume so the profile still reads y-up', () => {
    const sagittal: ImageOrientationPatient = [0, 1, 0, 0, 0, -1]
    const basis = meshToDisplayBasis(sagittal)
    expect(applyBasis(basis, X_MESH)).toEqual([0, 0, -1]) // columns run posterior
    expect(applyBasis(basis, Y_MESH)).toEqual([0, -1, 0]) // rows run inferior
    expect(applyBasis(basis, Z_MESH)).toEqual([-1, 0, 0]) // slices stack toward patient right
  })

  it('is always a proper rotation (winding and lengths preserved)', () => {
    const orientations: ImageOrientationPatient[] = [
      IDENTITY_IOP,
      [1, 0, 0, 0, 0, -1],
      [0, 1, 0, 0, 0, -1],
      // Slightly oblique acquisition, direction cosines still orthonormal.
      [0.9962, 0.0872, 0, -0.0872, 0.9962, 0],
    ]
    for (const orientation of orientations) {
      expect(determinant(meshToDisplayBasis(orientation))).toBeCloseTo(1, 4)
    }
  })
})
