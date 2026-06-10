# Nav Consolidation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Consolidate the ~6 per-document top-level nav tabs back into the Documents tab with a dropdown selector, dropping the main nav from ~12 tabs to 6.

**Architecture:** Three coordinated PHP changes — strip the `add_filter` from `register_document_tab()` in Settings.php, replace SettingsDocuments with a proper `output()` method hooked to `woi_pdf_settings_output_documents`, and update the settings-page view to dispatch that hook instead of hard-coding `settings_fields`. CSS and JS for the dropdown are already present in `settings-styles.min.css` and `admin.js`; no asset changes needed.

**Tech Stack:** PHP 7.4+, WordPress Settings API (`settings_fields`, `do_settings_sections`, `submit_button`, `add_query_arg`). PHPUnit 9.5 + Brain\Monkey for unit tests.

**Spec:** `docs/superpowers/specs/2026-06-10-nav-consolidation-design.md`

---

## File map

| File | Change |
|------|--------|
| `includes/Settings.php` | Remove `add_filter('woi_pdf_settings_tabs', …)` from `register_document_tab()` |
| `includes/Settings/SettingsDocuments.php` | Full rewrite: drop `init_settings()`, new `output()` hooked to `woi_pdf_settings_output_documents` |
| `views/settings-page.php` | Hook-check form body; updated `$preview_document_type`; preview picker always shown; dead `woi_pdf_*` branch removed |
| `woocommerce-orders-invoice-pdf.php` | Bump version `1.0.3` → `1.0.4` |

---

## Task 1 — Settings.php: stop injecting document types into the nav

**Files:**
- Modify: `includes/Settings.php:1542–1556`

Background: `register_document_tab()` is called once per document type from the plugin entry point. Currently it both adds a top-level nav tab (via `add_filter('woi_pdf_settings_tabs', …)`) **and** schedules `$document->init_settings()` on `admin_init`. After this task, it only does the latter — the nav injection is gone, so only the base 6 tabs (General, Documents, E-Documents, Customiser, Advanced, Upgrade) remain.

- [ ] **Step 1: Replace the method body in `includes/Settings.php`**

Find the full method starting at line 1542:

```php
public function register_document_tab( \WOI\PDF\Documents\DocumentInterface $document ): void {
    $tab_key = 'woi_pdf_' . $document->get_type();

    add_filter( 'woi_pdf_settings_tabs', function( array $tabs ) use ( $document, $tab_key ): array {
        $tabs[ $tab_key ] = array(
            'title'          => $document->get_title(),
            'preview_states' => 3,
        );
        return $tabs;
    } );

    add_action( 'admin_init', function() use ( $document ): void {
        $document->init_settings();
    } );
}
```

Replace with:

```php
public function register_document_tab( \WOI\PDF\Documents\DocumentInterface $document ): void {
    add_action( 'admin_init', function() use ( $document ): void {
        $document->init_settings();
    } );
}
```

- [ ] **Step 2: Run the existing test suite to confirm no regressions**

```bash
vendor/bin/phpunit
```

Expected: all tests green. The existing tests cover document interfaces and pure logic — none touch Settings.php, so this should be a clean pass.

- [ ] **Step 3: Commit**

```bash
git add includes/Settings.php
git commit -m "refactor: remove per-document nav-tab injection from register_document_tab"
```

---

## Task 2 — SettingsDocuments.php: replace placeholder with real `output()` method

**Files:**
- Modify: `includes/Settings/SettingsDocuments.php` (full rewrite)

Background: The current file registers a `woi_pdf_settings_documents` settings page that just renders a bullet-list of links. That page is never rendered after this change (the Documents tab will fire the `woi_pdf_settings_output_documents` hook instead). The new `output()` method renders the `.wcpdf_document_settings_sections` dropdown (CSS already in `settings-styles.min.css`, toggle JS already in `admin.js:60`) then calls `settings_fields` / `do_settings_sections` / `submit_button` for the selected document's option page.

The `output()` method reads `?section=` from its first argument (e.g., `invoice`, `packing-slip`). It resolves the matching document; if the section is missing or invalid it falls back to the first registered document. Each `<li>` in the dropdown links to `?tab=documents&section={type}`.

- [ ] **Step 1: Rewrite `includes/Settings/SettingsDocuments.php` in full**

```php
<?php
namespace WOI\PDF\Settings;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( '\\WOI\\PDF\\Settings\\SettingsDocuments' ) ) :

class SettingsDocuments {

	protected static ?self $_instance = null;

	public static function instance(): self {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function __construct() {
		add_action( 'woi_pdf_settings_output_documents', array( $this, 'output' ), 10, 2 );
	}

	public function output( string $section, string $nonce ): void {
		$section   = ! empty( $section ) ? sanitize_text_field( $section ) : 'invoice';
		$documents = \WOI_PDF()->documents->get_documents( 'all' );

		$active = null;
		foreach ( $documents as $doc ) {
			if ( $doc->get_type() === $section ) {
				$active = $doc;
				break;
			}
		}
		if ( empty( $active ) ) {
			$active  = reset( $documents );
			$section = $active ? $active->get_type() : 'invoice';
		}

		$option_name  = 'woi_pdf_documents_settings_' . $section;
		$active_title = wp_strip_all_tags( $active->get_title() );
		if ( '' === trim( $active_title ) ) {
			$active_title = '[' . __( 'untitled', 'woocommerce-orders-invoice-pdf' ) . ']';
		}
		?>
		<div class="wcpdf_document_settings_sections">
			<span><?php esc_html_e( 'Choose document', 'woocommerce-orders-invoice-pdf' ); ?></span>
			<h2><?php echo esc_html( $active_title ); ?><span class="arrow-down">&#9660;</span></h2>
			<ul>
				<?php foreach ( $documents as $doc ) :
					if ( $doc->get_type() === $section ) {
						continue;
					}
					$title = wp_strip_all_tags( $doc->get_title() );
					if ( '' === trim( $title ) ) {
						$title = '[' . __( 'untitled', 'woocommerce-orders-invoice-pdf' ) . ']';
					}
				?>
				<li>
					<a href="<?php echo esc_url( add_query_arg(
						array( 'tab' => 'documents', 'section' => $doc->get_type() ),
						admin_url( 'admin.php?page=woi_pdf_options_page' )
					) ); ?>">
						<?php echo esc_html( $title ); ?>
					</a>
				</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
		settings_fields( $option_name );
		do_settings_sections( $option_name );
		submit_button();
	}
}

endif;
```

- [ ] **Step 2: Run the existing test suite**

```bash
vendor/bin/phpunit
```

Expected: all tests green (no test covers SettingsDocuments directly).

- [ ] **Step 3: Commit**

```bash
git add includes/Settings/SettingsDocuments.php
git commit -m "feat: replace SettingsDocuments placeholder with dropdown output() method"
```

---

## Task 3 — settings-page.php: hook-check form + preview fixes

**Files:**
- Modify: `views/settings-page.php`

Three independent changes in the same file, applied together:

**A. Form body** (lines 68–74) — replace hard-coded `settings_fields` / `do_settings_sections` / `submit_button` with a hook dispatcher. For tabs that have a `woi_pdf_settings_output_{tab}` action (currently only `documents`), the action is fired with `($current_section, $nonce)`; all other tabs fall through to the existing defaults.

**B. `$preview_document_type`** (lines 35–37) — the old logic checked for `woi_pdf_*` tab keys (now gone). Replace it with a check for `?section=` on the `documents` tab; keep the `woi_pdf_*` branch as dead-but-harmless for any stale code paths.

**C. Preview picker** (lines 100–111) — the `'documents' !== $current_tab` guard was added when the Documents tab was only an overview with no preview. Now it has real document settings, so the picker should appear. Remove the guard entirely.

**D. Dead `woi_pdf_*` option-page branch** (lines 22–25) — the `woi_pdf_*` tab keys can no longer reach `settings-page.php` (the tab-validation guard at line 6 resets unknown keys to `$default_tab`). Remove the branch for clarity.

- [ ] **Step 1: Apply all four changes to `views/settings-page.php`**

The full updated file (show every changed region in context):

**Lines 20–30 — option page mapping:** Remove the now-dead `woi_pdf_*` branch.

Find:
```php
if ( ! $is_upgrade ) {
	if ( isset( $tab_option_page_map[ $current_tab ] ) ) {
		$option_page = $tab_option_page_map[ $current_tab ];
	} elseif ( 0 === strpos( $current_tab, 'woi_pdf_' ) ) {
		// Document tabs: 'woi_pdf_{type}' → 'woi_pdf_documents_settings_{type}'.
		$option_page = 'woi_pdf_documents_settings_' . substr( $current_tab, strlen( 'woi_pdf_' ) );
	} else {
		// Static tabs: 'general', 'debug', 'edi', … → 'woi_pdf_settings_{tab}'.
		$option_page = 'woi_pdf_settings_' . $current_tab;
	}
}
```

Replace with:
```php
if ( ! $is_upgrade ) {
	if ( isset( $tab_option_page_map[ $current_tab ] ) ) {
		$option_page = $tab_option_page_map[ $current_tab ];
	} else {
		$option_page = 'woi_pdf_settings_' . $current_tab;
	}
}
```

---

**Lines 35–37 — `$preview_document_type`:**

Find:
```php
$preview_document_type = isset( $_GET['tab'] ) && 0 === strpos( $current_tab, 'woi_pdf_' )
	? substr( $current_tab, strlen( 'woi_pdf_' ) )
	: 'invoice';
```

Replace with:
```php
if ( 'documents' === $current_tab && ! empty( $_GET['section'] ) ) {
	$preview_document_type = sanitize_text_field( wp_unslash( $_GET['section'] ) );
} else {
	$preview_document_type = 'invoice';
}
```

---

**Lines 68–74 — form body:**

Find:
```php
<form method="post" action="options.php" id="woi-pdf-settings" class="<?php echo esc_attr( $current_tab ); ?>">
	<input type="hidden" name="tab" value="<?php echo esc_attr( $current_tab ); ?>">
	<?php do_action( 'woi_pdf_before_settings', $current_tab, $nonce ); ?>
	<?php settings_fields( $option_page ); ?>
	<?php do_settings_sections( $option_page ); ?>
	<?php submit_button(); ?>
</form>
```

Replace with:
```php
<form method="post" action="options.php" id="woi-pdf-settings" class="<?php echo esc_attr( $current_tab ); ?>">
	<input type="hidden" name="tab" value="<?php echo esc_attr( $current_tab ); ?>">
	<?php
	do_action( 'woi_pdf_before_settings', $current_tab, $nonce );
	$current_section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : '';
	if ( has_action( "woi_pdf_settings_output_{$current_tab}" ) ) {
		do_action( "woi_pdf_settings_output_{$current_tab}", $current_section, $nonce );
	} else {
		settings_fields( $option_page );
		do_settings_sections( $option_page );
		submit_button();
	}
	?>
</form>
```

---

**Lines 100–111 — preview picker:**

Find:
```php
<?php if ( 'documents' !== $current_tab ) :
	$documents = WOI_PDF()->documents->get_documents( 'enabled', 'any' );
?>
<div class="preview-data preview-document-type">
	<p class="current"><span class="current-label"><?php esc_html_e( 'Invoice', 'woocommerce-orders-invoice-pdf' ); ?></span><span class="arrow-down">&#9660;</span></p>
	<ul class="preview-data-option-list" data-input-name="document_type">
		<?php foreach ( $documents as $doc ) : ?>
			<li data-value="<?php echo esc_attr( $doc->get_type() ); ?>"><?php echo esc_html( $doc->get_title() ); ?></li>
		<?php endforeach; ?>
	</ul>
</div>
<?php endif; ?>
```

Replace with:
```php
<?php $picker_documents = WOI_PDF()->documents->get_documents( 'enabled', 'any' ); ?>
<div class="preview-data preview-document-type">
	<p class="current"><span class="current-label"><?php esc_html_e( 'Invoice', 'woocommerce-orders-invoice-pdf' ); ?></span><span class="arrow-down">&#9660;</span></p>
	<ul class="preview-data-option-list" data-input-name="document_type">
		<?php foreach ( $picker_documents as $doc ) : ?>
			<li data-value="<?php echo esc_attr( $doc->get_type() ); ?>"><?php echo esc_html( $doc->get_title() ); ?></li>
		<?php endforeach; ?>
	</ul>
</div>
```

- [ ] **Step 2: Run the existing test suite**

```bash
vendor/bin/phpunit
```

Expected: all tests green.

- [ ] **Step 3: Commit**

```bash
git add views/settings-page.php
git commit -m "feat: route Documents tab through woi_pdf_settings_output_documents hook"
```

---

## Task 4 — Version bump + browser verification

**Files:**
- Modify: `woocommerce-orders-invoice-pdf.php:24`

- [ ] **Step 1: Bump version to bust LiteSpeed cache**

Find:
```php
public string $version     = '1.0.3';
```

Replace with:
```php
public string $version     = '1.0.4';
```

- [ ] **Step 2: Commit**

```bash
git add woocommerce-orders-invoice-pdf.php
git commit -m "fix: bump to 1.0.4 to bust LiteSpeed cache for nav consolidation"
```

- [ ] **Step 3: Hard-refresh and run the browser verification checklist**

Clear the LiteSpeed cache (WP admin toolbar → LiteSpeed → Purge All) or use `Ctrl+Shift+R` in Firefox.

Open `WooCommerce → PDF Settings` and verify each item:

**Nav bar**
- [ ] Exactly 6 tabs visible: General | Documents | E-Documents | Customiser | Advanced | Upgrade
- [ ] No second line of tabs, no wrapping

**Documents tab — default load (`?tab=documents`, no section)**
- [ ] Page loads without PHP error
- [ ] "Choose document" dropdown is present at the top of the settings form
- [ ] `h2` inside the dropdown shows "Invoice" (the default)
- [ ] Settings fields for Invoice are rendered below the dropdown
- [ ] Save button is present

**Documents tab — switch document via dropdown**
- [ ] Click the `h2` → the `<ul>` dropdown appears showing all other document types
- [ ] Click "Packing Slip" → navigates to `?tab=documents&section=packing-slip`
- [ ] `h2` now shows "Packing Slip"
- [ ] Settings fields for Packing Slip are rendered
- [ ] "Invoice" appears in the dropdown list (the other documents, not the active one)

**Documents tab — save settings**
- [ ] Change a setting on the Invoice section (e.g., toggle "Attach to email")
- [ ] Click Save Changes
- [ ] Page reloads with `settings-updated=true` and returns to `?tab=documents&section=invoice`
- [ ] The changed setting is persisted

**Documents tab — preview**
- [ ] Preview panel appears (Documents tab has `preview_states: 3`)
- [ ] Preview shows an Invoice PDF by default
- [ ] Document-type picker in the preview sidebar is visible
- [ ] Clicking "Packing Slip" in the preview sidebar switches the preview without reloading the page

**Customiser tab**
- [ ] Customiser tab still shows all 6 document sub-tabs (Invoice, Packing Slip, etc.) inside the jQuery UI tab bar — those are untouched
- [ ] Tab scroll arrows still work (from previous implementation)

**Other tabs**
- [ ] General, E-Documents, Advanced, Upgrade tabs load normally with their own settings
- [ ] No JavaScript console errors on any tab

- [ ] **Step 4: Push**

```bash
git push
```
