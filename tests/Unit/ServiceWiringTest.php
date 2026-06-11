<?php
namespace WOI\PDF\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Static source scan: every member accessed through the WOI_PDF() singleton
 * must be a property assigned (or method defined) in the main plugin class.
 *
 * Regression net for the "service never ported from upstream" bug class
 * (e.g. WOI_PDF()->order_util->is_wc_admin_page() fataling on WC Admin pages).
 */
class ServiceWiringTest extends TestCase {

    public function test_all_singleton_member_accesses_are_wired(): void {
        $root        = dirname( __DIR__, 2 );
        $main_source = file_get_contents( $root . '/woocommerce-orders-invoice-pdf.php' );

        preg_match_all( '/\$this->(\w+)\s*=[^=]/', $main_source, $assigned );
        preg_match_all( '/function\s+(\w+)\s*\(/', $main_source, $methods );
        $available = array_unique( array_merge( $assigned[1], $methods[1] ) );

        $usages = array();
        foreach ( $this->plugin_source_files( $root ) as $file ) {
            $source = file_get_contents( $file );
            if ( preg_match_all( '/WOI_PDF\(\)->(\w+)/', $source, $matches ) ) {
                foreach ( array_unique( $matches[1] ) as $member ) {
                    $usages[ $member ][] = basename( $file );
                }
            }
        }

        $missing = array_diff_key( $usages, array_flip( $available ) );

        $report = '';
        foreach ( $missing as $member => $files ) {
            $report .= sprintf( "\n - %s (used in: %s)", $member, implode( ', ', array_unique( $files ) ) );
        }

        $this->assertSame(
            array(),
            $missing,
            "WOI_PDF() members accessed but never assigned/defined in the main plugin class:{$report}"
        );
    }

    /**
     * @return string[]
     */
    private function plugin_source_files( string $root ): array {
        $files    = array( $root . '/woi-pdf-functions.php' );
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $root . '/includes', \FilesystemIterator::SKIP_DOTS )
        );

        foreach ( $iterator as $file ) {
            if ( $file->isFile() && 'php' === $file->getExtension() ) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
