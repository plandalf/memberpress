<?php

/**
 * Plandalf for MemberPress — test suite.
 *
 * Runs against a real WordPress + MemberPress install. Every test runs inside
 * a database transaction that is rolled back afterwards, Plandalf's API is
 * faked through `pre_http_request`, and outgoing email is captured.
 *
 *   cd /path/to/wordpress
 *   wp eval-file wp-content/plugins/plandalf-memberpress/tests/run.php            # all
 *   wp eval-file wp-content/plugins/plandalf-memberpress/tests/run.php refund     # filter
 *
 * Exits non-zero when a test fails.
 */
defined('ABSPATH') || exit(1);

require_once __DIR__.'/support.php';

foreach (glob(__DIR__.'/*-test.php') as $file) {
    require_once $file;
}

exit(Plandalf_Test::run((string) ($args[0] ?? '')));
