<?php
namespace WOI\PDF\Documents;

if ( ! defined( 'ABSPATH' ) ) exit;

interface NumberedDocumentInterface extends DocumentInterface {
    /**
     * @return DocumentNumber|string|null DocumentNumber object, or formatted string when called with $formatted = true.
     */
    public function get_number();
    public function set_number( $number, $order = null ): void;
    /**
     * @return \WC_DateTime|string|null WC_DateTime object, or formatted string when called with $formatted = true.
     */
    public function get_date();
    public function has_number(): bool;
}
