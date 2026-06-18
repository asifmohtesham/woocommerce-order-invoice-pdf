# Design: Download watermarked "Sample" PDF from settings preview

**Date:** 2026-06-18
**Branch:** feat/bilingual-second-language (or a dedicated feature branch)
**Status:** Approved — ready for implementation plan

## Problem

The settings PDF preview renders a document on-screen (page 1, via pdf.js) but
offers no way to download the previewed PDF. Users want to download the preview,
and that downloaded file must be clearly marked as not a real invoice.

## Goal

Add a Download control to the settings PDF preview. The downloaded file — and the
on-screen preview — carries a single centered diagonal light-gray "SAMPLE"
watermark on every page. Real/customer-facing PDFs are never affected.

## Decisions (from brainstorming)

- **Watermark scope:** On-screen preview **and** download. The preview PDF is
  stamped server-side; download just saves the blob the browser already holds.
- **Watermark style:** Single large "SAMPLE", rotated ~45°, centered, light gray
  (faint), on every page.
- **Download format:** PDF only. The button is hidden/disabled in XML (UBL) mode.

## Architecture

### 1. Server-side — watermark the preview PDF

The preview render path is `Settings::ajax_preview()` →
`OrderDocument::preview_pdf()` → `woi_pdf_get_pdf_maker(...)->output()`
(`includes/Makers/PDFMaker.php`, Dompdf engine — see [[pdf-engine-is-dompdf]]).

`PDFMaker::output()` already exposes a `woi_pdf_after_dompdf_render` filter that
passes the live `$dompdf` object between `render()` and `output()`. This is the
stamp point.

- Add a watermark callback (e.g. a small static method or standalone function)
  that, given the `$dompdf` object, draws the watermark via the Dompdf canvas:
  - `$canvas = $dompdf->getCanvas();` get `get_width()` / `get_height()`.
  - `$font = $dompdf->getFontMetrics()->getFont( 'DejaVu Sans', 'normal' );`
  - `$canvas->page_text( $x, $y, $text, $font, $size, $color, 0, 0, $angle );`
    `page_text()` renders on **every** page. Center using the text width from
    `$dompdf->getFontMetrics()->getTextWidth(...)`; angle ~45°; color a light
    gray array (e.g. `array(0.8, 0.8, 0.8)`) — `page_text()` has no true alpha,
    so a light gray is used to read as faint/semi-transparent.
- Attach this callback **only** during preview: in `preview_pdf()`, `add_filter`
  before `$pdf_maker->output()` and `remove_filter` immediately after. This
  guarantees normal PDF generation (orders, emails, My-Account) is untouched.
- Extensibility filters:
  - `woi_pdf_preview_watermark_enabled` (bool, default `true`)
  - `woi_pdf_preview_watermark_text` (string, default `SAMPLE`)
  The callback no-ops when disabled.
- XML preview (`preview_xml()`) is a separate path and gets no watermark.

### 2. Client-side — Download button

Markup (`views/settings-page.php`): add a **Download** button inside the
`.preview-data-wrapper` toolbar (the row holding the order-search and
document-type pickers, around line 167-192). Disabled by default.

Behavior (`assets/js/admin.js`):
- Store the latest PDF base64 in a module-level variable when a PDF preview loads
  successfully (currently `response.data.preview_data` is only used transiently
  inside the success callback around line 655-663). Clear/disable it when a load
  starts or errors.
- On Download click: decode the stored base64 to bytes, build a
  `Blob([...], { type: 'application/pdf' })`, create an object URL, and trigger
  download through a temporary `<a download="...">` element; revoke the URL after.
  **No extra server request** — the browser already has the watermarked PDF.
- Filename: `${previewDocumentType}-preview-sample.pdf`.
- Visibility: the button is shown only when `output_format === 'pdf'`. When the
  output format toggles to XML, hide/disable it (the format toggle handling lives
  near line 568-608). Keep it disabled until a PDF preview has loaded.

Localized label string added via the existing `woi_pdf_admin` localization in
`includes/Assets.php` (alongside `error_loading_number_preview`).

## Components & boundaries

| Unit | Responsibility | Depends on |
|------|----------------|------------|
| Watermark callback (PHP) | Draw "SAMPLE" on every page of a Dompdf object | Dompdf canvas + font metrics |
| `preview_pdf()` wiring | Attach/detach callback around preview render only | `woi_pdf_after_dompdf_render` filter |
| Download button markup | Toolbar control | settings-page view |
| `admin.js` download logic | Cache base64, build Blob, trigger save, toggle visibility | existing preview AJAX flow |

## Out of scope (YAGNI)

- No separate download endpoint or extra nonce round-trip.
- No watermark on real invoices, order emails, or My-Account downloads.
- No multi-page preview navigation changes.
- No XML download (button hidden in XML mode).

## Testing

**PHPUnit** (remember the `-d auto_prepend_file=tests/bootstrap.php` flag —
[[phpunit-abspath-gotcha]]):
- Watermark callback is attached during `preview_pdf()` and removed afterward
  (no lingering `woi_pdf_after_dompdf_render` hook).
- `woi_pdf_preview_watermark_enabled => false` makes the callback a no-op.
- `woi_pdf_preview_watermark_text` overrides the stamped text.
- Normal (non-preview) PDF generation does not attach the watermark hook.

**Manual:**
- Open settings preview → on-screen preview shows the "SAMPLE" watermark.
- Click Download → saved file is the watermarked PDF, every page stamped.
- Switch output format to XML → Download button hidden; switch back → shown.
- Confirm a real order invoice (front-end / email) has **no** watermark.
