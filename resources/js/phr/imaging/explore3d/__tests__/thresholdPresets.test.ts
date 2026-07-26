import {
  CT_PRESETS,
  resolveThresholdKey,
  THRESHOLD_STEP_COARSE,
  THRESHOLD_STEP_FINE,
} from '../hud/thresholdPresets'

function keyEvent(overrides: Partial<{ code: string; key: string; shiftKey: boolean }>) {
  return { code: '', key: '', shiftKey: false, ...overrides }
}

describe('resolveThresholdKey', () => {
  it('[ and ] step by the fine amount', () => {
    expect(resolveThresholdKey(keyEvent({ code: 'BracketRight' }), true, 0)).toBe(THRESHOLD_STEP_FINE)
    expect(resolveThresholdKey(keyEvent({ code: 'BracketLeft' }), true, 0)).toBe(-THRESHOLD_STEP_FINE)
  })

  it('Shift makes the step coarse', () => {
    expect(resolveThresholdKey(keyEvent({ code: 'BracketRight', shiftKey: true }), true, 100)).toBe(
      100 + THRESHOLD_STEP_COARSE,
    )
  })

  it('digits 1-3 select CT presets', () => {
    for (const preset of CT_PRESETS) {
      expect(resolveThresholdKey(keyEvent({ key: preset.hotkey }), true, 0)).toBe(preset.value)
    }
  })

  it('ignores preset digits for non-CT modalities', () => {
    expect(resolveThresholdKey(keyEvent({ key: '1' }), false, 0)).toBeNull()
  })

  it('returns null for movement and unrelated keys', () => {
    for (const code of ['KeyW', 'KeyA', 'KeyS', 'KeyD', 'KeyE', 'KeyQ', 'Space', 'Escape']) {
      expect(resolveThresholdKey(keyEvent({ code }), true, 0)).toBeNull()
    }
  })

  it('brackets still work while non-CT (density adjust without presets)', () => {
    expect(resolveThresholdKey(keyEvent({ code: 'BracketRight' }), false, 500)).toBe(500 + THRESHOLD_STEP_FINE)
  })
})
