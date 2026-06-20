<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
<?php echo woi_pdf_visual_document_css(); ?>
</style>
</head>
<body>
<?php echo $content; // already token-merged + sanitised on save ?>
</body>
</html>
