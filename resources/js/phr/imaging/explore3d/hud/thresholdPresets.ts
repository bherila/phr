export interface ThresholdPreset {
  label: string
  /** Keyboard digit that selects this preset (1-based). */
  hotkey: string
  value: number
}

/** Standard CT HU bands; the surface forms where density crosses the threshold. */
export const CT_PRESETS: readonly ThresholdPreset[] = [
  { label: 'Airways', hotkey: '1', value: -400 },
  { label: 'Soft tissue', hotkey: '2', value: 40 },
  { label: 'Bone', hotkey: '3', value: 300 },
]

/** Fine/coarse steps applied by the [ and ] density keys (Shift = coarse). */
export const THRESHOLD_STEP_FINE = 25
export const THRESHOLD_STEP_COARSE = 100

export interface ThresholdKeyEvent {
  code: string
  key: string
  shiftKey: boolean
}

/**
 * Maps a keydown to the requested density value, or null when the key isn't a
 * density shortcut. Pure so the mapping (chosen to avoid the movement keys
 * WASD/EQ/Shift and pointer-lock Esc) is unit-testable without a live scene.
 */
export function resolveThresholdKey(event: ThresholdKeyEvent, isCt: boolean, current: number): number | null {
  const step = event.shiftKey ? THRESHOLD_STEP_COARSE : THRESHOLD_STEP_FINE
  if (event.code === 'BracketLeft') {
    return current - step
  }
  if (event.code === 'BracketRight') {
    return current + step
  }
  if (isCt) {
    const preset = CT_PRESETS.find((candidate) => candidate.hotkey === event.key)
    if (preset) {
      return preset.value
    }
  }
  return null
}
