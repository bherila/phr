/**
 * Worker construction is isolated here so jsdom tests can mock this module —
 * `new Worker(new URL(...))` is both un-mockable and unsupported in jsdom.
 */

import decodeWorkerUrl from './decodeWorker.ts?worker&url'
import meshWorkerUrl from './meshWorker.ts?worker&url'

/**
 * `?worker&url` makes Vite bundle each worker into its own ES-module chunk and
 * return the built URL. This matters: `new URL('./x.ts', import.meta.url)`
 * written apart from the `new Worker(...)` call is only recognised as a *static
 * asset*, so the production build shipped the raw `.ts` file — served with a
 * `video/mp2t` MIME type and blocked by the browser. (ES-module chunks are also
 * required because the workers use dynamic `import()` for the OpenJPEG codec;
 * see `worker.format: 'es'` in the Vite config.)
 *
 * The Worker constructor requires a same-origin script, but under `composer dev`
 * the Blade page is served by Laravel (:8000) while Vite serves the worker chunk
 * from its own origin (:5173), which the browser rejects with a SecurityError.
 * Wrapping the URL in a same-origin Blob module that imports it sidesteps this:
 * module imports are CORS-fetched (which the Vite dev server allows), unlike
 * worker scripts. In production the chunk is same-origin, so the shim is skipped.
 */
function createModuleWorker(rawUrl: string): Worker {
  const url = new URL(rawUrl, window.location.href)
  if (url.origin === window.location.origin) {
    return new Worker(url, { type: 'module' })
  }
  const shim = URL.createObjectURL(
    new Blob([`import ${JSON.stringify(url.href)}`], { type: 'text/javascript' }),
  )
  try {
    return new Worker(shim, { type: 'module' })
  } finally {
    URL.revokeObjectURL(shim)
  }
}

export function createDecodeWorker(): Worker {
  return createModuleWorker(decodeWorkerUrl)
}

export function createMeshWorker(): Worker {
  return createModuleWorker(meshWorkerUrl)
}

export function decodePoolSize(): number {
  const cores = typeof navigator !== 'undefined' ? (navigator.hardwareConcurrency ?? 4) : 4
  return Math.max(1, Math.min(4, cores - 1))
}
