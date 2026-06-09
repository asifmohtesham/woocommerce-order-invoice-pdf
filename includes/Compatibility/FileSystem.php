<?php
namespace WOI\PDF\Compatibility;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( '\\WOI\\PDF\\Compatibility\\FileSystem' ) ) :

/**
 * Thin PHP-native filesystem wrapper used throughout the plugin.
 * Methods mirror WP_Filesystem_Direct so callers work with either backend.
 */
class FileSystem {

	protected static ?self $_instance = null;

	public static function instance(): self {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function exists( string $file ): bool {
		return file_exists( $file );
	}

	public function is_dir( string $path ): bool {
		return is_dir( $path );
	}

	public function is_readable( string $file ): bool {
		return is_readable( $file );
	}

	public function is_writable( string $path ): bool {
		return is_writable( $path );
	}

	public function get_contents( string $file ) {
		return file_get_contents( $file );
	}

	/**
	 * Write contents to a file.
	 *
	 * @param string   $file  Absolute file path.
	 * @param string   $data  Content to write.
	 * @param int|bool $mode  File permissions (passed to chmod). False = skip.
	 * @return bool
	 */
	public function put_contents( string $file, string $data, $mode = false ): bool {
		$result = file_put_contents( $file, $data );
		if ( $result === false ) {
			return false;
		}
		if ( $mode !== false ) {
			chmod( $file, $mode );
		}
		return true;
	}

	/**
	 * Create a directory (recursive).
	 *
	 * @return bool
	 */
	public function mkdir( string $path ): bool {
		return wp_mkdir_p( $path );
	}

	/**
	 * Delete a file or directory.
	 *
	 * @param string $path      Path to delete.
	 * @param bool   $recursive Delete directory contents recursively.
	 * @return bool
	 */
	public function delete( string $path, bool $recursive = false ): bool {
		if ( is_dir( $path ) ) {
			if ( $recursive ) {
				$items = array_diff( scandir( $path ), array( '.', '..' ) );
				foreach ( $items as $item ) {
					$this->delete( $path . DIRECTORY_SEPARATOR . $item, true );
				}
			}
			return rmdir( $path );
		}
		return unlink( $path );
	}

	/**
	 * Get file modification time.
	 *
	 * @return int|false Unix timestamp or false on failure.
	 */
	public function mtime( string $file ) {
		return filemtime( $file );
	}

	/**
	 * List directory contents.
	 *
	 * Returns an array of file-info arrays: [ ['name' => 'filename.ext', ...], ... ]
	 * Each entry has at minimum a 'name' key (matching WP_Filesystem_Direct::dirlist format).
	 *
	 * @param string $path           Directory path.
	 * @param bool   $include_hidden Include hidden files (dot-files).
	 * @param bool   $recursive      Recurse into subdirectories.
	 * @return array|false
	 */
	public function dirlist( string $path, bool $include_hidden = true, bool $recursive = false ) {
		if ( ! is_dir( $path ) ) {
			return false;
		}

		$entries = scandir( $path );
		if ( $entries === false ) {
			return false;
		}

		$result = array();
		foreach ( $entries as $entry ) {
			if ( $entry === '.' || $entry === '..' ) {
				continue;
			}
			if ( ! $include_hidden && $entry[0] === '.' ) {
				continue;
			}
			$full = trailingslashit( $path ) . $entry;
			$result[ $entry ] = array(
				'name' => $entry,
				'type' => is_dir( $full ) ? 'd' : 'f',
			);
		}

		return $result;
	}

	/**
	 * Output a file directly to the browser (streams the file).
	 *
	 * @return bool True if the file was read successfully.
	 */
	public function output_file( string $file ): bool {
		return readfile( $file ) !== false;
	}
}

endif;
