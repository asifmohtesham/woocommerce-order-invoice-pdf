<?php
namespace WOI\PDF\Documents;

interface EmailAttachableInterface {
    public function get_attach_to_email_ids(): array;
}
