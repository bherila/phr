/**
 * Shared doubles for the DICOM upload suites. Not a test file itself — jest's testMatch
 * only picks up `*.test.ts(x)`, so this module is safe to sit beside the specs.
 */

export const TEST_PATIENT_ID = 101
export const TEST_UPLOAD_ID = 501

export function makeDicomUpload(status: string, overrides: Record<string, unknown> = {}) {
  return {
    id: TEST_UPLOAD_ID,
    patient_id: TEST_PATIENT_ID,
    uploaded_by_user_id: 2,
    status,
    original_root_name: 'CARDIAC_CT',
    total_files: 1,
    stored_files: status === 'pending' ? 0 : 1,
    skipped_files: 0,
    total_bytes: 5,
    stored_bytes: status === 'pending' ? 0 : 5,
    manifest_json: null,
    skipped_files_json: [],
    error_message: null,
    created_at: null,
    updated_at: null,
    ...overrides,
  }
}

export function makeDicomUploadFileResponse(
  relativePath = 'CARDIAC_CT/IM0001',
  overrides: Partial<{ stored: boolean, skipped_reason: string | null, study_id: number | null }> = {},
) {
  return {
    result: {
      stored: true,
      skipped_reason: null,
      relative_path: relativePath,
      study_id: 7001,
      ...overrides,
    },
    upload: makeDicomUpload('pending'),
  }
}

export function makeDicomFile(relativePath: string, contents = 'dicom'): File {
  const name = relativePath.split('/').pop() ?? relativePath
  const file = new File([contents], name, { type: 'application/dicom' })
  Object.defineProperty(file, 'webkitRelativePath', { value: relativePath })
  return file
}

class MockUploadEventTarget {
  private readonly listeners = new Map<string, ((event?: ProgressEvent) => void)[]>()

  addEventListener(type: string, listener: (event?: ProgressEvent) => void): void {
    this.listeners.set(type, [...(this.listeners.get(type) ?? []), listener])
  }

  dispatch(type: string): void {
    for (const listener of this.listeners.get(type) ?? []) {
      listener()
    }
  }
}

export class MockUploadXMLHttpRequest {
  static instances: MockUploadXMLHttpRequest[] = []

  static status = 200

  static statusText = 'OK'

  static responseText = ''

  // When set, overrides `responseText` per request using the sent FormData — lets a
  // multi-file test give each file its own result payload (e.g. a distinct relative_path).
  static responseTextFor: ((body: FormData) => string) | null = null

  // When false, `send()` never fires 'load' on its own, leaving the request genuinely
  // in flight so a test can exercise `UploadController.abort()` mid-upload.
  static autoComplete = true

  static reset(): void {
    MockUploadXMLHttpRequest.instances = []
    MockUploadXMLHttpRequest.status = 200
    MockUploadXMLHttpRequest.statusText = 'OK'
    MockUploadXMLHttpRequest.responseText = JSON.stringify(makeDicomUploadFileResponse())
    MockUploadXMLHttpRequest.responseTextFor = null
    MockUploadXMLHttpRequest.autoComplete = true
  }

  /** Installs the mock and returns a restore function for `finally`. */
  static install(): () => void {
    const original = globalThis.XMLHttpRequest
    MockUploadXMLHttpRequest.reset()
    globalThis.XMLHttpRequest = MockUploadXMLHttpRequest as unknown as typeof XMLHttpRequest
    return () => {
      globalThis.XMLHttpRequest = original
    }
  }

  upload = new MockUploadEventTarget()

  status = MockUploadXMLHttpRequest.status

  statusText = MockUploadXMLHttpRequest.statusText

  responseText = MockUploadXMLHttpRequest.responseText

  withCredentials = false

  readonly requestHeaders: Record<string, string> = {}

  requestUrl = ''

  requestBody: FormData | null = null

  private readonly listeners = new MockUploadEventTarget()

  constructor() {
    MockUploadXMLHttpRequest.instances.push(this)
  }

  open(_method: string, url: string): void {
    this.requestUrl = url
  }

  setRequestHeader(name: string, value: string): void {
    this.requestHeaders[name] = value
  }

  addEventListener(type: string, listener: () => void): void {
    this.listeners.addEventListener(type, listener)
  }

  send(body?: FormData): void {
    this.requestBody = body ?? null
    if (MockUploadXMLHttpRequest.responseTextFor && body) {
      this.responseText = MockUploadXMLHttpRequest.responseTextFor(body)
    }
    if (MockUploadXMLHttpRequest.autoComplete) {
      queueMicrotask(() => this.listeners.dispatch('load'))
    }
  }

  /** Completes a request that was held open with `autoComplete = false`. */
  complete(): void {
    this.listeners.dispatch('load')
  }

  abort(): void {
    this.listeners.dispatch('abort')
  }
}
