<?php
namespace WOI\PDF\Tests\Unit\Status;

use PHPUnit\Framework\TestCase;
use WOI\PDF\Status\GitRevision;

class GitRevisionTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/woi-gitrev-' . uniqid();
		mkdir( $this->dir, 0777, true );
	}

	protected function tearDown(): void {
		$this->rrmdir( $this->dir );
		parent::tearDown();
	}

	private function rrmdir( string $path ): void {
		if ( ! is_dir( $path ) ) {
			if ( is_file( $path ) ) { unlink( $path ); }
			return;
		}
		foreach ( scandir( $path ) as $f ) {
			if ( '.' === $f || '..' === $f ) { continue; }
			$this->rrmdir( $path . '/' . $f );
		}
		rmdir( $path );
	}

	private function write( string $rel, string $contents ): void {
		$full = $this->dir . '/' . $rel;
		@mkdir( dirname( $full ), 0777, true );
		file_put_contents( $full, $contents );
	}

	public function test_reads_sha_and_branch_from_loose_ref(): void {
		$sha = '1234567890abcdef1234567890abcdef12345678';
		$this->write( '.git/HEAD', "ref: refs/heads/master\n" );
		$this->write( '.git/refs/heads/master', $sha . "\n" );

		$rev = ( new GitRevision() )->read( $this->dir );

		$this->assertSame( 'git', $rev['source'] );
		$this->assertSame( $sha, $rev['sha'] );
		$this->assertSame( '1234567', $rev['short'] );
		$this->assertSame( 'master', $rev['branch'] );
	}

	public function test_resolves_sha_from_packed_refs_when_loose_missing(): void {
		$sha = 'abcabcabcabcabcabcabcabcabcabcabcabcabca';
		$this->write( '.git/HEAD', "ref: refs/heads/main\n" );
		$this->write( '.git/packed-refs', "# pack-refs with: peeled fully-peeled sorted\n{$sha} refs/heads/main\n" );

		$rev = ( new GitRevision() )->read( $this->dir );

		$this->assertSame( 'git', $rev['source'] );
		$this->assertSame( $sha, $rev['sha'] );
		$this->assertSame( 'main', $rev['branch'] );
	}

	public function test_detached_head_raw_sha(): void {
		$sha = 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef';
		$this->write( '.git/HEAD', $sha . "\n" );

		$rev = ( new GitRevision() )->read( $this->dir );

		$this->assertSame( 'git', $rev['source'] );
		$this->assertSame( $sha, $rev['sha'] );
		$this->assertSame( 'detached', $rev['branch'] );
	}

	public function test_falls_back_to_revision_file_when_no_git(): void {
		$this->write( 'REVISION', "feedface0000feedface0000feedface00001111\n" );

		$rev = ( new GitRevision() )->read( $this->dir );

		$this->assertSame( 'file', $rev['source'] );
		$this->assertSame( 'feedface0000feedface0000feedface00001111', $rev['sha'] );
		$this->assertSame( 'feedfac', $rev['short'] );
	}

	public function test_unknown_when_nothing_present(): void {
		$rev = ( new GitRevision() )->read( $this->dir );
		$this->assertSame( 'unknown', $rev['source'] );
		$this->assertSame( '', $rev['sha'] );
		$this->assertSame( '', $rev['short'] );
	}
}
