# Horizontal Two-Tier Settings Navigation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the vertical left-nav sidebar in the settings shell with a horizontal two-tier tab bar — main tabs on row 1, document sub-tabs on row 2 (shown only when the Documents tab is active).

**Architecture:** Server-rendered, CSS-driven. `NavModel::build()` returns a structured `array( 'tabs' => [...], 'documents' => [...] )`; `views/settings-page.php` prints two `<nav>` rows; `admin-shell.css` stacks them horizontally and retires the sidebar rules. No new JavaScript.

**Tech Stack:** PHP (NavModel, settings-page.php), vanilla CSS (admin-shell.css), PHPUnit.

**Spec:** `docs/superpowers/specs/2026-06-16-horizontal-nav-design.md`

---

## File map

| File | Change |
|------|--------|
| `includes/Settings/NavModel.php` | `build()` returns `array( 'tabs' => [...], 'documents' => [...] )`; Documents becomes a clickable `tab` |
| `tests/Unit/Settings/NavModelTest.php` | Rewrite assertions for the new return shape |
| `views/settings-page.php` | Replace the `.woi-shell-nav` sidebar with `.woi-shell-tabs` + conditional `.woi-shell-subtabs` |
| `assets/css/admin-shell.css` | Replace body/nav rules (lines ~45–88); rewrite the `≤782px` nav rules (lines ~209–232) |
| `woocommerce-orders-invoice-pdf.php` | Bump version `1.2.0` → `1.3.0` (lines 6 and 24) for LiteSpeed cache bust |

---

## Task 1 — NavModel: structured tabs + documents

**Files:**
- Modify: `includes/Settings/NavModel.php:24-68`
- Test: `tests/Unit/Settings/NavModelTest.php`

- [ ] **Step 1: Rewrite the test file for the new return shape**

Replace the entire body of `tests/Unit/Settings/NavModelTest.php` with:

```php
<?php
namespace WOI\PDF\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use WOI\PDF\Settings\NavModel;

class NavModelTest extends TestCase {

	private function tabs(): array {
		return array(
			'home'      => array( 'title' => 'Home', 'preview_states' => 1 ),
			'general'   => array( 'title' => 'General', 'preview_states' => 3 ),
			'documents' => array( 'title' => 'Documents', 'preview_states' => 3 ),
			'debug'     => array( 'title' => 'Advanced', 'preview_states' => 1 ),
		);
	}

	private function documents(): array {
		return array(
			array( 'type' => 'invoice', 'title' => 'Invoice', 'enabled' => true ),
			array( 'type' => 'packing-slip', 'title' => 'Packing Slip', 'enabled' => false ),
		);
	}

	public function test_build_returns_tabs_and_documents_keys(): void {
		$nav = NavModel::build( $this->tabs(), $this->documents(), 'home', '' );
		$this->assertArrayHasKey( 'tabs', $nav );
		$this->assertArrayHasKey( 'documents', $nav );
	}

	public function test_main_tabs_in_source_order_all_kind_tab(): void {
		$nav = NavModel::build( $this->tabs(), $this->documents(), 'home', '' );
		$this->assertSame( array( 'home', 'general', 'documents', 'debug' ), array_column( $nav['tabs'], 'id' ) );
		$this->assertSame( array( 'tab' ), array_values( array_unique( array_column( $nav['tabs'], 'kind' ) ) ) );
	}

	public function test_documents_is_a_clickable_tab(): void {
		$nav      = NavModel::build( $this->tabs(), $this->documents(), 'documents', 'invoice' );
		$by_id    = array_combine( array_column( $nav['tabs'], 'id' ), $nav['tabs'] );
		$docs_tab = $by_id['documents'];
		$this->assertSame( 'tab', $docs_tab['kind'] );
		$this->assertSame( 'documents', $docs_tab['tab'] );
		$this->assertSame( '', $docs_tab['section'] );
		$this->assertTrue( $docs_tab['active'] );
	}

	public function test_documents_tab_inactive_on_other_tabs(): void {
		$nav   = NavModel::build( $this->tabs(), $this->documents(), 'home', '' );
		$by_id = array_combine( array_column( $nav['tabs'], 'id' ), $nav['tabs'] );
		$this->assertFalse( $by_id['documents']['active'] );
	}

	public function test_document_subitems_carry_enabled_and_active(): void {
		$nav   = NavModel::build( $this->tabs(), $this->documents(), 'documents', 'invoice' );
		$by_id = array_combine( array_column( $nav['documents'], 'id' ), $nav['documents'] );
		$this->assertTrue( $by_id['invoice']['enabled'] );
		$this->assertTrue( $by_id['invoice']['active'] );
		$this->assertFalse( $by_id['packing-slip']['enabled'] );
		$this->assertFalse( $by_id['packing-slip']['active'] );
	}

	public function test_plain_tab_active(): void {
		$nav   = NavModel::build( $this->tabs(), $this->documents(), 'debug', '' );
		$by_id = array_combine( array_column( $nav['tabs'], 'id' ), $nav['tabs'] );
		$this->assertTrue( $by_id['debug']['active'] );
	}

	public function test_string_tab_title_supported(): void {
		$nav = NavModel::build( array( 'general' => 'General' ), array(), 'general', '' );
		$this->assertSame( 'General', $nav['tabs'][0]['label'] );
	}

	public function test_documents_key_absent_yields_empty_documents(): void {
		$nav = NavModel::build( array( 'general' => 'General' ), $this->documents(), 'general', '' );
		$this->assertCount( 1, $nav['tabs'] );
		$this->assertSame( array(), $nav['documents'] );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit/Settings/NavModelTest.php`
Expected: FAIL — `build()` still returns a flat list, so `assertArrayHasKey('tabs', …)` and `array_column($nav['tabs'], …)` fail.

> Note (from project memory): PHPUnit dies silently without `-d auto_prepend_file=tests/bootstrap.php`. Always include it.

- [ ] **Step 3: Rewrite `NavModel::build()`**

In `includes/Settings/NavModel.php`, replace the method body (lines 24–68, from `public static function build` through the `return $items;` line) with:

```php
	public static function build( array $settings_tabs, array $documents, string $current_tab, string $current_section ): array {
		$tabs      = array();
		$doc_items = array();

		foreach ( $settings_tabs as $tab_key => $tab ) {
			$label = is_array( $tab ) ? (string) ( $tab['title'] ?? $tab_key ) : (string) $tab;

			if ( 'documents' === $tab_key ) {
				$tabs[] = array(
					'kind'    => 'tab',
					'id'      => 'documents',
					'label'   => $label,
					'tab'     => 'documents',
					'section' => '',
					'enabled' => null,
					'active'  => ( 'documents' === $current_tab ),
				);

				foreach ( $documents as $document ) {
					$doc_items[] = array(
						'kind'    => 'document',
						'id'      => (string) $document['type'],
						'label'   => (string) $document['title'],
						'tab'     => 'documents',
						'section' => (string) $document['type'],
						'enabled' => ! empty( $document['enabled'] ),
						'active'  => ( 'documents' === $current_tab && $current_section === $document['type'] ),
					);
				}

				continue;
			}

			$tabs[] = array(
				'kind'    => 'tab',
				'id'      => (string) $tab_key,
				'label'   => $label,
				'tab'     => (string) $tab_key,
				'section' => '',
				'enabled' => null,
				'active'  => ( $current_tab === $tab_key ),
			);
		}

		return array(
			'tabs'      => $tabs,
			'documents' => $doc_items,
		);
	}
```

Also update the docblock `@return` line (above the method, ~line 22) to:

```php
	 * @return array array( 'tabs' => list<item>, 'documents' => list<item> ) where each item is
	 *               array( 'kind', 'id', 'label', 'tab', 'section', 'enabled', 'active' )
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php tests/Unit/Settings/NavModelTest.php`
Expected: PASS (8 tests).

- [ ] **Step 5: Run the full unit suite to catch other NavModel consumers**

Run: `./vendor/bin/phpunit -d auto_prepend_file=tests/bootstrap.php`
Expected: PASS. If `ServiceWiringTest` or any other test references `NavModel::build` shape, it will surface here — only `views/settings-page.php` consumes it at runtime (handled in Task 2).

- [ ] **Step 6: Commit**

```bash
git add includes/Settings/NavModel.php tests/Unit/Settings/NavModelTest.php
git commit -m "refactor: NavModel returns structured tabs + documents for horizontal nav"
```

---

## Task 2 — View: two horizontal nav rows

**Files:**
- Modify: `views/settings-page.php:31-40` (breadcrumb), `:23-28` (icons), `:70-181` (nav rows)

- [ ] **Step 1: Fix the breadcrumb loop for the structured nav array**

The breadcrumb (lines 31–40) iterates the old flat `$nav_items`. With the new shape it must look in `documents` first (so it can render `Documents › Invoice`), then fall back to the active main tab. Replace:

```php
// Breadcrumb: active nav item label (documents get the group label prefix).
$breadcrumb = array();
foreach ( $nav_items as $item ) {
	if ( ! empty( $item['active'] ) ) {
		if ( 'document' === $item['kind'] ) {
			$breadcrumb[] = __( 'Documents', 'woocommerce-orders-invoice-pdf' );
		}
		$breadcrumb[] = $item['label'];
		break;
	}
}
```

with:

```php
// Breadcrumb: active document (prefixed with the group label) or active main tab.
$breadcrumb = array();
foreach ( $nav_items['documents'] as $doc ) {
	if ( ! empty( $doc['active'] ) ) {
		$breadcrumb[] = __( 'Documents', 'woocommerce-orders-invoice-pdf' );
		$breadcrumb[] = $doc['label'];
		break;
	}
}
if ( empty( $breadcrumb ) ) {
	foreach ( $nav_items['tabs'] as $item ) {
		if ( ! empty( $item['active'] ) ) {
			$breadcrumb[] = $item['label'];
			break;
		}
	}
}
```

- [ ] **Step 2: Add a Documents icon to the `$nav_icons` map**

In `views/settings-page.php`, the `$nav_icons` array (lines 23–28) currently has no `documents` key, so the Documents tab would fall back. Add an explicit entry for clarity. Replace:

```php
$nav_icons = array(
	'home'    => 'dashicons-admin-home',
	'general' => 'dashicons-admin-settings',
	'editor'  => 'dashicons-admin-customizer',
	'debug'   => 'dashicons-admin-tools',
);
```

with:

```php
$nav_icons = array(
	'home'      => 'dashicons-admin-home',
	'general'   => 'dashicons-admin-settings',
	'documents' => 'dashicons-media-document',
	'editor'    => 'dashicons-admin-customizer',
	'debug'     => 'dashicons-admin-tools',
);
```

- [ ] **Step 3: Replace the `<nav class="woi-shell-nav">` block with two horizontal rows**

In `views/settings-page.php`, replace the entire `<nav class="woi-shell-nav">…</nav>` block (lines 71–106) with:

```php
		<nav class="woi-shell-tabs" aria-label="<?php esc_attr_e( 'PDF Invoices settings', 'woocommerce-orders-invoice-pdf' ); ?>">
			<?php foreach ( $nav_items['tabs'] as $item ) :
				$url = add_query_arg(
					array_filter( array(
						'page'    => 'woi_pdf_options_page',
						'tab'     => $item['tab'],
						'section' => $item['section'],
					) ),
					admin_url( 'admin.php' )
				);
				$classes = array( 'woi-tab' );
				if ( $item['active'] ) {
					$classes[] = 'active';
				}
			?>
				<a class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" href="<?php echo esc_url( $url ); ?>" title="<?php echo esc_attr( $item['label'] ); ?>">
					<span class="dashicons <?php echo esc_attr( $nav_icons[ $item['id'] ] ?? 'dashicons-media-document' ); ?>"></span>
					<span class="woi-tab-label"><?php echo esc_html( $item['label'] ); ?></span>
				</a>
			<?php endforeach; ?>
		</nav>

		<?php if ( 'documents' === $current_tab && ! empty( $nav_items['documents'] ) ) : ?>
		<nav class="woi-shell-subtabs" aria-label="<?php esc_attr_e( 'Document types', 'woocommerce-orders-invoice-pdf' ); ?>">
			<?php foreach ( $nav_items['documents'] as $doc ) :
				$url = add_query_arg(
					array(
						'page'    => 'woi_pdf_options_page',
						'tab'     => $doc['tab'],
						'section' => $doc['section'],
					),
					admin_url( 'admin.php' )
				);
				$classes = array( 'woi-subtab' );
				if ( $doc['active'] ) {
					$classes[] = 'active';
				}
				if ( empty( $doc['enabled'] ) ) {
					$classes[] = 'woi-nav-disabled-doc';
				}
			?>
				<a class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" href="<?php echo esc_url( $url ); ?>" title="<?php echo esc_attr( $doc['label'] ); ?>">
					<span class="woi-nav-dot" aria-hidden="true"></span>
					<span class="woi-subtab-label"><?php echo esc_html( $doc['label'] ); ?></span>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php endif; ?>
```

Leave the `<main class="woi-shell-content">…</main>` block (lines 108–180) and the closing `</div>` of `.woi-shell-body` exactly as they are.

- [ ] **Step 4: Lint the PHP file**

Run: `php -l views/settings-page.php`
Expected: `No syntax errors detected in views/settings-page.php`

- [ ] **Step 5: Commit**

```bash
git add views/settings-page.php
git commit -m "feat: render horizontal two-tier nav rows in settings shell"
```

---

## Task 3 — CSS: horizontal tab styling

**Files:**
- Modify: `assets/css/admin-shell.css:45-88` (body/nav block)
- Modify: `assets/css/admin-shell.css:209-232` (mobile nav rules)

- [ ] **Step 1: Replace the body + sidebar-nav rules**

In `assets/css/admin-shell.css`, replace the block from `/* --- Body: nav + content --- */` (line 45) through `.woi-shell-content { flex: 1; min-width: 0; padding: 16px; }` (line 88) with:

```css
/* --- Body: stacked horizontal nav rows + content --- */
.woi-shell-body { display: block; min-height: 70vh; }

/* Row 1: main tabs */
.woi-shell-tabs {
	display: flex;
	align-items: stretch;
	gap: 2px;
	background: #fff;
	border-bottom: 1px solid #dcdcde;
	padding: 0 8px;
	overflow-x: auto;
}
.woi-tab {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 12px 14px;
	text-decoration: none;
	color: #1d2327;
	white-space: nowrap;
	border-bottom: 2px solid transparent;
}
.woi-tab:hover { color: #135e96; }
.woi-tab:focus { box-shadow: none; outline: none; }
.woi-tab.active {
	color: #135e96;
	font-weight: 600;
	border-bottom-color: #2271b1;
}
.woi-tab .dashicons { width: 18px; height: 18px; font-size: 18px; }

/* Row 2: document sub-tabs (only on the Documents tab) */
.woi-shell-subtabs {
	display: flex;
	align-items: stretch;
	gap: 2px;
	background: #f6f7f7;
	border-bottom: 1px solid #dcdcde;
	padding: 0 8px;
	overflow-x: auto;
}
.woi-subtab {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	text-decoration: none;
	color: #1d2327;
	white-space: nowrap;
	border-bottom: 2px solid transparent;
}
.woi-subtab:hover { color: #135e96; }
.woi-subtab.active {
	color: #135e96;
	font-weight: 600;
	border-bottom-color: #2271b1;
}

/* Green enabled-dot, shared by sub-tabs */
.woi-nav-dot {
	width: 8px;
	height: 8px;
	border-radius: 50%;
	background: #00a32a;
	flex: 0 0 8px;
}
.woi-nav-disabled-doc { color: #787c82; }
.woi-nav-disabled-doc .woi-nav-dot { background: transparent; border: 1px solid #a7aaad; }

.woi-shell-content { min-width: 0; padding: 16px; }
```

- [ ] **Step 2: Replace the mobile nav rules**

In the `@media screen and (max-width: 782px)` block, replace the comment + sidebar rules (lines 209–232, from the `/* icon rail leaves documents… */` comment through `.woi-nav-document a { padding-left: 12px; }`) with:

```css
	/* nav rows are already horizontal + scrollable; just tighten padding on touch */
	.woi-shell-tabs,
	.woi-shell-subtabs { padding: 0 4px; }
	.woi-tab { padding: 10px 12px; }
	.woi-subtab { padding: 8px 10px; }
```

Leave the header rules (`.woi-shell-header { top: 46px; … }` and below) and the legacy form-table grid rule untouched.

- [ ] **Step 3: Sanity-check no orphaned selectors remain**

Run: `grep -nE '\.woi-shell-nav|\.woi-nav-item|\.woi-nav-heading|\.woi-nav-document|\.woi-nav-label' assets/css/admin-shell.css`
Expected: no output (all retired sidebar selectors gone). `.woi-nav-dot` and `.woi-nav-disabled-doc` are intentionally kept.

- [ ] **Step 4: Commit**

```bash
git add assets/css/admin-shell.css
git commit -m "feat: horizontal two-tier tab styling, retire sidebar nav CSS"
```

---

## Task 4 — Version bump + manual verification

**Files:**
- Modify: `woocommerce-orders-invoice-pdf.php:6` and `:24`

- [ ] **Step 1: Bump the version in both places**

In `woocommerce-orders-invoice-pdf.php` line 6, change:

```php
 * Version:              1.2.0
```

to:

```php
 * Version:              1.3.0
```

And line 24, change:

```php
	public string $version     = '1.2.0';
```

to:

```php
	public string $version     = '1.3.0';
```

- [ ] **Step 2: Commit**

```bash
git add woocommerce-orders-invoice-pdf.php
git commit -m "chore: bump version to 1.3.0 for horizontal nav CSS"
```

- [ ] **Step 3: Manual browser verification**

Hard-refresh the settings page (`Ctrl+Shift+R`, or clear LiteSpeed cache) and verify:

1. **Main row** shows 5 horizontal tabs with icons: Home, General, Documents, Customiser, Advanced.
2. **Sub-row hidden** on Home / General / Customiser / Advanced.
3. **Click Documents** → lands on Invoice; sub-row appears with the 6 document types; Invoice is active (underline accent).
4. **Green dots** show on enabled documents; disabled docs are greyed with a hollow dot but still clickable.
5. **Active accents** — active main tab and active sub-tab both show the 2px `#2271b1` bottom border + bolder text.
6. **Breadcrumb** in the dark header reads `PDF Invoices › Documents › Invoice`.
7. **Click a disabled doc** (e.g. Credit Note) → navigates to it; sub-row stays, that doc becomes active.
8. **Narrow the window** → both rows scroll horizontally rather than wrapping; header wraps as before.
9. **Save + Preview** controls and the content/form area below are unchanged and still work.

- [ ] **Step 4: Push**

```bash
git push
```

---

## Self-review notes

- **Spec coverage:** §1 data model → Task 1; §2 view → Task 2; §3 styling → Task 3; §4 cache → Task 4; §Testing → Task 1 Steps 1–5 + Task 4 Step 3.
- **Type consistency:** `build()` returns `array( 'tabs' => …, 'documents' => … )` in Task 1; consumed as `$nav_items['tabs']` / `$nav_items['documents']` in Task 2. Item keys (`kind/id/label/tab/section/enabled/active`) are identical across model, tests, and view.
- **Shared CSS:** `.woi-nav-dot` and `.woi-nav-disabled-doc` are deliberately retained (used by `.woi-subtab`); all other `.woi-nav-*` / `.woi-shell-nav` selectors are removed (Task 3 Step 3 verifies).
