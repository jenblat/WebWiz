<?php
// /api/webhook.php — Stripe webhook receiver.
// Verifies signature, logs every event, fires transactional emails using
// the bold editorial templates in _email_templates.php.

declare(strict_types=1);

require_once __DIR__ . '/_meta.php';
require_once __DIR__ . '/_email_templates.php';
// Loaded up front (it was previously pulled in lazily, inside three different
// functions) so ww_sentry_alert() and ww_db() are available to every path in
// this file, including the ones that run before the first handler.
require_once __DIR__ . '/../../private/webwiz_lib.php';

$secrets = require __DIR__ . '/../../secrets.php';
$WEBHOOK_SECRET = $secrets['STRIPE_WEBHOOK_SECRET'] ?? '';
$BREVO_KEY      = $secrets['BREVO_API_KEY'] ?? '';
// FROM address hardcoded — non-sensitive config, not a secret.
$FROM_NAME      = 'Wizzy at WebWiz';
$FROM_ADDR      = 'wizzy@trywebwiz.com';
$REPLY_TO       = 'hello@trywebwiz.com';
$FALLBACK_ADMIN = $secrets['NOTIFY_EMAIL']    ?? 'ultimax97@gmail.com';

// Pull admin recipients from the users table (role='admin'). Falls back to
// $FALLBACK_ADMIN if the DB read fails for any reason.
function ww_admin_recipients(string $fallback): array {
    try {
        require_once __DIR__ . '/../../private/webwiz_lib.php';
        $db = ww_db();
        $st = $db->query("SELECT email, name FROM users WHERE role='admin' AND email IS NOT NULL AND email <> ''");
        $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        error_log('[webwiz webhook] admin lookup failed: ' . $e->getMessage());
        if (function_exists('ww_sentry_alert')) {
            ww_sentry_alert('Stripe webhook could not read the admin recipient list', [
                'component' => 'webhook', 'reason' => 'admin_lookup_failed', 'error' => $e->getMessage(),
            ], 'warning', $e);
        }
        $rows = [];
    }
    if (!$rows) return [['email' => $fallback, 'name' => 'WebWiz Team']];
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['email' => (string)$r['email'], 'name' => (string)($r['name'] ?? 'WebWiz Team')];
    }
    return $out;
}

// ---------- Read raw body ----------
$raw = file_get_contents('php://input') ?: '';
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// ---------- Verify signature ----------
function stripe_verify(string $payload, string $sig_header, string $secret, int $tolerance = 300): array {
    if ($secret === '') return [false, 'webhook secret not configured'];
    if ($sig_header === '') return [false, 'no Stripe-Signature header'];
    $items = [];
    foreach (explode(',', $sig_header) as $kv) {
        [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
        $items[$k][] = $v;
    }
    $ts = (int)($items['t'][0] ?? 0);
    if (!$ts || abs(time() - $ts) > $tolerance) return [false, 'timestamp out of tolerance'];
    $expected = hash_hmac('sha256', $ts . '.' . $payload, $secret);
    foreach (($items['v1'] ?? []) as $sig) {
        if (hash_equals($expected, $sig)) return [true, ''];
    }
    return [false, 'no matching v1 signature'];
}

[$ok, $err] = stripe_verify($raw, $sig_header, $WEBHOOK_SECRET);
if (!$ok) {
    error_log('[webwiz webhook] signature failed: ' . $err);
    if ($WEBHOOK_SECRET !== '') {
        // Only alert when something actually claimed to be Stripe. Random
        // internet scanners POST here constantly with no signature header at
        // all; paging on those would be pure noise.
        if ($sig_header !== '') {
            ww_sentry_alert('Stripe webhook signature verification failed', [
                'component' => 'webhook', 'reason' => 'signature_invalid', 'detail' => $err,
            ], 'warning');
        }
        http_response_code(400);
        exit('Bad signature.');
    }
    // No secret configured: we are about to act on an UNVERIFIED event. That is
    // a live security and correctness hole, not a warning.
    ww_sentry_alert('Stripe webhook is processing events with no signing secret configured', [
        'component' => 'webhook', 'reason' => 'webhook_secret_missing', 'detail' => $err,
    ], 'error');
}

$event = json_decode($raw, true);
if (!is_array($event)) {
    http_response_code(400);
    exit('Invalid JSON.');
}

// ---------- Persistent log ----------
$log_dir = __DIR__ . '/../../logs';
@mkdir($log_dir, 0755, true);
@file_put_contents($log_dir . '/stripe-events.jsonl',
    json_encode(['ts' => gmdate('c'), 'type' => $event['type'] ?? '', 'id' => $event['id'] ?? '', 'object' => $event['data']['object']['id'] ?? null]) . "\n",
    FILE_APPEND | LOCK_EX
);

// ---------------------------------------------------------------------------
// IDEMPOTENCY. Added 2026-08-05.
//
// Stripe guarantees AT LEAST ONCE delivery and retries anything that is not a
// 2xx. This file had no dedupe at all, so a redelivery re-ran every side effect
// - a second customer email, a second admin email, a second Meta CAPI Purchase.
// Observed live: evt_1U0NjqI9eJumTmB7RBWyzQ7E (customer.subscription.deleted)
// arrived twice one second apart on 2026-08-03 and sent two identical
// cancellation emails to the same person.
//
// Deliberately NOT a plain "seen it, skip it": the row is claimed on arrival but
// only marked completed at the very end. If a delivery dies halfway - a PHP
// fatal between taking the money and sending the receipt - Stripe's retry MUST
// still be processed, so an incomplete claim lets the event through (and says
// so). Only a fully completed event is skipped.
//
// Fails OPEN in every error case. A duplicate email is annoying; a dropped
// checkout.session.completed is a paid customer who never hears from us.
// ---------------------------------------------------------------------------
function ww_webhook_claim(string $event_id, string $type): string {
    if ($event_id === '') return 'fresh';
    try {
        $db = ww_db();
        $db->exec("CREATE TABLE IF NOT EXISTS stripe_events_seen (
            event_id     TEXT PRIMARY KEY,
            type         TEXT,
            received_at  TEXT NOT NULL DEFAULT (datetime('now')),
            completed_at TEXT
        )");
        $ins = $db->prepare("INSERT OR IGNORE INTO stripe_events_seen (event_id, type) VALUES (?, ?)");
        ww_db_write_retry(function () use ($ins, $event_id, $type) { return $ins->execute([$event_id, $type]); });
        if ($ins->rowCount() > 0) return 'fresh';

        $st = $db->prepare("SELECT completed_at FROM stripe_events_seen WHERE event_id = ? LIMIT 1");
        $st->execute([$event_id]);
        $done = $st->fetchColumn();
        return ($done !== false && $done !== null && (string)$done !== '') ? 'duplicate' : 'retry_incomplete';
    } catch (Throwable $e) {
        error_log('[webwiz webhook] idempotency claim failed: ' . $e->getMessage());
        ww_sentry_alert('Stripe webhook idempotency check failed; processing anyway', [
            'component' => 'webhook', 'reason' => 'idempotency_check_failed',
            'event_id' => $event_id, 'event_type' => $type, 'error' => $e->getMessage(),
        ], 'warning', $e);
        return 'fresh';
    }
}

function ww_webhook_complete(string $event_id): void {
    if ($event_id === '') return;
    try {
        $db = ww_db();
        $up = $db->prepare("UPDATE stripe_events_seen SET completed_at = datetime('now') WHERE event_id = ?");
        ww_db_write_retry(function () use ($up, $event_id) { return $up->execute([$event_id]); });
    } catch (Throwable $e) {
        error_log('[webwiz webhook] idempotency complete failed: ' . $e->getMessage());
    }
}

$WW_EVENT_ID   = (string)($event['id'] ?? '');
$WW_EVENT_TYPE = (string)($event['type'] ?? '');
$WW_CLAIM      = ww_webhook_claim($WW_EVENT_ID, $WW_EVENT_TYPE);

if ($WW_CLAIM === 'duplicate') {
    error_log('[webwiz webhook] duplicate event skipped: ' . $WW_EVENT_ID . ' (' . $WW_EVENT_TYPE . ')');
    ww_sentry_alert('Stripe redelivered an event we had already completed; skipped', [
        'component' => 'webhook', 'reason' => 'duplicate_event_skipped',
        'event_id' => $WW_EVENT_ID, 'event_type' => $WW_EVENT_TYPE,
    ], 'info');
    http_response_code(200);
    exit('ok (duplicate)');
}
if ($WW_CLAIM === 'retry_incomplete') {
    // We claimed this event before and never finished it. Something killed the
    // previous run mid-flight. Reprocess - but say so, loudly.
    ww_sentry_alert('Stripe retried an event whose previous delivery never completed; reprocessing', [
        'component' => 'webhook', 'reason' => 'retry_after_incomplete_delivery',
        'event_id' => $WW_EVENT_ID, 'event_type' => $WW_EVENT_TYPE,
    ], 'error');
}

// ---------- Brevo sender ----------
function brevo_send(string $key, array $from, array $to, ?array $reply_to, string $subject, string $html, string $text = ''): bool {
    if ($key === '') return false;
    $to_list = (isset($to['email'])) ? [$to] : array_values($to);
    if (!$to_list) return false;
    $payload = ['sender' => $from, 'to' => $to_list, 'subject' => $subject, 'htmlContent' => $html];
    if ($text !== '') $payload['textContent'] = $text;
    if ($reply_to)    $payload['replyTo'] = $reply_to;
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['accept: application/json', 'content-type: application/json', 'api-key: ' . $key],
    ]);
    $r = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http >= 300) {
        // Silent until 2026-08-05: we take the payment, Brevo rejects the
        // receipt, and the customer hears nothing. Nobody was watching the log.
        error_log('[webwiz brevo] http=' . $http . ' resp=' . substr((string)$r, 0, 500));
        if (function_exists('ww_sentry_alert')) {
            ww_sentry_alert('Stripe webhook could not send a transactional email', [
                'component'   => 'webhook',
                'reason'      => 'brevo_send_failed',
                'brevo_http'  => $http,
                'subject'     => $subject,
                'recipients'  => count($to_list),
            ], 'error');
        }
        return false;
    }
    return true;
}

function plan_label(?string $plan): string {
    return [
        'build_only'    => 'Build only ($500)',
        'build_plus_49' => 'Build + Hosting and Care ($500 plus $50/mo)',
        'build_plus_99' => 'Build + Hosting, Care and Edits ($500 plus $99/mo)',
    ][$plan ?? ''] ?? 'Custom order';
}

function dollars(?int $cents): string {
    if ($cents === null) return '$0.00';
    return '$' . number_format($cents / 100, 2);
}

function first_name_from(string $full): string {
    $full = trim($full);
    if ($full === '') return '';
    $p = preg_split('/\s+/', $full);
    return $p ? (string)$p[0] : '';
}

// Resolve admin recipient list + notify toggle.
$ADMIN_TO = ww_admin_recipients($FALLBACK_ADMIN);
$NOTIFY_ON = true;
try {
    require_once __DIR__ . '/../../private/webwiz_lib.php';
    $st = ww_db()->prepare("SELECT value FROM settings WHERE key='notify_emails_enabled'");
    $st->execute();
    $v = $st->fetchColumn();
    if ($v !== false && (string)$v === '0') $NOTIFY_ON = false;
} catch (Throwable $e) { /* leave default on */ }
if (!$NOTIFY_ON) $ADMIN_TO = [];

// ---------- Handlers ----------
$type = $event['type'] ?? '';
$obj  = $event['data']['object'] ?? [];

if ($type === 'checkout.session.completed') {
    $email   = $obj['customer_email'] ?? ($obj['customer_details']['email'] ?? null);
    $name    = (string)($obj['customer_details']['name'] ?? ($obj['metadata']['contact_name'] ?? ''));
    $biz     = (string)($obj['metadata']['business_name'] ?? '');
    $plan    = $obj['metadata']['plan'] ?? null;
    $amount  = $obj['amount_total'] ?? null;
    $sid     = (string)($obj['id'] ?? '');
    // Price-test metadata. offer_checkout.php sets offer_variant / lead_id /
    // token / source on BOTH the session and the subscription; only the session
    // copies are visible here. It never sets 'plan' - that key belongs to the
    // $500 try_checkout.php funnel - which is why content_name below used to go
    // out empty on every single price-test purchase.
    $ovariant = strtolower(trim((string)($obj['metadata']['offer_variant'] ?? '')));
    // 't' is the guarded $1 live-payment test cell. It is whitelisted here for
    // the same reason a/b/c are: without it the test purchase would reach Meta
    // and try_events with an empty variant and would prove nothing about how a
    // real cell behaves. Everything it produces is filterable on variant='t'.
    if (!in_array($ovariant, ['a', 'b', 'c', 't'], true)) $ovariant = '';
    $olead_id = (int)($obj['metadata']['lead_id'] ?? 0);
    $is_sub   = (string)($obj['mode'] ?? '') === 'subscription';

    $vars = [
        'first_name'    => first_name_from($name) ?: ($biz ?: 'friend'),
        'business_name' => $biz ?: '(no business name)',
        'plan_label'    => plan_label($plan),
        'amount'        => dollars($amount),
    ];

    if (!$email) {
        // A paid order we cannot acknowledge. Previously this branch just did
        // nothing at all.
        ww_sentry_alert('Paid checkout completed with no customer email; no receipt could be sent', [
            'component' => 'webhook', 'reason' => 'checkout_without_email',
            'event_id' => $WW_EVENT_ID, 'stripe_session' => $sid, 'variant' => $ovariant, 'amount' => $amount,
        ], 'error');
    }

    if ($email) {
        $tpl = ww_email_order_received($vars);
        brevo_send($BREVO_KEY,
            ['name' => $FROM_NAME, 'email' => $FROM_ADDR],
            ['email' => $email, 'name' => $name ?: ($biz ?: 'Friend')],
            ['email' => $REPLY_TO, 'name' => $FROM_NAME],
            $tpl['subject'],
            $tpl['html']
        );
    }

    $admin_vars = array_merge($vars, [
        'contact_name'   => $name,
        'customer_email' => $email,
        'phone'          => (string)($obj['metadata']['phone'] ?? ''),
        'current_site'   => (string)($obj['metadata']['current_site'] ?? ''),
        'what_you_do'    => (string)($obj['metadata']['what_you_do'] ?? ''),
        'audience'       => (string)($obj['metadata']['audience'] ?? ''),
        'inspiration'    => (string)($obj['metadata']['inspiration'] ?? ''),
        'notes'          => (string)($obj['metadata']['notes'] ?? ''),
        'payment_intent' => (string)($obj['payment_intent'] ?? ''),
    ]);
    if ($ADMIN_TO) {
        $tpl = ww_email_admin_new_order($admin_vars);
        brevo_send($BREVO_KEY,
            ['name' => 'WebWiz alerts', 'email' => $FROM_ADDR],
            $ADMIN_TO,
            null,
            $tpl['subject'],
            $tpl['html']
        );
    }

    // ----- Nurture: mark contact as purchased (stops the sequence) -----
    try {
        require_once __DIR__ . '/_nurture.php';
        $token_meta_n = (string)($obj['metadata']['token'] ?? '');
        $cid = ww_nurture_match_for_checkout(ww_db(), $token_meta_n ?: null, (string)$email);
        if ($cid) {
            ww_nurture_set_status(ww_db(), $cid, 'purchased');
            error_log('[webhook] nurture status=purchased for contact_id=' . $cid);
        }
    } catch (Throwable $e) {
        error_log('[webhook] nurture purchased update failed: ' . $e->getMessage());
        ww_sentry_alert('Stripe webhook could not stop the nurture sequence for a purchaser', [
            'component' => 'webhook', 'reason' => 'nurture_purchased_update_failed',
            'event_id' => $WW_EVENT_ID, 'stripe_session' => $sid, 'error' => $e->getMessage(),
        ], 'warning', $e);
    }

    // Funnel analytics
    //
    // FIX 2026-08-03: this used to be wrapped in `if (24-hex token)`, so a cell-C
    // purchase - which by design never has a job token, because cell C has no
    // builder - recorded NOTHING. The whole point of the price test is comparing
    // cells, and one of the three cells could not register a sale at all.
    // The token is now optional and only decides whether the token column is
    // filled; the row is always written.
    $tok_col = null;
    try {
        $token_meta = (string)($obj['metadata']['token'] ?? '');
        $source     = (string)($obj['metadata']['source'] ?? '');
        $tok_col    = preg_match('~^[a-f0-9]{24}$~', $token_meta) ? $token_meta : null;
        $pl = json_encode([
            'amount'    => $amount,
            'plan'      => $plan,
            'variant'   => $ovariant !== '' ? $ovariant : null,
            'lead_id'   => $olead_id ?: null,
            'source'    => $source,
            'biz'       => $biz,
            'recurring' => $is_sub,
        ], JSON_UNESCAPED_SLASHES);
        ww_db()->prepare("INSERT INTO try_events (event, token, session_id, payload) VALUES ('checkout_completed', ?, ?, ?)")
               ->execute([$tok_col, (string)$sid, $pl]);
    } catch (Throwable $e) {
        error_log('[webhook] try-event insert failed: ' . $e->getMessage());
        ww_sentry_alert('Stripe webhook could not record checkout_completed; the sale is missing from the funnel', [
            'component' => 'webhook', 'reason' => 'checkout_completed_event_insert_failed',
            'event_id' => $WW_EVENT_ID, 'stripe_session' => $sid, 'variant' => $ovariant, 'error' => $e->getMessage(),
        ], 'error', $e);
    }

    // ----- FIX 2026-08-03: close the loop on the offer lead -----
    // Nothing anywhere set offer_leads.status='purchased', so every lead sat at
    // 'new'/'checkout' forever and the conversion end of the funnel was blank.
    // Match on lead_id first (exact), then the Stripe session, then the job
    // token - any one of the three is enough and they cost nothing.
    try {
        require_once __DIR__ . '/../../private/webwiz_lib.php';
        $dbl = ww_db();
        ww_offer_leads_ensure($dbl);
        $where = null; $args = [];
        if ($olead_id > 0)                { $where = "id = ?";                $args = [$olead_id]; }
        elseif ($sid !== '')              { $where = "stripe_session_id = ?"; $args = [$sid]; }
        elseif ($tok_col !== null)        { $where = "token = ?";             $args = [$tok_col]; }
        if ($where !== null) {
            $sqlu = "UPDATE offer_leads SET status='purchased'"
                  . ($sid !== '' ? ", stripe_session_id=COALESCE(stripe_session_id, " . $dbl->quote($sid) . ")" : "")
                  . " WHERE $where AND status <> 'purchased'";
            $n = 0;
            ww_db_write_retry(function () use ($dbl, $sqlu, $args, &$n) {
                $stu = $dbl->prepare($sqlu);
                $r = $stu->execute($args);
                $n = $stu->rowCount();
                return $r;
            });
            error_log('[webhook] offer_leads purchased rows=' . $n . ' variant=' . ($ovariant ?: '-') . ' lead_id=' . $olead_id);
        }
    } catch (Throwable $e) {
        error_log('[webhook] offer lead close failed: ' . $e->getMessage());
        ww_sentry_alert('Stripe webhook could not mark the offer lead purchased', [
            'component' => 'webhook', 'reason' => 'offer_lead_close_failed',
            'event_id' => $WW_EVENT_ID, 'stripe_session' => $sid, 'variant' => $ovariant,
            'lead_id' => $olead_id, 'error' => $e->getMessage(),
        ], 'warning', $e);
    }

    // ----- Meta CAPI: Purchase (+ Subscribe for recurring plans) -----
    //
    // FIX 2026-08-03. content_name was `$obj['metadata']['plan']`, a key
    // offer_checkout.php never sets, so every price-test Purchase reached Meta
    // with an EMPTY content_name and the algorithm could not tell the three
    // cells apart. metadata['offer_variant'] is present on all of them; use it,
    // and keep 'plan' for the untouched $500 funnel.
    //
    // event_id is derived deterministically from the Stripe session id, which is
    // exactly what public/success.php and the /o receipt page use for the
    // browser Pixel, so the browser and server copies dedupe into one
    // conversion instead of double-counting the sale.
    try {
        $name_parts = $name ? explode(' ', trim($name), 2) : [];
        $first      = $name_parts[0] ?? '';
        $last       = $name_parts[1] ?? '';
        $phone      = (string)($obj['metadata']['phone'] ?? ($obj['customer_details']['phone'] ?? ''));
        $value      = $amount !== null ? round(((int)$amount) / 100, 2) : 0;
        $currency   = strtoupper((string)($obj['currency'] ?? 'usd')) ?: 'USD';
        $content    = $ovariant !== '' ? ('offer_' . $ovariant) : ((string)$plan !== '' ? (string)$plan : 'try_build');
        $category   = $ovariant !== '' ? 'price_test' : 'website_build';
        $user_data  = [
            'email'             => (string)$email,
            'phone'             => $phone,
            'first_name'        => $first,
            'last_name'         => $last,
            'client_ip_address' => ww_meta_client_ip(),
            'client_user_agent' => ww_meta_user_agent(),
        ];
        $custom = [
            'value'            => $value,
            'currency'         => $currency,
            'content_name'     => $content,
            'content_category' => $category,
        ];
        if ($ovariant !== '') $custom['content_ids'] = ['offer_' . $ovariant];
        $src_url = $ovariant !== ''
            ? 'https://trywebwiz.com/o/' . $ovariant . '/?success=1&sid=' . $sid
            : 'https://trywebwiz.com/success.php?session_id=' . $sid;

        ww_meta_send_event('Purchase', ww_meta_event_id($sid), $user_data, $custom, $src_url, 'website');

        // Subscribe: every /o cell and the hosting add-on are recurring, and Meta
        // treats Subscribe as its own optimisable conversion. Distinct event_id
        // (session id + suffix) so it is NOT deduped against the Purchase, but
        // still deterministic, so a webhook retry cannot double-count it.
        if ($is_sub) {
            // predicted_ltv was hardcoded to 11 more months at $50. On the $1
            // test cell that would have reported a $551 lifetime value for a $1
            // sale and fed Meta a number an order of magnitude wrong.
            $monthly_est = ($ovariant === 't') ? 1 : 50;
            ww_meta_send_event(
                'Subscribe',
                ww_meta_event_id($sid . ':subscribe'),
                $user_data,
                array_merge($custom, ['predicted_ltv' => round(($value + $monthly_est * 11), 2)]),
                $src_url,
                'website'
            );
        }
    } catch (Throwable $e) {
        error_log('[webhook] meta capi Purchase failed: ' . $e->getMessage());
        ww_sentry_alert('Stripe webhook could not send the Meta CAPI Purchase; the ad account will under-report this sale', [
            'component' => 'webhook', 'reason' => 'meta_capi_purchase_failed',
            'event_id' => $WW_EVENT_ID, 'stripe_session' => $sid, 'variant' => $ovariant, 'error' => $e->getMessage(),
        ], 'error', $e);
    }
}

elseif ($type === 'invoice.payment_failed') {
    $email = $obj['customer_email'] ?? null;
    $name  = (string)($obj['customer_name'] ?? '');
    $amt   = $obj['amount_due'] ?? null;
    $invoice = (string)($obj['hosted_invoice_url'] ?? '');

    $vars = [
        'first_name'         => first_name_from($name) ?: 'there',
        'amount'             => dollars($amt),
        'hosted_invoice_url' => $invoice,
    ];

    if ($email) {
        $tpl = ww_email_payment_failed($vars);
        brevo_send($BREVO_KEY,
            ['name' => $FROM_NAME, 'email' => $FROM_ADDR],
            ['email' => $email, 'name' => $name ?: 'Friend'],
            ['email' => $REPLY_TO, 'name' => $FROM_NAME],
            $tpl['subject'],
            $tpl['html']
        );
    }
    if ($ADMIN_TO) {
        $admin = ww_email_admin_payment_failed(array_merge($vars, ['customer_email' => $email]));
        brevo_send($BREVO_KEY,
            ['name' => 'WebWiz alerts', 'email' => $FROM_ADDR],
            $ADMIN_TO,
            null,
            $admin['subject'],
            $admin['html']
        );
    }
}

elseif ($type === 'customer.subscription.deleted') {
    $email = $obj['customer_email'] ?? null;
    $name  = '';
    if (!$email && !empty($obj['customer'])) {
        $secrets2 = require __DIR__ . '/../../secrets.php';
        $ch = curl_init('https://api.stripe.com/v1/customers/' . urlencode((string)$obj['customer']));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $secrets2['STRIPE_SECRET_KEY'] . ':',
            CURLOPT_TIMEOUT => 10,
        ]);
        $r = curl_exec($ch); curl_close($ch);
        $cust = json_decode((string)$r, true);
        $email = $cust['email'] ?? null;
        $name  = (string)($cust['name'] ?? '');
    }

    $vars = ['first_name' => first_name_from($name) ?: 'there'];

    if ($email) {
        $tpl = ww_email_sub_cancelled($vars);
        brevo_send($BREVO_KEY,
            ['name' => $FROM_NAME, 'email' => $FROM_ADDR],
            ['email' => $email, 'name' => $name ?: 'Friend'],
            ['email' => $REPLY_TO, 'name' => $FROM_NAME],
            $tpl['subject'],
            $tpl['html']
        );
    }
    if ($ADMIN_TO) {
        $admin = ww_email_admin_sub_cancelled(['customer_email' => $email]);
        brevo_send($BREVO_KEY,
            ['name' => 'WebWiz alerts', 'email' => $FROM_ADDR],
            $ADMIN_TO,
            null,
            $admin['subject'],
            $admin['html']
        );
    }
}

// Only now is the event genuinely done. A crash anywhere above leaves the claim
// row incomplete, so Stripe's retry is reprocessed instead of silently dropped.
ww_webhook_complete($WW_EVENT_ID);

http_response_code(200);
echo 'ok';
