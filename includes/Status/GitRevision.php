<?php
namespace WOI\PDF\Status;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( '\\WOI\\PDF\\Status\\GitRevision' ) ) :

/**
 * Resolve the deployed code revision without shelling out to git.
 *
 * Reads the plugin's own .git directory (works with a git-pull deploy), then
 * falls back to a generated REVISION file, then reports 'unknown'. Pure
 * filesystem reads against a base directory, so it stays unit-testable.
 */
class GitRevision {

	/**
	 * @param string $base_dir Directory that contains .git/ and/or REVISION (the plugin root).
	 * @return array{sha:string, short:string, branch:string, source:string, timestamp:?int, subject:string}
	 */
	public function read( string $base_dir ): array {
		$base_dir = rtrim( $base_dir, '/\\' );

		$from_git = $this->read_git( $base_dir . '/.git' );
		if ( null !== $from_git ) {
			return $from_git;
		}

		$from_file = $this->read_revision_file( $base_dir . '/REVISION' );
		if ( null !== $from_file ) {
			return $from_file;
		}

		return $this->shape( '', 'unknown', '', null, '' );
	}

	/** @return array|null */
	private function read_git( string $git_dir ) {
		if ( ! is_dir( $git_dir ) || ! is_readable( $git_dir . '/HEAD' ) ) {
			return null;
		}

		$head = trim( (string) file_get_contents( $git_dir . '/HEAD' ) );

		if ( 0 === strpos( $head, 'ref:' ) ) {
			$ref    = trim( substr( $head, 4 ) );
			$branch = preg_replace( '#^refs/heads/#', '', $ref );
			$sha    = $this->resolve_ref( $git_dir, $ref );
		} elseif ( preg_match( '/^[0-9a-f]{40}$/i', $head ) ) {
			$sha    = $head;
			$branch = 'detached';
		} else {
			return null;
		}

		if ( '' === (string) $sha ) {
			return null;
		}

		list( $timestamp, $subject ) = $this->read_head_log( $git_dir . '/logs/HEAD' );

		return $this->shape( $sha, 'git', (string) $branch, $timestamp, $subject );
	}

	/** Resolve a ref to a SHA via a loose ref file, then packed-refs. */
	private function resolve_ref( string $git_dir, string $ref ): string {
		$loose = $git_dir . '/' . $ref;
		if ( is_readable( $loose ) ) {
			return trim( (string) file_get_contents( $loose ) );
		}

		$packed = $git_dir . '/packed-refs';
		if ( is_readable( $packed ) ) {
			foreach ( preg_split( '/\R/', (string) file_get_contents( $packed ) ) as $line ) {
				$line = trim( $line );
				if ( '' === $line || '#' === $line[0] || '^' === $line[0] ) {
					continue;
				}
				$parts = preg_split( '/\s+/', $line, 2 );
				if ( 2 === count( $parts ) && $parts[1] === $ref ) {
					return $parts[0];
				}
			}
		}

		return '';
	}

	/**
	 * Best-effort: pull the deploy/checkout time and message from the last
	 * reflog line. Format: "<old> <new> <name> <unixtime> <tz>\t<message>".
	 *
	 * @return array{0:?int, 1:string}
	 */
	private function read_head_log( string $logfile ): array {
		if ( ! is_readable( $logfile ) ) {
			return array( null, '' );
		}
		$lines = array_filter( preg_split( '/\R/', (string) file_get_contents( $logfile ) ) );
		if ( empty( $lines ) ) {
			return array( null, '' );
		}
		$last = array_pop( $lines );
		$timestamp = null;
		$subject   = '';
		if ( preg_match( '/\b(\d{9,})\s+[+-]\d{4}\t(.*)$/', $last, $m ) ) {
			$timestamp = (int) $m[1];
			$subject   = trim( $m[2] );
		}
		return array( $timestamp, $subject );
	}

	/** @return array|null */
	private function read_revision_file( string $file ) {
		if ( ! is_readable( $file ) ) {
			return null;
		}
		$raw = trim( (string) file_get_contents( $file ) );
		if ( '' === $raw ) {
			return null;
		}

		// Allow either a JSON payload or a plain SHA (optionally "sha branch").
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) && ! empty( $decoded['sha'] ) ) {
			$ts = isset( $decoded['timestamp'] ) ? (int) $decoded['timestamp'] : null;
			return $this->shape(
				(string) $decoded['sha'],
				'file',
				(string) ( $decoded['branch'] ?? '' ),
				$ts,
				(string) ( $decoded['subject'] ?? '' )
			);
		}

		$parts  = preg_split( '/\s+/', $raw, 2 );
		$sha    = $parts[0];
		$branch = $parts[1] ?? '';
		return $this->shape( $sha, 'file', trim( $branch ), null, '' );
	}

	/** @return array{sha:string, short:string, branch:string, source:string, timestamp:?int, subject:string} */
	private function shape( string $sha, string $source, string $branch, ?int $timestamp, string $subject ): array {
		return array(
			'sha'       => $sha,
			'short'     => '' !== $sha ? substr( $sha, 0, 7 ) : '',
			'branch'    => $branch,
			'source'    => $source,
			'timestamp' => $timestamp,
			'subject'   => $subject,
		);
	}
}

endif;
