# Preview Overlay — Spec Addendum to Settings UI Overhaul

**Date:** 2026-06-11
**Status:** Approved
**Amends:** `2026-06-11-settings-ui-overhaul-design.md` (Preview pane + Responsiveness sections)

## Problem

User feedback during branch verification: the always-visible side-by-side PDF preview clutters the UI — worst on the Customiser, where the editor needs width and the preview ends up a cramped right column.

## Decision

The split-view preview is replaced by an **on-demand overlay on all tabs** (General, Documents, Customiser). Considered and rejected: a top/bottom vertical fold (wide-short pane suits an A4 portrait page poorly) and keeping the split closed-by-default (gutter strip remains visible clutter).

## Behavior

1. **Default:** preview hidden at every viewport width; the settings form/sidebar takes the full content area. The gutter (slider strip) is never shown.
2. **Toggle:** the header **Preview** button (previously ≤1100px-only) is always visible on preview-capable tabs and toggles the overlay: a fixed panel on the right, full height below the sticky header, `min(560px, 90vw)` wide, with shadow. Not rendered on Home, Advanced, or when previews are disabled (`preview_states !== 3`).
3. **State memory:** open/closed persists in `localStorage` (single global key `woi_pdf_preview_overlay_open`), restored on page load across all tabs.
4. **No wasted AJAX:** all preview refreshes flow through `triggerPreview()` (admin.js). While the overlay is closed, refreshes mark the preview stale (attribute on the wrapper) and skip the AJAX. Opening the overlay fires one fresh render if stale. While open, live refresh behaves exactly as before — including the Customiser.
5. **State machine neutralized:** `determinePreviewStates()` and the gutter slide handlers (admin.js) short-circuit when running inside the shell (`.woi-pdf-shell` ancestor), leaving layout entirely to CSS. No inline display styles are written in shell mode.

## Unchanged

Preview pane internals (order search, document-type picker), all PHP preview rendering/AJAX, the `preview_states` tab data (now inert in shell mode rather than removed), non-shell contexts of admin.js.
