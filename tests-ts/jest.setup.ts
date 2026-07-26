import '@testing-library/jest-dom';

import { configure } from '@testing-library/dom';

// Give async queries (waitFor/findBy) headroom so transient CPU-scheduling latency on the shared CI
// runners doesn't trip the 1000ms default and flake otherwise-correct tests.
configure({ asyncUtilTimeout: 8000 });

// Jest 30 does not honor a per-project `testTimeout` declared inside `projects[]` (it falls back to
// the 5000ms default), so set the per-test ceiling here where it is reliably applied. This keeps
// import-heavy entrypoint tests from flaking under parallel load on the shared CI runners.
//
// The runners are spare capacity on ci1..ci3, so a test that takes ~2s on a dev machine can take
// 20s+ there — that is expected, not a regression. The previous 20000ms ceiling left the heaviest
// suites under a second of margin (`main` passed CareerCompPage at 18.99s), making the required
// gate a coin flip. 45000ms is ~2x the worst observed CI test while staying far inside the
// 15-minute job cap, so a genuinely hung test is still caught.
jest.setTimeout(45000);

// Mock window.matchMedia
Object.defineProperty(window, 'matchMedia', {
  writable: true,
  value: jest.fn().mockImplementation(query => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: jest.fn(),
    removeListener: jest.fn(),
    addEventListener: jest.fn(),
    removeEventListener: jest.fn(),
    dispatchEvent: jest.fn(),
  })),
});

// Mock window.scrollTo
Object.defineProperty(window, 'scrollTo', {
  writable: true,
  value: jest.fn(),
});

// Mock window.scrollIntoView and Element.prototype.scrollIntoView for positioned UI controls
Object.defineProperty(window, 'scrollIntoView', {
  writable: true,
  value: jest.fn(),
});

// Also mock on Element.prototype since headless UI controls may call it on elements
if (typeof Element !== 'undefined') {
  Element.prototype.scrollIntoView = jest.fn()
}

// Base UI dispatches PointerEvent from non-native checkbox/switch roots.
// jsdom does not provide PointerEvent by default.
if (!window.PointerEvent) {
  window.PointerEvent = MouseEvent as unknown as typeof PointerEvent
}

// Base UI ScrollArea calls element.getAnimations() to wait for animations
// before measuring; jsdom doesn't implement the Web Animations API.
if (typeof Element !== 'undefined' && !Element.prototype.getAnimations) {
  Element.prototype.getAnimations = () => []
}

// Mock global fetch for tests (always override to keep tests deterministic)
;(globalThis as any).fetch = jest.fn(() =>
  Promise.resolve({
    ok: true,
    text: () => Promise.resolve(JSON.stringify([])),
    json: () => Promise.resolve([]),
  })
) as jest.Mock

// Provide a minimal ResizeObserver mock for components that rely on element sizing.
// Some tests render components which use ResizeObserver; Jest DOM doesn't provide it by default.
class ResizeObserverMock {
  observe() {}
  unobserve() {}
  disconnect() {}
}
if (!window.ResizeObserver) {
  window.ResizeObserver = ResizeObserverMock as unknown as typeof ResizeObserver
}

// Mock pdfjsLib for PDF viewer tests
(window as any).pdfjsLib = {
  GlobalWorkerOptions: {
    workerSrc: ''
  },
  getDocument: jest.fn(() => ({
    promise: Promise.resolve({
      numPages: 0,
      getPage: jest.fn(() => Promise.resolve({
        getViewport: jest.fn(() => ({ width: 0, height: 0 })),
        render: jest.fn(() => ({ promise: Promise.resolve() }))
      }))
    })
  })),
  version: 'mock-version'
};
