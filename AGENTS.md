# AGENTS.md

Instructions for AI coding agents working in this repo. `README.md` is the primary
reference for architecture, local setup, and the validation commands (type-check,
lint, Jest, Pint, PHPUnit) that must pass before committing.

## Frontend rules

- Inline image previews must gate on a browser-decodable allowlist — use
  `isBrowserPreviewableImage` from `resources/js/phr/documents/browserImagePreview.ts`
  (png/jpeg/gif/webp/avif/apng/bmp/svg), never `mime_type.startsWith('image/')`.
  HEIC/HEIF/TIFF documents render as a broken `<img>` in non-Safari browsers; types
  outside the allowlist must fall back to the download / open-file prompt instead.
