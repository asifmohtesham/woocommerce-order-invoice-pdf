<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
$woi_doc_opts = function_exists( 'woi_pdf_visual_doc_options' )
	? woi_pdf_visual_doc_options( 'invoice' )
	: array( 'accent' => 'navy', 'header' => 'center', 'density' => 'comfortable', 'arabic' => 'on', 'thumbs' => 'on', 'font' => 'grotesque' );
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
<?php echo woi_pdf_visual_document_css(); ?>
<?php if ( function_exists( 'woi_pdf_visual_options_css' ) ) { echo "\n" . woi_pdf_visual_options_css( $woi_doc_opts ); } ?>
</style>
</head>
<body data-accent="<?php echo esc_attr( $woi_doc_opts['accent'] ); ?>" data-header="<?php echo esc_attr( $woi_doc_opts['header'] ); ?>" data-density="<?php echo esc_attr( $woi_doc_opts['density'] ); ?>" data-arabic="<?php echo esc_attr( $woi_doc_opts['arabic'] ); ?>" data-thumbs="<?php echo esc_attr( $woi_doc_opts['thumbs'] ); ?>" data-font="<?php echo esc_attr( $woi_doc_opts['font'] ); ?>">
<?php echo $content; // already token-merged + sanitised on save ?>
</body>
</html>
