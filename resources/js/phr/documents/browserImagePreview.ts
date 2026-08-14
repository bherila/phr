/**
 * MIME types every mainstream browser can decode in an <img> element.
 *
 * Deliberately an allowlist rather than `mime_type.startsWith('image/')`:
 * image/tiff never renders in Chrome or Firefox, and image/heic + image/heif
 * only render in Safari. Documents with those types (TIFF uploads are accepted
 * by StorePhrDocumentRequest; HEIC/HEIF can arrive via FHIR / MyChart / GenAI
 * imports, which store the source-provided MIME type) must fall back to the
 * download / open-file UI instead of a broken <img>.
 */
const BROWSER_DECODABLE_IMAGE_MIME_TYPES: ReadonlySet<string> = new Set([
  'image/apng',
  'image/avif',
  'image/bmp',
  'image/gif',
  'image/jpeg',
  'image/png',
  'image/svg+xml',
  'image/webp',
])

export function isBrowserPreviewableImage(mimeType: string | null | undefined): boolean {
  if (!mimeType) {
    return false
  }

  const normalized = (mimeType.split(';', 1)[0] ?? '').trim().toLowerCase()
  return BROWSER_DECODABLE_IMAGE_MIME_TYPES.has(normalized)
}
