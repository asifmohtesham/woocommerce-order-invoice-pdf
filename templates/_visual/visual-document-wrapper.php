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
</style>
</head>
<body>
<?php echo $content; // already token-merged + sanitised on save ?>
</body>
</html>
