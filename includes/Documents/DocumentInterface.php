<?php
namespace WOI\PDF\Documents;

if ( ! defined( 'ABSPATH' ) ) exit;

interface DocumentInterface {
    public function get_type(): string;
    public function get_title(): string;
    public function is_enabled(): bool;
    public function exists(): bool;
    public function init( $order ): void;
    public function get_html(): string;
    public function get_settings_fields(): array;
    public function get_settings_option_name(): string;
}
