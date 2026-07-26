/**
 * Recommended camera viewpoints ("stations") for the fly-through, defined in
 * the patient-true display frame (+x = patient left, +y = superior,
 * +z = anterior) so they are meaningful for any acquisition plane. Offsets
 * are in units of the mesh bounding-box diagonal, relative to its center;
 * kept three.js-free so the set is unit-testable.
 */

export interface ViewStation {
  id: string
  label: string
  /** Camera position offset from the mesh center (diagonal units). */
  offset: readonly [number, number, number]
  /** Look-at target offset from the mesh center (diagonal units). */
  targetOffset: readonly [number, number, number]
}

export const VIEW_STATIONS: readonly ViewStation[] = [
  { id: 'front', label: 'Front', offset: [0, 0, 0.9], targetOffset: [0, 0, 0] },
  /* Anterior-inferior close-up looking up at the mid-face: on a head scan
   * this frames the nostril openings, the entry point for airway tours. */
  { id: 'front-low', label: 'From below', offset: [0, -0.4, 0.45], targetOffset: [0, 0, 0.2] },
  /* Center of the volume facing posterior: on a sinus CT this drops you in
   * the nasal cavity looking toward the sphenoid. */
  { id: 'inside', label: 'Inside', offset: [0, 0, 0], targetOffset: [0, 0, -0.4] },
  { id: 'left', label: 'Left', offset: [0.9, 0, 0], targetOffset: [0, 0, 0] },
  { id: 'right', label: 'Right', offset: [-0.9, 0, 0], targetOffset: [0, 0, 0] },
  { id: 'top', label: 'Top', offset: [0, 0.9, 0], targetOffset: [0, 0, 0] },
]

/** Display label for an enclosed-air-pocket station discovered by the air analysis. */
export function pocketStationLabel(index: number, volumeMm3: number): string {
  const milliliters = volumeMm3 / 1000
  const amount = milliliters >= 10 ? String(Math.round(milliliters)) : milliliters.toFixed(1)
  return `Pocket ${index + 1} · ${amount} mL`
}
