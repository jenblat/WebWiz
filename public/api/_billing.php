<?php
// /api/_billing.php — dunning + suspension lifecycle for lapsed subscriptions.
// Library only: direct HTTP access returns nothing (function definitions).
//
// WHY THIS EXISTS
// "Cancel anytime" and "the site comes down when payment stops" are two
// different levers and both are deliberate. Cancel-anytime removes the risk of
// signing up. The site coming down is the retention mechanic the entire
// pay-monthly website category depends on: a site that stays up forever after
// the card stops working is a free site, and the monthly fee becomes optional.
//
// The schedule (owner's call, 2026-08-11):
//   day 0  payment fails  -> email 1, "your card didn't go through"
//   day 3  email 2        -> "your site goes offline in 4 days"
//   day 7  email 3        -> "last warning, tomorrow"
//   day 8  suspend        -> the site is replaced with a holding page
//   any payment success   -> restored instantly, at any point above
//
// Nothing here deletes anything. Suspension MOVES the real index.html aside and
// puts a holding page in its place, so restoring is a rename and a customer can
// never lose their site because of a billing hiccup.
declare(strict_types=1);

const WW_BILLING_WARN2_DAYS   = 3;
const WW_BILLING_WARN3_DAYS   = 7;
const WW_BILLING_SUSPEND_DAYS = 8;
const WW_PREVIEW_ROOT         = '/var/www/sites/trywebwiz/public/preview';

function ww_billing_init_schema(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS billing_lifecycle (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            email           TEXT    NOT NULL,
            customer_id     TEXT    DEFAULT '',
            subscription_id TEXT    DEFAULT '',
            token           TEXT    DEFAULT '',
            business_name   TEXT    DEFAULT '',
            invoice_url     TEXT    DEFAULT '',
            state           TEXT    NOT NULL DEFAULT 'grace',
            failed_at       TEXT    DEFAULT (datetime('now')),
            warn2_at        TEXT,
            warn3_at        TEXT,
            suspended_at    TEXT,
            recovered_at    TEXT,
            updated_at      TEXT    DEFAULT (datetime('now'))
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_billing_state ON billing_lifecycle(state, failed_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_billing_email ON billing_lifecycle(email)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_billing_sub   ON billing_lifecycle(subscription_id)");
}

/**
 * Open (or refresh) a dunning record when an invoice fails.
 *
 * Idempotent per subscription: Stripe retries a failed invoice several times
 * over the dunning window and fires invoice.payment_failed on EACH attempt. If
 * every one of those opened a new row, the clock would reset on every retry and
 * the site would never actually suspend, which is the failure mode where this
 * whole feature quietly does nothing. So an existing open row is left alone and
 * only its invoice URL is refreshed.
 */
function ww_billing_open(PDO $db, array $d): int {
    ww_billing_init_schema($db);
    $email = strtolower(trim((string)($d['email'] ?? '')));
    $sub   = trim((string)($d['subscription_id'] ?? ''));
    if ($email === '' && $sub === '') return 0;

    $st = $db->prepare(
        "SELECT id FROM billing_lifecycle
          WHERE state IN ('grace','suspended')
            AND ((subscription_id <> '' AND subscription_id = ?) OR (? = '' AND email = ?))
          ORDER BY id DESC LIMIT 1"
    );
    $st->execute([$sub, $sub, $email]);
    $existing = (int)$st->fetchColumn();
    if ($existing > 0) {
        try {
            ww_db_write_retry(function () use ($db, $d, $existing) {
                return $db->prepare("UPDATE billing_lifecycle SET invoice_url = ?, updated_at = datetime('now') WHERE id = ?")
                          ->execute([(string)($d['invoice_url'] ?? ''), $existing]);
            });
        } catch (Throwable $e) { error_log('[billing] refresh failed: ' . $e->getMessage()); }
        return $existing;
    }

    try {
        ww_db_write_retry(function () use ($db, $d, $email, $sub) {
            return $db->prepare(
                "INSERT INTO billing_lifecycle (email, customer_id, subscription_id, token, business_name, invoice_url, state)
                 VALUES (?,?,?,?,?,?,'grace')"
            )->execute([
                $email,
                (string)($d['customer_id'] ?? ''),
                $sub,
                (string)($d['token'] ?? ''),
                (string)($d['business_name'] ?? ''),
                (string)($d['invoice_url'] ?? ''),
            ]);
        });
        return (int)$db->lastInsertId();
    } catch (Throwable $e) {
        error_log('[billing] open failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Payment came good. Restore the site if it was suspended and close the record.
 * Called from the webhook, so recovery is effectively instant.
 */
function ww_billing_recover(PDO $db, string $subscription_id, string $email = ''): int {
    ww_billing_init_schema($db);
    $email = strtolower(trim($email));
    $st = $db->prepare(
        "SELECT * FROM billing_lifecycle
          WHERE state IN ('grace','suspended')
            AND ((subscription_id <> '' AND subscription_id = ?) OR (? = '' AND email = ?))"
    );
    $st->execute([$subscription_id, $subscription_id, $email]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $n = 0;
    foreach ($rows as $r) {
        if (($r['state'] ?? '') === 'suspended') ww_billing_restore_site((string)$r['token']);
        try {
            ww_db_write_retry(function () use ($db, $r) {
                return $db->prepare("UPDATE billing_lifecycle SET state='recovered', recovered_at=datetime('now'), updated_at=datetime('now') WHERE id = ?")
                          ->execute([(int)$r['id']]);
            });
            $n++;
        } catch (Throwable $e) { error_log('[billing] recover failed: ' . $e->getMessage()); }
    }
    return $n;
}

/** The live file a visitor hits for a preview-hosted site. */
function ww_billing_site_path(string $token): ?string {
    if (!preg_match('~^[a-f0-9]{6,32}$~', $token)) return null;
    return WW_PREVIEW_ROOT . '/' . $token . '/v1/index.html';
}

/**
 * Take a site offline. The real page is MOVED, never deleted, so this is fully
 * reversible and a billing mistake can never destroy someone's website.
 */
function ww_billing_suspend_site(string $token, string $business = ''): bool {
    $live = ww_billing_site_path($token);
    if ($live === null || !is_file($live)) return false;
    $stash = dirname($live) . '/index.suspended.html';
    if (!is_file($stash) && !@rename($live, $stash)) return false;
    return @file_put_contents($live, ww_billing_holding_page($business)) !== false;
}

function ww_billing_restore_site(string $token): bool {
    $live = ww_billing_site_path($token);
    if ($live === null) return false;
    $stash = dirname($live) . '/index.suspended.html';
    if (!is_file($stash)) return false;
    @unlink($live);
    return @rename($stash, $live);
}

/**
 * The holding page. Deliberately NOT an error and NOT an accusation: it is a
 * neutral "this site is paused" with a way to fix it in one click. It also
 * carries noindex, because a suspended site must not be what Google caches.
 */
function ww_billing_holding_page(string $business = ''): string {
    $b = htmlspecialchars($business !== '' ? $business : 'This website', ENT_QUOTES);
    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow,noarchive,noimageindex">'
        . '<title>' . $b . '</title><style>'
        . '*{box-sizing:border-box;margin:0;padding:0}'
        . 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#FFF8E7;color:#12184A;'
        . 'min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;line-height:1.55}'
        . '.card{max-width:520px;background:#fff;border:3px solid #12184A;border-radius:18px;padding:38px 32px;'
        . 'box-shadow:8px 8px 0 #F7C84A;text-align:center}'
        . 'h1{font-size:28px;font-weight:900;letter-spacing:-.02em;margin-bottom:12px}'
        . 'p{font-size:16px;opacity:.85;margin-bottom:14px}'
        . 'a.btn{display:inline-block;margin-top:10px;background:#F7C84A;color:#12184A;border:2px solid #12184A;'
        . 'border-radius:10px;padding:13px 26px;text-decoration:none;font-weight:800}'
        . 'small{display:block;margin-top:18px;font-size:13px;opacity:.6}'
        . '</style></head><body><div class="card">'
        . '<h1>This site is paused</h1>'
        . '<p>' . $b . ' is temporarily offline because the hosting payment did not go through.</p>'
        . '<p>Everything is safe. Update the payment method and the site comes straight back, exactly as it was.</p>'
        . '<a class="btn" href="mailto:hello@trywebwiz.com?subject=Restore%20my%20site">Get it back online</a>'
        . '<small>WebWiz &middot; hello@trywebwiz.com</small>'
        . '</div></body></html>';
}
