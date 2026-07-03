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
<?php
// mPDF-only header re-centring. visual-document.css top-aligns the line-item
// header cells (so every column's primary label shares one baseline — mPDF
// otherwise drifts the VAT/"ضريبة %" column up when vertical-align:middle
// centres its taller mixed-script line). Top-aligning is correct but, in mPDF
// only, it stacks the Arabic secondary line's large font-descent leading
// entirely BELOW the text, so the header reads bottom-heavy in the PDF. The
// browser normalises that line box (top and middle look identical there), so
// this compensation must NOT live in the shared stylesheet — only here, which
// the canvas never loads. Nudge the header padding up to re-centre the text
// optically. Guarded on a bilingual secondary being present: single-line
// (monolingual) headers have no descent slack and keep the symmetric default.
if ( isset( $content ) && false !== strpos( (string) $content, 'woi-lbl-secondary' ) ) {
	echo "\n.order-details thead th { padding-top: 3.5mm; padding-bottom: 0.5mm; }";
}
?>
</style>
</head>
<body data-accent="<?php echo esc_attr( $woi_doc_opts['accent'] ); ?>" data-header="<?php echo esc_attr( $woi_doc_opts['header'] ); ?>" data-density="<?php echo esc_attr( $woi_doc_opts['density'] ); ?>" data-arabic="<?php echo esc_attr( $woi_doc_opts['arabic'] ); ?>" data-thumbs="<?php echo esc_attr( $woi_doc_opts['thumbs'] ); ?>" data-font="<?php echo esc_attr( $woi_doc_opts['font'] ); ?>">
<?php
// Running page-footer (accurate Page X of Y on every page) and, when the
// repeat-letterhead toggle is on, a running page-header carrying the letterhead
// banner — both registered before the content so they apply from page 1.
// mPDF only; no inline output. The browser canvas never sees this.
if ( isset( $document ) && is_object( $document ) ) {
	$woi_tokens = new \WOI\PDF\Visual\TemplateTokens();
	if ( 'on' === ( $woi_doc_opts['repeat_letterhead'] ?? 'off' ) ) {
		echo $woi_tokens->running_header( $document );
	}
	echo $woi_tokens->running_footer( $document );
}
echo $content; // already token-merged + sanitised on save
?>
</body>
</html>
