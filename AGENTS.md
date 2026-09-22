# AGENTS.md

Instructions for AI coding agents working in this repo. `README.md` is the primary
reference for architecture, local setup, and the validation commands (type-check,
lint, Jest, Pint, PHPUnit) that must pass before committing.

## Cloud sessions

**Claude Code cloud sessions:** the `SessionStart` hook in `.claude/settings.json` runs `cloud-repo-bootstrap` (installed by the cloud environment's setup script). It seeds `.env`, installs `vendor/` from this repo's prebuilt bundle (`.github/workflows/cloud-vendor.yml` uploads one per `composer.lock` to the `cloud-vendor` draft release), sets `APP_KEY`, runs pnpm, and prints a `[cloud-repo-bootstrap]` status report. Trust that report. In the cloud never run `composer install`/`update` against the network and never call `add_repo` for a dependency repository: the cloud GitHub proxy refuses GitHub archive downloads for repositories not attached to the session, and each `add_repo` is a manual permission grant. If the report says there is no bundle for this lock, push `composer.lock` (the workflow runs on it) and rerun `cloud-repo-bootstrap --force`. Keep the `cloud-vendor` release a draft: publishing it would create a tag and fire release workflows.

## Parallel git worktrees

Needed whenever you run agents or validation (PHPUnit, PHPStan) in more than one
git worktree of this repo at once. Do not symlink the main checkout's `vendor/` into the worktree
— it is silently wrong. Composer's `vendor/composer/autoload_psr4.php` derives
`$baseDir` from `dirname(dirname(__DIR__))`, and PHP resolves `__DIR__` through
symlinks to the real target, so a symlinked `vendor/` anchors autoloading to
the main checkout regardless of which worktree required it. PHPUnit then runs the
worktree's tests against the main checkout's unmodified sources — a false green,
not a loader error, so nothing in the test output flags it.

Instead give the worktree a real `vendor/` directory of per-package symlinks, with
real copies of the handful of entries that anchor path resolution (same
no-network-install constraint as "Cloud sessions" above — this only rearranges the
existing bundle, never reinstalls it):

```
cd "$WT"
SRC=$(git rev-parse --path-format=absolute --git-common-dir)/..   # the main checkout
SRC=$(cd "$SRC" && pwd)
cmp -s "$SRC/composer.lock" composer.lock || echo 'composer.lock differs — see below'
mkdir vendor
for e in "$SRC"/vendor/*; do
  n=$(basename "$e")
  case "$n" in composer|bin|autoload.php) continue;; esac
  ln -s "$e" "vendor/$n"
done
cp -r "$SRC/vendor/composer" vendor/composer
cp -r "$SRC/vendor/bin"      vendor/bin
cp    "$SRC/vendor/autoload.php" vendor/autoload.php
cp    "$SRC/.env" .env
```

`$SRC` is derived from git rather than hard-coded, so the recipe works wherever the
repository is cloned. Every path below is relative to it.

If the `cmp` reports a difference, stop: the worktree declares dependencies the
shared bundle does not contain, so PHPUnit and PHPStan would validate against the
wrong versions and report misleading results. Either work from a worktree whose
`composer.lock` matches the main checkout, or get a bundle built for that lock (see
"Cloud sessions" above) before constructing these links.

`vendor/composer`, `vendor/bin`, and `vendor/autoload.php` must be real copies (a
few MB); everything else stays a symlink so the worktree doesn't pay the full 215MB
vendor bundle cost.

**Mandatory verification — do this every time, before trusting any test run in the
worktree.** The failure mode above is silent: "I ran the tests and they passed"
proves nothing, because a symlinked `vendor/` produces exactly that outcome while
testing the wrong sources. Run this one-liner in the worktree and confirm the path
it prints is inside the worktree, not `$SRC`:

```
php -r 'require "vendor/autoload.php"; $r = new ReflectionClass("App\\Models\\User"); echo $r->getFileName(), "\n";'
```

`php -d memory_limit=1G vendor/bin/phpunit` runs correctly against the worktree's
own sources under this layout.

`vendor/bin/phpstan` does not, by default: it fatals with `Cannot redeclare class
ComposerAutoloaderInit…`, because the phpstan phar resolves the shared real
`vendor/composer` path and double-loads the autoloader. Fix: also make
`vendor/phpstan` a real copy (`rm vendor/phpstan && cp -r "$SRC/vendor/phpstan" vendor/phpstan`,
~27MB). With that copy in place, `vendor/bin/phpstan analyse` runs clean and
correctly reports errors in the worktree's own edited files.

Nothing under `$SRC/vendor` may be modified by this recipe (only read
from), and it never runs `composer install`/`update` or `pnpm install` — see
"Cloud sessions" above for why.

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
