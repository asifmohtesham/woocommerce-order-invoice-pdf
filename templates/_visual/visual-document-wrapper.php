<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
@page { margin: 15mm; }
body { font-family: "dejavusans", sans-serif; font-size: 11pt; color: #222; }
/* Arabic shaping is handled natively by mPDF; the secondary-language
   font stack is registered by MpdfMaker. RTL spans carry dir="rtl". */
.woi-bilingual-secondary, [dir="rtl"] { direction: rtl; }
table { border-collapse: collapse; width: 100%; }

/* --- Invoice table fidelity (ported from Standard UAE Tax Invoice/style.css) --- */

/* Line-items table */
table.order-details { width: 100%; margin-top: 3mm; margin-bottom: 5mm; }
table.order-details th, table.order-details td { border: 0.5pt solid #000; padding: 2px 4px; }
.order-details th {
    font-weight: normal;
    text-align: inherit;
    border-bottom: 1px solid #000;
    border-top: 1px solid #000;
    padding-top: 0;
}
.order-details td, .order-details th { padding: 0.375em; }

/* Totals table */
table.totals-table { width: 100%; border-collapse: separate; }
table.totals-table th, table.totals-table td { border: 0; padding: 4px; }
table.totals-table th.description { text-align: inherit; vertical-align: top; min-width: 2cm; }
table.totals-table td.price { text-align: right; }
tr.grand-total td, tr.grand-total th { border-top: 1px solid #000; border-bottom: 1px solid #000; }

/* Bilingual secondary labels (Arabic column headers / totals labels) */
.woi-lbl-secondary { display: block; direction: rtl; }
</style>
</head>
<body>
<?php echo $content; // already token-merged + sanitised on save ?>
</body>
</html>
