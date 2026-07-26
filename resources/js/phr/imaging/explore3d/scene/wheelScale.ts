/**
 * Converts a wheel event's delta into a multiplicative viewer-scale factor.
 *
 * Kept pure (no THREE, no DOM) so it is unit-testable, and so a mouse wheel and
 * a macOS two-finger trackpad scroll feel consistent. Browsers disagree on wheel
 * units: Chrome/Safari (and trackpads) report pixels (deltaMode 0), Firefox
 * reports lines for a mouse wheel (deltaMode 1). Normalising to pixels makes one
 * notch a fixed ratio everywhere, while a trackpad's stream of small pixel
 * deltas naturally produces smooth continuous scaling. deltaMode-0 input is left
 * numerically unchanged, so the existing mouse/trackpad feel is preserved.
 */

/** Approximate pixels per "line" (deltaMode 1) and "page" (deltaMode 2). */
const LINE_PX = 16
const PAGE_PX = 800
/** Pixels of scroll that equal one scale step (100 keeps the prior feel). */
const PX_PER_STEP = 100
/** Clamp a single event so one flick/inertia burst can't jump the whole range. */
const MAX_STEP_PX = 240

/** Normalise a wheel delta to pixels and clamp outliers. Exported for testing. */
export function wheelPixelDelta(deltaY: number, deltaMode: number): number {
  const pixels = deltaMode === 1 ? deltaY * LINE_PX : deltaMode === 2 ? deltaY * PAGE_PX : deltaY
  return Math.max(-MAX_STEP_PX, Math.min(MAX_STEP_PX, pixels))
}

/**
 * Multiplicative scale factor for one wheel event. Scrolling up (deltaY < 0)
 * grows the anatomy, matching the `+`/`=` key and the on-screen hint.
 */
export function wheelScaleRatio(deltaY: number, deltaMode: number, step: number): number {
  return Math.pow(step, -wheelPixelDelta(deltaY, deltaMode) / PX_PER_STEP)
}
