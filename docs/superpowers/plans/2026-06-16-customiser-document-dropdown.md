# Customiser Document Picker: Tabs → Dropdown — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Customiser editor's horizontal document tab strip (+ scroll arrows) with a labeled `<select>` dropdown that drives the existing jQuery UI tabs widget.

**Architecture:** Server-rendered `<select>` acts as a remote control: on `change` it calls `$('#documents').tabs('option','active', selectedIndex)` and syncs the preview document type. The `<ul class="document-tabs">` stays in the DOM (hidden) because `.tabs()` binds to it. The `.tab-scroll-wrapper`/arrows/`initTabScroll()`/`debounce` are removed. The select is select2-enhanced only when document count exceeds a filterable threshold (default 8).

**Tech Stack:** PHP (EditorSettings.php), jQuery + jQuery UI Tabs (editor.js), vanilla CSS (editor.css).

**Spec:** `docs/superpowers/specs/2026-06-16-customiser-document-dropdown-design.md`

**Note — this is UI/template work with no unit-test harness.** Verification is `php -l`, the full PHPUnit suite as a regression guard (the `NavModel` suite is unrelated but must stay green), and a manual browser pass. There are no new unit tests.

---

## File map

| File | Change |
|------|--------|
| `includes/Editor/EditorSettings.php` | ~1388–1401: replace `.tab-scroll-wrapper` block with `.document-select-wrapper` (label + `<select>`); keep `<ul class="document-tabs">` (now hidden) |
| `assets/js/editor.js` | Add `.document-select` change handler; remove `initTabScroll()` call + def + `debounce`; remove old tab click handler; rewrite ready-sync to read the select |
| `assets/css/editor.css` | 27–120 + 447–458: remove tab-scroll/tab-strip rules, hide `.document-tabs`, add `.document-select*` rules |
| `woocommerce-orders-invoice-pdf.php` | Bump version 1.3.0 → 1.3.1 (lines 6 and 24) |

---

## Task 1 — PHP: replace the tab strip with a `<select>`

**Files:**
- Modify: `includes/Editor/EditorSettings.php:1388-1401`

- [ ] **Step 1: Replace the `.tab-scroll-wrapper` block**

Find this exact block (starts at line 1388):

```php
		<div id="documents" style="display:none;">
			<div class="tab-scroll-wrapper">
				<button type="button" class="tab-scroll-btn tab-scroll-prev" aria-label="<?php esc_attr_e( 'Previous tabs', 'woi_pdf_templates' ); ?>">&#8249;</button>
				<div class="tab-scroll-track">
					<ul class="document-tabs">
						<?php foreach ($args['documents'] as $document => $title) {
							$document_id = $id.'_'.$document;
							printf( '<li><a href="#%1$s" data-document_type="%2$s">%3$s</a></li>', $document_id, $document, $title );
						}
						?>
					</ul>
				</div>
				<button type="button" class="tab-scroll-btn tab-scroll-next" aria-label="<?php esc_attr_e( 'Next tabs', 'woi_pdf_templates' ); ?>">&#8250;</button>
			</div>
```

Replace it with:

```php
		<div id="documents" style="display:none;">
			<div class="document-select-wrapper">
				<label for="<?php echo esc_attr( $id ); ?>_document_select" class="document-select-label"><?php esc_html_e( 'Document', 'woi_pdf_templates' ); ?></label>
				<?php
				$select2_threshold       = (int) apply_filters( 'woi_pdf_document_select_select2_threshold', 8 );
				$document_select_classes = 'document-select';
				if ( count( $args['documents'] ) > $select2_threshold ) {
					$document_select_classes .= ' wc-enhanced-select';
				}
				?>
				<select id="<?php echo esc_attr( $id ); ?>_document_select" class="<?php echo esc_attr( $document_select_classes ); ?>">
					<?php foreach ( $args['documents'] as $document => $title ) {
						$document_id = $id.'_'.$document;
						printf( '<option value="#%1$s" data-document_type="%2$s">%3$s</option>', esc_attr( $document_id ), esc_attr( $document ), esc_html( $title ) );
					} ?>
				</select>
			</div>
			<ul class="document-tabs">
				<?php foreach ( $args['documents'] as $document => $title ) {
					$document_id = $id.'_'.$document;
					printf( '<li><a href="#%1$s" data-document_type="%2$s">%3$s</a></li>', $document_id, $document, $title );
				} ?>
			</ul>
```

This keeps `<ul class="document-tabs">` as the **first `<ul>`** inside `#documents` (so `.tabs()` still binds to it) and leaves the per-document panels that follow (line 1403 onward) untouched.

- [ ] **Step 2: Lint**

Run: `php -l includes/Editor/EditorSettings.php`
Expected: `No syntax errors detected in includes/Editor/EditorSettings.php`

- [ ] **Step 3: Commit**

```bash
git add includes/Editor/EditorSettings.php
git commit -m "feat: render Customiser document picker as a select dropdown"
```

---

## Task 2 — JS: drive tabs from the select, remove scroll code

**Files:**
- Modify: `assets/js/editor.js`

- [ ] **Step 1: Remove the `debounce` helper (lines 2–8)**

It is used only by `initTabScroll` (removed in Step 3). Delete this block, immediately after `jQuery(function($) {`:

```js
	function debounce( fn, wait ) {
		var timer;
		return function() {
			clearTimeout( timer );
			timer = setTimeout( fn, wait );
		};
	}

```

- [ ] **Step 2: Wire the select to tabs + preview, drop the `initTabScroll()` call**

Find:

```js
	$( '#documents' ).tabs().show();
	$(document.body).trigger( 'wc-enhanced-select-init' );
	initTabScroll();
```

Replace with:

```js
	$( '#documents' ).tabs().show();
	$(document.body).trigger( 'wc-enhanced-select-init' );

	// Document picker: drive jQuery UI tabs() + sync preview document type
	$( '#documents' ).on( 'change', '.document-select', function() {
		$( '#documents' ).tabs( 'option', 'active', this.selectedIndex );
		var document_type = $( this ).find( 'option:selected' ).data( 'document_type' );
		$( '#woi-pdf-preview-wrapper :input[name="document_type"]' ).val( document_type ).trigger( 'change' );
	} );
```

The handler is delegated on `#documents` so it survives select2 wrapping; select2 fires `change` on the native `<select>` and `this.selectedIndex` stays valid.

- [ ] **Step 3: Remove the `initTabScroll()` function definition**

Delete this entire block (was lines 278–312):

```js
	function initTabScroll() {
		$( window ).off( 'resize.tabScroll' );
		var $wrapper = $( '#documents .tab-scroll-wrapper' );
		if ( ! $wrapper.length ) return;

		var $track = $wrapper.find( '.tab-scroll-track' );
		var $ul    = $wrapper.find( '.document-tabs' );
		var $prev  = $wrapper.find( '.tab-scroll-prev' );
		var $next  = $wrapper.find( '.tab-scroll-next' );
		var ul     = $ul[0];

		function update() {
			var atStart = ul.scrollLeft <= 0;
			var atEnd   = ul.scrollLeft + ul.clientWidth >= ul.scrollWidth - 1;
			$prev.toggleClass( 'hidden', atStart );
			$next.toggleClass( 'hidden', atEnd );
			$track.toggleClass( 'at-start', atStart );
			$track.toggleClass( 'at-end',   atEnd );
		}

		$prev.off( 'click.tabScroll' ).on( 'click.tabScroll', function() {
			ul.scrollLeft -= Math.round( ul.clientWidth * 0.5 );
			update();
		} );

		$next.off( 'click.tabScroll' ).on( 'click.tabScroll', function() {
			ul.scrollLeft += Math.round( ul.clientWidth * 0.5 );
			update();
		} );

		$ul.off( 'scroll.tabScroll' ).on( 'scroll.tabScroll', debounce( update, 16 ) );
		$( window ).on( 'resize.tabScroll', debounce( update, 150 ) );

		update();
	}
```

- [ ] **Step 4: Remove the old tab-click preview handler**

Delete this block (was lines 345–349):

```js
	// Update Preview document type on editor document change
	$( document ).on( 'click', 'ul.document-tabs > li > a', function( event ) {
		let document_type = $( this ).data( 'document_type' );
		$( '#woi-pdf-preview-wrapper :input[name="document_type"]' ).val( document_type ).trigger( 'change' );
	} );
```

The `<ul>` is hidden now, so it never fires; the select handler (Step 2) supersedes it.

- [ ] **Step 5: Rewrite the ready-time preview sync to read the select**

Find (was lines 351–360):

```js
	// Detect if the editor active tab is different from Invoice, and if yes change the preview document type input
	$( document ).ready( function() {
		if ( $( '#documents ul.document-tabs' ).length ) {
			let $active_tab_link = $( '#documents ul.document-tabs > li.ui-state-active > a' );
			let document_type    = $active_tab_link.data( 'document_type' );
			if ( document_type.length && document_type != 'invoice' ) {
				$( '#woi-pdf-preview-wrapper :input[name="document_type"]' ).val( document_type ).trigger( 'change' );
			}
		}
	} );
```

Replace with:

```js
	// Detect if the editor document is different from Invoice, and if yes change the preview document type input
	$( document ).ready( function() {
		var $select = $( '#documents .document-select' );
		if ( $select.length ) {
			var document_type = $select.find( 'option:selected' ).data( 'document_type' );
			if ( document_type && document_type !== 'invoice' ) {
				$( '#woi-pdf-preview-wrapper :input[name="document_type"]' ).val( document_type ).trigger( 'change' );
			}
		}
	} );
```

- [ ] **Step 6: Sanity-check no tab-scroll references remain in JS**

Run: `grep -nE 'initTabScroll|tabScroll|tab-scroll|document-tabs|debounce' assets/js/editor.js`
Expected: NO output.

- [ ] **Step 7: Commit**

```bash
git add assets/js/editor.js
git commit -m "feat: drive Customiser panels from document select, remove tab-scroll JS"
```

---

## Task 3 — CSS: hide the list, style the select

**Files:**
- Modify: `assets/css/editor.css:27-120` and `:447-458`

- [ ] **Step 1: Replace the tab-scroll + tab-strip rules (lines 27–120)**

Find the block from the comment `/* ── Scrollable tab bar ─...`  (line 27) through the rule:

```css
#woi-pdf-settings .document-tabs li.ui-tabs-active a {
	color:                  black;
}
```

(line 120 — i.e. everything from line 27 up to and including line 120, the rule just before `#woi-pdf-settings .document-content {`). Replace that ENTIRE range with:

```css
/* ── Document picker ────────────────────────────────────────── */
#woi-pdf-settings .document-tabs { display: none; }

#woi-pdf-settings .document-select-wrapper {
	display:     flex;
	align-items: center;
	gap:         8px;
	margin:      0 0 10px 20px;
}

#woi-pdf-settings .document-select-label {
	font-weight: bold;
	font-size:   14px;
	color:       #555;
}

#woi-pdf-settings .document-select {
	min-width: 200px;
	max-width: 320px;
}
/* ─────────────────────────────────────────────────────────────── */
```

- [ ] **Step 2: Replace the mobile overrides (lines 447–458)**

Inside the `@media` block, find:

```css
	#woi-pdf-settings .tab-scroll-wrapper {
		margin:                 0 0 10px 0;
	}

	#woi-pdf-settings .document-tabs li {
		margin:                 0 8px 8px 0;
	}

	#woi-pdf-settings .document-tabs li.ui-tabs-active {
		border-bottom-color:    #ccc;
		padding:                0;
	}
```

Replace with:

```css
	#woi-pdf-settings .document-select-wrapper {
		margin:                 0 0 10px 0;
	}
```

- [ ] **Step 3: Sanity-check no orphaned tab-scroll selectors remain in CSS**

Run: `grep -nE 'tab-scroll|document-tabs li|at-start|at-end' assets/css/editor.css`
Expected: NO output. (`.document-tabs { display:none }` is the only `document-tabs` selector left — grepping `document-tabs li` specifically should return nothing.)

- [ ] **Step 4: Commit**

```bash
git add assets/css/editor.css
git commit -m "feat: hide document-tabs list, style document select, drop tab-scroll CSS"
```

---

## Task 4 — Version bump + verification

**Files:**
- Modify: `woocommerce-orders-invoice-pdf.php:6` and `:24`

- [ ] **Step 1: Bump the version in both places**

Line 6: change ` * Version:              1.3.0` to ` * Version:              1.3.1`
Line 24: change `	public string $version     = '1.3.0';` to `	public string $version     = '1.3.1';`

- [ ] **Step 2: Run the full PHPUnit suite (regression guard)**

Run (capture to a file — the harness drops phpunit's piped stdout):
`php -d auto_prepend_file=tests/bootstrap.php vendor/phpunit/phpunit/phpunit > pu.txt 2>&1`
then read `pu.txt`.
Expected: `OK (61 tests, 108 assertions)`. Delete `pu.txt` afterward.

- [ ] **Step 3: Commit**

```bash
git add woocommerce-orders-invoice-pdf.php
git commit -m "chore: bump version to 1.3.1 for Customiser dropdown CSS/JS"
```

- [ ] **Step 4: Manual browser verification**

Open the Customiser tab in WP admin (hard-refresh / clear LiteSpeed cache first) and confirm:

1. The tab strip and ‹ › arrows are gone; a labeled **Document** dropdown sits in their place.
2. The Invoice editor panel shows by default.
3. Selecting Packing Slip (or another type) switches the editor panel below AND the PDF preview document type to match.
4. Switching back to Invoice restores the Invoice panel + preview.
5. The select is keyboard-focusable and operable.
6. No console errors; no leftover scrollbar/arrow artifacts.
7. (Optional) Temporarily lower the threshold via the `woi_pdf_document_select_select2_threshold` filter (e.g. return 2) to confirm the select2 branch renders a searchable dropdown and items 1–4 still work; then revert.

---

## Self-review notes

- **Spec coverage:** §1 markup → Task 1; §2 behaviour → Task 2; §3 styling → Task 3; §4 cache → Task 4 Steps 1–3; §Testing → Task 1 Step 2, Task 4 Steps 2 & 4.
- **Consistency:** `.document-select` class + `data-document_type` options (Task 1) are exactly what the change handler and ready-sync read (Task 2) and what the CSS targets (Task 3). The hidden `<ul class="document-tabs">` is preserved in Task 1 and hidden in Task 3 — never deleted.
- **Dead-code:** `debounce` (Task 2 Step 1) and `initTabScroll` (Step 3) are removed together; Step 6 greps to confirm no tab-scroll identifiers linger in JS, Task 3 Step 3 does the same for CSS.
