import { wheelPixelDelta, wheelScaleRatio } from '../scene/wheelScale'

const STEP = 1.4

describe('wheelScaleRatio', () => {
  it('scrolling up (negative delta) grows the anatomy, down shrinks it', () => {
    expect(wheelScaleRatio(-100, 0, STEP)).toBeCloseTo(STEP, 5)
    expect(wheelScaleRatio(100, 0, STEP)).toBeCloseTo(1 / STEP, 5)
  })

  it('is neutral on a zero delta', () => {
    expect(wheelScaleRatio(0, 0, STEP)).toBe(1)
  })

  it('treats a small trackpad delta as a gentle continuous step', () => {
    const ratio = wheelScaleRatio(-4, 0, STEP)
    expect(ratio).toBeGreaterThan(1)
    expect(ratio).toBeLessThan(1.02)
  })

  it('leaves pixel-mode input (mouse/trackpad) numerically unchanged', () => {
    // deltaMode 0 must map straight through so the existing feel is preserved.
    expect(wheelPixelDelta(-73, 0)).toBe(-73)
  })

  it('normalises line-mode (Firefox mouse wheel) to a comparable pixel step', () => {
    // 3 lines ≈ 48px, a real zoom — not the near-nothing raw "3" would give.
    const lineNotch = wheelScaleRatio(-3, 1, STEP)
    const rawThree = wheelScaleRatio(-3, 0, STEP)
    expect(lineNotch).toBeGreaterThan(rawThree)
    expect(wheelPixelDelta(-3, 1)).toBe(-48)
  })

  it('clamps an oversized single delta so one flick cannot jump the whole range', () => {
    expect(wheelPixelDelta(-100000, 0)).toBe(-240)
    expect(wheelScaleRatio(-100000, 0, STEP)).toBeCloseTo(wheelScaleRatio(-240, 0, STEP), 5)
  })
})
