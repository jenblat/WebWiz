<?php
/**
 * /api/offer_checkout.php - Stripe Checkout for the unlisted price-test pages.
 *
 * Deliberately separate from try_checkout.php. That one is the live $500 funnel
 * and must not change while this is only a test; duplicating the ~20 lines of
 * Stripe payload is much cheaper than risking the funnel that currently earns.
 *
 * OFFERS (must stay in sync with $VARIANTS in /o/_offer.php):
 *   a  $100 build + $50/month   - one-time build line + recurring line, with a
 *                                 30-day trial on the recurring line so the
 *                                 total due TODAY reads $100 and not $150.
 *   b  $0 build + $50/month     - recurring line only, NO trial. Reached
 *                                 through the builder, same as a, but the
 *                                 build is free: the $50 taken today is
 *                                 month one.
 *   c  $0 build + $50/month     - recurring line only, NO trial, same money as
 *                                 b. Reached through the brief form instead of
 *                                 the builder, so it never carries a token.
 *
 * The 30-day trial exists ONLY on a. Without it Stripe would bill the build fee
 * AND the first month at once, and the total due would read $150 against a page
 * promising $100. b and c have no build fee, so there is nothing to defer.
 *
 * SECURITY: the variant is never taken from the request body. See the
 * resolution block below.
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

$lead_id = (int)($body['lead_id'] ?? 0);
$email   = trim((string)($body['email'] ?? ''));
$token   = substr(trim((string)($body['token'] ?? '')), 0, 64);

// Offer table. Keep in lockstep with $VARIANTS in /o/_offer.php.
// Only A charges a build fee. B and C bill the $50/month and nothing else, so
// they have no trial: the $50 taken today IS their first month.
$OFFERS = [
    'a' => ['build' => 10000, 'monthly' => 5000, 'trial' => 30, 'label' => 'WebWiz website build'],
    'b' => ['build' => 0,     'monthly' => 5000, 'trial' => 0,  'label' => 'WebWiz hosting & care'],
    'c' => ['build' => 0,     'monthly' => 5000, 'trial' => 0,  'label' => 'WebWiz hosting & care'],
];

// ---------------------------------------------------------------------------
// VARIANT RESOLUTION - server-side only.
//
// The variant is NEVER read from the request body. It used to be, whenever no
// token was supplied, which let a cell-A visitor POST {"variant":"b"} with no
// token and buy A's product with the $100 build fee stripped out.
//
//   token present -> the jobs row is the only authority. Cells a and b are the
//                    builder cells and ALWAYS carry the token of the site they
//                    generated. A token that resolves to no known variant is a
//                    hard 400: failing closed is correct, because failing open
//                    means selling the wrong cell at the wrong price.
//   no token      -> forced to 'c'. C is the only funnel without a builder,
//                    therefore the only one that legitimately reaches checkout
//                    with no job token. A tokenless request IS a cell-C brief
//                    checkout by definition, and c is also the cheapest cell to
//                    fulfil, so this cannot be used to buy down a price.
// ---------------------------------------------------------------------------
$variant = 'c';
if ($token !== '') {
    try {
        $st = ww_db()->prepare("SELECT offer_variant, customer_email FROM jobs WHERE token = ? LIMIT 1");
        $st->execute([$token]);
        $job = $st->fetch(PDO::FETCH_ASSOC);
        if (!$job) oc_fail('Unknown preview.', 404);
        if (empty($job['offer_variant'])) oc_fail('This preview is not part of a price test.', 400);
        $variant = strtolower(trim((string)$job['offer_variant']));
        if (!isset($OFFERS[$variant])) oc_fail('This preview is not part of a price test.', 400);
        if ($email === '' && !empty($job['customer_email'])) $email = (string)$job['customer_email'];
    } catch (Throwable $e) {
        error_log('[offer_checkout] token lookup: ' . $e->getMessage());
        oc_fail('Could not start checkout. Please try again.', 500);
    }
}

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
    // Session-level copies too: webhook.php reads $obj['metadata']['token'] and
    // ['source'] off the checkout.session object to stop the nurture sequence
    // and record checkout_completed. Subscription metadata is not visible there.
    'metadata[offer_variant]' => $variant,
    'metadata[lead_id]'       => (string)$lead_id,
    'metadata[token]'         => $token,
    'metadata[source]'        => 'offer_price_test',
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
    // Builder cells: remember the session on the job, the same way
    // try_checkout.php does, so the webhook can tie a payment to a preview.
    if ($token !== '' && !empty($json['id'])) {
        ww_db_write_retry(function () use ($db, $json, $token) {
            return $db->prepare("UPDATE jobs SET stripe_session_id = ? WHERE token = ?")
                      ->execute([(string)$json['id'], $token]);
        });
    }
    $db->prepare("INSERT INTO try_events (event, token, session_id, payload, ip, user_agent) VALUES ('offer_checkout', ?, ?, ?, ?, ?)")
       ->execute([
           $token !== '' ? $token : null,
           $json['id'] ?? null,
           json_encode(['variant' => $variant, 'lead_id' => $lead_id, 'session' => $json['id'] ?? null], JSON_UNESCAPED_SLASHES),
           substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
           substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
       ]);
} catch (Throwable $e) { /* never block a paying customer on analytics */ }

// 'url' is what /o/_offer.php's brief form reads; 'checkout_url' is what the
// builder page (/try/index.php, shared with the $500 try_checkout.php path)
// reads. Return both so neither caller has to care which endpoint answered.
echo json_encode([
    'ok'           => true,
    'url'          => $json['url'],
    'checkout_url' => $json['url'],
    'session_id'   => $json['id'] ?? null,
]);
