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
    $pdo->exec('PRAGMA busy_timeout = 10000'); // 10s. The comment here used to say 2s; it never matched the value.
    // NORMAL fsync is durable enough under WAL and is ~2-3x faster than FULL
    // for writes. Reduces the per-tx hold time so concurrent magic-link
    // generations don't pile up on the writer.
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    ww_migrate($pdo);
    return $pdo;
}

/**
 * A NEW, private PDO handle - deliberately NOT the shared static one ww_db()
 * hands out.
 *
 * Why this exists (Sentry WEBWIZ-G, 2026-08-05): an executed-but-unfinalized
 * SELECT pins a WAL read snapshot on its connection. Any later BEGIN IMMEDIATE
 * on that same connection is then a read->write UPGRADE, and SQLite fails those
 * with SQLITE_BUSY *immediately* - it does not consult busy_timeout, and no
 * amount of retrying can clear it, because the snapshot is only released when
 * the offending statement is finalized. One leaked cursor anywhere earlier in
 * the request therefore poisons every subsequent write on that handle.
 *
 * A long request path cannot practically guarantee that no cursor is left open
 * (magic.php is ~1200 lines), so revenue-critical writes take a clean handle
 * instead of hoping. On a fresh handle BEGIN IMMEDIATE behaves correctly: it
 * takes the write lock, or genuinely waits out busy_timeout under real
 * contention.
 *
 * Schema migration is intentionally NOT run here - ww_db() already did it for
 * this request, and running ALTERs on a second handle would only add lock
 * pressure.
 */
function ww_db_fresh(): PDO {
    $pdo = new PDO('sqlite:/var/www/sites/trywebwiz/data/webwiz.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA busy_timeout = 30000');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
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

/**
 * Ensure offer_leads exists and carries the columns the /o price test needs.
 *
 * Added 2026-08-03. offer_leads was created ad-hoc by /api/offer_lead.php and
 * only cell C ever wrote to it, so the table had no way to represent a builder
 * (cell a/b) lead at all: no token to dedupe on and no session id to close the
 * loop against a Stripe payment. Without those, lead-per-view - the single
 * metric the whole price test exists to produce - could not be computed for
 * two of the three cells.
 *
 *   token             links a builder-cell lead to its jobs row, and is the
 *                     dedupe key so one preview cannot produce two leads.
 *   stripe_session_id lets webhook.php and the /o success page mark a lead
 *                     purchased without needing the lead_id to survive the
 *                     round trip through Stripe.
 *   source            which surface wrote the row ('brief' or 'builder').
 *
 * Idempotent, and guarded by a static so it costs one PRAGMA per process.
 */
function ww_offer_leads_ensure(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $db->exec("CREATE TABLE IF NOT EXISTS offer_leads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        variant TEXT NOT NULL,
        business TEXT NOT NULL,
        about TEXT,
        wants TEXT,
        contact TEXT NOT NULL,
        ip TEXT,
        user_agent TEXT,
        status TEXT NOT NULL DEFAULT 'new',
        created_at TEXT NOT NULL
    )");
    $have = [];
    try {
        foreach ($db->query("PRAGMA table_info(offer_leads)") as $c) {
            $have[(string)($c['name'] ?? '')] = true;
        }
    } catch (Throwable $e) { return; }
    foreach ([
        'token'             => "ALTER TABLE offer_leads ADD COLUMN token TEXT",
        'stripe_session_id' => "ALTER TABLE offer_leads ADD COLUMN stripe_session_id TEXT",
        'source'            => "ALTER TABLE offer_leads ADD COLUMN source TEXT",
    ] as $col => $sql) {
        if (!isset($have[$col])) { try { $db->exec($sql); } catch (Throwable $e) { /* raced */ } }
    }
    foreach ([
        "CREATE INDEX IF NOT EXISTS idx_offer_leads_token ON offer_leads(token)",
        "CREATE INDEX IF NOT EXISTS idx_offer_leads_session ON offer_leads(stripe_session_id)",
        "CREATE INDEX IF NOT EXISTS idx_offer_leads_variant ON offer_leads(variant, status)",
    ] as $sql) { try { $db->exec($sql); } catch (Throwable $e) {} }
}

/**
 * ---------------------------------------------------------------------------
 * GUARDED $1 LIVE-PAYMENT TEST CELL (variant 't'). Added 2026-08-03.
 * ---------------------------------------------------------------------------
 * The A/B/C price-test cells are behind live paid traffic, so we have never
 * been able to push a REAL card through the funnel to prove what happens after
 * `checkout.session.completed`. Variant 't' is a fourth cell that runs the
 * exact same code - same /o/_offer.php, same /api/offer_checkout.php, same
 * Stripe Checkout, same /api/webhook.php, same receipt - at $1.00 build +
 * $1.00/month, so the whole chain can be exercised for the price of a coffee.
 *
 * It is NOT a bypass. Nothing is special-cased downstream; 't' is just another
 * row in the offer tables. The ONLY special thing about it is who is allowed
 * to select it, which is what this gate is for.
 *
 * SECURITY MODEL. A $1 price that a stranger can reach is a $1 price we will
 * be held to. So every surface that can CHOOSE variant 't' demands the secret:
 *
 *   /o/t/                 404 without ?k=              (tokenless / brief cell)
 *   /o/t/try/             404 without ?k=              (builder cell)
 *   /try/?offer=t         ignored without ?k=          (bare query string)
 *   /api/magic.php?offer=t  not persisted without &k=  (writes jobs.offer_variant)
 *   /api/offer_checkout.php tokenless -> 't' only with a valid test_key in the
 *                         POST body; otherwise a tokenless request still means
 *                         cell C exactly as before.
 *
 * A checkout that arrives WITH a token is priced from jobs.offer_variant and
 * needs no key, which is correct: that job row could only have been created
 * through the gate above.
 *
 * Fails closed everywhere - missing secret, missing key or a bad key all mean
 * "not the test cell", never "cheapest cell".
 */
function ww_offer_test_key_ok(?string $key): bool {
    $key = (string)$key;
    if ($key === '') return false;
    try { $s = ww_secrets(); } catch (Throwable $e) { return false; }
    $want = (string)($s['OFFER_TEST_KEY'] ?? '');
    if ($want === '') return false;          // not configured => cell unreachable
    return hash_equals($want, $key);
}

/** The test key itself, for building self-referential URLs. '' if unset. */
function ww_offer_test_key(): string {
    try { $s = ww_secrets(); } catch (Throwable $e) { return ''; }
    return (string)($s['OFFER_TEST_KEY'] ?? '');
}

/**
 * Which /o cell a generation request belongs to, for jobs.offer_variant.
 *
 * Used by magic.php at persist time. 'a' and 'b' are the live builder cells and
 * are open, because their prices are the ones the ads actually sell. 't' is the
 * $1 test cell and is only honoured with the secret, so a scraped ?offer=t can
 * never mint a job row that later checks out at $1.
 */
function ww_offer_variant_from_request(): ?string {
    $o = strtolower(trim((string)($_GET['offer'] ?? '')));
    if ($o === 'a' || $o === 'b') return $o;
    if ($o === 't' && ww_offer_test_key_ok((string)($_GET['k'] ?? ''))) return 't';
    return null;
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

        // NO custom shutdown fatal handler here. \Sentry\init() already installs the SDK's
        // own ErrorHandler, which reports fatals AND uncaught exceptions with a real stack
        // trace. A hand-rolled register_shutdown_function() on top of it did not add
        // coverage - it duplicated it: one uncaught exception produced TWO issues, a
        // Sentry\Exception\FatalErrorException (with the trace) and a bare "PHP fatal: ..."
        // captureMessage (without one). That doubled the event count for every fatal, burned
        // quota, and split one incident across two issues during triage.
        //
        // Verified 2026-08-07: a single uncaught RuntimeException raised both WEBWIZ-9 and
        // WEBWIZ-Z. If you are tempted to re-add a shutdown hook, confirm first that the SDK
        // is not already covering the case.
    } catch (\Throwable $t) {
        // Never let monitoring take the site down.
    }
}

ww_sentry_init();

/**
 * ---------------------------------------------------------------------------
 * ww_sentry_alert() - report a GENUINE failure that returns JSON instead of
 * throwing. Added 2026-08-05.
 * ---------------------------------------------------------------------------
 * Why this exists: on 2026-08-03 a race in magic.php meant the buy button on a
 * fresh preview answered "We could not find that preview" for up to 61s. Both
 * builder price-test cells were on that path with live Meta spend behind them.
 * It produced ZERO Sentry events, because every checkout endpoint reports
 * failure by echoing {"ok":false} and calling exit() - never by throwing - and
 * Sentry only sees uncaught throwables and PHP fatals. The dead end was found
 * by hand, days later.
 *
 * Use this for failures the BUSINESS cares about: a buyer turned away, a
 * payment we could not start, an email we could not send, a webhook we could
 * not process. Do NOT use it for ordinary user-side validation (empty field,
 * bad email, rate limit) - that is not a bug, and paging on it trains everyone
 * to ignore the alerts.
 *
 * Fingerprint is (component, reason) so distinct dead ends stay distinct
 * issues; a regression on one path therefore cannot hide inside another.
 * Everything is wrapped: monitoring must never break the request it watches.
 */
function ww_sentry_alert(string $message, array $context = [], string $level = 'error', ?Throwable $ex = null): void {
    try {
        if (!function_exists('Sentry\\withScope') || !function_exists('Sentry\\captureMessage')) return;

        switch ($level) {
            case 'fatal':   $sev = \Sentry\Severity::fatal();   break;
            case 'warning': $sev = \Sentry\Severity::warning(); break;
            case 'info':    $sev = \Sentry\Severity::info();    break;
            default:        $sev = \Sentry\Severity::error();   break;
        }

        $component = (string)($context['component'] ?? 'webwiz');
        $reason    = (string)($context['reason']    ?? $message);

        // Request identity is useful on every one of these and is cheap. No PII:
        // no email, no card, no name - token and variant only.
        $context += [
            'path'       => (string)($_SERVER['REQUEST_URI']    ?? ''),
            'method'     => (string)($_SERVER['REQUEST_METHOD'] ?? 'CLI'),
            'release'    => defined('WW_RELEASE') ? WW_RELEASE : '',
            'at'         => gmdate('c'),
        ];

        \Sentry\withScope(function (\Sentry\State\Scope $scope) use ($message, $context, $sev, $ex, $component, $reason) {
            $scope->setLevel($sev);
            $scope->setTag('component', $component);
            $scope->setTag('reason',    $reason);
            foreach (['variant', 'source', 'http_status', 'event_type'] as $t) {
                if (isset($context[$t]) && $context[$t] !== '' && $context[$t] !== null) {
                    $scope->setTag($t, (string)$context[$t]);
                }
            }
            $scope->setContext('webwiz', $context + ['message' => $message]);
            $scope->setFingerprint(['webwiz', $component, $reason]);
            if ($ex instanceof Throwable) {
                \Sentry\captureException($ex);
            } else {
                \Sentry\captureMessage($message, $sev);
            }
        });

        // These endpoints exit() immediately after alerting, and PHP-FPM/lsapi
        // will not run the async transport on a process that is exiting, so the
        // event has to be pushed synchronously or it is simply lost.
        \Sentry\flush(2);
    } catch (\Throwable $t) {
        // Never let monitoring take the site down. This is the whole reason the
        // original ww_sentry_init() is guarded the same way.
    }
}

/**
 * Throttled front door to ww_sentry_alert(). Use this for ROUTINE reporting; call
 * ww_sentry_alert() directly only where every single occurrence must be sent.
 *
 * Why the throttle is not optional. ww_sentry_alert() ends in a synchronous
 * \Sentry\flush(2) — a blocking network round trip, up to 2s, on the request being
 * watched. Endpoints like img.php and track.php serve every image and every email open
 * on the site, so a broken upstream would mean (a) tens of thousands of events burning
 * the Sentry quota and (b) every one of those requests stalling on a flush. Monitoring
 * would become the outage. One event per (component, reason) per window fixes both.
 *
 * Nothing is lost by suppressing: Sentry groups on the same (component, reason)
 * fingerprint anyway, so repeats only ever incremented a counter. That counter is
 * preserved here and sent as `suppressed_since_last` on the next event that goes out,
 * which is strictly more informative than the raw flood.
 *
 * Pass $throttle_s = 0 on money paths, where every individual occurrence matters.
 */
function ww_report(
    string $component,
    string $reason,
    string $message,
    array $ctx = [],
    string $level = 'error',
    ?Throwable $ex = null,
    int $throttle_s = 300
): void {
    try {
        if ($throttle_s > 0) {
            $key   = preg_replace('/[^A-Za-z0-9_]+/', '_', $component . '__' . $reason);
            $file  = sys_get_temp_dir() . '/ww_rep_' . substr($key, 0, 80);
            $now   = time();
            $state = @json_decode((string)@file_get_contents($file), true);
            if (is_array($state) && ($now - (int)($state['t'] ?? 0)) < $throttle_s) {
                // Inside the window: count it and stay silent.
                $state['n'] = (int)($state['n'] ?? 0) + 1;
                @file_put_contents($file, json_encode($state), LOCK_EX);
                return;
            }
            $suppressed = is_array($state) ? (int)($state['n'] ?? 0) : 0;
            @file_put_contents($file, json_encode(['t' => $now, 'n' => 0]), LOCK_EX);
            if ($suppressed > 0) $ctx['suppressed_since_last'] = $suppressed;
        }
        ww_sentry_alert($message, ['component' => $component, 'reason' => $reason] + $ctx, $level, $ex);
    } catch (\Throwable $t) {
        // Monitoring must never break the request it watches.
    }
}

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
