<?php
// Shared WebWiz library: secrets, SQLite handle, helpers.
// Path: /var/www/sites/trywebwiz/private/webwiz_lib.php

declare(strict_types=1);

// ---- WebWiz release version (semantic; bump on each meaningful deploy) ----
if (!defined('WW_VERSION')) define('WW_VERSION', '1.1.0');

// ---- Deploy identity ----
// WW_VERSION is the human-facing semver. WW_RELEASE adds the commit actually on
// disk so Sentry can attribute a regression to a specific deploy rather than to a
// version string that only changes when someone remembers to bump it.
// private/RELEASE is written by private/stamp-release.sh, which the git post-merge
// and post-checkout hooks call. If the file is missing we fall back to WW_VERSION.
if (!defined('WW_RELEASE')) {
    $__ww_rel = WW_VERSION;
    $__ww_rf  = __DIR__ . '/RELEASE';
    if (is_file($__ww_rf)) {
        $__ww_sha = trim((string) @file_get_contents($__ww_rf));
        // git describe already embeds the tag (e.g. v1.1.0-9-gd41378f), so use it as-is.
        if ($__ww_sha !== '') { $__ww_rel = $__ww_sha; }
    }
    define('WW_RELEASE', $__ww_rel);
    unset($__ww_rel, $__ww_rf, $__ww_sha);
}

function ww_secrets(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = require '/var/www/sites/trywebwiz/secrets.php';
    return $cache;
}

function ww_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $path = '/var/www/sites/trywebwiz/data/webwiz.db';
    if (!is_dir(dirname($path))) {
        @mkdir(dirname($path), 0750, true);
    }
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 10000'); // 2s — fail fast; sustained contention >2s means a bug, not a wait
    // NORMAL fsync is durable enough under WAL and is ~2-3x faster than FULL
    // for writes. Reduces the per-tx hold time so concurrent magic-link
    // generations don't pile up on the writer.
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    ww_migrate($pdo);
    return $pdo;
}

function ww_migrate(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT UNIQUE NOT NULL,
        name TEXT NOT NULL,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL CHECK (role IN ('admin','team_member')) DEFAULT 'team_member',
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        last_login_at TEXT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS prospects (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT,
        name TEXT,
        business_name TEXT,
        current_url TEXT,
        source TEXT NOT NULL DEFAULT 'csv',
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        type TEXT NOT NULL CHECK (type IN ('inbound','outbound')),
        prospect_id INTEGER REFERENCES prospects(id) ON DELETE SET NULL,
        stripe_session_id TEXT,
        customer_email TEXT,
        business_name TEXT,
        scrape_data TEXT,
        status TEXT NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','running','ready','failed','sent','picked','archived')),
        scheduled_for TEXT NOT NULL DEFAULT (datetime('now')),
        started_at TEXT,
        completed_at TEXT,
        error TEXT,
        total_cost_cents INTEGER NOT NULL DEFAULT 0,
        token TEXT UNIQUE,
        picked_variant INTEGER,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS previews (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        job_id INTEGER NOT NULL REFERENCES jobs(id) ON DELETE CASCADE,
        variant_n INTEGER NOT NULL,
        html_path TEXT NOT NULL,
        archived INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS api_calls (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        job_id INTEGER REFERENCES jobs(id) ON DELETE SET NULL,
        provider TEXT NOT NULL DEFAULT 'anthropic',
        model TEXT NOT NULL,
        prompt_tokens INTEGER NOT NULL DEFAULT 0,
        completion_tokens INTEGER NOT NULL DEFAULT 0,
        cost_usd REAL NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS live_sites (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        job_id INTEGER REFERENCES jobs(id) ON DELETE SET NULL,
        slug TEXT UNIQUE NOT NULL,
        domain TEXT,
        owner_email TEXT,
        status TEXT NOT NULL DEFAULT 'building' CHECK (status IN ('building','live','paused','archived')),
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    // Performance indexes for hot queries (prospects list, batch counts, cost rollups, worker selection)
    foreach ([
        "CREATE INDEX IF NOT EXISTS idx_jobs_prospect_id ON jobs(prospect_id)",
        "CREATE INDEX IF NOT EXISTS idx_jobs_upload_batch_id ON jobs(upload_batch_id)",
        "CREATE INDEX IF NOT EXISTS idx_jobs_status_sched ON jobs(status, scheduled_for)",
        "CREATE INDEX IF NOT EXISTS idx_jobs_generation_mode ON jobs(generation_mode)",
        "CREATE INDEX IF NOT EXISTS idx_jobs_status_total_cost ON jobs(status, total_cost_cents)",
        "CREATE INDEX IF NOT EXISTS idx_previews_job_id ON previews(job_id)",
        "CREATE INDEX IF NOT EXISTS idx_api_calls_job_id ON api_calls(job_id)",
        "CREATE INDEX IF NOT EXISTS idx_api_calls_key_label ON api_calls(key_label)",
        "CREATE INDEX IF NOT EXISTS idx_api_calls_provider ON api_calls(provider)",
        "CREATE INDEX IF NOT EXISTS idx_prospects_created ON prospects(created_at, id)",
    ] as $sql) { @$pdo->exec($sql); }
}

function ww_h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function ww_user_by_email(string $email): ?array {
    $st = ww_db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $st->execute([$email]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function ww_user_by_id(int $id): ?array {
    $st = ww_db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function ww_send_email(array $to, string $subject, string $html, ?string $reply_to = null): bool {
    $s = ww_secrets();
    if (empty($s['BREVO_API_KEY'])) return false;
    $payload = [
        'sender'      => ['name' => $s['EMAIL_FROM_NAME'], 'email' => $s['EMAIL_FROM_ADDR']],
        'to'          => [$to],
        'subject'     => $subject,
        'htmlContent' => $html,
    ];
    if ($reply_to) $payload['replyTo'] = ['email' => $reply_to, 'name' => $s['EMAIL_FROM_NAME']];
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['accept: application/json', 'content-type: application/json', 'api-key: ' . $s['BREVO_API_KEY']],
    ]);
    $r = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $http < 300;
}

// ---- Sentry error monitoring -------------------------------------------
// Added 2026-07-29. DSN lives in secrets.php (SeedSite Secrets Manager) and is
// never hardcoded. vendor/ sits under private/ so it is not web-accessible.
// This must never break a page render: everything is guarded.
function ww_sentry_init(): void {
    static $started = false;
    if ($started) { return; }
    $started = true;

    try {
        $autoload = __DIR__ . '/vendor/autoload.php';
        if (!is_file($autoload)) { return; }
        require_once $autoload;

        $s   = ww_secrets();
        $dsn = $s['SENTRY_DSN'] ?? '';
        if ($dsn === '') { return; }

        \Sentry\init([
            'dsn'                => $dsn,
            'environment'        => $s['SENTRY_ENVIRONMENT'] ?? 'production',
            'release'             => defined('WW_RELEASE') ? WW_RELEASE : (defined('WW_VERSION') ? WW_VERSION : null),
            'traces_sample_rate' => 0.1,
            'send_default_pii'   => false,
        ]);

        // Report fatals that PHP would otherwise only write to the error log.
        register_shutdown_function(static function (): void {
            $e = error_get_last();
            if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                \Sentry\captureMessage(
                    sprintf('PHP fatal: %s in %s:%d', $e['message'], $e['file'], $e['line']),
                    \Sentry\Severity::fatal()
                );
                \Sentry\flush(2);
            }
        });
    } catch (\Throwable $t) {
        // Never let monitoring take the site down.
    }
}

ww_sentry_init();

/**
 * Run a DB write, retrying on SQLITE_BUSY ("database is locked", error 5).
 *
 * PRAGMA busy_timeout already makes a writer WAIT for a lock, but it does NOT
 * cover every case: when a DEFERRED transaction tries to upgrade from read to
 * write while another writer has already written, SQLite returns SQLITE_BUSY
 * immediately rather than waiting, because waiting could deadlock. That is the
 * class of failure behind Sentry WEBWIZ-4 / WEBWIZ-5, where the hourly nurture
 * cron collided with a live /api/prospect_add.php transaction at 13:00:02 and
 * threw instead of waiting.
 *
 * This wrapper turns those into a short bounded backoff (~0.1s .. ~1.6s), so a
 * collision blocks and then succeeds instead of surfacing as a 500 or, worse,
 * an uncaught fatal that aborts the whole cron run.
 */
function ww_db_write_retry(callable $fn, int $tries = 6) {
    $delay = 100000; // 100ms, doubled each attempt
    for ($i = 1; ; $i++) {
        try {
            return $fn();
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            $busy = (stripos($msg, 'database is locked') !== false)
                 || (stripos($msg, 'database table is locked') !== false);
            if (!$busy || $i >= $tries) throw $e;
            usleep($delay);
            $delay = min($delay * 2, 1600000);
        }
    }
}
