<?php
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/vendor/strauss/autoload.php';
require_once dirname( __DIR__ ) . '/woi-pdf-functions.php';

// Brain Monkey needs no WP install — stubs are provided per test via Monkey\setUp()
