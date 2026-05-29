<?php
// /api/try_checkout.php — Stripe Checkout Session creator for the /try ad-funnel.
// POST { token } → returns { ok, checkout_url } so the front-end can redirect.
// Two line items: $500 one-time launch fee + $50/month recurring hosting.
// session_id from Stripe is stored on jobs.stripe_session_id so the webhook
// can match it back. token also goes into metadata.token so success/cancel
// URLs can restore the preview state.
declare(strict_types=1);
header('Content-Type: application/json');

require_once '/var/www/sites/trywebwiz/private/webwiz_lib.php';
$secrets = ww_secrets();
$STRIPE_SECRET = (string)($secrets['STRIPE_SECRET_KEY'] ?? '');

function tc_fail(string $m, int $code = 400) { http_response_code($code); echo json_encode(['error' => $m]); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') tc_fail('POST required.', 405);
if ($STRIPE_SECRET === '') tc_fail('Stripe is not configured.', 500);

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) $body = [];
$token = trim((string)($body['token'] ?? ''));
if (!preg_match('~^[a-f0-9]{24}$~', $token)) tc_fail('Invalid token.');

$db = ww_db();
$job = $db->prepare("SELECT id, business_name, generation_mode, customer_email FROM jobs WHERE token = ? LIMIT 1");
$job->execute([$token]);
$job = $job->fetch(PDO::FETCH_ASSOC);
if (!$job) tc_fail('Preview not found.', 404);
if (($job['generation_mode'] ?? '') !== 'magic') tc_fail('Checkout is only available on instant previews.', 403);

$biz = (string)($job['business_name'] ?? 'Your business');
$preview_url = 'https://trywebwiz.com/preview/' . $token . '/v1/index.html';

// ---- Build Stripe Checkout Session ----
$origin = 'https://trywebwiz.com';
$success_url = $origin . '/try/?success=1&t=' . $token . '&sid={CHECKOUT_SESSION_ID}';
$cancel_url  = $origin . '/try/?t=' . $token;

$line1_name = 'WebWiz Launch & Polish — ' . mb_substr($biz, 0, 80);
$line1_desc = 'One-time: human designer review, domain setup, business email, first-30-day support.';
$line2_name = 'WebWiz Hosting — ' . mb_substr($biz, 0, 80);
$line2_desc = 'Recurring: hosting + uptime. Cancel anytime; the site stays yours.';

$payload = [
    'mode'        => 'subscription',
    'success_url' => $success_url,
    'cancel_url'  => $cancel_url,
    'metadata[token]'   => $token,
    'metadata[biz]'     => mb_substr($biz, 0, 80),
    'metadata[source]'  => 'try_ad_funnel',
    'subscription_data[metadata][token]' => $token,
    'subscription_data[metadata][source]'=> 'try_ad_funnel',
    'subscription_data[description]'     => 'WebWiz Hosting',
    'automatic_tax[enabled]'             => 'false',
    'billing_address_collection'         => 'required',
    'allow_promotion_codes'              => 'true',

    'line_items[0][price_data][currency]'                                 => 'usd',
    'line_items[0][price_data][unit_amount]'                              => 50000,           // $500.00
    'line_items[0][price_data][product_data][name]'                       => $line1_name,
    'line_items[0][price_data][product_data][description]'                => $line1_desc,
    'line_items[0][quantity]'                                             => 1,

    'line_items[1][price_data][currency]'                                 => 'usd',
    'line_items[1][price_data][unit_amount]'                              => 5000,            // $50.00
    'line_items[1][price_data][recurring][interval]'                      => 'month',
    'line_items[1][price_data][product_data][name]'                       => $line2_name,
    'line_items[1][price_data][product_data][description]'                => $line2_desc,
    'line_items[1][quantity]'                                             => 1,
];
if (!empty($job['customer_email'])) $payload['customer_email'] = (string)$job['customer_email'];

$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_USERPWD        => $STRIPE_SECRET . ':',
    CURLOPT_HTTPHEADER     => ['Stripe-Version: 2024-06-20'],
]);
$resp = curl_exec($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$json = json_decode((string)$resp, true);
if ($http >= 300 || !is_array($json) || empty($json['url']) || empty($json['id'])) {
    $msg = is_array($json) && isset($json['error']['message']) ? (string)$json['error']['message'] : 'Stripe error.';
    error_log('[try_checkout] http=' . $http . ' resp=' . substr((string)$resp, 0, 500));
    tc_fail($msg, 502);
}

// Persist session id on the job so the webhook can match it.
try {
    $db->prepare("UPDATE jobs SET stripe_session_id = ? WHERE id = ?")->execute([$json['id'], (int)$job['id']]);
} catch (Throwable $e) { /* non-fatal */ }

echo json_encode(['ok' => true, 'checkout_url' => $json['url'], 'session_id' => $json['id']]);
