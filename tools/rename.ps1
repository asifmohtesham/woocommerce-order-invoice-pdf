function Rename-WoiPdf($path) {
    $pairs = @(
        @('WPO\WC\PDF_Invoices_Pro\',      'WOI\PDF\'),
        @('WPO\WC\PDF_Invoices\',          'WOI\PDF\'),
        @('WPO\WC\PDF_Invoices_Templates\','WOI\PDF\Editor\'),
        @('WPO\IPS\',                        'WOI\PDF\'),
        @('WPO_WCPDF_Pro()',                'WOI_PDF()'),
        @('WPO_WCPDF()',                    'WOI_PDF()'),
        @('WPO_WCPDF_VERSION',             'WOI_PDF_VERSION'),
        @('WPO_WCPDF_',                    'WOI_PDF_'),
        @('WPO_WCPDF',                     'WOI_PDF'),
        @('wpo_wcpdf_',                    'woi_pdf_'),
        @('wpo_wcpdf',                     'woi_pdf'),
        @('wpo-wcpdf',                     'woi-pdf'),
        @('wcpdf_get_document(',           'woi_pdf_get_document('),
        @('wcpdf_filter_order_ids(',       'woi_pdf_filter_order_ids(')
    )
    $bytes   = [System.IO.File]::ReadAllBytes($path)
    $content = [System.Text.Encoding]::UTF8.GetString($bytes).TrimStart([char]0xFEFF)
    foreach ($p in $pairs) { $content = $content.Replace($p[0], $p[1]) }
    $utf8NoBom = [System.Text.UTF8Encoding]::new($false)
    [System.IO.File]::WriteAllText($path, $content, $utf8NoBom)
}
# Usage: Rename-WoiPdf "path\to\file.php"
# Batch: Get-ChildItem "path\to\dir" -Recurse -Filter *.php | ForEach-Object { Rename-WoiPdf $_.FullName }
