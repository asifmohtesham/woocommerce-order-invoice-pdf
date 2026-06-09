<?php
namespace WOI\PDF\Documents;

if ( ! defined( 'ABSPATH' ) ) exit;

interface EmailAttachableInterface {
    public function get_attach_to_email_ids(): array;
}
