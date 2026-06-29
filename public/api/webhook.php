<?php
// /api/webhook.php — Stripe webhook receiver.
// Verifies signature, logs every event, fires transactional emails using
// the bold editorial templates in _email_templates.php.

declare(strict_types=1);

require_once __DIR__ . '/_meta.php';
require_once __DIR__ . '/_email_templates.php';

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
        http_response_code(400);
        exit('Bad signature.');
    }
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
        error_log('[webwiz brevo] http=' . $http . ' resp=' . substr((string)$r, 0, 500));
        return false;
    }
    return true;
}

function plan_label(?string $plan): string {
    return [
        'build_only'    => 'Build only ($499)',
        'build_plus_49' => 'Build + Hosting and Care ($499 plus $49/mo)',
        'build_plus_99' => 'Build + Hosting, Care and Edits ($499 plus $99/mo)',
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

    $vars = [
        'first_name'    => first_name_from($name) ?: ($biz ?: 'friend'),
        'business_name' => $biz ?: '(no business name)',
        'plan_label'    => plan_label($plan),
        'amount'        => dollars($amount),
    ];

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
    } catch (Throwable $e) { error_log('[webhook] nurture purchased update failed: ' . $e->getMessage()); }

    // Funnel analytics
    try {
        $token_meta = (string)($obj['metadata']['token'] ?? '');
        $source     = (string)($obj['metadata']['source'] ?? '');
        if (preg_match('~^[a-f0-9]{24}$~', $token_meta)) {
            $pl = json_encode([
                'amount'    => $amount,
                'plan'      => $plan,
                'source'    => $source,
                'biz'       => $biz,
                'recurring' => (string)($obj['mode'] ?? '') === 'subscription',
            ]);
            ww_db()->prepare("INSERT INTO try_events (event, token, session_id, payload) VALUES ('checkout_completed', ?, ?, ?)")
                   ->execute([$token_meta, (string)$sid, $pl]);
        }
    } catch (Throwable $e) { error_log('[webhook] try-event insert failed: ' . $e->getMessage()); }

    // Meta CAPI Purchase event (dedupes with success.php client Pixel via event_id)
    try {
        $name_parts = $name ? explode(' ', trim($name), 2) : [];
        $first      = $name_parts[0] ?? '';
        $last       = $name_parts[1] ?? '';
        $phone      = (string)($obj['metadata']['phone'] ?? ($obj['customer_details']['phone'] ?? ''));
        ww_meta_send_event(
            'Purchase',
            ww_meta_event_id($sid),
            [
                'email'             => (string)$email,
                'phone'             => $phone,
                'first_name'        => $first,
                'last_name'         => $last,
                'client_ip_address' => ww_meta_client_ip(),
                'client_user_agent' => ww_meta_user_agent(),
            ],
            [
                'value'            => $amount !== null ? round(((int)$amount) / 100, 2) : 0,
                'currency'         => strtoupper((string)($obj['currency'] ?? 'usd')) ?: 'USD',
                'content_name'     => (string)$plan,
                'content_category' => 'website_build',
            ],
            'https://trywebwiz.com/success.php?session_id=' . $sid,
            'website'
        );
    } catch (Throwable $e) { error_log('[webhook] meta capi Purchase failed: ' . $e->getMessage()); }
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

http_response_code(200);
echo 'ok';
