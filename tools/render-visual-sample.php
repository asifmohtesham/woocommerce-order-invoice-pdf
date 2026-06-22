<?php
/**
 * Local visual-document render harness (no WordPress, no deploy).
 *
 * Merges sample data into the visual invoice markup, wraps it with
 * templates/_visual/visual-document.css, and renders to PDF via the vendored
 * mPDF — mirroring MpdfMaker's config. Lets us iterate on the redesign and
 * rasterise the result (see tools/rasterize.py) without a live site.
 *
 * Usage:  php tools/render-visual-sample.php [accent] [header] [density] [arabic] [thumbs]
 *   accent  = navy|red|mono     (default navy)
 *   header  = center|left       (default center)
 *   density = comfortable|compact (default comfortable)
 *   arabic  = on|off            (default on)
 *   thumbs  = on|off            (default on)
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
require dirname( __DIR__ ) . '/vendor/autoload.php';
require dirname( __DIR__ ) . '/vendor/strauss/autoload.php';

$accent  = $argv[1] ?? 'navy';
$header  = $argv[2] ?? 'center';
$density = $argv[3] ?? 'comfortable';
$arabic  = $argv[4] ?? 'on';
$thumbs  = $argv[5] ?? 'on';
$borders = $argv[6] ?? 'off';
$stripes = $argv[7] ?? 'off';

$css_path = dirname( __DIR__ ) . '/templates/_visual/visual-document.css';
$css = file_get_contents( $css_path );
// Mirror the wrapper: append the PHP option override (mPDF ignores the
// data-attr selectors, so this flat-selector override is authoritative).
if ( function_exists( 'woi_pdf_visual_options_css' ) ) {
    $css .= "\n" . woi_pdf_visual_options_css( array(
        'accent' => $accent, 'header' => $header, 'density' => $density,
        'arabic' => $arabic, 'thumbs' => $thumbs, 'font' => 'grotesque',
        'borders' => $borders, 'stripes' => $stripes,
    ) );
}

$starter = file_get_contents( dirname( __DIR__ ) . '/assets/visual-editor/starter-invoice.html' );

/* ---- sample token values (mirror what TemplateTokens emits live) ---- */
$logo = '<div style="font-size:15pt;font-weight:bold;color:#140858;letter-spacing:.04em">MILANO</div>'
      . '<div style="font-size:7pt;letter-spacing:.22em;color:#8A8378">LEATHER</div>';

// Native mPDF QR needs the mpdf/qrcode package; emit it only when present, else
// a styled placeholder slot (mirrors the {{qr_code}} token's safe degrade).
if ( class_exists( '\WOI\PDF\Vendor\Mpdf\QrCode\QrCode' ) || class_exists( '\Mpdf\QrCode\QrCode' ) ) {
    $qr = '<barcode code="https://milanoleather.ae/verify/INV-2026-0237" type="QR" error="M" size="0.5" disableborder="1" />';
} else {
    $qr = '<div class="woi-qr-placeholder">QR</div>';
}

$line_items = woi_sample_line_items();
$totals     = woi_sample_totals();
$bank       = woi_sample_bank();

$tokens = array(
    '{{logo}}'              => $logo,
    '{{shop_name}}'         => 'Milano Leather Trading LLC',
    '{{shop_address}}'      => 'Al Buteen, Office 12<br>Deira, Dubai — 112247<br>United Arab Emirates',
    '{{shop_name_ar}}'      => 'شركة ميلانو لتجارة الجلود ذ.م.م',
    '{{shop_address_ar}}'   => 'البطين، مكتب ١٢<br>ديرة، دبي — ١١٢٢٤٧<br>الإمارات العربية المتحدة',
    '{{document_title}}'     => 'TAX INVOICE',
    '{{document_title_ar}}'  => 'فاتورة ضريبية',
    '{{trn}}'                => '100579920800003',
    '{{shop_phone}}'         => '+971 4 252 6744',
    '{{shop_email}}'         => 'info@milanoleather.ae',
    '{{shop_website}}'       => 'www.milanoleather.ae',
    '{{invoice_number}}'     => 'INV-2026-0237',
    '{{invoice_date}}'       => '21 Jun 2026',
    '{{order_number}}'       => '#237',
    '{{payment_method}}'     => 'Bank Transfer',
    '{{billing_address}}'    => '<span class="woi-party-name">Nesto Hypermarket LLC</span><br>Branch — Burjnahar, Deira<br>Dubai, United Arab Emirates',
    '{{recipient_trn}}'      => '<div class="woi-party-trn">TRN&nbsp; 100123456700003</div>',
    '{{shipping_address}}'   => '<span class="woi-party-name">Nesto Hypermarket LLC</span><br>Burjnahar, Deira<br>Dubai, United Arab Emirates<br>+971 4 000 0000',
    '{{line_items}}'         => $line_items,
    '{{totals}}'             => $totals,
    '{{bank_details}}'       => $bank,
    '{{amount_words}}'       => 'UAE Dirham One Thousand Seven Hundred Ten only.',
    '{{qr_code}}'            => $qr,
);

$body = strtr( $starter, $tokens );
$body = preg_replace( '/\{\{[^}]*\}\}/', '', $body );

// Running page-footer (mirror TemplateTokens::running_footer) — registered before
// content so it applies from page 1. Pass argv[6]=2page to force a second page.
$footer = '<htmlpagefooter name="woiFooter"><div class="woi-footer woi-running-footer">'
    . '<span>Milano Leather Trading LLC</span> <span class="woi-dot">•</span> '
    . '<span>TRN 100579920800003</span> <span class="woi-dot">•</span> '
    . '<span>www.milanoleather.ae</span> <span class="woi-dot">•</span> '
    . '<span><span class="woi-lbl-primary">Page {PAGENO} of {nbpg}</span> <span class="woi-lbl-secondary" dir="rtl">صفحة {PAGENO} من {nbpg}</span></span>'
    . '</div></htmlpagefooter>';
$force2 = ( ( $argv[6] ?? '' ) === '2page' ) ? '<div style="height:170mm"></div><div class="woi-pagebreak"></div>' . $body : '';

$html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>' . $css . '</style></head>'
      . '<body data-accent="' . $accent . '" data-header="' . $header . '" data-density="' . $density . '" data-arabic="' . $arabic . '" data-thumbs="' . $thumbs . '">'
      . $footer . $body . $force2 . '</body></html>';

/* ---- render via vendored mPDF (mirror MpdfMaker config) ---- */
$cls = class_exists( '\WOI\PDF\Vendor\Mpdf\Mpdf' ) ? '\WOI\PDF\Vendor\Mpdf\Mpdf' : '\Mpdf\Mpdf';
$tmp = sys_get_temp_dir() . '/woi_mpdf_harness';
@mkdir( $tmp );
$mpdf = new $cls( array(
    'mode'             => 'utf-8',
    'format'           => 'A4',
    'orientation'      => 'P',
    'tempDir'          => $tmp,
    'autoScriptToLang' => true,
    'autoLangToFont'   => true,
) );
$mpdf->WriteHTML( $html );
$out = dirname( __DIR__ ) . '/tmp-visual-sample.pdf';
file_put_contents( $out, $mpdf->Output( '', 'S' ) );
echo "OK -> $out (" . filesize( $out ) . " bytes)\n";

/* ---------------- sample renderers ---------------- */
function woi_sample_line_items(): string {
    $headers = array(
        array( 'class' => 'position',  'en' => 'Sr.',        'ar' => 'م' ),
        array( 'class' => 'thumbnail', 'en' => '',           'ar' => '' ),
        array( 'class' => 'sku',       'en' => 'Barcode',    'ar' => 'الباركود' ),
        array( 'class' => 'description','en' => 'Description', 'ar' => 'البيان' ),
        array( 'class' => 'quantity',  'en' => 'Qty',        'ar' => 'الكمية' ),
        array( 'class' => 'price',     'en' => 'Rate',       'ar' => 'السعر' ),
        array( 'class' => 'tax_rate',  'en' => 'Tax %',      'ar' => 'الضريبة' ),
        array( 'class' => 'total',     'en' => 'Amount',     'ar' => 'المبلغ' ),
    );
    $rows = array(
        array( '20022532', 'Classic Milano Cotton Belt', 'Personalisation: JC', 24, '40.00&nbsp;د.إ', '0%', '960.00&nbsp;د.إ' ),
        array( '10000014', 'Force Automatic Wallet — Black', 'Colour: Black', 12, '25.00&nbsp;د.إ', '0%', '300.00&nbsp;د.إ' ),
        array( '10017944', 'Classic Milano Auto-Lock Belt', '', 12, '14.00&nbsp;د.إ', '0%', '168.00&nbsp;د.إ' ),
        array( '10017913', 'Classic Milano PU Pin Buckle Belt', '', 6, '9.00&nbsp;د.إ', '0%', '54.00&nbsp;د.إ' ),
        array( '10017876', 'Force Casual Pin Buckle Belt', '', 12, '19.00&nbsp;د.إ', '0%', '228.00&nbsp;د.إ' ),
    );
    // Mirror TemplateTokens::size_thumbnail_imgs output: an inline width (the only
    // image-width lever mPDF honours) replacing the 90px attributes EditorMain emits.
    $thumb = '<img style="width:13mm;height:auto" src="' . woi_sample_thumb_datauri() . '" alt="">';

    $h = '<table class="order-details"><thead><tr>';
    foreach ( $headers as $hd ) {
        $h .= '<th class="' . $hd['class'] . '"><span class="woi-lbl-primary">' . htmlspecialchars( $hd['en'] ) . '</span>';
        if ( $hd['ar'] !== '' ) {
            $h .= '<br><span class="woi-lbl-secondary" style="color:#8A8378" dir="rtl">' . $hd['ar'] . '</span>';
        }
        $h .= '</th>';
    }
    $h .= '</tr></thead><tbody>';
    foreach ( $rows as $i => $r ) {
        $meta = $r[2] !== '' ? '<div class="wc-item-meta">' . htmlspecialchars( $r[2] ) . '</div>' : '';
        $h .= '<tr>'
            . '<td class="position">' . ( $i + 1 ) . '</td>'
            . '<td class="thumbnail">' . $thumb . '</td>'
            . '<td class="sku">' . $r[0] . '</td>'
            . '<td class="description"><span class="woi-item-name">' . htmlspecialchars( $r[1] ) . '</span>' . $meta . '</td>'
            . '<td class="quantity">' . $r[3] . '</td>'
            . '<td class="price">' . $r[4] . '</td>'
            . '<td class="tax_rate">' . $r[5] . '</td>'
            . '<td class="total">' . $r[6] . '</td>'
            . '</tr>';
    }
    return $h . '</tbody></table>';
}

function woi_sample_totals(): string {
    $rows = array(
        array( 'Subtotal',  'المجموع الفرعي',          '1,710.00', '' ),
        array( 'VAT (5%)',  'ضريبة القيمة المضافة',     '85.50',    '' ),
        array( 'Shipping',  'الشحن',                    '0.00',     '' ),
        array( 'Total (AED)','المجموع',                 '1,795.50', 'grand-total' ),
    );
    $h = '<table class="totals-table">';
    foreach ( $rows as $r ) {
        $sec_style = ( false !== strpos( $r[3], 'grand-total' ) ) ? '' : ' style="color:#8A8378"';
        $h .= '<tr class="' . $r[3] . '"><th class="description"><span class="woi-lbl-primary">' . htmlspecialchars( $r[0] ) . '</span>'
            . '<br><span class="woi-lbl-secondary"' . $sec_style . ' dir="rtl">' . $r[1] . '</span></th>'
            . '<td class="price">' . $r[2] . '</td></tr>';
    }
    return $h . '</table>';
}

function woi_sample_bank(): string {
    $rows = array(
        array( 'Bank', 'Emirates NBD' ),
        array( 'Account Name', 'Milano Leather Trading LLC' ),
        array( 'IBAN', 'AE07 0331 2345 6789 0123 456' ),
        array( 'Account No.', '1023456789001' ),
        array( 'SWIFT', 'EBILAEAD' ),
    );
    $h = '<table class="woi-bank"><tbody>';
    foreach ( $rows as $r ) {
        $cls = in_array( $r[0], array( 'IBAN', 'Account No.', 'SWIFT' ), true ) ? ' class="mono"' : '';
        $h .= '<tr><th>' . htmlspecialchars( $r[0] ) . '</th><td' . $cls . '>' . htmlspecialchars( $r[1] ) . '</td></tr>';
    }
    return $h . '</tbody></table>';
}

/** Neutral product-photo placeholder as an SVG data URI. */
function woi_sample_thumb_datauri(): string {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40"><rect width="40" height="40" fill="#FBFAF6" stroke="#D9D4C9"/>'
         . '<path d="M7 27 L16 17 L23 24 L29 18 L34 24 L34 33 L7 33 Z" fill="#140858" opacity="0.45"/>'
         . '<circle cx="13" cy="13" r="3" fill="#140858" opacity="0.45"/></svg>';
    return 'data:image/svg+xml;base64,' . base64_encode( $svg );
}
