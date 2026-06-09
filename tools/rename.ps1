function Rename-WoiPdf($path) {
    $pairs = @(
        @('WPO\\WC\\PDF_Invoices_Pro\\',      'WOI\\PDF\\'),
        @('WPO\\WC\\PDF_Invoices\\',          'WOI\\PDF\\'),
        @('WPO\\WC\\PDF_Invoices_Templates\\','WOI\\PDF\\Editor\\'),
        @('WPO\\IPS\\',                        'WOI\\PDF\\'),
        @('WPO_WCPDF_Pro\(\)',                 'WOI_PDF()'),
        @('WPO_WCPDF\(\)',                     'WOI_PDF()'),
        @('WPO_WCPDF_VERSION',                 'WOI_PDF_VERSION'),
        @('WPO_WCPDF_',                        'WOI_PDF_'),
        @('WPO_WCPDF',                         'WOI_PDF'),
        @('wpo_wcpdf_',                        'woi_pdf_'),
        @('wpo_wcpdf',                         'woi_pdf'),
        @('wpo-wcpdf',                         'woi-pdf'),
        @('wcpdf_get_document\(',              'woi_pdf_get_document('),
        @('wcpdf_filter_order_ids\(',          'woi_pdf_filter_order_ids(')
    )
    $content = Get-Content $path -Raw
    foreach ($p in $pairs) { $content = $content -replace $p[0], $p[1] }
    Set-Content $path $content -NoNewline
}
# Usage: Rename-WoiPdf "path\to\file.php"
# Batch: Get-ChildItem "path\to\dir" -Recurse -Filter *.php | ForEach-Object { Rename-WoiPdf $_.FullName }
