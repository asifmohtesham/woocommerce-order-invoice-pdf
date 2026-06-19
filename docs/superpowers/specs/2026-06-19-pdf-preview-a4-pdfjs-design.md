# Real-time A4 PDF preview in the visual editor

**Date:** 2026-06-19
**Status:** Approved (brainstorming)

## Goal

Replace the visual editor's PDF-tab native-viewer `<iframe>` with a PDF.js
canvas render, framed as ISO A4 sheet(s) to mimic real-world paper output, and
make it auto-refresh (debounced) as the user edits the template.

The real PDF is still generated server-side by mPDF via the existing
`woi_pdf_preview` AJAX action — that pipeline is unchanged. This work only
changes how the returned PDF is *displayed* and *when* it is refreshed.

## Context (current state)

- The visual editor (`includes/Visual/VisualEditorPage.php` +
  `assets/visual-editor/app.js` + `editor.css`) has a right-side preview pane
  with two tabs: **Live HTML** (instant `srcdoc`, re-renders on every edit) and
  **PDF**.
- The PDF tab today: a **Render PDF** button → `save()` → AJAX
  `woi_pdf_preview` → base64 PDF (mPDF) → Blob URL → set as
  `#woi-preview-pdf-frame` iframe `src`, displayed by the browser's native PDF
  viewer. Not real-time; refreshes only on button click or when the PDF tab is
  active during an edit (`woiMaybeRefreshPdf`, immediate, no debounce).
- PDF.js is **already vendored** at `assets/js/pdf_js/pdf.min.js` +
  `pdf.worker.min.js`, exposing `globalThis.pdfjsLib`. It is already used by the
  legacy settings-page preview (`assets/js/admin.js` `renderPdf()`), which
  renders **page 1 only** to a single canvas. No new dependency is needed.

## Decisions

- **Refresh model:** debounced auto-refresh (~1s after edits stop), only while
  the preview pane is open and the PDF tab is active. Manual **Render PDF**
  button stays as a fallback / force-refresh.
- **Render mode:** PDF.js as a library rendering to `<canvas>` (no viewer
  chrome/toolbar) — cleanest WYSIWYG "sheet of paper" look. This replaces the
  literal `<iframe>` with `<canvas>` elements.
- **Pages:** render **all** pages, stacked vertically, scrollable.

## Design

### 1. Renderer & assets (`VisualEditorPage.php` enqueue)

- Enqueue `assets/js/pdf_js/pdf.min.js` (handle e.g. `woi-pdfjs`) and add it as
  a dependency of the `woi-visual-editor` script, versioned with
  `WOI_PDF_VERSION`.
- Add `pdfWorkerUrl` to the `woiVisual` localized object, pointing at
  `plugin_url() . '/assets/js/pdf_js/pdf.worker.min.js'`, so JS can set
  `pdfjsLib.GlobalWorkerOptions.workerSrc`.

### 2. Markup (`VisualEditorPage.php`)

Remove the `#woi-preview-pdf-frame` `<iframe>`. Replace the PDF tab body with a
scroll viewport containing a sheet stage that JS fills with one `<canvas>` per
page:

```
<div id="woi-preview-pdf" hidden>
  <p><button ... id="woi-render-pdf">Render PDF</button>
     <span id="woi-render-pdf-status"></span></p>
  <div class="woi-a4-scroll">
    <div class="woi-a4-stage" id="woi-pdf-stage"></div>
  </div>
</div>
```

The Render PDF button, its status span (`#woi-render-pdf-status`), and the
`#woi-preview-pdf` container id are preserved so existing JS hooks keep working.

### 3. CSS (`editor.css`)

- `.woi-a4-scroll`: `flex:1 1 auto; overflow:auto;` PDF-viewer-style gray
  backdrop (`#525659`); centers content horizontally; vertical gap between
  pages; padding around sheets.
- `.woi-a4-stage`: capped responsive width (`min(100%, 820px)`) so a sheet
  reads as paper rather than a stretched canvas; column layout for stacked
  pages.
- `.woi-a4-page` (each `<canvas>`): white background, `aspect-ratio:210/297`,
  paper drop shadow, CSS `width:100%` of the stage. The canvas *intrinsic*
  pixel dimensions are set by JS (PDF.js viewport × `devicePixelRatio`) for
  crisp HiDPI rendering; CSS scales the display size to fit the stage.
- Update the existing layout-mode overrides (`[data-layout="full"|"stack"|
  "overlay"]`) that currently reference `#woi-preview-pdf-frame` to target the
  new structure (`.woi-a4-scroll` / `#woi-preview-pdf`).

### 4. JS (`app.js`)

Rewrite `woiRenderPdf()`:

1. `save()` → existing `woi_pdf_preview` AJAX → base64 PDF (server unchanged).
2. Decode base64 → `Uint8Array`; set
   `pdfjsLib.GlobalWorkerOptions.workerSrc = woiVisual.pdfWorkerUrl`;
   `pdfjsLib.getDocument({ data: bytes })`.
3. Loop **all** pages (`pdf.numPages`), rendering each into a fresh
   `.woi-a4-page` canvas. To avoid a blank flash during debounced re-renders,
   render pages into a detached fragment and swap them into `#woi-pdf-stage`
   (replacing prior pages) only once this render is confirmed latest. Render
   scale = a fit-width base × `devicePixelRatio` for sharpness.
4. **Concurrency guard:** maintain a monotonic generation counter. Each render
   captures the current generation; before mutating the DOM or applying page
   results it checks it is still the latest generation, otherwise it bails and
   calls `loadingTask.destroy()`. This prevents debounced bursts from
   interleaving stale pages into the stage.
5. Status span shows `Rendering…`, clears on success, or `Error: …` on failure
   (same UX as today).

Drop the `woiPdfBlobUrl` Blob-URL plumbing (no longer needed — PDF.js consumes
the bytes directly); remove the `beforeunload` revoke tied to it.

Debounce auto-refresh: wrap the body of `woiMaybeRefreshPdf()` in a ~1000ms
debounce (trailing) so an edit triggers a single re-render once typing settles,
still gated on `woiPaneOpen() && woiPdfTabActive()`. The manual button calls
`woiRenderPdf()` directly (no debounce).

### 5. Cache-bust

Bump `WOI_PDF_VERSION` (plugin version constant) and the plugin header version
so browsers do not serve stale `app.js` / `editor.css` / enqueued assets.

## Edge cases

- **Concurrent / rapid edits:** handled by the generation guard (§4.4); only the
  newest render's pages reach the stage.
- **Render error / empty PDF:** caught, surfaced in `#woi-render-pdf-status`;
  the stage is left in a sane state (cleared or prior pages retained — cleared
  only once the new render is confirmed latest).
- **HiDPI displays:** canvas intrinsic size scaled by `devicePixelRatio`.
- **Multi-page documents:** all pages rendered and stacked; the scroll viewport
  handles overflow.

## Out of scope

- Changing the mPDF generation pipeline or the `woi_pdf_preview` endpoint.
- Zoom / page-navigation / search controls (canvas render is chrome-free by
  design).
- The Live HTML tab (unchanged; still the instant-feedback path).
- Sharing/refactoring the legacy `admin.js` `renderPdf()` (left as-is).
