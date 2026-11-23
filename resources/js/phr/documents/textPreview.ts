/**
 * Text documents the preview frame shows inline. Markdown is served to the
 * frame as plain text (browsers download text/markdown rather than show it),
 * so it previews as text, never as an image.
 */
const TEXT_PREVIEW_MIME_TYPES: ReadonlySet<string> = new Set(['text/plain', 'text/markdown', 'text/html'])

export function isTextDocument(mimeType: string | null | undefined): boolean {
  if (!mimeType) {
    return false
  }

  const normalized = (mimeType.split(';', 1)[0] ?? '').trim().toLowerCase()
  return TEXT_PREVIEW_MIME_TYPES.has(normalized)
}
