/** A URL and headers the server signed for one PUT. The URL is a credential: never log or show it. */
export interface SignedPut {
  url: string
  headers: Record<string, string>
}

export interface PutOptions {
  /** Bytes sent since the last call. */
  onProgress?: (deltaBytes: number) => void
  signal?: AbortSignal
}

export class StorageUploadCancelled extends Error {
  constructor() {
    super('Cancelled.')
    this.name = 'StorageUploadCancelled'
  }
}

/**
 * Sends one body straight to the installation's object store. No cookies or CSRF token go
 * with it: the signature is the authority, and the store is another origin.
 *
 * The store's CORS answers decide what a page can see. A browser reports a CORS refusal as a
 * bare network error, and a bucket that does not expose ETag answers 200 with no ETag to read,
 * so both are named here for whoever has to fix the bucket.
 *
 * @returns the object's or part's ETag, or null when the store's CORS rules hide it
 */
export function putToStorage(signed: SignedPut, body: Blob, options: PutOptions = {}): Promise<string | null> {
  return new Promise<string | null>((resolve, reject) => {
    if (options.signal?.aborted) {
      reject(new StorageUploadCancelled())
      return
    }

    const xhr = new XMLHttpRequest()
    xhr.open('PUT', signed.url)
    for (const [name, value] of Object.entries(signed.headers)) {
      xhr.setRequestHeader(name, value)
    }

    let lastLoaded = 0
    xhr.upload.addEventListener('progress', (event) => {
      if (event.lengthComputable) {
        options.onProgress?.(event.loaded - lastLoaded)
        lastLoaded = event.loaded
      }
    })

    const onAbort = (): void => xhr.abort()
    options.signal?.addEventListener('abort', onAbort)
    const settled = (): void => options.signal?.removeEventListener('abort', onAbort)

    xhr.addEventListener('load', () => {
      settled()
      if (xhr.status >= 200 && xhr.status < 300) {
        options.onProgress?.(body.size - lastLoaded)
        resolve(xhr.getResponseHeader('ETag'))
      } else {
        reject(new Error(`Storage refused the upload (HTTP ${xhr.status}).`))
      }
    })
    xhr.addEventListener('error', () => {
      settled()
      reject(new Error('The file could not reach storage. Check the connection, and that the bucket’s CORS rules allow this site.'))
    })
    xhr.addEventListener('abort', () => {
      settled()
      reject(new StorageUploadCancelled())
    })

    xhr.send(body)
  })
}
