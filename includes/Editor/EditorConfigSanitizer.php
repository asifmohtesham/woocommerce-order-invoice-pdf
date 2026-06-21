<?php

namespace WOI\PDF\Editor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure, schema-aware sanitizer for Customiser block rows (columns / totals).
 * No WordPress install required — only relies on sanitize_* + the two global
 * CSS/width helpers (all loaded by tests/bootstrap.php).
 */
class EditorConfigSanitizer {

	/**
	 * @param array $incoming List of assoc rows, each with a 'type'.
	 * @param array $schema   type => array{ options: array<string, array> }.
	 * @return array 1-indexed list of clean rows.
	 */
	public static function sanitize_blocks( array $incoming, array $schema ): array {
		$clean = array();
		$i     = 1;
		foreach ( $incoming as $row ) {
			$row  = (array) $row;
			$type = isset( $row['type'] ) ? sanitize_key( (string) $row['type'] ) : '';
			if ( '' === $type || ! isset( $schema[ $type ] ) ) {
				continue;
			}
			$c       = array( 'type' => $type );
			$options = ( isset( $schema[ $type ]['options'] ) && is_array( $schema[ $type ]['options'] ) )
				? $schema[ $type ]['options'] : array();

			foreach ( $options as $opt_key => $field ) {
				if ( ! is_array( $field ) || ! isset( $field['type'] ) || ! array_key_exists( $opt_key, $row ) ) {
					continue;
				}
				$val = self::sanitize_option( (string) $field['type'], $row[ $opt_key ], $field, (string) $opt_key );
				if ( null !== $val ) {
					$c[ $opt_key ] = $val;
				}
			}

			// Preserve unknown scalar keys (e.g. filter-added wiring) untouched.
			foreach ( $row as $k => $v ) {
				if ( 'type' === $k || isset( $c[ $k ] ) || isset( $options[ $k ] ) || ! is_scalar( $v ) ) {
					continue;
				}
				$c[ sanitize_key( (string) $k ) ] = sanitize_text_field( (string) $v );
			}

			$clean[ $i++ ] = $c;
		}
		return $clean;
	}

	/**
	 * @return string|null Sanitized value, or null to omit the key.
	 */
	public static function sanitize_option( string $widget, $value, array $field, string $opt_key = '' ): ?string {
		switch ( $widget ) {
			case 'checkbox':
				return ( $value && '0' !== (string) $value ) ? '1' : null;

			case 'select':
				$allowed = ( isset( $field['options'] ) && is_array( $field['options'] ) )
					? array_map( 'strval', array_keys( $field['options'] ) ) : array();
				$v = (string) $value;
				return in_array( $v, $allowed, true ) ? $v : null;

			case 'number':
				if ( '' === $value || null === $value ) {
					return null;
				}
				if ( 'width' === $opt_key && function_exists( 'woi_pdf_templates_normalize_column_width' ) ) {
					$w = woi_pdf_templates_normalize_column_width( $value );
					if ( '' === $w ) {
						return null;
					}
					$value = $w; // fall through to min/max clamping below
				}
				if ( ! is_numeric( $value ) ) {
					return null;
				}
				$n = 0 + $value;
				if ( isset( $field['min'] ) && $n < $field['min'] ) {
					$n = $field['min'];
				}
				if ( isset( $field['max'] ) && $n > $field['max'] ) {
					$n = $field['max'];
				}
				return (string) $n;

			case 'text':
				if ( 'style' === $opt_key && function_exists( 'woi_pdf_templates_sanitize_column_style' ) ) {
					return woi_pdf_templates_sanitize_column_style( (string) $value );
				}
				return sanitize_text_field( (string) $value );

			case 'textarea':
				return function_exists( 'sanitize_textarea_field' )
					? sanitize_textarea_field( (string) $value )
					: sanitize_text_field( (string) $value );

			default: // documentation, separator, unknown
				return null;
		}
	}
}
