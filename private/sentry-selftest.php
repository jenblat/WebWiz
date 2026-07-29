<?php
// Sentry self-test for WebWiz. CLI only. Reports one error and exits.
require_once '/var/www/sites/trywebwiz/private/webwiz_lib.php';

function ww_selftest_dispatch(): void {
    throw new RuntimeException('Sentry self-test from webwiz at ' . gmdate('c'));
}

function ww_selftest_run(): void {
    ww_selftest_dispatch();
}

try {
    ww_selftest_run();
} catch (\Throwable $e) {
    $id = \Sentry\captureException($e);
    echo 'captured event id: ' . $id . PHP_EOL;
}

\Sentry\flush(8);
echo 'flushed to sentry' . PHP_EOL;
