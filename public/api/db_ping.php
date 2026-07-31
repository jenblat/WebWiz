<?php
// /api/db_ping.php - tiny unauthenticated liveness probe that actually EXERCISES
// SQLite, so an external synthetic monitor can detect a database outage.
//
// WHY THIS EXISTS
// SeedTester polls /, /start.html, /api/version.php and /api/sentry.js.php every
// 10 minutes. None of those touch the database - version.php only echoes the
// WW_VERSION constant - so the monitor reported all-green straight through the
// 51-minute SQLite outage on 2026-07-30 (Sentry WEBWIZ-2 / WEBWIZ-3).
//
// WHY IT WRITES RATHER THAN READS
// When the data volume fills up, SQLite READS still succeed; only writes fail
// with "disk I/O error". A read-only probe would therefore have stayed green
// through the exact outage it is meant to catch. This performs one idempotent
// UPSERT against a single fixed key.
//
// WHY IT IS UNAUTHENTICATED
// health_check.php requires a ?key= secret from the settings table, and
// seedtester.js has no env substitution for query-string values. This endpoint
// is safe to expose: it takes NO user input, always writes the same one key,
// and returns nothing but up/down. No schema, counts, settings or error text
// leak - the error detail goes to the server log, not the response.
declare(strict_types=1);
@set_time_limit(15);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

try {
    require_once '/var/www/sites/trywebwiz/private/webwiz_lib.php';
    $db = ww_db();

    // One bounded, idempotent write. No user input reaches this statement.
    $db->prepare("INSERT OR REPLACE INTO settings (key,value) VALUES ('db_ping_ts',?)")
       ->execute([gmdate('Y-m-d H:i:s')]);

    // And one trivial read back, so a half-broken DB does not pass.
    $st = $db->prepare("SELECT value FROM settings WHERE key='db_ping_ts'");
    $st->execute();
    $ts = (string)$st->fetchColumn();

    if ($ts === '') { throw new RuntimeException('readback empty'); }

    echo json_encode(['db' => 'ok', 'rw' => true, 'at' => $ts]);
} catch (Throwable $e) {
    http_response_code(503);
    error_log('[db_ping] FAILED: ' . $e->getMessage());
    echo json_encode(['db' => 'down', 'rw' => false]);
}
