import { isBrowserPreviewableImage } from '@/phr/documents/browserImagePreview'

describe('isBrowserPreviewableImage', () => {
  it.each([
    'image/png',
    'image/jpeg',
    'image/gif',
    'image/webp',
    'image/avif',
    'image/apng',
    'image/bmp',
    'image/svg+xml',
  ])('accepts browser-decodable type %s', (mimeType) => {
    expect(isBrowserPreviewableImage(mimeType)).toBe(true)
  })

  it.each([
    'image/tiff',
    'image/tif',
    'image/heic',
    'image/heif',
    'image/heic-sequence',
    'image/x-adobe-dng',
  ])('rejects image type %s that browsers cannot decode', (mimeType) => {
    expect(isBrowserPreviewableImage(mimeType)).toBe(false)
  })

  it.each(['application/pdf', 'text/plain', 'text/html', 'application/octet-stream'])(
    'rejects non-image type %s',
    (mimeType) => {
      expect(isBrowserPreviewableImage(mimeType)).toBe(false)
    },
  )

  it('rejects null, undefined, and empty values', () => {
    expect(isBrowserPreviewableImage(null)).toBe(false)
    expect(isBrowserPreviewableImage(undefined)).toBe(false)
    expect(isBrowserPreviewableImage('')).toBe(false)
  })

  it('normalizes case and MIME parameters', () => {
    expect(isBrowserPreviewableImage('IMAGE/PNG')).toBe(true)
    expect(isBrowserPreviewableImage('image/jpeg; charset=binary')).toBe(true)
    expect(isBrowserPreviewableImage('IMAGE/TIFF')).toBe(false)
  })
})
