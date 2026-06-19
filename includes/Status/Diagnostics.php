<?php
namespace WOI\PDF\Status;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( '\\WOI\\PDF\\Status\\Diagnostics' ) ) :

/**
 * Collects version + environment + config + server diagnostics for the
 * Status screen. collect() reads live WP/WC/PHP state; to_text() is a pure
 * formatter (unit-tested) used by the copy-to-clipboard report.
 */
class Diagnostics {

	private string $plugin_dir;
	private GitRevision $git;

	public function __construct( string $plugin_dir, GitRevision $git ) {
		$this->plugin_dir = $plugin_dir;
		$this->git        = $git;
	}

	/**
	 * Build the grouped diagnostics.
	 *
	 * @return array<string,array<string,string>> Ordered group => (label => value).
	 */
	public function collect(): array {
		return array(
			'Versions'    => $this->versions(),
			'Plugin'      => $this->plugin_config(),
			'Environment' => $this->environment(),
			'Server'      => $this->server(),
		);
	}

	private function versions(): array {
		$rev   = $this->git->read( $this->plugin_dir );
		$out   = array();

		$out['Plugin version'] = defined( 'WOI_PDF_VERSION' ) ? WOI_PDF_VERSION : 'n/a';

		if ( 'unknown' === $rev['source'] ) {
			$out['Git revision'] = 'unknown (no .git or REVISION file)';
		} else {
			$label  = $rev['short'];
			$label .= '' !== $rev['branch'] ? ' (' . $rev['branch'] . ')' : '';
			$label .= ' [' . $rev['source'] . ']';
			$out['Git revision'] = $label;
			$out['Git commit']   = $rev['sha'];
			if ( ! empty( $rev['timestamp'] ) ) {
				$out['Deployed'] = $this->format_time( (int) $rev['timestamp'] );
			}
			if ( '' !== (string) $rev['subject'] ) {
				$out['Last ref update'] = $rev['subject'];
			}
		}

		$out['mPDF'] = $this->mpdf_version();

		return $out;
	}

	private function plugin_config(): array {
		$out = array();

		$out['Active template'] = $this->active_template();

		$invoice = (array) get_option( 'woi_pdf_documents_settings_invoice', array() );

		$out['Visual template (invoice)'] = ! empty( $invoice['enable_visual_template_invoice'] ) ? 'Enabled' : 'Disabled';

		if ( class_exists( '\\WOI\\PDF\\Visual\\VisualTemplateStore' ) ) {
			$stored                           = ( new \WOI\PDF\Visual\VisualTemplateStore() )->get( 'invoice' );
			$out['Visual template saved']     = '' !== trim( $stored ) ? 'Yes (' . strlen( $stored ) . ' bytes)' : 'No';
		}

		$out['Second language (invoice)'] = ! empty( $invoice['enable_second_language'] )
			? 'Enabled (' . ( ! empty( $invoice['second_language'] ) ? $invoice['second_language'] : 'ar' ) . ')'
			: 'Disabled';

		return $out;
	}

	private function environment(): array {
		return array(
			'WordPress'   => get_bloginfo( 'version' ),
			'WooCommerce' => $this->wc_version(),
			'PHP'         => PHP_VERSION,
			'Web server'  => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'n/a',
		);
	}

	private function server(): array {
		$upload     = wp_upload_dir();
		$upload_ok  = empty( $upload['error'] ) && wp_is_writable( $upload['basedir'] );

		return array(
			'PHP memory limit'   => (string) ini_get( 'memory_limit' ),
			'Max execution time' => (string) ini_get( 'max_execution_time' ) . 's',
			'GD'                 => extension_loaded( 'gd' ) ? 'Available' : 'Missing',
			'Imagick'            => extension_loaded( 'imagick' ) ? 'Available' : 'Missing',
			'Uploads writable'   => $upload_ok ? 'Yes' : 'No',
		);
	}

	private function active_template(): string {
		if ( function_exists( 'WOI_PDF' ) && isset( WOI_PDF()->settings ) && is_callable( array( WOI_PDF()->settings, 'get_template_path' ) ) ) {
			$path = (string) WOI_PDF()->settings->get_template_path();
			if ( '' !== $path ) {
				return basename( $path );
			}
		}
		$general = (array) get_option( 'woi_pdf_settings_general', array() );
		return ! empty( $general['template'] ) ? (string) $general['template'] : 'n/a';
	}

	private function mpdf_version(): string {
		$class = '\\WOI\\PDF\\Vendor\\Mpdf\\Mpdf';
		if ( class_exists( $class ) && defined( $class . '::VERSION' ) ) {
			return (string) constant( $class . '::VERSION' );
		}
		return 'n/a';
	}

	private function wc_version(): string {
		if ( defined( 'WC_VERSION' ) ) {
			return WC_VERSION;
		}
		if ( function_exists( 'WC' ) && isset( WC()->version ) ) {
			return (string) WC()->version;
		}
		return 'n/a';
	}

	private function format_time( int $ts ): string {
		if ( function_exists( 'wp_date' ) ) {
			return wp_date( 'Y-m-d H:i', $ts );
		}
		return gmdate( 'Y-m-d H:i', $ts ) . ' UTC';
	}

	/**
	 * Pure formatter: grouped key/values into a copy-pasteable plain-text report.
	 *
	 * @param array<string,array<string,string>> $groups
	 */
	public static function to_text( array $groups ): string {
		$lines = array();
		foreach ( $groups as $group => $items ) {
			$lines[] = '== ' . $group . ' ==';
			foreach ( $items as $label => $value ) {
				$lines[] = $label . ': ' . $value;
			}
			$lines[] = '';
		}
		return trim( implode( "\n", $lines ) ) . "\n";
	}
}

endif;
