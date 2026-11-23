import { isBrowserPreviewableImage } from '@/phr/documents/browserImagePreview'
import { isTextDocument } from '@/phr/documents/textPreview'

describe('isTextDocument', () => {
  it.each(['text/plain', 'text/markdown', 'text/html', 'TEXT/MARKDOWN; charset=utf-8'])('previews %s as text', (mimeType) => {
    expect(isTextDocument(mimeType)).toBe(true)
  })

  it.each([null, undefined, '', 'application/pdf', 'image/png', 'text/csv'])('does not preview %s as text', (mimeType) => {
    expect(isTextDocument(mimeType)).toBe(false)
  })

  it('never treats markdown as an image', () => {
    expect(isBrowserPreviewableImage('text/markdown')).toBe(false)
  })
})
