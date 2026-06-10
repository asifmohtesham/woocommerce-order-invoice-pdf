# Nav Consolidation — Design Spec

**Date:** 2026-06-10
**Feature:** Consolidate per-document nav tabs back into the Documents tab with a dropdown selector
**Problem:** `register_document_tab()` injects each of the 6 document types (`Invoice`, `Packing Slip`, `Proforma Invoice`, `Credit Note`, `Receipt`, `Summary of Invoices`) as separate top-level nav tabs via the `woi_pdf_settings_tabs` filter, bringing the nav total to ~12 tabs. This wraps to multiple lines at normal admin widths.
**Solution:** Stop adding document types as top-level tabs. All document settings live inside the **Documents** tab, with a `.wcpdf_document_settings_sections` dropdown selector matching the Plugin 1/2 pattern. The dropdown CSS is already present in `settings-styles.min.css`; the toggle JS is already in `admin.js:60`.

---

## 1. Affected files

| File | Change type |
|------|-------------|
| `includes/Settings.php` | Remove `add_filter('woi_pdf_settings_tabs', …)` from `register_document_tab()` |
| `includes/Settings/SettingsDocuments.php` | Add `output()` method hooked to `woi_pdf_settings_output_documents`; remove dead `init_settings()` placeholder |
| `views/settings-page.php` | Form body uses `has_action` hook check; `$preview_document_type` and preview picker updated |

---

## 2. `includes/Settings.php` — `register_document_tab()`

### Before (lines 1542–1556)

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

### After

```php
public function register_document_tab( \WOI\PDF\Documents\DocumentInterface $document ): void {
    add_action( 'admin_init', function() use ( $document ): void {
        $document->init_settings();
    } );
}
```

The `add_filter('woi_pdf_settings_tabs', …)` block is removed entirely. The `admin_init` registration stays — `init_settings()` must still run so `options.php` can save each document's settings.

---

## 3. `includes/Settings/SettingsDocuments.php`

### Constructor

Add a second `add_action` for the output hook:

```php
public function __construct() {
    add_action( 'woi_pdf_settings_output_documents', array( $this, 'output' ), 10, 2 );
}
```

Remove `add_action( 'admin_init', array( $this, 'init_settings' ) )` — the `init_settings()` method registered the now-unused `woi_pdf_settings_documents` placeholder page.

### Remove `init_settings()` entirely

The method registered a bullet-list overview under `woi_pdf_settings_documents`. That page is never rendered after this change. Remove the whole method.

### New `output( string $section, string $nonce )` method

```php
public function output( string $section, string $nonce ): void {
    $section   = ! empty( $section ) ? sanitize_text_field( $section ) : 'invoice';
    $documents = \WOI_PDF()->documents->get_documents( 'all' );

    // Resolve active document; fall back to first if section is invalid.
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
```

**Saving:** `settings_fields($option_name)` adds the nonce and `option_page` hidden inputs for the selected document's option page (`woi_pdf_documents_settings_invoice`, etc.). After `options.php` saves, it redirects to `$_POST['_wp_http_referer']`, which includes `?tab=documents&section=invoice` — the user stays on the same document's settings.

---

## 4. `views/settings-page.php`

### Form body (lines 68–74)

Replace the hard-coded `settings_fields` / `do_settings_sections` / `submit_button` block with a hook-check:

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

### `$preview_document_type` (lines 35–37)

Replace:
```php
$preview_document_type = isset( $_GET['tab'] ) && 0 === strpos( $current_tab, 'woi_pdf_' )
    ? substr( $current_tab, strlen( 'woi_pdf_' ) )
    : 'invoice';
```

With:
```php
if ( 'documents' === $current_tab && ! empty( $_GET['section'] ) ) {
    $preview_document_type = sanitize_text_field( wp_unslash( $_GET['section'] ) );
} elseif ( isset( $_GET['tab'] ) && 0 === strpos( $current_tab, 'woi_pdf_' ) ) {
    $preview_document_type = substr( $current_tab, strlen( 'woi_pdf_' ) );
} else {
    $preview_document_type = 'invoice';
}
```

The `woi_pdf_` branch is now dead code — `settings-page.php` line 6 resets any tab key not in `$settings_tabs` back to `$default_tab`, so old bookmarked URLs like `?tab=woi_pdf_invoice` redirect to General anyway. The branch can be removed for cleanliness as part of this task.

### Preview document-type picker (lines 100–111)

Remove the `'documents' !== $current_tab` guard so the picker appears on the Documents tab (same as it did on the old individual document tabs):

```php
<?php
$picker_documents = WOI_PDF()->documents->get_documents( 'enabled', 'any' );
?>
<div class="preview-data preview-document-type">
    <p class="current"><span class="current-label"><?php esc_html_e( 'Invoice', 'woocommerce-orders-invoice-pdf' ); ?></span><span class="arrow-down">&#9660;</span></p>
    <ul class="preview-data-option-list" data-input-name="document_type">
        <?php foreach ( $picker_documents as $doc ) : ?>
            <li data-value="<?php echo esc_attr( $doc->get_type() ); ?>"><?php echo esc_html( $doc->get_title() ); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
```

---

## 5. What does NOT change

- `$document->init_settings()` registrations — saving still works for every document type
- Customiser tab and its jQuery UI document sub-tabs (`EditorSettings.php`, `editor.js`, `editor.css`) — completely untouched
- `assets/css/settings-styles.min.css` — `.wcpdf_document_settings_sections` rules already present
- `admin.js:60` — dropdown toggle handler (`$('.wcpdf_document_settings_sections > h2').on('click', …)`) already wired
- `$tab_option_page_map` — kept as-is (used by `editor` tab and other static overrides)

---

## 6. Result

Main nav: **General | Documents | E-Documents | Customiser | Advanced | Upgrade** (6 tabs, no wrapping).

Navigating to Documents tab opens `?tab=documents` → defaults to Invoice settings. Dropdown lets users switch document types without leaving the tab.
