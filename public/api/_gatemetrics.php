<?php
// /api/_gatemetrics.php — refund/dispute capture and paid-invoice history for
// the gate test. Library only: direct HTTP access returns nothing.
//
// Omar's brief requires four metrics per cell. reveal-to-paid and
// reveal-to-unlock-click already existed. These two did not:
//   - refund and chargeback rate
//   - month-two retention
// Refunds are the early-warning signal that the generated sites are not good
// enough to charge for. Running paid traffic against a gate with no refund
// telemetry is precisely the risk the QA work was sequenced to avoid.
declare(strict_types=1);

function ww_gm_init_schema(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS billing_events (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            stripe_event_id TEXT UNIQUE,
            type            TEXT NOT NULL,
            charge_id       TEXT DEFAULT '',
            subscription_id TEXT DEFAULT '',
            customer_id     TEXT DEFAULT '',
            email           TEXT DEFAULT '',
            amount_cents    INTEGER DEFAULT 0,
            currency        TEXT DEFAULT 'usd',
            reason          TEXT DEFAULT '',
            offer_variant   TEXT,
            token           TEXT DEFAULT '',
            occurred_at     TEXT DEFAULT (datetime('now')),
            raw_json        TEXT
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_be_type    ON billing_events(type, occurred_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_be_variant ON billing_events(offer_variant)");
    $db->exec("
        CREATE TABLE IF NOT EXISTS billing_invoices (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_id      TEXT UNIQUE,
            subscription_id TEXT DEFAULT '',
            customer_id     TEXT DEFAULT '',
            email           TEXT DEFAULT '',
            billing_reason  TEXT DEFAULT '',
            amount_cents    INTEGER DEFAULT 0,
            paid_at         TEXT,
            offer_variant   TEXT,
            token           TEXT DEFAULT ''
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_bi_sub    ON billing_invoices(subscription_id, paid_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_bi_reason ON billing_invoices(billing_reason)");
}

/**
 * Resolve which test cell a payment object belongs to, AT WRITE TIME.
 *
 * This deliberately does not defer to report time. A refund can land 30 days
 * after the sale, long after the checkout session has aged out of anything cheap
 * to query, and a dispute later still. If the cell is not captured on the row,
 * the number that matters most (refund rate per cell) is unrecoverable.
 *
 * Resolution order, cheapest and most reliable first:
 *   1. metadata on the object we already hold
 *   2. subscription metadata (offer_checkout.php stamps token + offer_variant
 *      onto the subscription as well as the session)
 *   3. payment_intent -> its checkout session -> metadata
 *   4. the token we resolved, via jobs.token -> jobs.offer_variant
 * Returns [variant|null, token].
 */
function ww_gm_resolve_cell(PDO $db, array $obj, string $stripe_key): array {
    $variant = strtolower(trim((string)($obj['metadata']['offer_variant'] ?? '')));
    $token   = trim((string)($obj['metadata']['token'] ?? ''));

    $get = function (string $url) use ($stripe_key): array {
        if ($stripe_key === '') return [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => $stripe_key . ':', CURLOPT_TIMEOUT => 10]);
        $r = json_decode((string)curl_exec($ch), true);
        curl_close($ch);
        return is_array($r) ? $r : [];
    };

    $sub = (string)($obj['subscription'] ?? '');
    if (($variant === '' || $token === '') && $sub !== '') {
        $s = $get('https://api.stripe.com/v1/subscriptions/' . urlencode($sub));
        if ($variant === '') $variant = strtolower(trim((string)($s['metadata']['offer_variant'] ?? '')));
        if ($token === '')   $token   = trim((string)($s['metadata']['token'] ?? ''));
    }

    $pi = (string)($obj['payment_intent'] ?? '');
    if (($variant === '' || $token === '') && $pi !== '') {
        $cs = $get('https://api.stripe.com/v1/checkout/sessions?payment_intent=' . urlencode($pi) . '&limit=1');
        $row = $cs['data'][0] ?? [];
        if ($variant === '') $variant = strtolower(trim((string)($row['metadata']['offer_variant'] ?? '')));
        if ($token === '')   $token   = trim((string)($row['metadata']['token'] ?? ''));
        if ($sub === '' && !empty($row['subscription'])) {
            $s2 = $get('https://api.stripe.com/v1/subscriptions/' . urlencode((string)$row['subscription']));
            if ($variant === '') $variant = strtolower(trim((string)($s2['metadata']['offer_variant'] ?? '')));
            if ($token === '')   $token   = trim((string)($s2['metadata']['token'] ?? ''));
        }
    }

    // Last resort: the job row knows its own cell.
    if ($variant === '' && $token !== '') {
        try {
            $st = $db->prepare("SELECT offer_variant FROM jobs WHERE token = ? ORDER BY id DESC LIMIT 1");
            $st->execute([$token]);
            $variant = strtolower(trim((string)($st->fetchColumn() ?: '')));
        } catch (Throwable $e) { /* leave unresolved */ }
    }

    if (!in_array($variant, ['a','b','c','u','t'], true)) $variant = '';
    return [$variant !== '' ? $variant : null, $token];
}

/** Record a refund or dispute. Unique on stripe_event_id so replays are free. */
function ww_gm_record_event(PDO $db, string $event_id, string $type, array $obj, PDO $unused = null, string $stripe_key = ''): bool {
    ww_gm_init_schema($db);
    [$variant, $token] = ww_gm_resolve_cell($db, $obj, $stripe_key);
    $amount = (int)($obj['amount_refunded'] ?? $obj['amount'] ?? 0);
    $email  = (string)($obj['billing_details']['email'] ?? $obj['receipt_email'] ?? '');
    $reason = (string)($obj['reason'] ?? ($obj['outcome']['reason'] ?? ''));
    try {
        return (bool)ww_db_write_retry(function () use ($db, $event_id, $type, $obj, $variant, $token, $amount, $email, $reason) {
            return $db->prepare(
                "INSERT OR IGNORE INTO billing_events
                 (stripe_event_id,type,charge_id,subscription_id,customer_id,email,amount_cents,currency,reason,offer_variant,token,occurred_at,raw_json)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,datetime('now'),?)"
            )->execute([
                $event_id, $type,
                (string)($obj['charge'] ?? $obj['id'] ?? ''),
                (string)($obj['subscription'] ?? ''),
                (string)($obj['customer'] ?? ''),
                $email, $amount,
                (string)($obj['currency'] ?? 'usd'),
                mb_substr($reason, 0, 120),
                $variant, $token,
                mb_substr(json_encode($obj, JSON_UNESCAPED_SLASHES), 0, 4000),
            ]);
        });
    } catch (Throwable $e) {
        error_log('[gatemetrics] record_event failed: ' . $e->getMessage());
        return false;
    }
}

/** Record a paid invoice. This is what month-two retention is computed from. */
function ww_gm_record_invoice(PDO $db, array $inv, string $stripe_key = ''): bool {
    ww_gm_init_schema($db);
    if ((string)($inv['status'] ?? '') !== 'paid' && (int)($inv['amount_paid'] ?? 0) <= 0) return false;
    [$variant, $token] = ww_gm_resolve_cell($db, $inv, $stripe_key);
    $paid = !empty($inv['status_transitions']['paid_at'])
        ? gmdate('Y-m-d H:i:s', (int)$inv['status_transitions']['paid_at'])
        : gmdate('Y-m-d H:i:s');
    try {
        return (bool)ww_db_write_retry(function () use ($db, $inv, $variant, $token, $paid) {
            return $db->prepare(
                "INSERT OR IGNORE INTO billing_invoices
                 (invoice_id,subscription_id,customer_id,email,billing_reason,amount_cents,paid_at,offer_variant,token)
                 VALUES (?,?,?,?,?,?,?,?,?)"
            )->execute([
                (string)($inv['id'] ?? ''),
                (string)($inv['subscription'] ?? ''),
                (string)($inv['customer'] ?? ''),
                (string)($inv['customer_email'] ?? ''),
                (string)($inv['billing_reason'] ?? ''),
                (int)($inv['amount_paid'] ?? 0),
                $paid, $variant, $token,
            ]);
        });
    } catch (Throwable $e) {
        error_log('[gatemetrics] record_invoice failed: ' . $e->getMessage());
        return false;
    }
}
