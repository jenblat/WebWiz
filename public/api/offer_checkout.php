<?php
/**
 * /api/offer_checkout.php - Stripe Checkout for the unlisted price-test pages.
 *
 * Deliberately separate from try_checkout.php. That one is the live $500 funnel
 * and must not change while this is only a test; duplicating the ~20 lines of
 * Stripe payload is much cheaper than risking the funnel that currently earns.
 *
 * OFFERS (must stay in sync with $VARIANTS in /o/_offer.php):
 *   a  $100 build + $50/month   - one-time line + recurring line, 30-day trial
 *                                 on the recurring line so TODAY reads $100.
 *   b  same as a, reached through the builder instead of the brief form.
 *   c  $0 build + $50/month     - recurring line only, NO trial, so the $50
 *                                 charged today literally is month one, which
 *                                 is how the page words it.
 *
 * The 30-day trial on a/b is the same trick try_checkout.php uses: without it
 * Stripe bills the build fee AND the first month at once, and the total due
 * would read $150 against a page promising $100.
 */
declare(strict_types=1);
@set_time_limit(25);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once '/var/www/sites/trywebwiz/private/webwiz_lib.php';

function oc_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') oc_fail('POST only', 405);

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) oc_fail('Bad request');

$variant = substr(trim((string)($body['variant'] ?? '')), 0, 12);
$lead_id = (int)($body['lead_id'] ?? 0);
$email   = trim((string)($body['email'] ?? ''));
$token   = substr(trim((string)($body['token'] ?? '')), 0, 64);

// Builder cells (a, b) check out from /try and send a token rather than a
// variant. The job row is the authority on which offer they were sold, so it
// always wins over anything the client claims - otherwise a crafted request
// could buy a $100 build for $0.
if ($token !== '') {
    try {
        $st = ww_db()->prepare("SELECT offer_variant, customer_email FROM jobs WHERE token = ? LIMIT 1");
        $st->execute([$token]);
        $job = $st->fetch(PDO::FETCH_ASSOC);
        if (!$job) oc_fail('Unknown preview.', 404);
        if (empty($job['offer_variant'])) oc_fail('This preview is not part of a price test.', 400);
        $variant = (string)$job['offer_variant'];
        if ($email === '' && !empty($job['customer_email'])) $email = (string)$job['customer_email'];
    } catch (Throwable $e) {
        error_log('[offer_checkout] token lookup: ' . $e->getMessage());
        oc_fail('Could not start checkout. Please try again.', 500);
    }
}

// Offer table. Keep in lockstep with /o/_offer.php.
$OFFERS = [
    // Only A charges a build fee. B and C bill the $50/month and nothing else,
    // so they have no trial: the $50 taken today IS their first month.
    'a' => ['build' => 10000, 'monthly' => 5000, 'trial' => 30, 'label' => 'WebWiz website build'],
    'b' => ['build' => 0,     'monthly' => 5000, 'trial' => 0,  'label' => 'WebWiz hosting & care'],
    'c' => ['build' => 0,     'monthly' => 5000, 'trial' => 0,  'label' => 'WebWiz hosting & care'],
];
if (!isset($OFFERS[$variant])) oc_fail('Unknown offer.');
$O = $OFFERS[$variant];

$secrets = function_exists('ww_secrets') ? ww_secrets() : [];
$STRIPE_SECRET = (string)($secrets['STRIPE_SECRET_KEY'] ?? '');
if ($STRIPE_SECRET === '') oc_fail('Stripe is not configured.', 500);

$origin = 'https://trywebwiz.com';

$payload = [
    'mode'        => 'subscription',
    // Builder cells must come back to their preview, not the landing page -
    // returning someone to a sales page after they have paid, or losing their
    // generated site on cancel, would both be bad.
    'success_url' => $token !== ''
        ? $origin . '/o/' . $variant . '/try/?success=1&t=' . urlencode($token) . '&sid={CHECKOUT_SESSION_ID}'
        : $origin . '/o/' . $variant . '/?success=1&sid={CHECKOUT_SESSION_ID}',
    'cancel_url'  => $token !== ''
        ? $origin . '/o/' . $variant . '/try/?t=' . urlencode($token)
        : $origin . '/o/' . $variant . '/?cancelled=1',
    'allow_promotion_codes' => 'true',
    'subscription_data[metadata][offer_variant]' => $variant,
    'subscription_data[metadata][lead_id]'       => (string)$lead_id,
    'subscription_data[metadata][token]'         => $token,
    'subscription_data[metadata][source]'        => 'offer_price_test',
    'subscription_data[description]'             => 'WebWiz Hosting & Care',
    'metadata[offer_variant]' => $variant,
    'metadata[lead_id]'       => (string)$lead_id,
];

if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $payload['customer_email'] = $email;
}

$i = 0;
if ($O['build'] > 0) {
    // One-time build fee.
    $payload["line_items[$i][price_data][currency]"]                      = 'usd';
    $payload["line_items[$i][price_data][unit_amount]"]                   = $O['build'];
    $payload["line_items[$i][price_data][product_data][name]"]            = $O['label'];
    $payload["line_items[$i][price_data][product_data][description]"]     = 'Custom website designed for your business by a real designer.';
    $payload["line_items[$i][quantity]"]                                  = 1;
    $i++;
}
// Recurring hosting.
$payload["line_items[$i][price_data][currency]"]                          = 'usd';
$payload["line_items[$i][price_data][unit_amount]"]                       = $O['monthly'];
$payload["line_items[$i][price_data][recurring][interval]"]               = 'month';
$payload["line_items[$i][price_data][product_data][name]"]                = 'WebWiz Hosting & Care';
$payload["line_items[$i][price_data][product_data][description]"]         = 'Managed hosting, SSL, daily backups and unlimited traffic. Cancel anytime.';
$payload["line_items[$i][quantity]"]                                      = 1;

if ($O['trial'] > 0) {
    $payload['subscription_data[trial_period_days]'] = $O['trial'];
}

$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($payload),
    CURLOPT_USERPWD        => $STRIPE_SECRET . ':',
    CURLOPT_TIMEOUT        => 20,
]);
$resp = curl_exec($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$json = json_decode((string)$resp, true);
if ($http !== 200 || empty($json['url'])) {
    error_log('[offer_checkout] stripe http=' . $http . ' resp=' . substr((string)$resp, 0, 400));
    oc_fail('Could not start checkout. Please try again.', 502);
}

// Record that this lead reached checkout, so the funnel can be read per variant.
try {
    $db = ww_db();
    if ($lead_id > 0) {
        ww_db_write_retry(function () use ($db, $lead_id) {
            return $db->prepare("UPDATE offer_leads SET status='checkout' WHERE id=?")->execute([$lead_id]);
        });
    }
    $db->prepare("INSERT INTO try_events (event, payload, ip, user_agent) VALUES ('offer_checkout', ?, ?, ?)")
       ->execute([
           json_encode(['variant' => $variant, 'lead_id' => $lead_id, 'session' => $json['id'] ?? null], JSON_UNESCAPED_SLASHES),
           substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
           substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
       ]);
} catch (Throwable $e) { /* never block a paying customer on analytics */ }

echo json_encode(['ok' => true, 'url' => $json['url']]);
