<?php
namespace WOI\PDF;

use WOI\PDF\Vendor\Dompdf\Dompdf;
use WOI\PDF\Vendor\Dompdf\Options;
use function apply_filters;
use function do_action;
use function get_temp_dir;
use function header;
use function strlen;
use function file_exists;
use function file_put_contents;
use function base64_encode;
use function sanitize_file_name;
use function trailingslashit;
use function wp_mkdir_p;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( '\\WOI\\PDF\\DocumentRenderer' ) ) :

class DocumentRenderer {

    /** @var string[] Valid output modes */
    private array $output_modes = array( 'download', 'inline', 'base64', 'save' );

    public function get_output_modes(): array {
        return $this->output_modes;
    }

    /**
     * Render HTML to a PDF binary string via Dompdf.
     *
     * @param string $html    Full HTML document string.
     * @param array  $options Optional Dompdf options (paper size, orientation, etc.).
     * @return string Raw PDF binary.
     */
    public function render( string $html, array $options = array() ): string {
        $dompdf_options = new Options();
        $dompdf_options->set( 'defaultFont', 'open-sans' );
        $dompdf_options->set( 'isRemoteEnabled', apply_filters( 'woi_pdf_dompdf_remote_enabled', true ) );
        $dompdf_options->set( 'tempDir', apply_filters( 'woi_pdf_tmp_path', get_temp_dir() ) );

        foreach ( $options as $key => $value ) {
            $dompdf_options->set( $key, $value );
        }

        $dompdf = new Dompdf( $dompdf_options );
        $dompdf->setPaper(
            apply_filters( 'woi_pdf_paper_size', 'A4' ),
            apply_filters( 'woi_pdf_paper_orientation', 'portrait' )
        );

        $dompdf->loadHtml( apply_filters( 'woi_pdf_get_html', $html ) );

        do_action( 'woi_pdf_before_dompdf_render', $dompdf );
        $dompdf->render();
        do_action( 'woi_pdf_after_dompdf_render', $dompdf );

        return $dompdf->output();
    }

    /**
     * Stream PDF to the browser.
     *
     * @param string $pdf      Raw PDF binary from render().
     * @param string $filename Suggested filename for download.
     * @param string $mode     'download' or 'inline'.
     */
    public function stream( string $pdf, string $filename, string $mode = 'download' ): void {
        $disposition = ( 'inline' === $mode ) ? 'inline' : 'attachment';
        header( 'Content-Type: application/pdf' );
        header( sprintf( 'Content-Disposition: %s; filename="%s"', $disposition, sanitize_file_name( $filename ) ) );
        header( 'Content-Length: ' . strlen( $pdf ) );
        header( 'Cache-Control: private, max-age=0, must-revalidate' );
        header( 'Pragma: public' );
        echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    /**
     * Save PDF to a temp file and return the path.
     *
     * @param string $pdf      Raw PDF binary from render().
     * @param string $filename Desired filename (without path).
     * @return string Absolute path to saved file.
     */
    public function save_temp( string $pdf, string $filename ): string {
        $tmp_dir = apply_filters( 'woi_pdf_tmp_path', get_temp_dir() . 'woi-pdf/' );

        if ( ! file_exists( $tmp_dir ) ) {
            wp_mkdir_p( $tmp_dir );
        }

        $path = trailingslashit( $tmp_dir ) . sanitize_file_name( $filename );
        file_put_contents( $path, $pdf ); // phpcs:ignore WordPress.WP.AlternativeFunctions

        return $path;
    }

    /**
     * Return PDF as a base64-encoded string (for REST API responses).
     *
     * @param string $pdf Raw PDF binary from render().
     * @return string Base64-encoded PDF.
     */
    public function to_base64( string $pdf ): string {
        return base64_encode( $pdf ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
    }
}

endif;
