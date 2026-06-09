<?php
namespace WOI\PDF\Documents;

if ( ! defined( 'ABSPATH' ) ) exit;

interface BulkDocumentInterface extends DocumentInterface {
    public function set_order_ids( array $order_ids ): void;
}
