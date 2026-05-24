<?php
// /api/checkout.php — creates a Stripe Checkout Session and redirects there.
// Loads secrets from outside the web root so they never get served.

declare(strict_types=1);

// ---------- Load secrets ----------
$secrets_path = __DIR__ . '/../../secrets.php'; // /var/www/sites/trywebwiz/secrets.php
if (!file_exists($secrets_path)) {
    http_response_code(500);
    error_log('[webwiz checkout] secrets.php missing at ' . $secrets_path);
    exit('Server misconfigured. Email hello@trywebwiz.com.');
}
$secrets = require $secrets_path;
$STRIPE_SECRET = $secrets['STRIPE_SECRET_KEY'] ?? '';
if (!$STRIPE_SECRET || strpos($STRIPE_SECRET, 'sk_') !== 0) {
    http_response_code(500);
    exit('Server misconfigured.');
}

// ---------- Method check ----------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed. Submit the order form at https://trywebwiz.com/start');
}

// ---------- Read & sanitize form ----------
function in_str($k, $max = 500) {
    $v = $_POST[$k] ?? '';
    if (!is_string($v)) return '';
    $v = trim($v);
    if (strlen($v) > $max) $v = substr($v, 0, $max);
    return $v;
}

$plan          = in_str('plan', 32);
$business_name = in_str('business_name', 200);
$contact_name  = in_str('contact_name', 200);
$email         = in_str('email', 200);
$phone         = in_str('phone', 50);
$current_site  = in_str('current_site', 500);
$what_you_do   = in_str('what_you_do', 2000);
$audience      = in_str('audience', 1000);
$inspiration   = in_str('inspiration', 1000);
$notes         = in_str('notes', 4000);

// ---------- Validate ----------
$errors = [];
if (!in_array($plan, ['build_only','build_plus_49','build_plus_99'], true)) {
    $errors[] = 'Pick a plan.';
}
if ($business_name === '')      $errors[] = 'Business name is required.';
if ($contact_name === '')       $errors[] = 'Contact name is required.';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
if ($what_you_do === '')        $errors[] = 'Tell us what your business does.';

if ($errors) {
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset=utf-8><title>Form error</title>';
    echo '<body style="font-family:system-ui;padding:40px;max-width:560px;margin:auto;">';
    echo '<h1>We need a few more details</h1><ul>';
    foreach ($errors as $e) echo '<li>' . htmlspecialchars($e) . '</li>';
    echo '</ul><p><a href="javascript:history.back()">Go back</a> and try again.</p>';
    exit;
}

// ---------- Plan price config ----------
$BUILD_FEE_CENTS = 49900;

$plan_config = [
    'build_only'    => ['mode' => 'payment',      'monthly_cents' => 0,    'label' => 'WebWiz Build'],
    'build_plus_49' => ['mode' => 'subscription', 'monthly_cents' => 4900, 'label' => 'WebWiz Hosting & Care'],
    'build_plus_99' => ['mode' => 'subscription', 'monthly_cents' => 9900, 'label' => 'WebWiz Hosting & Care + Edits'],
];
$cfg = $plan_config[$plan];

$origin = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$origin .= ($_SERVER['HTTP_HOST'] ?? 'trywebwiz.com');

$params = [
    'mode'                => $cfg['mode'],
    'success_url'         => $origin . '/success?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url'          => $origin . '/cancel',
    'customer_email'      => $email,
    'client_reference_id' => substr(preg_replace('/[^A-Za-z0-9_\-]/', '', $business_name . '_' . time()), 0, 199),
    'allow_promotion_codes' => 'true',
    'metadata' => [
        'plan'          => $plan,
        'business_name' => $business_name,
        'contact_name'  => $contact_name,
        'phone'         => $phone,
        'current_site'  => $current_site,
        'what_you_do'   => substr($what_you_do, 0, 500),
        'audience'      => substr($audience, 0, 500),
        'inspiration'   => substr($inspiration, 0, 500),
        'notes'         => substr($notes, 0, 500),
    ],
];

if ($cfg['mode'] === 'payment') {
    $params['line_items'] = [[
        'price_data' => [
            'currency'     => 'usd',
            'unit_amount'  => $BUILD_FEE_CENTS,
            'product_data' => ['name' => 'WebWiz Build', 'description' => 'Hand-designed website. Two revision rounds. Hosting, SSL, domain — first year on us.'],
        ],
        'quantity' => 1,
    ]];
    $params['payment_intent_data'] = [
        'description' => 'WebWiz build for ' . $business_name,
    ];
} else {
    // Subscription mode: mix one-time + recurring line items.
    // Stripe Checkout charges both on the first invoice; only the recurring item renews.
    $params['line_items'] = [
        [
            'price_data' => [
                'currency'     => 'usd',
                'unit_amount'  => $BUILD_FEE_CENTS,
                'product_data' => ['name' => 'WebWiz Build (one-time)'],
            ],
            'quantity' => 1,
        ],
        [
            'price_data' => [
                'currency'     => 'usd',
                'unit_amount'  => $cfg['monthly_cents'],
                'recurring'    => ['interval' => 'month'],
                'product_data' => ['name' => $cfg['label']],
            ],
            'quantity' => 1,
        ],
    ];
    $params['subscription_data'] = [
        'description' => 'WebWiz care plan for ' . $business_name,
        'metadata'    => ['plan' => $plan, 'business_name' => $business_name],
    ];
}

$body = http_build_query($params, '', '&');

$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 25,
    CURLOPT_USERPWD        => $STRIPE_SECRET . ':',
    CURLOPT_HTTPHEADER     => [
        'Stripe-Version: 2024-06-20',
        'Content-Type: application/x-www-form-urlencoded',
    ],
]);
$resp = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($resp === false) {
    error_log('[webwiz checkout] curl error: ' . $err);
    http_response_code(502);
    exit('Could not reach payment processor. Email hello@trywebwiz.com.');
}

$data = json_decode($resp, true);
if ($http_code >= 400 || empty($data['url'])) {
    error_log('[webwiz checkout] stripe ' . $http_code . ' resp: ' . substr($resp, 0, 2000));
    http_response_code(502);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset=utf-8><title>Payment error</title>';
    echo '<body style="font-family:system-ui;padding:40px;max-width:560px;margin:auto;">';
    echo '<h1>Payment processor said no</h1>';
    echo '<p>' . htmlspecialchars($data['error']['message'] ?? 'Unknown error from Stripe.') . '</p>';
    echo '<p>Email <a href="mailto:hello@trywebwiz.com">hello@trywebwiz.com</a> and we will sort it out.</p>';
    exit;
}

// Log the lead
$log_dir = __DIR__ . '/../../logs';
@mkdir($log_dir, 0755, true);
$log_line = json_encode([
    'ts'            => gmdate('c'),
    'session_id'    => $data['id'] ?? null,
    'plan'          => $plan,
    'email'         => $email,
    'business_name' => $business_name,
    'contact_name'  => $contact_name,
    'phone'         => $phone,
    'current_site'  => $current_site,
    'remote'        => $_SERVER['REMOTE_ADDR'] ?? '',
]);
@file_put_contents($log_dir . '/leads.jsonl', $log_line . "\n", FILE_APPEND | LOCK_EX);

header('Location: ' . $data['url'], true, 303);
exit;
