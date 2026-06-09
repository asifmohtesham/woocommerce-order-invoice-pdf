<?php
namespace WOI\PDF\Documents;

if ( ! defined( 'ABSPATH' ) ) exit;

interface NumberedDocumentInterface extends DocumentInterface {
    public function get_number(): ?DocumentNumber;
    public function set_number( $number, $order = null ): void;
    public function get_date(): ?\WC_DateTime;
    public function has_number(): bool;
}
