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

## PR review

- A bot comment reading "You have reached your Codex usage limits for security
  reviews" is scoped to **security reviews only** and says nothing about code-review
  availability. Disregard it when judging whether a code review ran, and never report
  "no review" or "out of quota" on the strength of it.
- Checking whether a review landed means checking all three endpoints — findings
  usually arrive as inline review comments, not issue comments:
  - `gh api repos/<owner>/<repo>/issues/<n>/comments` — issue-level comments
  - `gh api repos/<owner>/<repo>/pulls/<n>/reviews` — review submissions
  - `gh api repos/<owner>/<repo>/pulls/<n>/comments` — inline review comments, where
    findings appear
- A 👀 reaction means a review is in progress; wait for it. Absence of issue comments
  proves nothing.
