# AGENTS.md

This file is the operating manual for anyone (human or AI agent) changing this
repository. Read it before making changes. It describes how the project is built,
tested, secured and released.

## Project overview

SimplePHPImageGallery is a deliberately small PHP image gallery. A folder tree on
disk becomes a browsable gallery. The application has no database and no build
step for production. It uses:

- **PHP 8.2+** for routing, gallery rendering, search, previews, metadata, ZIP
  downloads and GPS extraction. CI runs the latest PHP release (currently 8.4).
- **jQuery** for DOM/event convenience.
- **Leaflet + marker clustering** for the map.
- **GD** for image thumbnails and **EXIF** for metadata/GPS.
- **ffmpeg/ffprobe (optional)** for video previews.
- **Playwright + plain PHP tests** for the automated suite.

Main files:

| File | Responsibility |
| --- | --- |
| `index.php` | Front controller, HTML shell, headers, gallery/search/zip/map endpoints |
| `functions.php` | Gallery rendering, search, thumbnails, EXIF, ZIP, path security |
| `functions.js` | UI behaviour, search, map, selection, lazy loading, lightbox |
| `main.js` | Global client state, keyboard shortcuts, layout detection |
| `style.css` | Responsive light/dark UI |
| `get_search_index.php` | JSON search index cache |
| `image.php` | Cached raw image/video delivery with path validation |
| `sw.js` | Service worker for installable PWA and thumbnail caching |
| `manifest.webmanifest` | PWA manifest and app icons |
| `tests/` | Isolated fixtures, HTTP tests and Playwright tests |

## Mandatory validation

Every change must pass the full suite before it is considered done:

```console
npm ci
npx playwright install --with-deps chromium
bash tests/run.sh
```

The runner creates a temporary document root, generates example images, folders,
TXT keywords, GPS data, a video (when ffmpeg is available), and starts PHP's
built-in server with multiple workers. It then runs:

- `tests/http_tests.php` — endpoint, caching, search, preview, ZIP, metadata,
  map-data, traversal and XSS regression tests.
- `tests/ui_tests.mjs` — real Chromium tests for clicks, search, fuzzy search,
  search filters, folder navigation, pagination, selection, long press, lightbox,
  details, shortcuts, favorites, timeline, slideshow, map filtering, PWA
  registration, lazy loading, back-to-top, mobile layout and browser errors.

Additional checks:

```console
php -l index.php
php -l functions.php
php -l image.php
php -l get_search_index.php
node --check functions.js
node --check main.js
node --check tests/ui_tests.mjs
```

Do not mark work complete based on a manual click-through alone. If an endpoint or
UI interaction changes, add or update a regression test. Never weaken or delete a
test just to make CI green; fix the behaviour instead.

## CI and tagging

`.github/workflows/ci.yml` runs on every push and pull request. On a successful
push build it creates and pushes an annotated tag containing the run number, run
ID, attempt and runner name, for example:

```text
ci-42-123456789-1-GitHub-Actions-1000071041
```

Do not remove this tagging step. Do not push to a tag. CI must remain able to
reproduce the test environment from a clean checkout.

## Definition of done

A change is done only when:

1. The full test suite passes.
2. PHP and JS syntax checks pass.
3. New behaviour has automated coverage.
4. Security invariants below are respected.
5. Desktop and mobile layouts both work and there is no horizontal overflow.
6. No warnings, notices or deprecations are introduced in test-server logs.
7. Generated files (`node_modules/`, `tests/artifacts/`, `thumbnails_cache/`) are
   not committed.
8. The change stays within the project's dependency footprint unless there is a
   strong reason to add a dependency.

## Security invariants

Treat every query parameter, filename, folder name and TXT content as untrusted.

### Filesystem paths

- All user-supplied paths must be validated with `is_path_within_gallery()` before
  any `file_exists`, `is_file`, `is_dir`, `scandir`, `file_get_contents`, `readfile`
  or thumbnail generation.
- Reject `..`, absolute paths and paths that resolve outside the gallery base.
- Never pass a raw request path directly to `realpath`, `scandir`, `getimagesize`,
  `exif_read_data`, `ZipArchive` or a shell command.
- `image.php`, `create_preview()`, `print_file_metadata()`, `display_gallery()` and
  `create_zip()` are security-sensitive. Keep their validation tests passing.

### Output escaping

- Escape HTML with `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` or the existing
  `escape_html_attribute()` helper.
- Inline values for JavaScript must be JSON-encoded with
  `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` or built through the
  DOM. Never interpolate untrusted strings into `eval` or inline event handlers.
- Use `textContent` for metadata values in the browser. Only server-generated EXIF
  HTML may use `innerHTML`.
- Keep the CSP and security headers in `index.php` intact. If you add a new external
  asset, update `Content-Security-Policy` deliberately.

### Shell commands

- Every interpolated shell argument must go through `escapeshellarg()`.
- Prefer checking `is_executable()` and handling missing ffmpeg/ffprobe gracefully.
- Do not add new `shell_exec`, `exec`, `system` or `passthru` calls without a
  security review and tests.

### Downloads and archives

- ZIP entries must be validated against the gallery base after resolving paths.
- Do not expose server files, symlink targets outside the gallery, dotfiles or
  helper assets such as `loading.gif`.
- Keep ZIP response headers and temporary-file cleanup correct.

### Caches

- Thumbnail files are content-addressed by source path hash plus a version suffix.
- When thumbnail resolution or encoding changes, bump `THUMBNAIL_CACHE_VERSION`.
- Never trust cached HTML or cache filenames as proof that a path is valid.
- Do not cache errors or partial responses in a way that hides future fixes.

## Performance invariants

- Grid thumbnails are generated at 320, 640 and 1280 px and exposed through
  `srcset`; the default `THUMBNAIL_MAX_SIZE` is 640 px. Keep high-DPI screens sharp.
- Thumbnails negotiate WebP/AVIF when the browser and GD support it. Keep
  `Vary: Accept` and long-lived immutable cache headers.
- Folder thumbnails are cached by source hash, size and
  `THUMBNAIL_CACHE_VERSION`; bump the version whenever rendering changes.
- Gallery folders are paginated (`GALLERY_PAGE_SIZE`, currently 120) and extend
  through the sentinel/IntersectionObserver infinite-scroll path.
- The map is lazy: geolist/EXIF scanning must only run when the map trigger is
  near the viewport. Do not render an empty map placeholder. Keep map results
  limited to the current folder tree and hide the map if no photo has GPS data.
- Video GIF previews use the smaller `VIDEO_THUMBNAIL_MAX_SIZE`.
- Images below the fold must load lazily through `IntersectionObserver`.
- Folder changes use async `fetch`; do not reintroduce blocking synchronous AJAX.
- Search requests are debounced, stale requests are aborted and stale responses are
  ignored.
- Map rendering must not block clicks or folder navigation.
- Avoid `array_merge` in recursive loops where appending is enough.
- Avoid re-reading EXIF for known non-GPS files; negative GPS results are cached.

## UI and UX rules

- Desktop and mobile are detected automatically via viewport/pointer media queries
  and `document.documentElement.dataset.layout`.
- The mobile layout must remain usable at 320–430 px wide, with 44 px minimum touch
  targets, safe-area padding and no horizontal scrolling.
- Preserve keyboard support: `/` focuses search, `Esc` closes overlays, arrow keys
  navigate the lightbox, `?` opens shortcuts.
- Keep controls labelled for screen readers (`aria-label`, `aria-pressed`).
- Keep dark mode, reduced-motion support, focus-visible styling and loading/empty
  states.
- Keep the lightbox controls and dialogs keyboard accessible; modal focus is
  trapped and restored.
- Preserve the PWA shell (`sw.js`, `manifest.webmanifest`, icons) and keep its
  caches same-origin only. The PWA icon PNGs are helper assets and must never be
  listed as gallery photos or search results.
- Use the configured map tile provider in `create_map_tile_layer()` (currently
  Esri raster tiles). Do not use OpenStreetMap volunteer tile servers or providers
  that require an API key. Keep provider attribution visible.
- On phones, secondary actions collapse into the mobile actions sheet. Do not
  reintroduce a crowded topbar or desktop-only shortcuts into the phone layout.
- Every interactive control must have a visible hover/focus/active state.
- When adding UI, update the Playwright tests and verify both desktop and mobile.

## Coding conventions

- Match the existing style: tabs in PHP/JS/CSS, plain functions, no unnecessary
  abstractions or frameworks.
- Keep comments sparse and useful; explain security or non-obvious decisions.
- Prefer small, focused functions and existing helpers over duplicated logic.
- Do not add emojis to source files unless explicitly requested.
- Keep generated paths relative (`./folder`) and normalise them with the existing
  helpers. Preview URLs are never folder paths.
- Do not rename public query parameters or endpoint names without updating tests,
  documentation and callers.
- The application intentionally has no upload/admin mode. Do not add one without
  an explicit request, authentication, CSRF protection and rate limiting.

## Adding new behaviour

1. Write/adjust a failing HTTP or Playwright test first when practical.
2. Implement the smallest safe change.
3. Add security checks for any new path/shell/output surface.
4. Run the full suite and syntax checks.
5. Update this file if the change alters architecture, security or release rules.

## Release checklist

```console
bash tests/run.sh
git status
git diff --check
git add <intended files>
git commit -m "Clear, descriptive message"
git push origin main
```

Only commit when explicitly asked. CI will test the commit and tag it with the
runner identity after success.
