# Preview Overlay Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the side-by-side PDF preview with an on-demand right-side overlay on all tabs, per spec addendum `docs/superpowers/specs/2026-06-11-preview-overlay-addendum.md`.

**Architecture:** CSS hides gutter + preview at every width and generalizes the existing ≤1100px overlay rules; `admin.js`'s split-view state machine short-circuits inside the shell; `triggerPreview()` gates AJAX on overlay visibility with a stale flag; `admin-shell.js` owns the toggle + `localStorage` persistence and requests a refresh via the existing `woi-pdf-refresh-preview` document event. One PHP touch: the header Preview button renders only when `preview_states === 3`.

**Tech Stack:** CSS, jQuery (existing admin.js conventions), one-line PHP view change.

**Test command:** `php -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit` (the ONLY working invocation on this machine — `vendor/bin/phpunit` exits silently). Baseline: 37 tests / 64 assertions.

**Verified code facts:**

| Fact | Location |
|---|---|
| `triggerPreview( timeoutDuration )` — sole gate for every preview refresh (page load 340, settings-changed 345, refresh events 349-351, search 362, doc-type 583, order-id 589, etc.) | `assets/js/admin.js:505-518`; guard at 509: `if ( ! $previewWrapper.length \|\| 1 === $previewStates ) return;` |
| `determinePreviewStates()` — sets inline `.show()`/`.hide()` + `data-preview-state*` attrs; bound at load + window resize | `assets/js/admin.js:187-246` |
| Gutter slide handlers (also write inline styles) | `assets/js/admin.js:248-303` |
| Custom refresh event admin-shell can fire | `assets/js/admin.js:349-351`: `$( document ).on( 'woi-pdf-refresh-preview woi_pdf_refresh_preview', ... )` |
| `$previewWrapper` declared `let $previewWrapper = $( '#woi-pdf-preview-wrapper' )` in the outer ready closure | `assets/js/admin.js:~116` |
| Preview toggle button markup (renders on all non-home tabs, `hidden` attr) | `views/settings-page.php:61`: `<button type="button" class="button woi-shell-preview-toggle" hidden>` inside `if ( 'home' !== $current_tab )` |
| `$preview_states` available in the view | `views/settings-page.php:~20`: `$preview_states = isset( $settings_tabs[ $current_tab ]['preview_states'] ) ? ... : 1;` (3 = preview available; 1 = none, incl. debug tab + disable_preview) |
| Existing overlay CSS (≤1100px) + desktop-hide of the toggle (min-width:1101px) | `assets/css/admin-shell.css` — `@media screen and (max-width: 1100px)` block and `@media screen and (min-width: 1101px) { .woi-shell-preview-toggle { display: none; } }` |
| admin-shell.js toggle block (reveals button when wrapper exists, toggles `woi-preview-overlay-open` on `$shell = $( '.woi-pdf-shell' )`) | `assets/js/admin-shell.js`, bottom section |
| Legacy split CSS keyed on `data-preview-state` attrs | `assets/css/settings-styles.min.css` (do not edit; override from admin-shell.css) |

---

### Task 1: CSS + PHP — overlay at all widths, button only where preview exists

**Files:**
- Modify: `assets/css/admin-shell.css`
- Modify: `views/settings-page.php` (one condition)

- [ ] **Step 1: Generalize the overlay CSS**

In `assets/css/admin-shell.css`, replace the entire `@media screen and (max-width: 1100px) { ... }` block (the one containing the gutter/preview-document/overlay rules) with unconditional rules:

```css
/* --- Preview overlay (all widths) --- */
.woi-pdf-shell #woi-pdf-preview-wrapper .gutter { display: none; }
.woi-pdf-shell #woi-pdf-preview-wrapper .preview-document { display: none; }
.woi-pdf-shell #woi-pdf-preview-wrapper .sidebar {
	display: block;
	width: 100%;
	max-width: none;
}
.woi-pdf-shell.woi-preview-overlay-open #woi-pdf-preview-wrapper .preview-document {
	display: block;
	position: fixed;
	top: 88px;
	right: 16px;
	bottom: 16px;
	width: min(560px, 90vw);
	background: #fff;
	border: 1px solid #dcdcde;
	border-radius: 6px;
	box-shadow: 0 4px 20px rgba(0,0,0,.18);
	z-index: 99;
	overflow: auto;
	padding: 10px;
}
```

Also DELETE the `@media screen and (min-width: 1101px) { .woi-shell-preview-toggle { display: none; } }` block — the toggle is now legitimate at all widths.

Add a stale-indicator dot for the toggle button (used by Task 2's JS):

```css
.woi-shell-preview-toggle.stale::after {
	content: "";
	display: inline-block;
	width: 6px;
	height: 6px;
	border-radius: 50%;
	background: #d63638;
	margin-left: 6px;
	vertical-align: middle;
}
```

- [ ] **Step 2: Render the Preview button only on preview-capable tabs**

In `views/settings-page.php`, the header buttons block currently reads:

```php
			<?php if ( 'home' !== $current_tab ) : ?>
				<button type="button" class="button button-primary woi-shell-save" hidden><?php esc_html_e( 'Save', 'woocommerce-orders-invoice-pdf' ); ?></button>
				<button type="button" class="button woi-shell-preview-toggle" hidden><?php esc_html_e( 'Preview', 'woocommerce-orders-invoice-pdf' ); ?></button>
			<?php endif; ?>
```

Wrap ONLY the preview-toggle line in an additional condition (the Save button stays as is). NOTE: `$preview_states` is computed a few lines below this block in the current file — move the `$preview_states` / `$preview_states_lock` / `$preview_document_type` computation ABOVE the `<div class="wrap ...">` opening so it's available to the header:

```php
				<?php if ( 3 === (int) $preview_states ) : ?>
				<button type="button" class="button woi-shell-preview-toggle" hidden><?php esc_html_e( 'Preview', 'woocommerce-orders-invoice-pdf' ); ?></button>
				<?php endif; ?>
```

- [ ] **Step 3: Verify + commit**

```powershell
php -l views/settings-page.php
php -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit
git add assets/css/admin-shell.css views/settings-page.php
git commit -m "feat: preview overlay CSS at all widths, toggle only on preview-capable tabs"
```

Expected: no syntax errors, 37 tests passing.

---

### Task 2: JS — state-machine short-circuit, AJAX gating, toggle persistence

**Files:**
- Modify: `assets/js/admin.js` (two surgical edits)
- Modify: `assets/js/admin-shell.js` (rework the toggle block)

- [ ] **Step 1: Short-circuit the split state machine inside the shell**

In `assets/js/admin.js`, at the TOP of `determinePreviewStates()` (line ~191, before the lock check), add:

```js
		// Shell overlay mode: layout is pure CSS; never write inline styles here
		if ( $previewWrapper.closest( '.woi-pdf-shell' ).length ) {
			previousWindowWidth = $( window ).width();
			return;
		}
```

Add the same first-line guard (without the width bookkeeping) to the `.slide-left` and `.slide-right` click handlers (lines ~248 and ~277):

```js
		if ( $previewWrapper.closest( '.woi-pdf-shell' ).length ) {
			return;
		}
```

- [ ] **Step 2: Gate preview AJAX on overlay visibility**

In `assets/js/admin.js`, inside `triggerPreview()` (line ~505), after the existing disabled/absent guard, add:

```js
		// Shell overlay mode: skip the AJAX while the overlay is closed; mark stale instead
		let $shell = $( '.woi-pdf-shell' );
		if ( $shell.length && ! $shell.hasClass( 'woi-preview-overlay-open' ) ) {
			$previewWrapper.attr( 'data-preview-stale', '1' );
			$( '.woi-shell-preview-toggle' ).addClass( 'stale' );
			return;
		}
		$previewWrapper.removeAttr( 'data-preview-stale' );
		$( '.woi-shell-preview-toggle' ).removeClass( 'stale' );
```

- [ ] **Step 3: Rework the toggle block in admin-shell.js**

Replace the existing preview-overlay section at the bottom of `assets/js/admin-shell.js`:

```js
	//----------> Small-screen preview overlay <----------//
	if ( $( '#woi-pdf-preview-wrapper' ).length ) {
		$( '.woi-shell-preview-toggle' ).prop( 'hidden', false ).on( 'click', function() {
			$shell.toggleClass( 'woi-preview-overlay-open' );
		} );
	}
```

with:

```js
	//----------> Preview overlay (all widths) <----------//
	const $previewWrapper = $( '#woi-pdf-preview-wrapper' );
	const $previewToggle  = $( '.woi-shell-preview-toggle' );
	const overlayStateKey = 'woi_pdf_preview_overlay_open';
	const previewCapable  = $previewWrapper.length && 3 === parseInt( $previewWrapper.attr( 'data-preview-states' ), 10 );

	function refreshPreviewIfStale() {
		if ( '1' === $previewWrapper.attr( 'data-preview-stale' ) ) {
			$( document ).trigger( 'woi-pdf-refresh-preview' );
		}
	}

	if ( previewCapable ) {
		$previewToggle.prop( 'hidden', false ).on( 'click', function() {
			const open = $shell.toggleClass( 'woi-preview-overlay-open' ).hasClass( 'woi-preview-overlay-open' );
			localStorage.setItem( overlayStateKey, open ? '1' : '0' );

			if ( open ) {
				refreshPreviewIfStale();
			}
		} );

		// Restore persisted state (admin.js ready-handler already ran and may have marked stale)
		if ( '1' === localStorage.getItem( overlayStateKey ) ) {
			$shell.addClass( 'woi-preview-overlay-open' );
			refreshPreviewIfStale();
		}
	}
```

(Note: this section may currently sit before/after others — keep its position; `$shell` is already defined at the top of the file.)

- [ ] **Step 4: Verify + commit**

```powershell
node --check assets/js/admin.js
node --check assets/js/admin-shell.js
php -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit
git add assets/js/admin.js assets/js/admin-shell.js
git commit -m "feat: gate preview AJAX behind overlay, persist overlay state, neutralize split state machine in shell"
```

Expected: clean lints, 37 tests passing.

Interaction trace to sanity-check while implementing (page load, overlay closed): admin.js ready runs first → `triggerPreview()` at line 340 → shell present + no open class → stale flag set, no AJAX. admin-shell.js ready runs second → localStorage '1'? → adds open class → sees stale → fires `woi-pdf-refresh-preview` → admin.js handler → `triggerPreview()` → open now, flag cleared, AJAX fires once.

---

### Task 3: Verification

- [ ] **Step 1: Full suite** — 37 passing via the standard command.
- [ ] **Step 2: Manual checks (user, on the test site):**
  1. General/Documents/Customiser: no gutter, no preview column; settings use full width.
  2. Header Preview button visible on those tabs; absent on Home and Advanced.
  3. Toggling opens a fixed right panel with the rendered PDF; first open after changes shows a fresh render (stale dot on the button clears).
  4. State persists across reloads and tab switches.
  5. With overlay closed, editing settings fires NO `woi_pdf_preview` AJAX (network tab); with it open, live refresh works, including Customiser edits.
  6. `?tab=debug` and the disable-preview setting: no Preview button.
