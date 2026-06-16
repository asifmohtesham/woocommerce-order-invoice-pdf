<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
|--------------------------------------------------------------------------
| Document getter functions
|--------------------------------------------------------------------------
|
| Global functions to get the document object for an order
|
*/

function woi_pdf_filter_order_ids( $order_ids, $document_type ) {
	$order_ids = apply_filters( 'woi_pdf_process_order_ids', $order_ids, $document_type );

	// Filter out trashed orders.
	foreach ( $order_ids as $key => $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! empty( $order ) && is_callable( array( $order, 'get_status' ) ) && 'trash' === $order->get_status() ) {
			unset( $order_ids[ $key ] );
		}
	}

	// Ensure duplicated order IDs do not incorrectly trigger a BulkDocument.
	return array_values( array_unique( $order_ids ) );
}

/**
 * Get the document object for an order
 *
 * @param string $document_type
 * @param mixed  $order
 * Passing an order object will return the document object for that order.
 * Passing an array of order ids will return a BulkDocument object.
 * Passing a single order ID within an array retrieves the document object for that order and refreshes the order object to ensure the data is up-to-date.
 * Passing null will return a document object without an order.
 *
 * @param bool   $init
 *
 * @return object|false
 */
function woi_pdf_get_document( string $document_type, $order, bool $init = false ) {
	if ( ! empty( $order ) ) {
		if ( ! is_object( $order ) && ! is_array( $order ) && is_numeric( $order ) ) {
			$order = array( absint( $order ) ); // convert single order id to array.
		}
		if ( is_object( $order ) ) {
			// we filter order_ids for objects too:
			// an order object may need to be converted to several refunds for example.
			$order_ids          = array( $order->get_id() );
			$filtered_order_ids = woi_pdf_filter_order_ids( $order_ids, $document_type );

			// check if something has changed.
			$order_id_diff = array_diff( $filtered_order_ids, $order_ids );
			if ( empty( $order_id_diff ) && count( $order_ids ) == count( $filtered_order_ids ) ) {
				// nothing changed, load document with Order object.
				do_action( 'woi_pdf_process_template_order', $document_type, $order->get_id() );
				$document = WOI_PDF()->documents->get_document( $document_type, $order );

				if ( ! $document || ! is_callable( array( $document, 'is_allowed' ) ) || ! $document->is_allowed() ) {
					return apply_filters( 'woi_pdf_get_document', false, $document_type, $order, $init );
				}

				if ( $init && ! $document->exists() ) {
					$document->init();
					$document->save();
				}
				return apply_filters( 'woi_pdf_get_document', $document, $document_type, $order, $init );
			} else {
				// order ids array changed, continue processing that array.
				$order_ids = $filtered_order_ids;
			}
		} elseif ( is_array( $order ) ) {
			$order_ids = woi_pdf_filter_order_ids( $order, $document_type );
		} else {
			return apply_filters( 'woi_pdf_get_document', false, $document_type, $order, $init );
		}

		if ( empty( $order_ids ) ) {
			// No orders to export for this document type.
			return apply_filters( 'woi_pdf_get_document', false, $document_type, $order, $init );
		}

		// if we only have one order, it's simple.
		if ( count( $order_ids ) == 1 ) {
			$order_id = array_pop( $order_ids );
			$order    = wc_get_order( $order_id );

			do_action( 'woi_pdf_process_template_order', $document_type, $order_id );

			$document = WOI_PDF()->documents->get_document( $document_type, $order );

			if ( ! $document || ! $document->is_allowed() ) {
				return apply_filters( 'woi_pdf_get_document', false, $document_type, $order, $init );
			}

			if ( $init && ! $document->exists() ) {
				$document->init();
				$document->save();
			}
		// otherwise we use bulk class to wrap multiple documents in one.
		} else {
			$document = woi_pdf_get_bulk_document( $document_type, $order_ids );
		}
	} else {
		// orderless document (used as wrapper for bulk, for example).
		$document = WOI_PDF()->documents->get_document( $document_type, $order );
	}

	return apply_filters( 'woi_pdf_get_document', $document, $document_type, $order, $init );
}

function woi_pdf_get_bulk_document( $document_type, $order_ids ) {
	return new \WOI\PDF\Documents\BulkDocument( $document_type, $order_ids );
}

function woi_pdf_get_invoice( $order, $init = false ) {
	woi_pdf_deprecated_function( __FUNCTION__, '4.6.3', 'woi_pdf_get_document( \'invoice\', $order, $init )' );
	return woi_pdf_get_document( 'invoice', $order, $init );
}

function woi_pdf_get_packing_slip( $order, $init = false ) {
	woi_pdf_deprecated_function( __FUNCTION__, '4.6.3', 'woi_pdf_get_document( \'packing-slip\', $order, $init )' );
	return woi_pdf_get_document( 'packing-slip', $order, $init );
}

function woi_pdf_get_bulk_actions() {
	$actions   = array();
	$documents = WOI_PDF()->documents->get_documents( 'enabled', 'any' );

	foreach ( $documents as $document ) {
		foreach ( $document->output_formats as $output_format ) {
			if ( 'xml' === $output_format && ! \woi_pdf_edi_is_available() ) {
				continue;
			}

			$slug = $document->get_type();

			if ( 'pdf' !== $output_format ) {
				$slug .= "_{$output_format}";
			}

			if ( $document->is_enabled( $output_format ) ) {
				$prefix           = strtoupper( $output_format ) . ' ';
				$actions[ $slug ] = $prefix . $document->get_title();
			}
		}
	}

	return apply_filters( 'woi_pdf_bulk_actions', $actions );
}

/**
 * Load HTML into (pluggable) PDF library, DomPDF 1.0.2 by default
 * Use woi_pdf_pdf_maker filter to change the PDF class (which can wrap another PDF library).
 *
 * @param string       $html
 * @param array        $settings
 * @param null|object  $document
 * @return WOI\PDF\Makers\PDFMaker
 */
function woi_pdf_get_pdf_maker( $html, $settings = array(), $document = null ) {
	$class = '\\WOI\\PDF\\Makers\\PDFMaker';

	if ( ! class_exists( $class ) ) {
		include_once( WOI_PDF()->plugin_path() . '/includes/Makers/PDFMaker.php' );
	}

	$class = apply_filters( 'woi_pdf_pdf_maker', $class );

	return new $class( $html, $settings, $document );
}

/**
 * Check if the default PDF maker is used for creating PDF
 *
 * @return bool whether the PDF maker is the default or not
 */
function woi_pdf_pdf_maker_is_default() {
	$default_pdf_maker = '\\WOI\\PDF\\Makers\\PDFMaker';

	return $default_pdf_maker == apply_filters( 'woi_pdf_pdf_maker', $default_pdf_maker );
}

/**
 * Send PDF headers for inline viewing or file download.
 *
 * @param string      $filename PDF file name
 * @param string      $mode     Delivery mode ('inline' or 'download')
 * @param string|null $pdf      PDF string
 */
function woi_pdf_pdf_headers( string $filename, string $mode = 'inline', ?string $pdf = null ) {
	// Decide whether to display inline or prompt a download
	$disposition  = ( $mode === 'download' ) ? 'attachment' : 'inline';
	$content_type = ( $mode === 'download' ) ? 'application/octet-stream' : 'application/pdf';

	// PDF-specific headers
	header( "Content-Type: $content_type" );
	header( "Content-Disposition: $disposition; filename=\"" . rawurlencode( $filename ) . "\"" );
	header( 'Content-Transfer-Encoding: binary' );
	header( 'Accept-Ranges: bytes' );

	// Cache control headers
	header( 'Cache-Control: public, must-revalidate, max-age=0' );
	header( 'Pragma: public' );
	header( 'Expires: 0' );

	// Allows other developers or code to hook in
	do_action( 'woi_pdf_headers', $filename, $mode, $pdf );
}

/**
 * Get the document file
 *
 * @param  object $document
 * @param  string $output_format
 * @param  string $error_handling
 * @return string|false
 */
function woi_pdf_get_document_file( object $document, string $output_format = 'pdf', string $error_handling = 'exception' ) {
	$default_output_format = 'pdf';

	if ( ! $document ) {
		$error_message = 'No document object provided.';
		return woi_pdf_error_handling( $error_message, $error_handling, true, 'critical' );
	}

	if ( empty( $output_format ) ) {
		$output_format = $default_output_format;
	}

	if ( ! in_array( $output_format, $document->output_formats ) ) {
		$error_message = "Invalid output format: {$output_format}. Expected one of: " . implode( ', ', $document->output_formats );
		return woi_pdf_error_handling( $error_message, $error_handling, true, 'critical' );
	}

	if ( is_callable( array( $document, 'is_enabled' ) ) && ! $document->is_enabled( $output_format ) ) {
		$error_message = "The {$output_format} output format is not enabled for this document: {$document->get_title()}.";
		return woi_pdf_error_handling( $error_message, $error_handling, true, 'critical' );
	}

	$tmp_path = WOI_PDF()->main->get_tmp_path( 'attachments' );

	if ( ! WOI_PDF()->file_system->is_dir( $tmp_path ) || ! WOI_PDF()->file_system->is_writable( $tmp_path ) ) {
		$error_message = "Couldn't get the attachments temporary folder path: {$tmp_path}.";
		return woi_pdf_error_handling( $error_message, $error_handling, true, 'critical' );
	}

	/**
	 * Calls a dynamic attachment function based on the output format.
	 *
	 * @uses get_document_pdf_attachment()
	 * @uses get_document_xml_attachment()
	 */
	$function = "get_document_{$output_format}_attachment";

	if ( ! is_callable( array( WOI_PDF()->main, $function ) ) ) {
		$error_message = "The {$function} method is not callable on WOI_PDF()->main.";
		return woi_pdf_error_handling( $error_message, $error_handling, true, 'critical' );
	}

	$file_path = WOI_PDF()->main->$function( $document, $tmp_path );

	return apply_filters( 'woi_pdf_get_document_file', $file_path, $document, $output_format );
}

/**
 * Get the document output format extension
 *
 * @param  string $output_format
 * @return string
 */
function woi_pdf_get_document_output_format_extension( string $output_format ): string {
	$output_formats = array(
		'pdf' => '.pdf',
		'xml' => '.xml',
	);

	return isset( $output_formats[ $output_format ] ) ? $output_formats[ $output_format ] : $output_formats['pdf'];
}

/**
 * Wrapper for deprecated functions so we can apply some extra logic.
 *
 * @since  2.0
 * @param  string $function
 * @param  string $version
 * @param  string $replacement
 */
function woi_pdf_deprecated_function( $function, $version, $replacement = null ) {
	if ( apply_filters( 'woi_pdf_disable_deprecation_notices', false ) ) {
		return;
	}

	// if the deprecated function is called from one of our filters, $this should be $document.
	$filter               = current_filter();
	$global_woi_pdf_filters = array( 'wp_ajax_generate_woi_pdf' );

	if ( ! empty( $filter ) && ! empty( $replacement ) && ! in_array( $filter, $global_woi_pdf_filters ) && false !== strpos( $filter, 'woi_pdf' ) && false !== strpos( $replacement, '$this' ) ) {
		$replacement =  str_replace( '$this', '$document', $replacement );
		$replacement = "{$replacement} - check that the \$document parameter is included in your action or filter ($filter)!";
	}

	$is_ajax = function_exists( 'wp_doing_ajax' ) ? wp_doing_ajax() : defined( 'DOING_AJAX' ) && DOING_AJAX;

	if ( $is_ajax ) {
		do_action( 'deprecated_function_run', $function, $replacement, $version );
		$log_string  = "The {$function} function is deprecated since version {$version}.";
		$log_string .= $replacement ? " Replace with {$replacement}." : '';
		woi_pdf_log_error( $log_string, 'warning' );
	} else {
		_deprecated_function( esc_html( $function ), esc_html( $version ), esc_html( $replacement ) );
	}
}

/**
 * Logs errors thrown by this plugin.
 * Uses the WooCommerce logger when available (WC 3.0+), otherwise falls back to PHP error_log().
 *
 * @param string           $message Error message to log.
 * @param string           $level   Log level: debug, info, notice, warning, error, critical, alert, emergency.
 * @param \Throwable|null  $e       (Optional) Exception or error object.
 * @param string           $source  Source of the log entry, defaults to 'woi-pdf'.
 * @return void
 */
function woi_pdf_log_error( string $message, string $level = 'error', ?\Throwable $e = null, string $source = 'woi-pdf' ): void {
	/**
	 * Appends exception details to the message if available.
	 *
	 * @param string          $message
	 * @param \Throwable|null $e
	 * @return string
	 */
	$format_message = static function ( string $message, ?\Throwable $e ): string {
		if ( $e instanceof \Throwable ) {
			$message = sprintf( '%s (%s:%d)', $message, $e->getFile(), $e->getLine() );

			if ( apply_filters( 'woi_pdf_log_stacktrace', false ) && is_callable( array( $e, 'getTraceAsString' ) ) ) {
				$message .= "\n" . $e->getTraceAsString();
			}
		}
		return $message;
	};

	$message = $format_message( $message, $e );

	if ( ! function_exists( 'wc_get_logger' ) ) {
		error_log( '[' . $source . '] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		return;
	}

	$logger  = wc_get_logger();
	$context = array( 'source' => $source );

	$logger->log( $level, $message, $context );
}

/**
 * Outputs an error message in the frontend.
 *
 * @param string          $message Error message to display.
 * @param string          $level   Log level (unused here, but kept for consistency).
 * @param \Throwable|null $e       (Optional) Exception or error object.
 * @return void
 */
function woi_pdf_output_error( string $message, string $level = 'error', ?\Throwable $e = null ): void {
	if ( ! current_user_can( 'edit_shop_orders' ) ) {
		esc_html_e( 'Error creating PDF, please contact the site owner.', 'woocommerce-orders-invoice-pdf' );
		return;
	}

	echo '<div style="border: 2px solid red; padding: 5px;">';
	echo '<h3>' . wp_kses_post( $message ) . '</h3>';

	if ( $e instanceof \Throwable ) {
		echo '<pre>' . esc_html( $e->getFile() ) . ' (' . esc_html( (string) $e->getLine() ) . ')</pre>';
		echo '<pre>' . esc_html( $e->getTraceAsString() ) . '</pre>';
	}

	echo '</div>';
}

/**
 * Handles errors by either throwing an exception or outputting the error, optionally logging it first.
 *
 * @param string $message        The error message.
 * @param string $handling_type  How to handle the error: 'exception' (default) or 'output'.
 * @param bool   $log_error      Whether to log the error via woi_pdf_log_error().
 * @param string $log_level      Log level to use when logging the error.
 * @return bool Always returns false when not throwing.
 * @throws \Exception When handling_type is 'exception'.
 */
function woi_pdf_error_handling( string $message, string $handling_type = 'exception', bool $log_error = true, string $log_level = 'error' ): bool {
	if ( $log_error ) {
		woi_pdf_log_error( $message, $log_level );
	}

	switch ( $handling_type ) {
		case 'exception':
			throw new \Exception( esc_html( $message ) );
		case 'output':
			woi_pdf_output_error( $message, $log_level );
			break;
		default:
			// Unexpected handling type
			woi_pdf_log_error( sprintf( 'Unknown error handling type: %s', $handling_type ), 'warning' );
			break;
	}

	return false;
}

/**
 * Date formatting function
 *
 * @param object $document
 * @param string $date_type Optional. A date type to be filtered eg. 'invoice_date', 'order_date_created', 'order_date_modified', 'order_date', 'order_date_paid', 'order_date_completed', 'current_date', 'document_date', 'packing_slip_date'.
 */
function woi_pdf_date_format( $document = null, $date_type = null ) {
	return apply_filters( 'woi_pdf_date_format', wc_date_format(), $document, $date_type );
}

/**
 * Catch MySQL errors from $wpdb and log them.
 *
 * @param  \wpdb  $wpdb
 * @param  string $context Optional prefix for messages (e.g. __METHOD__).
 * @return array  List of error strings logged.
 */
function woi_pdf_catch_db_object_errors( \wpdb $wpdb, string $context = '' ): array {
	global $EZSQL_ERROR;

	static $seen = array(); // avoid duplicate logs in the same request
	$errors      = array();

	// Using $wpdb->queries (if SAVEQUERIES is true and a collector populates results).
	if ( ! empty( $wpdb->queries ) && is_array( $wpdb->queries ) ) {
		foreach ( $wpdb->queries as $query ) {
			$result = isset( $query['result'] ) ? $query['result'] : null;
			if ( is_wp_error( $result ) && is_array( $result->errors ) ) {
				foreach ( $result->errors as $error ) {
					$errors[] = array(
						'error' => reset( $error ),
						'query' => isset( $query['query'] ) ? $query['query'] : '',
					);
				}
			}
		}
	}

	// Fallback to $EZSQL_ERROR (wpdb::print_error collects here).
	if ( empty( $errors ) && ! empty( $EZSQL_ERROR ) && is_array( $EZSQL_ERROR ) ) {
		foreach ( $EZSQL_ERROR as $error ) {
			if ( empty( $error['error_str'] ) ) {
				continue;
			}

			$errors[] = array(
				'error' => $error['error_str'],
				'query' => isset( $error['query'] ) ? $error['query'] : '',
			);
		}
	}

	// Log (with optional context) and dedupe per request.
	foreach ( $errors as $item ) {
		$msg   = (string) ( $item['error'] ?? '' );
		$query = (string) ( $item['query'] ?? '' );

		if ( '' === $msg ) {
			continue;
		}

		// Dedupe by error+query (context does not create a "new" error).
		$key = md5( $msg . '|' . $query );

		if ( isset( $seen[ $key ] ) ) {
			continue;
		}

		$seen[ $key ] = true;

		$line = '' !== $context ? "{$context}: {$msg}" : $msg;

		if ( '' !== $query ) {
			$line .= "\nQuery: {$query}";
		}

		woi_pdf_log_error( $line, 'critical' );
	}

	return wp_list_pluck( $errors, 'error' );
}

/**
 * String convert encoding.
 *
 * @param  string $string
 * @param  string $tool
 * @return string
 */
function woi_pdf_convert_encoding( $string, $tool = 'mb_convert_encoding' ) {
	if ( empty( $string ) ) {
		return $string;
	}

	$tool          = apply_filters( 'woi_pdf_convert_encoding_tool', $tool );
	$from_encoding = apply_filters( 'woi_pdf_convert_from_encoding', 'UTF-8', $tool );

	switch ( $tool ) {
		case 'mb_convert_encoding':
			$to_encoding = apply_filters( 'woi_pdf_convert_to_encoding', 'HTML-ENTITIES', $tool );

			// provided by composer 'symfony/polyfill-mbstring' library.
			// it uses 'iconv()', must have 'libiconv' configured instead of 'glibc' library.
			if ( class_exists( '\\Symfony\\Polyfill\\Mbstring\\Mbstring' ) ) {
				$string = \Symfony\Polyfill\Mbstring\Mbstring::mb_convert_encoding( $string, $to_encoding, $from_encoding );
			}
			break;
		case 'uconverter':
			$to_encoding = apply_filters( 'woi_pdf_convert_to_encoding', 'HTML-ENTITIES', $tool );

			// only for PHP 8.2+.
			if ( version_compare( PHP_VERSION, '8.1', '>' ) && class_exists( 'UConverter' ) && extension_loaded( 'intl' ) ) {
				$string = UConverter::transcode( $string, $to_encoding, $from_encoding );
			}
			break;
		case 'iconv':
			$to_encoding = apply_filters( 'woi_pdf_convert_to_encoding', 'ISO-8859-1', $tool );

			// provided by composer 'symfony/polyfill-iconv' library.
			if ( class_exists( '\\Symfony\\Polyfill\\Iconv\\Iconv' ) ) {
				$string = \Symfony\Polyfill\Iconv\Iconv::iconv( $from_encoding, $to_encoding, $string );

			// default server library.
			// must have 'libiconv' configured instead of 'glibc' library.
			} elseif ( function_exists( 'iconv' ) ) {
				$string = iconv( $from_encoding, $to_encoding, $string );
			}
			break;
	}

	return $string;
}

/**
 * Sanitize HTML content, prevents XSS attacks.
 *
 * @param string $html
 * @param string $context
 * @param array  $allow_tags
 *
 * @return string
 */
function woi_pdf_sanitize_html_content( string $html, string $context = '', array $allow_tags = array() ): string {
	if ( empty( $html ) ) {
		return $html;
	}

	// default allowed tags
	$allow_tags = array_merge( apply_filters( 'woi_pdf_sanitize_html_default_allow_tags', array(
		// tag   => allowed attributes eg. array( 'href', 'title' ) in case of a <a> tag.
		'br'     => array(),
		'em'     => array(),
		'strong' => array(),
		'p'      => array(),
	), $context ), $allow_tags );

	$safe_tags = array(
		'b'          => array(),
		'blockquote' => array(),
		'br'         => array(),
		'em'         => array(),
		'i'          => array(),
		'li'         => array(),
		'ol'         => array(),
		'p'          => array(),
		'strong'     => array(),
		'u'          => array(),
		'ul'         => array(),
		'span'       => array( 'style' ),
		'h1'         => array(),
		'h2'         => array(),
		'h3'         => array(),
		'h4'         => array(),
		'h5'         => array(),
		'h6'         => array(),
		'div'        => array( 'style' ),
		'table'      => array( 'border', 'cellspacing', 'cellpadding' ),
		'tr'         => array(),
		'td'         => array( 'colspan', 'rowspan' ),
		'th'         => array( 'colspan', 'rowspan', 'scope' ),
		'thead'      => array(),
		'tbody'      => array(),
		'tfoot'      => array(),
		'code'       => array(),
		'pre'        => array(),
		'dl'         => array(),
		'dt'         => array(),
		'dd'         => array(),
		'hr'         => array(),
		'sup'        => array(),
		'sub'        => array(),
		'figure'     => array(),
		'figcaption' => array(),
		'abbr'       => array( 'title' ),
	);

	$filtered_tags = array();

	foreach ( $allow_tags as $tag => $attributes ) {
		if ( array_key_exists( $tag, $safe_tags ) ) {
			$safe_attributes       = array_intersect( $attributes, $safe_tags[ $tag ] );
			$filtered_tags[ $tag ] = ! empty( $safe_attributes ) ? $safe_attributes : array();
		}
	}

	if ( empty( $filtered_tags ) ) {
		return $html;
	}

	$dom = new \DOMDocument();

	// clean up special chars
	if ( apply_filters( 'woi_pdf_convert_encoding', function_exists( 'htmlspecialchars_decode' ) ) ) {
		$html = htmlspecialchars_decode( woi_pdf_convert_encoding( $html ), ENT_QUOTES );
	}

	libxml_use_internal_errors( true ); // suppress malformed HTML errors
	@$dom->loadHTML( '<div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
	libxml_clear_errors();

	$extra_wrapper = $dom->getElementsByTagName( 'div' )->item( 0 );
	$content       = ! empty( $extra_wrapper ) ? $extra_wrapper->parentNode->removeChild( $extra_wrapper ) : null;

	if ( ! empty( $content ) ) {
		// Clear DOM by removing all nodes from it.
		while ( $dom->firstChild ) {
			$dom->removeChild( $dom->firstChild );
		}

		// Append the content to the DOM to remove the extra DIV wrapper.
		while ( $content->firstChild ) {
			$dom->appendChild( $content->firstChild );
		}
	}

	$xpath = new \DOMXPath( $dom );

	// iterate over all nodes.
	foreach ( $xpath->query( '//*' ) as $node ) {
		// check if the node is allowed.
		if ( array_key_exists( $node->nodeName, $filtered_tags ) ) {
			// if the node is allowed, check each attribute.
			foreach ( $node->attributes as $attr ) {
				if ( ! in_array( $attr->nodeName, $filtered_tags[ $node->nodeName ] ) ) {
					$node->removeAttribute( $attr->nodeName );
				}
			}
		} else {
			// if the node is not allowed, remove it but try to preserve text.
			if ( $node->parentNode ) {
				$fragment = $dom->createDocumentFragment();

				while ( $node->childNodes->length > 0 ) {
					$fragment->appendChild( $node->childNodes->item( 0 ) );
				}

				if ( $fragment->hasChildNodes() ) {
					$node->parentNode->replaceChild( $fragment, $node );
				} else {
					$node->parentNode->removeChild( $node );
				}
			}
		}
	}

	$html = $dom->saveHTML();

	if ( empty( $html ) ) {
		return '';
	}

	return trim( $html );
}

/**
 * Sanitize phone number
 *
 * @param string $text
 *
 * @return string
 */
function woi_pdf_sanitize_phone_number( string $text ): string {
	return preg_replace( '/[^0-9\+\-\(\)\s\.x]/', '', $text );
}

/**
 * Safe redirect or die.
 *
 * @param  string          $url
 * @param  string|WP_Error $message
 * @return void
 */
function woi_pdf_safe_redirect_or_die( $url = '', $message = '' ) {
	if ( ! empty( $url ) ) {
		wp_safe_redirect( $url );
		exit;
	} else {
		wp_die( esc_html( $message ) );
	}
}

/**
 * Parse document date for WP_Query.
 *
 * @param array $wp_query_args
 * @param array $query_args
 *
 * @return array
 */
function woi_pdf_parse_document_date_for_wp_query( array $wp_query_args, array $query_vars ): array {
	$documents = WOI_PDF()->documents->get_documents();

	if ( ! empty( $documents ) ) {
		foreach ( $documents as $document ) {
			if ( ! empty( $query_vars[ "woi_pdf_{$document->slug}_date" ] ) ) {
				$wp_query_args = ( new \WC_Order_Data_Store_CPT() )->parse_date_for_wp_query( $query_vars[ "woi_pdf_{$document->slug}_date" ], "_woi_pdf_{$document->slug}_date", $wp_query_args );

				if ( isset( $wp_query_args[ "woi_pdf_{$document->slug}_date" ] ) ) {
					unset( $wp_query_args[ "woi_pdf_{$document->slug}_date" ] );
				}
			}
		}
	}

	return $wp_query_args;
}

/**
 * Get multilingual languages.
 *
 * @return array
 */
function woi_pdf_get_multilingual_languages(): array {
	$languages = array();

	// refers to WPML or Polylang only
	if ( function_exists( 'icl_get_languages' ) ) {
		// use this instead of function call for development outside of WPML
		// $icl_get_languages = 'a:3:{s:2:"en";a:8:{s:2:"id";s:1:"1";s:6:"active";s:1:"1";s:11:"native_name";s:7:"English";s:7:"missing";s:1:"0";s:15:"translated_name";s:7:"English";s:13:"language_code";s:2:"en";s:16:"country_flag_url";s:43:"http://yourdomain/wpmlpath/res/flags/en.png";s:3:"url";s:23:"http://yourdomain/about";}s:2:"fr";a:8:{s:2:"id";s:1:"4";s:6:"active";s:1:"0";s:11:"native_name";s:9:"Français";s:7:"missing";s:1:"0";s:15:"translated_name";s:6:"French";s:13:"language_code";s:2:"fr";s:16:"country_flag_url";s:43:"http://yourdomain/wpmlpath/res/flags/fr.png";s:3:"url";s:29:"http://yourdomain/fr/a-propos";}s:2:"it";a:8:{s:2:"id";s:2:"27";s:6:"active";s:1:"0";s:11:"native_name";s:8:"Italiano";s:7:"missing";s:1:"0";s:15:"translated_name";s:7:"Italian";s:13:"language_code";s:2:"it";s:16:"country_flag_url";s:43:"http://yourdomain/wpmlpath/res/flags/it.png";s:3:"url";s:26:"http://yourdomain/it/circa";}}';
		// $icl_get_languages = unserialize($icl_get_languages);

		$icl_get_languages = icl_get_languages( 'skip_missing=0' );

		foreach ( $icl_get_languages as $lang => $data ) {
			$languages[ $data['language_code'] ] = $data['native_name'];
		}
	}

	return apply_filters( 'woi_pdf_multilingual_languages', $languages );
}

/**
 * Get image mime type
 *
 * @param string $src
 * @return string
 */
function woi_pdf_get_image_mime_type( string $src ): string {
	$mime_type = '';

	if ( empty( $src ) ) {
		return $mime_type;
	}

	// Check if 'getimagesize' function exists and try to get mime type for local files
	if ( function_exists( 'getimagesize' ) && ! filter_var( $src, FILTER_VALIDATE_URL ) ) {
		$image_info = @getimagesize( $src );

		if ( $image_info && isset( $image_info['mime'] ) ) {
			$mime_type = $image_info['mime'];
		}
	}

	// Fallback to 'finfo_file' if mime type is empty for local files only (no remote files allowed)
	if ( empty( $mime_type ) && function_exists( 'finfo_open' ) && ! filter_var( $src, FILTER_VALIDATE_URL ) ) {
		$finfo = finfo_open( FILEINFO_MIME_TYPE );

		if ( $finfo ) {
			$mime_type = finfo_file( $finfo, $src );

			if ( PHP_VERSION_ID < 80100 ) {
				finfo_close( $finfo );
			}
		}
	}

	// Handle remote files
	if ( empty( $mime_type ) && filter_var( $src, FILTER_VALIDATE_URL ) ) {
		$context = stream_context_create( array(
			'http' => array(
				'method'        => 'HEAD',
				'ignore_errors' => true,
			),
			'https' => array(
				'method'           => 'HEAD',
				'ignore_errors'    => true,
				'verify_peer'      => false,
				'verify_peer_name' => false,
			),
		) );

		$headers = @get_headers( $src, 1, $context );

		if ( $headers ) {
			if ( isset( $headers['Content-Type'] ) ) {
				$mime_type = is_array( $headers['Content-Type'] ) ? $headers['Content-Type'][0] : $headers['Content-Type'];
			}
		}
	}

	// Fetch the actual image data if MIME type is still unknown (remote files)
	if ( empty( $mime_type ) && filter_var( $src, FILTER_VALIDATE_URL ) ) {
		$response = wp_remote_get( $src );

		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$image_data = wp_remote_retrieve_body( $response );

			if ( $image_data && function_exists( 'finfo_open' ) ) {
				$finfo = finfo_open( FILEINFO_MIME_TYPE );

				if ( $finfo ) {
					$mime_type = finfo_buffer( $finfo, $image_data );

					if ( PHP_VERSION_ID < 80100 ) {
						finfo_close( $finfo );
					}
				}
			}
		}
	}

	// Determine using WP functions
	if ( empty( $mime_type ) ) {
		$path      = wp_parse_url( $src, PHP_URL_PATH );
		$file_info = wp_check_filetype( $path );
		$mime_type = $file_info['type'] ?? '';
	}

	// Last chance, determine from file extension
	if ( empty( $mime_type ) ) {
		$path      = parse_url( $src, PHP_URL_PATH );
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		switch ( $extension ) {
			case 'jpg':
			case 'jpeg':
				$mime_type = 'image/jpeg';
				break;
			case 'png':
				$mime_type = 'image/png';
				break;
			case 'gif':
				$mime_type = 'image/gif';
				break;
			case 'bmp':
				$mime_type = 'image/bmp';
				break;
			case 'webp':
				$mime_type = 'image/webp';
				break;
			case 'svg':
				$mime_type = 'image/svg+xml';
				break;
		}
	}

	return $mime_type;
}

/**
 * Base64 encode file from local path
 *
 * @param string $local_path
 *
 * @return string|bool
 */
function woi_pdf_base64_encode_file( string $local_path ) {
	if ( empty( $local_path ) ) {
		return false;
	}

	$file_data = WOI_PDF()->file_system->get_contents( $local_path );

	return $file_data ? base64_encode( $file_data ) : false;
}

/**
 * Check if a file is readable
 *
 * @param string $path
 * @return bool
 */
function woi_pdf_is_file_readable( string $path ): bool {
	if ( empty( $path ) ) {
		return false;
	}

	// Check if the path is a URL
	if ( filter_var( $path, FILTER_VALIDATE_URL ) ) {
		$parsed_url = wp_parse_url( $path );
		$args	    = array();

		// Check if the URL is localhost
		if (
			'localhost' === $parsed_url['host']                                             ||
			'127.0.0.1' === $parsed_url['host']                                             ||
			( preg_match( '/^192\.168\./', $parsed_url['host'] ) === 1 )                    || // 192.168.*
			( preg_match( '/^10\./', $parsed_url['host'] ) === 1 )                          || // 10.*
			( preg_match( '/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $parsed_url['host'] ) === 1 ) || // 172.16.* to 172.31.*
			getenv( 'DISABLE_SSL_VERIFY' ) === 'true'
		) {
			$args['sslverify'] = false;
		}

		$args     = apply_filters( 'woi_pdf_url_remote_head_args', $args, $parsed_url, $path );
		$response = wp_safe_remote_head( $path, $args );

		if ( is_wp_error( $response ) ) {
			woi_pdf_log_error( 'Failed to access file URL: ' . $path . ' Error: ' . $response->get_error_message(), 'critical' );
			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		return ( $status_code === 200 );

	// Local path file check
	} else {
		if ( WOI_PDF()->file_system->is_readable( $path ) ) {
			return true;
		} else {
			// Fallback to checking file readability by attempting to open it
			$file_contents = WOI_PDF()->file_system->get_contents( $path );

			if ( $file_contents ) {
				return true;
			} else {
				woi_pdf_log_error( 'Failed to open local file: ' . $path, 'critical' );
				return false;
			}
		}
	}
}

/**
 * Get image source in base64 format
 *
 * @param string $src
 *
 * @return string
 */
function woi_pdf_get_image_src_in_base64( string $src ): string {
	if ( empty( $src ) ) {
		return $src;
	}

	$mime_type = woi_pdf_get_image_mime_type( $src );

	if ( empty( $mime_type ) ) {
		woi_pdf_log_error( 'Unable to determine image mime type for file: ' . $src, 'critical' );
		return $src;
	}

	$image_base64 = woi_pdf_base64_encode_file( $src );

	if ( ! $image_base64 ) {
		woi_pdf_log_error( 'Unable to encode image source to base64:' . $src, 'critical' );
		return $src;
	}

	return 'data:' . $mime_type . ';base64,' . $image_base64;
}

/**
 * Determine if the checkout is a block.
 *
 * @return bool
 */
function woi_pdf_checkout_is_block(): bool {
	$checkout_page_id = wc_get_page_id( 'checkout' );

	$is_block = $checkout_page_id &&
		function_exists( 'has_block' ) &&
		has_block( 'woocommerce/checkout', $checkout_page_id );

	if ( ! $is_block ) {
		$is_block = class_exists( '\\WC_Blocks_Utils' ) &&
			count( \WC_Blocks_Utils::get_blocks_from_page( 'woocommerce/checkout', 'checkout' ) ) > 0;
	}

	if ( ! $is_block ) {
		$is_block = class_exists( '\\Automattic\\WooCommerce\\Blocks\\Utils\\CartCheckoutUtils' ) &&
			is_callable( array( '\\Automattic\\WooCommerce\\Blocks\\Utils\\CartCheckoutUtils', 'is_checkout_block_default' ) ) &&
			\Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::is_checkout_block_default();
	}

	return $is_block;
}

/**
 * Get the default table headers for the Simple template.
 *
 * @param object $document
 * @return array
 */
function woi_pdf_get_simple_template_default_table_headers( $document ): array {
	$headers = array(
		'product'  => __( 'Product', 'woocommerce-orders-invoice-pdf' ),
		'quantity' => __( 'Quantity', 'woocommerce-orders-invoice-pdf' ),
		'price'    => __( 'Price', 'woocommerce-orders-invoice-pdf' ),
	);

	if ( 'packing-slip' === $document->get_type() ) {
		unset( $headers['price'] );
	}

	return apply_filters( 'woi_pdf_simple_template_default_table_headers', $headers, $document );
}

/**
 * Get the WP_Filesystem instance
 *
 * @return WP_Filesystem|false
 * @throws RuntimeException
 */
function woi_pdf_get_wp_filesystem() {
	woi_pdf_deprecated_function( 'woi_pdf_get_wp_filesystem', '4.2.0', '\WOI\PDF\Compatibility\FileSystem::instance()->wp_filesystem' );

	if ( class_exists( '\\WOI\\PDF\\Compatibility\\FileSystem' ) ) {
		$filesystem = \WOI\PDF\Compatibility\FileSystem::instance();
		$filesystem->initialize_wp_filesystem();
		return $filesystem->wp_filesystem ?? false;
	}

	return false;
}

/**
 * Escapes a URL, filesystem path, or base64 string for safe output in HTML.
 *
 * @param string $url_path_or_base64
 * @return string
 */
function woi_pdf_escape_url_path_or_base64( string $url_path_or_base64 ): string {
	// Check if it's a URL
	if ( 0 === strpos( $url_path_or_base64, 'http' ) ) {
		return esc_url( $url_path_or_base64 );
	}

	// Check if it's a base64 string
	if ( preg_match( '/^data:[a-zA-Z0-9\/\-\.\+]+;base64,/', $url_path_or_base64 ) ) {
		return esc_attr( $url_path_or_base64 );
	}

	// Otherwise, assume it's a filesystem path
	return esc_attr( wp_normalize_path( $url_path_or_base64 ) );
}

/**
 * Dynamic string translation
 *
 * @param string $string
 * @param string $textdomain
 * @return string
 */
function woi_pdf_dynamic_translate( string $string, string $textdomain ): string {
	static $cache       = array();
	static $logged      = array();

	$cache_key          = md5( $textdomain . '::' . $string );
	$log_enabled        = ! empty( WOI_PDF()->settings->debug_settings['log_missing_translations'] );
	$multilingual_class = '\WOI\PDF\Multilingual_Full';
	$translation        = $string;

	// Return early if empty string
	if ( '' === $string ) {
		if ( $log_enabled && ! isset( $logged[ $cache_key ] ) ) {
			woi_pdf_log_error( "Skipping translation for empty string in textdomain: {$textdomain}", 'warning' );
			$logged[ $cache_key ] = true;
		}
		return $string;
	}

	// Check cache
	if ( isset( $cache[ $cache_key ] ) ) {
		return $cache[ $cache_key ];
	}

	// Attempt to get a translation from multilingual class
	if ( class_exists( $multilingual_class ) && method_exists( $multilingual_class, 'maybe_get_string_translation' ) ) {
		$translation = $multilingual_class::maybe_get_string_translation( $string, $textdomain );
	}

	// If not translated yet, try native translate() first, then custom filters
	if ( $translation === $string && function_exists( 'translate' ) ) {
		$translation = translate( $string, $textdomain ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.WP.I18n.NonSingularStringLiteralDomain, WordPress.WP.I18n.LowLevelTranslationFunction
	}

	// If still not translated, try custom filters
	if ( $translation === $string ) {
		$translation = woi_pdf_gettext( $string, $textdomain );
	}

	// Log a warning if no translation is found and debug logging is enabled
	if ( $translation === $string && $log_enabled && ! isset( $logged[ $cache_key ] ) ) {
		woi_pdf_log_error( "Missing translation for: {$string} in textdomain: {$textdomain}", 'warning' );
		$logged[ $cache_key ] = true;
	}

	// Store in cache and return
	$cache[ $cache_key ] = $translation;
	return $cache[ $cache_key ];
}

/**
 * Get text translation
 *
 * @param string $string
 * @param string $textdomain
 * @return string
 */
function woi_pdf_gettext( string $string, string $textdomain ): string {
	$filtered = apply_filters( 'woi_pdf_gettext', $string, $textdomain );

	if ( ! empty( $filtered ) && $filtered !== $string ) {
		$translation = $filtered;
	} else {
		// standard WP gettext filters
		$translation = apply_filters( 'gettext', $string, $string, $textdomain );
		$translation = apply_filters( "gettext_{$textdomain}", $translation, $string, $textdomain );
	}

	return $translation;
}

/**
 * Check if the order is VAT exempt.
 *
 * @param \WC_Abstract_Order $order
 * @return bool
 */
function woi_pdf_order_is_vat_exempt( \WC_Abstract_Order $order ): bool {
	if ( 'shop_order_refund' === $order->get_type() ) {
		$order = wc_get_order( $order->get_parent_id() );

		if ( ! $order ) {
			return false;
		}
	}

	// Check if order is VAT exempt based on order meta
	$vat_exempt_meta_key = apply_filters( 'woi_pdf_order_vat_exempt_meta_key', 'is_vat_exempt', $order );
	$is_vat_exempt       = apply_filters(  'woocommerce_order_is_vat_exempt', 'yes' === $order->get_meta( $vat_exempt_meta_key ), $order );

	// Fallback to customer VAT exemption if order is not exempt
	if ( ! $is_vat_exempt && apply_filters( 'woi_pdf_order_vat_exempt_fallback_to_customer', true, $order ) ) {
		$customer_id  = is_callable( array( $order, 'get_customer_id' ) )
			? $order->get_customer_id()
			: 0;

		if ( $customer_id > 0 ) {
			$customer      = new \WC_Customer( $customer_id );
			$is_vat_exempt = $customer->is_vat_exempt();
		}
	}

	// Check VAT exemption for EU orders based on VAT number and tax details
	if ( ! $is_vat_exempt && apply_filters( 'woi_pdf_order_vat_exempt_fallback_to_customer_vat_number', true, $order ) ) {
		$is_eu_order = in_array(
			$order->get_billing_country(),
			WC()->countries->get_european_union_countries( 'eu_vat' ),
			true
		);

		if ( $is_eu_order && $order->get_total() > 0 && $order->get_total_tax() == 0 ) {
			$vat_number    = woi_pdf_get_order_customer_vat_number( $order );
			$is_vat_exempt = ! empty( $vat_number );
		}
	}

	return apply_filters( 'woi_pdf_is_vat_exempt_order', $is_vat_exempt, $order );
}

/**
 * Retrieve the customer VAT number from order meta.
 *
 * @param \WC_Abstract_Order $order
 * @return string|null
 */
function woi_pdf_get_order_customer_vat_number( \WC_Abstract_Order $order ): ?string {
	$vat_meta_keys = apply_filters( 'woi_pdf_order_customer_vat_number_meta_keys', array(
		'vat_number',             // Manually added to the order's custom fields
		'_vat_number',            // WooCommerce EU VAT Number
		'_billing_vat_number',    // WooCommerce EU VAT Number 2.3.21+
		'VAT Number',             // WooCommerce EU VAT Compliance
		'_eu_vat_evidence',       // Aelia EU VAT Assistant
		'_billing_eu_vat_number', // EU VAT Number for WooCommerce (WP Whale/former Algoritmika)
		'yweu_billing_vat',       // YITH WooCommerce EU VAT
		'billing_vat',            // German Market
		'_billing_vat_id',        // Germanized Pro
		'_shipping_vat_id',       // Germanized Pro (alternative)
		'_billing_dic',           // EU/UK VAT Manager for WooCommerce
		'_billing_eu_vat',        // WooCommerce Eu Vat & B2B (WCEV)
		'_billing_btw_nummer'     // Some Belgium customers use this key as a custom field
	), $order );

	// Maybe add General Checkout Field key
	if ( empty( WOI_PDF()->frontend ) ) {
		$frontend = \WOI\PDF\Frontend::instance();
	} else {
		$frontend = WOI_PDF()->frontend;
	}

	if ( ! empty( $frontend ) && is_callable( array( $frontend, 'checkout_field_is_vat_number' ) ) ) {
		$checkout_field_is_vat_number = $frontend->checkout_field_is_vat_number();

		if ( $checkout_field_is_vat_number ) {
			array_unshift( $vat_meta_keys, '_woi_pdf_checkout_field' );
		}
	}

	$vat_number = null;

	foreach ( $vat_meta_keys as $meta_key ) {
		$meta_value = $order->get_meta( $meta_key );

		// Handle multidimensional VAT data (e.g., Aelia EU VAT Assistant)
		if ( '_eu_vat_evidence' === $meta_key && is_array( $meta_value ) ) {
			$meta_value = $meta_value['exemption']['vat_number'] ?? '';
		}

		if ( $meta_value ) {
			$vat_number = $meta_value;
			break;
		}
	}

	return apply_filters( 'woi_pdf_order_customer_vat_number', $vat_number, $order, $meta_key ?? null );
}

/**
 * The supplier's (shop) VAT/TRN number from the general settings.
 *
 * @return string
 */
function woi_pdf_get_supplier_trn(): string {
	$general = get_option( 'woi_pdf_settings_general', array() );
	$trn     = is_array( $general ) ? ( $general['vat_number'] ?? '' ) : '';
	return trim( (string) $trn );
}

/**
 * The customer's VAT/TRN stored at the customer (user) level.
 *
 * Mirrors the "Treat as VAT number" checkout field, which persists to user
 * meta and is reused across that customer's orders. Returns empty unless the
 * checkout field is configured as a VAT number.
 *
 * @param int $customer_id
 * @return string
 */
function woi_pdf_get_customer_profile_trn( $customer_id ): string {
	$customer_id = (int) $customer_id;
	if ( $customer_id <= 0 ) {
		return '';
	}

	// Dedicated, admin-managed customer TRN (set on the customer edit screen).
	$trn = trim( (string) get_user_meta( $customer_id, '_woi_pdf_customer_trn', true ) );
	if ( '' !== $trn ) {
		return $trn;
	}

	// Otherwise fall back to the checkout field saved on the customer, but only
	// when that field is configured to hold a VAT number.
	$general = get_option( 'woi_pdf_settings_general', array() );
	if ( empty( $general['checkout_field_as_vat_number'] ) ) {
		return '';
	}

	return trim( (string) get_user_meta( $customer_id, 'woi_pdf_checkout_field', true ) );
}

/**
 * Register a "TRN" field on the customer edit screen (under billing).
 *
 * Hooked to `woocommerce_customer_meta_fields`; WooCommerce renders and saves
 * it to the `_woi_pdf_customer_trn` user meta automatically.
 *
 * @param array $fields
 * @return array
 */
function woi_pdf_add_customer_trn_field( $fields ): array {
	$fields = is_array( $fields ) ? $fields : array();

	if ( ! isset( $fields['billing'] ) || ! is_array( $fields['billing'] ) ) {
		$fields['billing'] = array();
	}
	if ( ! isset( $fields['billing']['fields'] ) || ! is_array( $fields['billing']['fields'] ) ) {
		$fields['billing']['fields'] = array();
	}

	$fields['billing']['fields']['_woi_pdf_customer_trn'] = array(
		'label'       => __( 'TRN', 'woocommerce-orders-invoice-pdf' ),
		'description' => __( 'Tax Registration Number shown on this customer\'s tax invoices.', 'woocommerce-orders-invoice-pdf' ),
	);

	return $fields;
}

/**
 * The recipient's (customer) VAT/TRN for an order.
 *
 * Resolves from the order first (captured checkout field or a VAT plugin),
 * then falls back to the customer's saved profile value so it behaves as a
 * customer-level field even for orders created in the admin.
 *
 * @param \WC_Abstract_Order|mixed $order
 * @return string
 */
function woi_pdf_get_recipient_trn( $order ): string {
	if ( ! $order instanceof \WC_Abstract_Order ) {
		return '';
	}

	$trn = trim( (string) woi_pdf_get_order_customer_vat_number( $order ) );
	if ( '' === $trn ) {
		$trn = woi_pdf_get_customer_profile_trn( $order->get_customer_id() );
	}

	return $trn;
}

/**
 * Build a labelled TRN line for the document, or an empty string when there is
 * no value to show.
 *
 * @param string $value
 * @param string $label
 * @return string
 */
function woi_pdf_format_trn_line( string $value, string $label ): string {
	$value = trim( $value );
	if ( '' === $value ) {
		return '';
	}

	return sprintf(
		'<div class="trn-number"><span class="label">%1$s</span> %2$s</div>',
		esc_html( $label ),
		esc_html( $value )
	);
}

/**
 * Prepare an identifier query for use with $wpdb->prepare().
 *
 * @param string $query
 * @param array  $identifiers Identifiers for %i placeholders.
 * @param array  $values      Regular values for %s, %d, etc.
 * @return string|void
 */
function woi_pdf_prepare_identifier_query( string $query, array $identifiers = array(), array $values = array() ) {
	global $wpdb;

	$has_identifier_escape = version_compare( get_bloginfo( 'version' ), '6.2', '>=' );

	if ( $has_identifier_escape ) {
		// Combine both arrays in the order the placeholders appear
		$all_placeholders = array();
		$identifier_index = 0;
		$value_index      = 0;
		$split            = preg_split( '/(%[a-zA-Z])/', $query, -1, PREG_SPLIT_DELIM_CAPTURE );

		foreach ( $split as $part ) {
			if ( '%i' === $part ) {
				$all_placeholders[] = $identifiers[ $identifier_index++ ] ?? null;
			} elseif ( preg_match( '/^%[sdfb]/', $part ) ) {
				$all_placeholders[] = $values[ $value_index++ ] ?? null;
			}
		}

		$total_placeholders = substr_count( $query, '%i' ) + (int) preg_match_all( '/%[sdfb]/', $query, $matches );
		if ( count( $all_placeholders ) !== $total_placeholders ) {
			woi_pdf_log_error(
				sprintf(
					"The number of passed identifiers/values (%d) does not match the number of placeholders (%d).\nQuery: %s\nIdentifiers: %s\nValues: %s",
					count( $all_placeholders ),
					$total_placeholders,
					$query,
					wp_json_encode( $identifiers ),
					wp_json_encode( $values )
				),
				'critical'
			);
			return;
		}

		return $wpdb->prepare( $query, ...$all_placeholders ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	// Fallback for < 6.2: replace %i manually
	foreach ( $identifiers as &$id ) {
		$id = '`' . woi_pdf_sanitize_identifier( $id ) . '`';
	}

	// Replace %i manually, leave others for prepare()
	$segments = explode( '%i', $query );
	$query    = array_shift( $segments );

	foreach ( $segments as $index => $segment ) {
		$query .= $identifiers[ $index ] . $segment;
	}

	return $wpdb->prepare( $query, ...$values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

/**
 * Sanitize a database identifier (e.g., table or column name).
 *
 * @param string $identifier The identifier to sanitize.
 * @return string The sanitized identifier.
 */
function woi_pdf_sanitize_identifier( string $identifier ): string {
	$pattern = apply_filters( 'woi_pdf_prepare_identifier_regex', '/[^a-zA-Z0-9_\-]/' );
	return preg_replace( $pattern, '', $identifier );
}

/**
 * Get the latest stable and prerelease versions from GitHub.
 *
 * @param string $owner
 * @param string $repo
 * @param int    $cache_duration
 * @return array {
 *     @type array $stable   Latest stable release.
 *     @type array $unstable Latest valid pre-release.
 * }
 */
function woi_pdf_get_latest_releases_from_github( string $owner = 'wpovernight', string $repo = 'woocommerce-orders-invoice-pdf', int $cache_duration = 1800 ): array {
	$option_key   = 'wpo_latest_releases_' . md5( $owner . '/' . $repo );
	$empty_result = array( 'stable' => array(), 'unstable' => array() );
	$cached       = get_option( $option_key );

	if ( $cached && isset( $cached['timestamp'], $cached['data'] ) ) {
		if ( ( time() - $cached['timestamp'] ) < $cache_duration ) {
			return $cached['data'];
		}
	}

	$url      = "https://api.github.com/repos/$owner/$repo/releases?per_page=10";
	$response = wp_remote_get(
		$url,
		array(
			'headers' => array(
				'User-Agent' => sprintf(
					'%s (%s)',
					wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
					home_url()
				),
			),
			'timeout' => 15,
			'accept'  => 'application/vnd.github.v3+json',
		)
	);

	if ( is_wp_error( $response ) ) {
		return $empty_result;
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		return $empty_result;
	}

	$releases = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $releases ) ) {
		return $empty_result;
	}

	$stable   = array();
	$unstable = array();

	foreach ( $releases as $release ) {
		$tag  = $release['tag_name'];
		$name = ltrim( $release['name'], 'v' );

		if ( preg_match( '/-(pr|i)\d+(?:\.\d+)?/i', $tag ) ) {
			continue;
		}

		$release_data = apply_filters( 'woi_pdf_github_release_data', array(
			'name'     => $name,
			'tag'      => $tag,
			'url'      => $release['html_url'],
			'zipball'  => $release['zipball_url'],
			'download' => "https://github.com/{$owner}/{$repo}/releases/download/{$tag}/{$repo}.{$name}.zip"
		), $release, $owner, $repo );

		if ( ! $release['prerelease'] && empty( $stable ) ) {
			$stable = $release_data;

			// Once we find the first stable, we stop.
			break;
		}

		if ( $release['prerelease'] && empty( $unstable ) ) {
			$unstable = $release_data;
		}
	}

	$data = array(
		'stable'   => $stable,
		'unstable' => $unstable,
	);

	// Check if a new prerelease is available
	$last_seen_option_key = 'wpo_last_seen_prerelease_' . md5( $owner . '/' . $repo );
	$last_seen_tag        = get_option( $last_seen_option_key );

	if ( ! empty( $unstable['tag'] ) && $unstable['tag'] !== $last_seen_tag ) {
		update_option( $last_seen_option_key, $unstable['tag'], false );

		/**
		 * Fires when a new GitHub prerelease becomes available.
		 *
		 * @param array  $unstable The new prerelease data.
		 * @param string $owner    GitHub repo owner.
		 * @param string $repo     GitHub repo name.
		 */
		do_action( 'woi_pdf_new_github_prerelease_available', $unstable, $owner, $repo );
	}

	update_option( $option_key, array(
		'timestamp' => time(),
		'data'      => $data,
	), false );

	return $data;
}

/**
 * Get the latest plugin version from the WordPress.org API.
 *
 * @param string $plugin_slug
 * @return string|false
 */
function woi_pdf_get_latest_plugin_version( string $plugin_slug ) {
	// Ensure plugin update info is loaded
	if ( ! function_exists( 'get_site_transient' ) ) {
		require_once ABSPATH . 'wp-includes/option.php';
	}

	$update_plugins = get_site_transient( 'update_plugins' );

	if ( isset( $update_plugins->response[ $plugin_slug ] ) ) {
		return $update_plugins->response[ $plugin_slug ]->new_version;
	}

	// No update available or plugin not found
	return false;
}

/**
 * Get the country name from the country code.
 *
 * @param string $country_code
 *
 * @return string Country name or empty string if not found.
 */
function woi_pdf_get_country_name_from_code( string $country_code ): string {
	$country_code = strtoupper( trim( $country_code ) );
	return \WC()->countries->get_countries()[ $country_code ] ?? '';
}

/**
 * Get the state name from state code and country code.
 *
 * @param string $state_code
 * @param string $country_code
 *
 * @return string State name or empty string if not found.
 */
function woi_pdf_get_state_name_from_code( string $state_code, string $country_code ): string {
	$state_code = $state_name = strtoupper( trim( $state_code ) );
	$states     = woi_pdf_get_country_states( $country_code );

	if ( ! empty( $state_code ) && is_array( $states ) && isset( $states[ $state_code ] ) ) {
		$state_name = $states[ $state_code ];
	}

	return $state_name ?? '';
}

/**
 * Get the address format for a given country.
 *
 * @param string $country_code Country code, like the NL.
 *
 * @return string
 */
function woi_pdf_get_country_address_format( string $country_code ): string {
	$country_code    = strtoupper( trim( $country_code ) );
	$address_formats = \WC()->countries->get_address_formats();

	return ! empty( $country_code ) && ! empty( $address_formats[ $country_code ] )
		? $address_formats[ $country_code ]
		: $address_formats['default'];
}

/**
 * Get the states for a given country code.
 *
 * @param string $country_code
 *
 * @return array
 */
function woi_pdf_get_country_states( string $country_code ): array {
	$states = array();

	if ( ! empty( $country_code ) ) {
		$country_code = strtoupper( trim( $country_code ) );
		$states       = \WC()->countries->get_states( $country_code );
	}

	return $states ?: array();
}

/**
 * Get the formatted address.
 *
 * @param array $address
 *
 * @return string
 */
function woi_pdf_format_address( array $address ): string {
	// Set default values for missing address fields.
	$address['country_code']    = strtoupper( $address['country_code'] ?? '' );
	$address['state_code']      = strtoupper( $address['state_code'] ?? '' );
	$address['country']         = woi_pdf_get_country_name_from_code( $address['country_code'] );
	$address['state']           = woi_pdf_get_state_name_from_code( $address['state_code'], $address['country_code'] );
	$address['state_upper']     = strtoupper( $address['state'] );
	$address['city_upper']      = strtoupper( $address['city'] ?? '' );
	$address['last_name_upper'] = strtoupper( $address['last_name'] ?? '' );
	$address['postcode_upper']  = strtoupper( $address['postcode'] ?? '' );

	// Filter the address before formatting.
	$address = apply_filters( 'woi_pdf_format_address', $address );

	// Get the country address format
	$address_format = woi_pdf_get_country_address_format( $address['country_code'] );

	// Replace placeholders
	$formatted_address = preg_replace_callback(
		'/\{([a-zA-Z0-9_]+)}/',
		function ( $matches ) use ( $address ) {
			return $address[ $matches[1] ] ?? '';
		},
		$address_format
	);

	// Normalize commas and remove extra line breaks.
	$formatted_address = preg_replace(
		array(
			'/,\s*,+/', // Remove consecutive commas
			'/,\s*$/',  // Remove trailing commas
			'/\n\s*\n/' // Remove empty lines
		),
		array( ',', '', "\n" ),
		$formatted_address
	);

	// Trim newline characters from beginning and end.
	$formatted_address = trim( $formatted_address, "\n" );

	// Add additional info if provided.
	if ( ! empty( $address['additional'] ) ) {
		$formatted_address .= "\n" . $address['additional'];
	}

	// Convert to HTML line breaks.
	$formatted_address = nl2br( ltrim( $formatted_address, "\r\n" ) );

	// Remove any new lines.
	$formatted_address = str_replace( "\n", '', $formatted_address );

	return esc_html( $formatted_address );
}

/**
 * Determines whether a specific document type is using historical settings
 * instead of the latest settings.
 *
 * @param string $document_type The document type slug (e.g. 'invoice', 'packing-slip').
 * @return bool True if the document is using historical settings, false if using the latest settings.
 */
function woi_pdf_is_document_using_historical_settings( string $document_type ): bool {
	$document_settings = get_option( 'woi_pdf_documents_settings_' . $document_type, array() );
	$is_using          = true;

	// this setting is inverted on the frontend so that it needs to be actively/purposely enabled to be used
	if ( ! empty( $document_settings ) && isset( $document_settings['use_latest_settings'] ) ) {
		$is_using = false;
	}

	return apply_filters( 'woi_pdf_is_document_using_historical_settings', $is_using, $document_settings, $document_type );
}


/**
 * Formats a document number by applying a prefix, suffix, and optional padding,
 * with support for dynamic placeholders based on order and document dates.
 *
 * Available placeholders in prefix and suffix:
 * - [order_year], [order_month], [order_day]
 * - [invoice_year], [invoice_month], [invoice_day] (uses $document->slug)
 * - [order_number]
 * - [order_date="{date_format}"], [invoice_date="{date_format}"] (with $document->slug as type)
 *
 * @param int|null                         $plain_number The base document number (unformatted).
 * @param string|null                      $prefix       The prefix string (may contain placeholders).
 * @param string|null                      $suffix       The suffix string (may contain placeholders).
 * @param int|null                         $padding      Number of digits for zero-padding the base number.
 * @param \WOI\PDF\Documents\OrderDocument $document     The document object (e.g. invoice or credit note).
 * @param \WC_Abstract_Order               $order        The WooCommerce order associated with the document.
 *
 * @return string The fully formatted document number.
 */
function woi_pdf_format_document_number(
	?int $plain_number,
	?string $prefix,
	?string $suffix,
	?int $padding,
	\WOI\PDF\Documents\OrderDocument $document,
	\WC_Abstract_Order $order
): string {
	// Get dates
	$order_date = $order->get_date_created();

	// Order date can be empty when order is being saved, fallback to current time
	if ( empty( $order_date ) ) {
		$order_date = function_exists( 'wc_string_to_datetime' )
			? wc_string_to_datetime( date_i18n( 'Y-m-d H:i:s' ) )
			: new \WC_DateTime( 'now', wp_timezone() );
	}

	$document_date = $document->get_date();
	// fallback to order date if no document date available
	if ( empty( $document_date ) ) {
		$document_date = $order_date;
	}

	// load replacement values
	$order_year     = $order_date->date_i18n( 'Y' );
	$order_month    = $order_date->date_i18n( 'm' );
	$order_day      = $order_date->date_i18n( 'd' );
	$document_year  = $document_date->date_i18n( 'Y' );
	$document_month = $document_date->date_i18n( 'm' );
	$document_day   = $document_date->date_i18n( 'd' );

	$order_number = '';
	// get order number
	if ( is_callable( array( $order, 'get_order_number' ) ) ) { // order
		$order_number = $order->get_order_number();
	} elseif ( $document->is_refund( $order ) ) { // refund order
		$parent_order = $document->get_refund_parent( $order );

		if ( ! empty( $parent_order ) && is_callable( array( $parent_order, 'get_order_number' ) ) ) {
			$order_number = $parent_order->get_order_number();
		}
	}

	// get format settings
	$formats = array(
		'prefix' => $prefix,
		'suffix' => $suffix,
	);

	$placeholder_value = apply_filters(
		'woi_pdf_format_document_number_placeholder_value',
		array(
			'order_year'              => $order_year,
			'order_month'             => $order_month,
			'order_day'               => $order_day,
			'order_number'            => $order_number,
			"{$document->slug}_year"  => $document_year,
			"{$document->slug}_month" => $document_month,
			"{$document->slug}_day"   => $document_day,
		),
		$plain_number,
		$prefix,
		$suffix,
		$padding,
		$document,
		$order
	);

	// make replacements
	foreach ( $formats as $key => $value ) {
		if ( empty( $value ) ) {
			continue;
		}

		foreach ( $placeholder_value as $placeholder => $replacement ) {
			$value = str_replace( "[{$placeholder}]", $replacement, $value );
		}

		// replace date tag in the form [invoice_date="{$date_format}"] or [order_date="{$date_format}"]
		$date_types = array( 'order', $document->slug );
		foreach ( $date_types as $date_type ) {
			if ( false !== strpos( $value, "[{$date_type}_date=" ) ) {
				preg_match_all( "/\[{$date_type}_date=\"(.*?)\"\]/", $value, $document_date_tags );

				if ( ! empty( $document_date_tags[1] ) ) {
					foreach ( $document_date_tags[1] as $match_id => $date_format ) {
						if ( 'order' === $date_type ) {
							$value = str_replace( $document_date_tags[0][ $match_id ], $order_date->date_i18n( $date_format ), $value );
						} else {
							$value = str_replace( $document_date_tags[0][ $match_id ], $document_date->date_i18n( $date_format ), $value );
						}
					}
				}
			}
		}
		$formats[ $key ] = $value;
	}

	// Padding
	if ( ! empty( $padding ) ) {
		$plain_number = sprintf( '%0' . intval( $padding ) . 'd', $plain_number );
	}

	// Add prefix & suffix
	return $formats['prefix'] . $plain_number . $formats['suffix'];
}

/**
 * Outputs item meta data.
 *
 * This is a customized version of the WooCommerce function `wc_display_item_meta()`,
 * which uses the `get_all_formatted_meta_data()` method instead of `get_formatted_meta_data()`.
 *
 * @param WC_Order_Item $item Order item object.
 * @param array         $args Optional. Display arguments.
 *
 * @return string|void Meta data HTML output or void if echoed directly.
 */

function woi_pdf_display_item_meta( \WC_Order_Item $item, array $args = array() ) {
	$strings = array();
	$html    = '';
	$args    = wp_parse_args(
		$args,
		array(
			'before'       => '<ul class="wc-item-meta"><li>',
			'after'        => '</li></ul>',
			'separator'    => '</li><li>',
			'echo'         => true,
			'autop'        => false,
			'label_before' => '<strong class="wc-item-meta-label">',
			'label_after'  => ':</strong> ',
		)
	);

	$meta_data = method_exists( $item, 'get_all_formatted_meta_data' )
		? $item->get_all_formatted_meta_data()
		: $item->get_formatted_meta_data();

	foreach ( $meta_data as $meta_id => $meta ) {
		$value     = $args['autop'] ? wp_kses_post( $meta->display_value ) : wp_kses_post( make_clickable( trim( $meta->display_value ) ) );
		$strings[] = $args['label_before'] . wp_kses_post( $meta->display_key ) . $args['label_after'] . $value;
	}

	if ( $strings ) {
		$html = $args['before'] . implode( $args['separator'], $strings ) . $args['after'];
	}

	$html = apply_filters(
		'woi_pdf_display_item_meta_html',
		apply_filters( 'woocommerce_display_item_meta', $html, $item, $args ),
		$item,
		$args
	);

	if ( $args['echo'] ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $html;
	} else {
		return $html;
	}
}

/**
 * Check if the order has a local pickup shipping method.
 *
 * @param \WC_Abstract_Order $order
 *
 * @return bool
 */
function woi_pdf_order_has_local_pickup_method( \WC_Abstract_Order $order ): bool {
	$has_local_pickup_method = false;

	if ( $order instanceof \WC_Order_Refund ) {
		return $has_local_pickup_method;
	}

	if ( ! class_exists( '\Automattic\WooCommerce\Utilities\ArrayUtil' ) ) {
		return $has_local_pickup_method;
	}

	$local_pickup_methods = apply_filters( 'woocommerce_local_pickup_methods', array( 'legacy_local_pickup', 'local_pickup' ) );
	$shipping_method_ids  = \Automattic\WooCommerce\Utilities\ArrayUtil::select( $order->get_shipping_methods(), 'get_method_id', \Automattic\WooCommerce\Utilities\ArrayUtil::SELECT_BY_OBJECT_METHOD );

	if ( count( array_intersect( $shipping_method_ids, $local_pickup_methods ) ) > 0 ) {
		$has_local_pickup_method = true;
	}

	return $has_local_pickup_method;
}

/**
 * Add multiple filters.
 *
 * @param array $filters Array of filters to add.
 * @return void
 */
function woi_pdf_add_filters( array $filters ): void {
	foreach ( $filters as $filter ) {
		$args = woi_pdf_normalize_filter_args( $filter );
		if ( $args['is_valid'] && ! empty( $args['callback'] ) ) {
			add_filter( $args['hook_name'], $args['callback'], $args['priority'], $args['accepted_args'] );
		}
	}
}

/**
 * Remove multiple filters.
 *
 * @param array $filters Array of filters to remove.
 * @return void
 */
function woi_pdf_remove_filters( array $filters ): void {
	foreach ( $filters as $filter ) {
		$args = woi_pdf_normalize_filter_args( $filter );
		if ( $args['is_valid'] && ! empty( $args['callback'] ) ) {
			remove_filter( $args['hook_name'], $args['callback'], $args['priority'] );
		}
	}
}

/**
 * Normalize filter arguments.
 *
 * @param array $filter Filter arguments.
 * @return array
 */
function woi_pdf_normalize_filter_args( array $filter ): array {
	$args      = array_values( $filter );
	$hook_name = '';
	$callback  = '';
	$is_valid  = true;

	// Validate minimum array structure
	if ( count( $args ) < 2 ) {
		woi_pdf_log_error( 'Filter array must contain at least hook name and callback.', 'critical' );
		$is_valid = false;
	} else {
		// Validate and sanitize hook name
		$hook_name = isset( $args[0] ) ? sanitize_text_field( $args[0] ) : '';
		if ( empty( $hook_name ) ) {
			woi_pdf_log_error( 'Empty or invalid hook name provided for filter.', 'critical' );
			$is_valid = false;
		}

		// Validate callback
		if ( isset( $args[1] ) && is_callable( $args[1] ) ) {
			$callback = $args[1];
		} elseif ( isset( $args[1] ) ) {
			woi_pdf_log_error( sprintf(
				'Non-callable callback provided for filter "%s": %s',
				$hook_name,
				is_string( $args[1] ) ? $args[1] : gettype( $args[1] )
			), 'critical' );
			$is_valid = false;
		} else {
			woi_pdf_log_error( sprintf(
				'No callback provided for filter "%s".',
				$hook_name
			), 'critical' );
			$is_valid = false;
		}
	}

	$priority      = isset( $args[2] ) ? absint( $args[2] ) : 10;
	$accepted_args = isset( $args[3] ) ? absint( $args[3] ) : 1;

	return compact( 'hook_name', 'callback', 'priority', 'accepted_args', 'is_valid' );
}

/**
 * Get refund IDs for given order IDs or order object.
 *
 * @param \WC_Order|int|int[] $order_or_ids Order object or order ID(s).
 * @return int[] Unique array of refund IDs.
 */
function woi_pdf_get_refund_ids( $order_or_ids ) {
	$refund_ids = array();

	// Normalize input to an array of IDs.
	if ( $order_or_ids instanceof WC_Order ) {
		$order_ids = array( $order_or_ids->get_id() );
	} elseif ( is_array( $order_or_ids ) ) {
		$order_ids = array_map( 'absint', $order_or_ids );
	} else {
		$order_ids = array( absint( $order_or_ids ) );
	}

	foreach ( $order_ids as $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			continue;
		}

		foreach ( $order->get_refunds() as $refund ) {
			$refund_ids[] = $refund->get_id();
		}
	}

	// Clean output: remove empty, dedupe, reindex
	return array_values( array_unique( array_filter( $refund_ids ) ) );
}

/**
 * Safely format any setting value for report output.
 *
 * @param mixed $value
 * @return string
 */
function woi_pdf_format_report_setting_value( $value ): string {
	// Booleans
	if ( is_bool( $value ) ) {
		return $value
			? '<span class="badge badge-enabled">Enabled</span>'
			: '<span class="badge badge-disabled">Disabled</span>';
	}

	// Null / empty
	if ( is_null( $value ) || $value === '' ) {
		return '<em>None</em>';
	}

	// Strings
	if ( is_string( $value ) ) {
		$normalized = strtolower( trim( $value ) );

		if ( in_array( $normalized, array( 'enabled', 'yes', 'true', 'on' ), true ) ) {
			return '<span class="badge badge-enabled">Enabled</span>';
		}

		if ( in_array( $normalized, array( 'disabled', 'no', 'false', 'off' ), true ) ) {
			return '<span class="badge badge-disabled">Disabled</span>';
		}

		if ( in_array( $normalized, array( 'restricted', 'limited', 'partial', 'deprecated', 'experimental', 'warning' ), true ) ) {
			return '<span class="badge badge-warning">' . esc_html( ucfirst( $value ) ) . '</span>';
		}

		return esc_html( $value );
	}

	// Arrays
	if ( is_array( $value ) ) {

		// Directory permissions array (value, status, status_message)
		if ( isset( $value['value'], $value['status'], $value['status_message'] ) ) {
			$html  = '<div class="config-item">';
			$html .= '<div class="config-value"><strong>Value:</strong> ' . esc_html( $value['value'] ) . '</div>';

			$html .= '<div class="config-status"><strong>Status:</strong> ';
			if ( 'ok' === $value['status'] ) {
				$html .= '<span class="badge badge-enabled">' . esc_html( $value['status_message'] ) . '</span>';
			} else {
				$html .= '<span class="badge badge-disabled">' . esc_html( $value['status_message'] ) . '</span>';
			}
			$html .= '</div>';

			if ( ! empty( $value['description'] ) ) {
				$html .= '<div class="config-description"><em>' . esc_html( $value['description'] ) . '</em></div>';
			}

			$html .= '</div>';

			return $html;
		}

		// Server config array (required/value/result[/fallback])
		if ( isset( $value['required'] ) || isset( $value['value'] ) || isset( $value['result'] ) ) {
			$html = '<div class="config-item">';

			if ( ! empty( $value['required'] ) ) {
				$html .= '<div class="config-required"><strong>Required:</strong> ' . $value['required'] . '</div>';
			}

			if ( isset( $value['value'] ) && '' !== $value['value'] ) {
				$html .= '<div class="config-value"><strong>Value:</strong> ' . woi_pdf_format_report_setting_value( $value['value'] ) . '</div>';
			}

			if ( array_key_exists( 'result', $value ) ) {
				$result = (bool) $value['result'];

				$html .= '<div class="config-result"><strong>Result:</strong> ';
				if ( $result ) {
					$html .= '<span class="badge badge-enabled">OK</span>';
				} else {
					$html .= '<span class="badge badge-warning">Not OK</span>';
				}
				$html .= '</div>';
			}

			if ( ! empty( $value['fallback'] ) && empty( $value['result'] ) ) {
				$html .= '<div class="config-fallback"><em>' . $value['fallback'] . '</em></div>';
			}

			$html .= '</div>';

			return $html;
		}

		// Generic fallback for multidimensional arrays
		$items = array();
		foreach ( $value as $key => $val ) {
			$items[] = esc_html( (string) $key ) . ': ' . woi_pdf_format_report_setting_value( $val );
		}

		return '<ul style="margin:0; padding-left:15px;"><li>' . implode( '</li><li>', $items ) . '</li></ul>';
	}

	// Objects
	if ( is_object( $value ) ) {
		return '<pre style="margin:0;">' . esc_html( print_r( $value, true ) ) . '</pre>'; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
	}

	// Numbers and everything else
	return esc_html( (string) $value );
}

/**
 * Build plugin data array from a list of plugin file paths.
 *
 * @param array $plugin_files Array of plugin file paths (e.g., 'plugin-folder/plugin-file.php').
 * @return array
 */
function woi_pdf_get_plugins_data( array $plugin_files ): array {
	$plugins           = array();
	$installed_plugins = get_plugins();

	foreach ( $plugin_files as $plugin_file ) {
		// Check if the plugin is installed.
		if ( ! isset( $installed_plugins[ $plugin_file ] ) ) {
			continue;
		}

		$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file );

		if ( ! empty( $plugin_data ) ) {
			$plugins[ $plugin_file ] = array(
				'name'      => $plugin_data['Name'],
				'version'   => $plugin_data['Version'],
				'is_active' => is_plugin_active( $plugin_file ),
			);
		}
	}

	return $plugins;
}

/**
 * Check if the current page contains the WooCommerce classic checkout (block or shortcode).
 *
 * @return bool
 */
function woi_pdf_current_page_has_checkout_shortcode(): bool {
	if ( is_admin() ) {
		return (bool) apply_filters(
			'woi_pdf_current_page_has_checkout_shortcode',
			false,
			0,
			null
		);
	}

	$page_id = get_queried_object_id();
	if ( ! $page_id ) {
		return (bool) apply_filters(
			'woi_pdf_current_page_has_checkout_shortcode',
			false,
			0,
			null
		);
	}

	$post = get_post( $page_id );
	if ( ! $post instanceof \WP_Post ) {
		return (bool) apply_filters(
			'woi_pdf_current_page_has_checkout_shortcode',
			false,
			$page_id,
			null
		);
	}

	$content = (string) $post->post_content;

	// Block-based "Classic Shortcode" wrapper.
	if ( function_exists( 'has_block' ) && has_block( 'woocommerce/classic-shortcode', $content ) ) {
		$blocks = function_exists( 'parse_blocks' ) ? parse_blocks( $content ) : array();

		$has_checkout = static function( array $blocks ) use ( &$has_checkout ): bool {
			foreach ( $blocks as $block ) {
				if ( empty( $block['blockName'] ) ) {
					continue;
				}

				if ( 'woocommerce/classic-shortcode' === $block['blockName'] ) {
					$shortcode = $block['attrs']['shortcode'] ?? '';
					if ( 'checkout' === $shortcode ) {
						return true;
					}
				}

				if ( ! empty( $block['innerBlocks'] ) && $has_checkout( $block['innerBlocks'] ) ) {
					return true;
				}
			}

			return false;
		};

		if ( $has_checkout( $blocks ) ) {
			return (bool) apply_filters(
				'woi_pdf_current_page_has_checkout_shortcode',
				true,
				$page_id,
				$post
			);
		}
	}

	// Legacy shortcode-based checkout page.
	$result = function_exists( 'has_shortcode' ) && (
		has_shortcode( $content, 'woocommerce_checkout' ) ||
		has_shortcode( $content, 'checkout' )
	);

	return (bool) apply_filters(
		'woi_pdf_current_page_has_checkout_shortcode',
		$result,
		$page_id,
		$post
	);
}

/**
 * Check if the current page contains the WooCommerce checkout block.
 *
 * @return bool
 */
function woi_pdf_current_page_has_checkout_block(): bool {
	if ( is_admin() ) {
		return (bool) apply_filters(
			'woi_pdf_current_page_has_checkout_block',
			false,
			0,
			null
		);
	}

	$page_id = get_queried_object_id();
	if ( ! $page_id ) {
		return (bool) apply_filters(
			'woi_pdf_current_page_has_checkout_block',
			false,
			0,
			null
		);
	}

	$post = get_post( $page_id );
	if ( ! $post instanceof WP_Post ) {
		return (bool) apply_filters(
			'woi_pdf_current_page_has_checkout_block',
			false,
			$page_id,
			null
		);
	}

	// Native block detection.
	if ( function_exists( 'has_block' ) && has_block( 'woocommerce/checkout', $post ) ) {
		return (bool) apply_filters(
			'woi_pdf_current_page_has_checkout_block',
			true,
			$page_id,
			$post
		);
	}

	$blocks = function_exists( 'parse_blocks' ) ? parse_blocks( $post->post_content ) : array();
	$result = woi_pdf_blocks_contain( $blocks, 'woocommerce/checkout' );

	return (bool) apply_filters(
		'woi_pdf_current_page_has_checkout_block',
		$result,
		$page_id,
		$post
	);
}

/**
 * Recursively check if blocks contain a specific block name.
 *
 * @param array  $blocks The array of blocks to search through.
 * @param string $needle The block name to search for (e.g., 'woocommerce/checkout').
 * @return bool True if the block is found, false otherwise.
 */
function woi_pdf_blocks_contain( array $blocks, string $needle ): bool {
	if ( empty( $blocks ) ) {
		return false;
	}

	foreach ( $blocks as $block ) {
		if ( ! empty( $block['blockName'] ) && $needle === $block['blockName'] ) {
			return true;
		}

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			if ( woi_pdf_blocks_contain( $block['innerBlocks'], $needle ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Check if the current page is the configured WooCommerce checkout page.
 *
 * @return bool
 */
function woi_pdf_is_current_page_checkout_page(): bool {
	if ( is_admin() ) {
		return false;
	}

	$page_id = get_queried_object_id();
	if ( ! $page_id ) {
		return false;
	}

	$checkout_page_id = (int) get_option( 'woocommerce_checkout_page_id' );

	return $checkout_page_id > 0 && $checkout_page_id === (int) $page_id;
}

/**
 * Register an additional checkout block field.
 *
 * @param array $options
 * @return void
 */
function woi_pdf_register_additional_checkout_field( array $options ): void {
	if ( ! defined( 'WC_VERSION' ) || version_compare( WC_VERSION, '8.9.0', '<' ) ) {
		return;
	}
	
	if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) && defined( 'WC_PLUGIN_FILE' ) ) {
		$file                 = dirname( WC_PLUGIN_FILE ) . '/src/Blocks/Domain/Services/functions.php';
		$file_system_instance = WOI_PDF()->file_system ?? null;
		$file_system_instance = $file_system_instance
			? $file_system_instance
			: \WOI\PDF\Compatibility\FileSystem::instance();
		
		if ( $file_system_instance->is_readable( $file ) ) {
			include_once $file;
		}
	}

	woocommerce_register_additional_checkout_field( $options );
}

/**
 * Get the date types available for bulk document export (e.g. Summary).
 *
 * @return array
 */
function woi_pdf_get_export_bulk_date_types(): array {
	return apply_filters( 'woi_pdf_export_bulk_date_type_options', array(
		'order_date'    => __( 'Order date', 'woocommerce-orders-invoice-pdf' ),
		'document_date' => __( 'Document date', 'woocommerce-orders-invoice-pdf' ),
	) );
}

/**
 * Get WooCommerce payment method options.
 *
 * @return array
 */
function woi_pdf_get_payment_method_options(): array {
	$payment_methods = array();

	if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
		return $payment_methods;
	}

	foreach ( WC()->payment_gateways()->payment_gateways() as $gateway_id => $gateway ) {
		$payment_methods[ $gateway_id ] = ! empty( $gateway->method_title )
			? $gateway->method_title
			: $gateway_id;
	}

	return $payment_methods;
}

/**
 * Get WooCommerce BACS account options.
 *
 * @return array
 */
function woi_pdf_get_bacs_account_options(): array {
	$bacs_accounts        = get_option( 'woocommerce_bacs_accounts', array() );
	$bacs_account_options = array();

	if ( empty( $bacs_accounts ) || ! is_array( $bacs_accounts ) ) {
		return $bacs_account_options;
	}

	foreach ( $bacs_accounts as $index => $account ) {
		$account_name = ! empty( $account['account_name'] )
			? $account['account_name']
			: __( 'Unnamed account', 'woocommerce-orders-invoice-pdf' );

		$iban = ! empty( $account['iban'] ) ? $account['iban'] : '';
		$bic  = ! empty( $account['bic'] ) ? $account['bic'] : '';

		$label = $account_name;

		if ( ! empty( $iban ) ) {
			$label .= ' - ' . $iban;
		} elseif ( ! empty( $bic ) ) {
			$label .= ' - ' . $bic;
		}

		$bacs_account_options[ (string) $index ] = $label;
	}

	return $bacs_account_options;
}

// EDI extension stubs — the EDI/UBL extension is not included in this build.
// These stubs keep base-plugin code paths from fataling when the extension is absent.
if ( ! function_exists( 'woi_pdf_edi_is_available' ) ) {
	function woi_pdf_edi_is_available(): bool { return false; }
}
if ( ! function_exists( 'woi_pdf_edi_preview_is_enabled' ) ) {
	function woi_pdf_edi_preview_is_enabled(): bool { return false; }
}
if ( ! function_exists( 'woi_pdf_edi_send_attachments' ) ) {
	function woi_pdf_edi_send_attachments(): bool { return false; }
}
if ( ! function_exists( 'woi_pdf_edi_peppol_is_available' ) ) {
	function woi_pdf_edi_peppol_is_available(): bool { return false; }
}
if ( ! function_exists( 'woi_pdf_edi_get_tax_settings' ) ) {
	function woi_pdf_edi_get_tax_settings(): array { return array(); }
}
if ( ! function_exists( 'woi_pdf_edi_get_order_customer_identifiers_data' ) ) {
	function woi_pdf_edi_get_order_customer_identifiers_data( $order ): array { return array(); }
}
if ( ! function_exists( 'woi_pdf_edi_peppol_identifier_input_mode' ) ) {
	function woi_pdf_edi_peppol_identifier_input_mode(): string { return 'simple'; }
}
if ( ! function_exists( 'woi_pdf_edi_vat_number_has_country_prefix' ) ) {
	function woi_pdf_edi_vat_number_has_country_prefix( $vat ): bool { return false; }
}
if ( ! function_exists( 'woi_pdf_edi_generate_action_button_html' ) ) {
	function woi_pdf_edi_generate_action_button_html( ...$args ): string { return ''; }
}
if ( ! function_exists( 'woi_pdf_edi_write_file' ) ) {
	function woi_pdf_edi_write_file( $document, $save = false, $contents_only = false ) { return false; }
}
if ( ! function_exists( 'woi_pdf_edi_file_headers' ) ) {
	function woi_pdf_edi_file_headers( $quoted, $size ): void {}
}
if ( ! function_exists( 'woi_pdf_edi_save_order_taxes' ) ) {
	function woi_pdf_edi_save_order_taxes( $order ): void {}
}
if ( ! function_exists( 'woi_pdf_edi_maybe_save_order_peppol_data' ) ) {
	function woi_pdf_edi_maybe_save_order_peppol_data( $order, $values = array() ): void {}
}
if ( ! function_exists( 'woi_pdf_edi_peppol_save_customer_identifiers' ) ) {
	function woi_pdf_edi_peppol_save_customer_identifiers( $customer_id, $values ): void {}
}

// Template helper functions (ported from woocommerce-pdf-ips-templates, renamed wpo_ → woi_).

if ( ! function_exists( 'woi_pdf_templates_is_product_bundles_plugin_active' ) ) {
	function woi_pdf_templates_is_product_bundles_plugin_active(): bool {
		return class_exists( 'WC_Bundles' );
	}
}

if ( ! function_exists( 'woi_pdf_templates_get_table_headers' ) ) {
	function woi_pdf_templates_get_table_headers( $document ) {
		$column_settings = \WOI\PDF\Editor\EditorSettings::instance()->get_settings( $document->get_type(), 'columns', $document );
		$order_discount  = $document->get_order_discount( 'total', 'incl' );
		$taxes           = $document->get_order_taxes();

		if ( ! empty( $column_settings ) ) {
			end( $column_settings );
			$column_settings[ key( $column_settings ) ]['position'] = 'last';
			reset( $column_settings );
			$column_settings[ key( $column_settings ) ]['position'] = 'first';
		}

		$headers = array();
		foreach ( $column_settings as $column_key => $column_setting ) {
			if ( ! $order_discount && isset( $column_setting['only_discounted'] ) ) {
				continue;
			}
			if ( 'vat' === $column_setting['type'] && isset( $column_setting['split'] ) && ! empty( $taxes ) ) {
				foreach ( $taxes as $tax ) {
					$title      = $tax['label'] . ' (' . $tax['rate'] . ')';
					$new_column = array(
						'split' => '1',
						'title' => apply_filters( 'woi_pdf_vat_split_column_title', $title, $tax ),
						'class' => 'vat-split',
						'type'  => 'vat',
					);
					$new_column_key             = $column_key . '_' . $tax['rate_id'];
					$headers[ $new_column_key ] = $column_setting + $new_column + \WOI\PDF\Editor\EditorMain::instance()->get_order_details_header( $new_column, $document );
				}
			} else {
				$headers[ $column_key ] = $column_setting + \WOI\PDF\Editor\EditorMain::instance()->get_order_details_header( $column_setting, $document );
			}
		}

		return apply_filters( 'woi_pdf_templates_table_headers', $headers, $document->get_type(), $document );
	}
}

if ( ! function_exists( 'woi_pdf_templates_get_table_body' ) ) {
	function woi_pdf_templates_get_table_body( $document ) {
		$column_settings = \WOI\PDF\Editor\EditorSettings::instance()->get_settings( $document->get_type(), 'columns', $document );
		$order_discount  = $document->get_order_discount( 'total', 'incl' );
		$taxes           = $document->get_order_taxes();

		if ( ! empty( $column_settings ) ) {
			end( $column_settings );
			$column_settings[ key( $column_settings ) ]['position'] = 'last';
			reset( $column_settings );
			$column_settings[ key( $column_settings ) ]['position'] = 'first';
		}

		$body  = array();
		$items = $document->get_order_items();

		if ( sizeof( $items ) > 0 ) {
			foreach ( $column_settings as $column_key => $column_setting ) {
				$line_number = 1;
				foreach ( $items as $item_id => $item ) {
					if ( ! $order_discount && isset( $column_setting['only_discounted'] ) ) {
						continue;
					}
					$column_setting['line_number'] = $line_number;

					if ( 'vat' === $column_setting['type'] && isset( $column_setting['split'] ) && ! empty( $taxes ) ) {
						$item_taxes                   = $item['item']->get_taxes();
						$item_subtotal_taxes          = isset( $item_taxes['subtotal'] ) ? $item_taxes['subtotal'] : array();
						$filtered_item_subtotal_taxes = array_filter( $item_subtotal_taxes );
						$multiple                     = ! empty( $filtered_item_subtotal_taxes ) && count( $filtered_item_subtotal_taxes ) > 1;

						foreach ( $taxes as $tax ) {
							$split = array();
							foreach ( $item_taxes as $item_tax_type => $item_tax_values ) {
								$value                   = ! empty( $item_tax_values[ $tax['rate_id'] ] ) ? $item_tax_values[ $tax['rate_id'] ] : 0;
								$split[ $item_tax_type ] = floatval( $value );
							}
							if ( ! isset( $split['subtotal'] ) && isset( $split['total'] ) ) {
								$split['subtotal'] = $split['total'];
							}
							$split['multiple'] = $multiple;
							$split['tax_rate'] = $tax['rate'];
							if ( is_callable( array( $item['item'], 'get_subtotal' ) ) && is_callable( array( $item['item'], 'get_total' ) ) ) {
								$split['discount'] = floatval( $item['item']->get_subtotal() - $item['item']->get_total() );
							} else {
								$split['discount'] = 0;
							}
							$split['discount_tax'] = floatval( $item['item']['line_subtotal_tax'] - $item['item']['line_tax'] );

							$new_column = array(
								'type'          => $column_setting['type'],
								'split'         => $split,
								'dash_for_zero' => isset( $column_setting['dash_for_zero'] ),
								'label'         => $column_setting['label'],
								'price_type'    => $column_setting['price_type'],
								'discount'      => $column_setting['discount'],
								'width'         => isset( $column_setting['width'] ) ? $column_setting['width'] : '',
							);
							$new_column_key                      = $column_key . '_' . $tax['rate_id'];
							$body[ $item_id ][ $new_column_key ] = $new_column + \WOI\PDF\Editor\EditorMain::instance()->get_order_details_data( $new_column, $item, $document );
						}
					} else {
						$body[ $item_id ][ $column_key ] = $column_setting + \WOI\PDF\Editor\EditorMain::instance()->get_order_details_data( $column_setting, $item, $document );
					}
					$line_number++;
				}
			}
		}

		return apply_filters( 'woi_pdf_templates_table_body', $body, $document->get_type(), $document );
	}
}

if ( ! function_exists( 'woi_pdf_templates_get_totals' ) ) {
	function woi_pdf_templates_get_totals( $document ) {
		$total_settings = \WOI\PDF\Editor\EditorSettings::instance()->get_settings( $document->get_type(), 'totals', $document );
		$totals_data    = \WOI\PDF\Editor\EditorMain::instance()->get_totals_table_data( $total_settings, $document );
		return apply_filters( 'woi_pdf_templates_totals', $totals_data, $document->get_type(), $document );
	}
}

if ( ! function_exists( 'woi_pdf_templates_get_footer_settings' ) ) {
	function woi_pdf_templates_get_footer_settings( $document, $default_height = '5cm' ) {
		$footer_height = str_replace( ' ', '', \WOI\PDF\Editor\EditorSettings::instance()->get_footer_height() );
		if ( empty( $footer_height ) ) {
			$footer_height = $default_height;
		}
		$page_bottom = floatval( $footer_height );
		if ( strpos( $footer_height, 'in' ) !== false ) {
			$page_bottom = $page_bottom * 2.54;
		} elseif ( strpos( $footer_height, 'mm' ) !== false ) {
			$page_bottom = $page_bottom / 10;
		}
		$limit_cap   = apply_filters( 'woi_pdf_templates_footer_height_limit', 10 );
		$page_bottom = min( $page_bottom, $limit_cap );
		$footer_height = $page_bottom . 'cm';
		$page_bottom   = ( $page_bottom + 1 ) . 'cm';
		return compact( 'footer_height', 'page_bottom' );
	}
}

if ( ! function_exists( 'woi_pdf_templates_normalize_column_width' ) ) {
	/**
	 * Validate and normalize a column width percentage.
	 *
	 * Accepts a numeric value in the range (0, 100]. Returns the trimmed
	 * numeric string (no unit) or '' when unset/invalid/out of range.
	 *
	 * @param mixed $value Raw width value from settings.
	 * @return string Normalized number as string, or '' if invalid.
	 */
	function woi_pdf_templates_normalize_column_width( $value ): string {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return '';
		}
		$num = (float) $value;
		if ( $num <= 0 || $num > 100 ) {
			return '';
		}
		// Trim trailing zeros: 50.00 -> 50, 12.50 -> 12.5.
		return rtrim( rtrim( sprintf( '%.2f', $num ), '0' ), '.' );
	}
}

if ( ! function_exists( 'woi_pdf_templates_sanitize_column_style' ) ) {
	function woi_pdf_templates_sanitize_column_style( string $css ): string {
		$css = preg_replace( '/\/\*.*?\*\//s', '', $css );
		$css = str_replace( array( "\r", "\n", "\t" ), '', $css );
		$css = trim( $css );
		if ( '' === $css ) {
			return '';
		}
		$allowed_styles = array(
			'color'               => '/^(?:#[0-9a-f]{3,8}|(?:rgb|hsl)a?\([0-9%\s,.]+\)|[a-z]+)$/i',
			'background-color'    => '/^(?:#[0-9a-f]{3,8}|(?:rgb|hsl)a?\([0-9%\s,.]+\)|[a-z]+)$/i',
			'font-size'           => '/^[0-9.]+(?:px|pt|em|rem|%)$/i',
			'font-weight'         => '/^(?:normal|bold|bolder|lighter|\d{3})$/i',
			'font-style'          => '/^(?:normal|italic|oblique)$/i',
			'font-family'         => '/^[a-z0-9,"\-\s]+$/i',
			'line-height'         => '/^[0-9.]+(?:px|pt|em|rem|%)$/i',
			'letter-spacing'      => '/^[0-9.]+(?:px|pt|em|rem|%)$/i',
			'text-align'          => '/^(?:left|right|center|justify)$/i',
			'vertical-align'      => '/^(?:baseline|sub|super|top|middle|bottom|text-bottom)$/i',
			'white-space'         => '/^(?:normal|nowrap|pre|pre-wrap|pre-line)$/i',
			'text-overflow'       => '/^(?:clip|ellipsis)$/i',
			'padding'             => '/^[0-9.\s]+(?:px|pt|em|rem|%)$/i',
			'padding-top'         => '/^[0-9.]+(?:px|pt|em|rem|%)$/i',
			'padding-right'       => '/^[0-9.]+(?:px|pt|em|rem|%)$/i',
			'padding-bottom'      => '/^[0-9.]+(?:px|pt|em|rem|%)$/i',
			'padding-left'        => '/^[0-9.]+(?:px|pt|em|rem|%)$/i',
			'margin'              => '/^[0-9.\s]+(?:px|pt|em|rem|%)$/i',
			'margin-top'          => '/^[0-9.]+(?:px|pt|em|rem|%)$/i',
			'margin-right'        => '/^[0-9.]+(?:px|pt|em|rem|%)$/i',
			'margin-bottom'       => '/^[0-9.]+(?:px|pt|em|rem|%)$/i',
			'margin-left'         => '/^[0-9.]+(?:px|pt|em|rem|%)$/i',
			'border'              => '/^[0-9.]+(?:px|pt|em|rem)?\s+(?:solid|dashed|dotted|double)\s+(?:#[0-9a-f]{3,8}|[a-z]+)$/i',
			'border-radius'       => '/^[0-9.]+(?:px|pt|em|rem|%)$/i',
			'width'               => '/^[0-9.]+(?:px|pt|em|rem|%)$/i',
			'min-width'           => '/^[0-9.]+(?:px|pt|em|rem|%)$/i',
			'max-width'           => '/^[0-9.]+(?:px|pt|em|rem|%)$/i',
			'height'              => '/^[0-9.]+(?:px|pt|em|rem|%)$/i',
			'min-height'          => '/^[0-9.]+(?:px|pt|em|rem|%)$/i',
			'max-height'          => '/^[0-9.]+(?:px|pt|em|rem|%)$/i',
			'background'          => '/^(?!.*\burl\s*\([^\)]*\)).+$/i',
			'background-repeat'   => '/^(?:repeat|repeat-x|repeat-y|no-repeat)$/i',
			'background-position' => '/^(?:left|center|right|top|bottom|[0-9.]+(?:px|%)?\s+[0-9.]+(?:px|%)?)$/i',
			'background-size'     => '/^(?:auto|cover|contain|[0-9.]+(?:px|%)?)$/i',
			'overflow'            => '/^(?:visible|hidden|scroll|auto)$/i',
		);
		$safe_parts = array();
		foreach ( explode( ';', $css ) as $declaration ) {
			if ( false === strpos( $declaration, ':' ) ) {
				continue;
			}
			list( $prop, $val ) = array_map( 'trim', explode( ':', $declaration, 2 ) );
			$prop = strtolower( $prop );
			if ( ! isset( $allowed_styles[ $prop ] ) ) {
				continue;
			}
			$val = preg_replace( '/\bexpression\s*\([^\)]*\)/i', '', $val );
			$val = preg_replace( '/\burl\s*\([^\)]*\)/i', '', $val );
			if ( preg_match( $allowed_styles[ $prop ], $val ) ) {
				$safe_parts[] = $prop . ': ' . $val;
			}
		}
		return $safe_parts ? implode( '; ', $safe_parts ) . ';' : '';
	}
}

if ( ! function_exists( 'woi_pdf_templates_maybe_apply_column_styles' ) ) {
	function woi_pdf_templates_maybe_apply_column_styles( array $column_data, string $target ): string {
		$style = '';

		// Freeform style respects the style_target setting (header / cells / both).
		if ( ! empty( $column_data['style'] ) ) {
			$apply_style = ! isset( $column_data['style_target'] )
				|| 'both'  === $column_data['style_target']
				|| $target === $column_data['style_target'];
			if ( $apply_style ) {
				$style = woi_pdf_templates_sanitize_column_style( $column_data['style'] );
			}
		}

		// Dedicated width applies to BOTH header and cells, and wins over any
		// width coming from the freeform style.
		$width = woi_pdf_templates_normalize_column_width(
			isset( $column_data['width'] ) ? $column_data['width'] : ''
		);
		if ( '' !== $width ) {
			$style = preg_replace( '/(?<![a-z-])width\s*:[^;]*;?/i', '', $style );
			$style = trim( $style );
			if ( '' !== $style && ';' !== substr( $style, -1 ) ) {
				$style .= ';';
			}
			if ( '' !== $style ) {
				$style .= ' ';
			}
			$style .= 'width: ' . $width . '%;';
		}

		$style = preg_replace( '/\s{2,}/', ' ', trim( $style ) );
		if ( '' === $style ) {
			return '';
		}

		return ' style="' . esc_attr( $style ) . '"';
	}
}

if ( ! function_exists( 'woi_pdf_templates_get_shipping_zones' ) ) {
	function woi_pdf_templates_get_shipping_zones(): array {
		$zones         = \WC_Shipping_Zones::get_zones();
		$zones[0]      = \WC_Shipping_Zones::get_zone( 0 );
		return $zones;
	}
}

if ( ! function_exists( 'woi_pdf_templates_get_shipping_method_title' ) ) {
	function woi_pdf_templates_get_shipping_method_title( \WC_Shipping_Method $shipping_method ): string {
		$title = '';
		if ( is_callable( array( $shipping_method, 'get_title' ) ) ) {
			$title = $shipping_method->get_title();
		}
		if ( empty( $title ) && is_callable( array( $shipping_method, 'get_method_title' ) ) ) {
			$title = $shipping_method->get_method_title();
		}
		return apply_filters( 'woi_pdf_templates_get_shipping_method_title', $title, $shipping_method );
	}
}

if ( ! function_exists( 'woi_pdf_templates_generate_shipping_method_label' ) ) {
	function woi_pdf_templates_generate_shipping_method_label( string $suffix, string $title, string $id = '' ): string {
		$label = sprintf( '%s - %s', $title, $suffix );
		return '' === $id ? $label : sprintf( '%s (#%s)', $label, $id );
	}
}

if ( ! function_exists( 'woi_pdf_templates_get_checkout_block_pickup_locations' ) ) {
	function woi_pdf_templates_get_checkout_block_pickup_locations(): array {
		$pickup_locations          = array();
		$pickup_locations_settings = class_exists( '\\Automattic\\WooCommerce\\StoreApi\\Utilities\\LocalPickupUtils' )
			? \Automattic\WooCommerce\StoreApi\Utilities\LocalPickupUtils::get_local_pickup_settings()
			: array();
		$pickup_locations_enabled  = isset( $pickup_locations_settings['enabled'] ) ? $pickup_locations_settings['enabled'] : false;
		$pickup_locations_title    = isset( $pickup_locations_settings['title'] ) ? $pickup_locations_settings['title'] : __( 'Pickup Locations', 'woocommerce-orders-invoice-pdf' );

		if ( $pickup_locations_enabled && class_exists( '\\Automattic\\WooCommerce\\Blocks\\Shipping\\PickupLocation' ) ) {
			foreach ( get_option( 'pickup_location_pickup_locations', array() ) as $index => $location ) {
				if ( wc_string_to_bool( $location['enabled'] ) ) {
					$pl               = new \Automattic\WooCommerce\Blocks\Shipping\PickupLocation();
					$pl->id           = 'pickup_location';
					$pl->instance_id  = $index;
					$pl->title        = sprintf( '%s (%s)', $pickup_locations_title, $location['name'] );
					$pickup_locations[ 'pickup_location:' . $index ] = $pl;
				}
			}
		}

		return apply_filters( 'woi_pdf_templates_get_checkout_block_pickup_locations', $pickup_locations, $pickup_locations_settings );
	}
}

if ( ! function_exists( 'woi_pdf_convert_shipping_methods_to_options' ) ) {
	function woi_pdf_convert_shipping_methods_to_options( array $shipping_methods ): array {
		if ( empty( $shipping_methods ) ) {
			return array();
		}
		$shipping_options = array();
		foreach ( $shipping_methods as $key => $shipping_method ) {
			if ( ! isset( $shipping_method->id ) || ! isset( $shipping_method->instance_id ) || ! isset( $shipping_method->title ) ) {
				continue;
			}
			if ( ! preg_match( '/:\d+$/', $key ) ) {
				$key = $shipping_method->id . ':' . $shipping_method->instance_id;
			}
			if ( $shipping_method instanceof \WC_Shipping_Method && is_callable( array( $shipping_method, 'get_instance_id' ) ) ) {
				$instance_id           = absint( $shipping_method->get_instance_id() );
				$zone                  = \WC_Shipping_Zones::get_zone_by( 'instance_id', $instance_id );
				$zone_id               = $zone && is_callable( array( $zone, 'get_id' ) ) ? $zone->get_id() : 0;
				$zone_name             = $zone && is_callable( array( $zone, 'get_zone_name' ) ) ? $zone->get_zone_name() : '';
				$method_title          = woi_pdf_templates_get_shipping_method_title( $shipping_method );
				$suffix                = ( 0 === $zone_id && empty( $zone_name ) ) ? __( 'Other locations', 'woocommerce-orders-invoice-pdf' ) : $zone_name;
				$shipping_options[$key] = woi_pdf_templates_generate_shipping_method_label( $suffix, $method_title, $instance_id );
			}
		}
		return $shipping_options;
	}
}

if ( ! function_exists( 'woi_pdf_get_all_shipping_zone_methods' ) ) {
	function woi_pdf_get_all_shipping_zone_methods(): array {
		$methods = array();
		foreach ( woi_pdf_templates_get_shipping_zones() as $zone_data ) {
			if ( is_array( $zone_data ) && isset( $zone_data['id'] ) ) {
				$zone = new \WC_Shipping_Zone( $zone_data['id'] );
			} else {
				$zone = $zone_data;
			}
			$methods += $zone->get_shipping_methods();
		}
		return apply_filters( 'woi_pdf_get_all_shipping_zone_methods', $methods );
	}
}

if ( ! function_exists( 'woi_pdf_templates_get_all_shipping_methods_as_options' ) ) {
	function woi_pdf_templates_get_all_shipping_methods_as_options(): array {
		$options  = woi_pdf_convert_shipping_methods_to_options( woi_pdf_get_all_shipping_zone_methods() );
		$options += woi_pdf_convert_shipping_methods_to_options( woi_pdf_templates_get_checkout_block_pickup_locations() );
		return apply_filters( 'woi_pdf_templates_get_all_shipping_methods_as_options', $options );
	}
}

if ( ! function_exists( 'woi_pdf_templates_get_order_shipping_methods' ) ) {
	function woi_pdf_templates_get_order_shipping_methods( \WC_Abstract_Order $order ): array {
		$shipping_methods = array();
		foreach ( $order->get_shipping_methods() as $shipping_item ) {
			$method_id   = is_callable( array( $shipping_item, 'get_method_id' ) ) ? $shipping_item->get_method_id() : '';
			$instance_id = is_callable( array( $shipping_item, 'get_instance_id' ) ) ? $shipping_item->get_instance_id() : '';
			$found       = false;
			foreach ( woi_pdf_templates_get_all_shipping_methods_as_options() as $key => $title ) {
				if ( preg_match( '/:\d+$/', $key ) ) {
					$key_arr = explode( ':', $key );
					if ( $key_arr[0] === $method_id && is_numeric( $instance_id ) && absint( $key_arr[1] ) === absint( $instance_id ) ) {
						$shipping_methods[] = $key;
						$found = true;
						break;
					}
				}
			}
			if ( ! $found ) {
				$shipping_methods[] = $method_id . ':' . $instance_id;
			}
		}
		return apply_filters( 'woi_pdf_templates_get_order_shipping_methods', $shipping_methods, $order );
	}
}

if ( ! function_exists( 'woi_pdf_templates_get_country_groups' ) ) {
	function woi_pdf_templates_get_country_groups(): array {
		$all_countries    = WC()->countries->get_countries();
		$eu_countries     = WC()->countries->get_european_union_countries();
		$eu_vat_countries = WC()->countries->get_european_union_countries( 'eu_vat' );
		return apply_filters( 'woi_pdf_templates_country_groups', array(
			'ASEAN'      => array( 'label' => __( 'ASEAN countries', 'woocommerce-orders-invoice-pdf' ), 'countries' => array( 'BN', 'ID', 'KH', 'LA', 'MM', 'MY', 'PH', 'SG', 'TH', 'VN' ) ),
			'BRICS'      => array( 'label' => __( 'BRICS countries', 'woocommerce-orders-invoice-pdf' ), 'countries' => array( 'BR', 'RU', 'IN', 'CN', 'ZA', 'SA', 'EG', 'AE', 'ET', 'ID', 'IR' ) ),
			'EAC'        => array( 'label' => __( 'EAC countries', 'woocommerce-orders-invoice-pdf' ), 'countries' => array( 'CD', 'BI', 'KE', 'RW', 'SO', 'SS', 'UG', 'TZ' ) ),
			'EFTA'       => array( 'label' => __( 'EFTA countries', 'woocommerce-orders-invoice-pdf' ), 'countries' => array( 'CH', 'IS', 'LI', 'NO' ) ),
			'EU'         => array( 'label' => __( 'EU countries', 'woocommerce-orders-invoice-pdf' ), 'countries' => $eu_countries ),
			'NON_EU'     => array( 'label' => __( 'Non-EU countries', 'woocommerce-orders-invoice-pdf' ), 'countries' => array_diff( array_keys( $all_countries ), $eu_countries ) ),
			'NON_EU_VAT' => array( 'label' => __( 'Non-EU VAT countries', 'woocommerce-orders-invoice-pdf' ), 'countries' => array_diff( array_keys( $all_countries ), $eu_vat_countries ) ),
		) );
	}
}
