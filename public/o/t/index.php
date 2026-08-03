<?php
/**
 * /o/t/ - GUARDED $1 LIVE-PAYMENT TEST CELL, tokenless (brief) entry point.
 *
 * WHY THIS EXISTS
 * The A/B/C price test runs behind live paid traffic, so nothing downstream of a
 * real `checkout.session.completed` for an /o cell has ever executed: webhook
 * fulfilment, offer_leads.status='purchased', the checkout_completed event, the
 * Meta Purchase + Subscribe CAPI events, the paid branch of the receipt, and the
 * confirmation email are all unproven. Proving them needs a real card, and a
 * real card needs a price we are willing to actually pay. Hence $1.00 today and
 * $1.00/month, with no trial.
 *
 * This is deliberately NOT a shortcut. It renders /o/_offer.php, posts to
 * /api/offer_lead.php and /api/offer_checkout.php, goes through Stripe Checkout,
 * comes back through /api/webhook.php and renders the same receipt block as
 * cell C. A bypass would prove nothing.
 *
 * TOKENLESS ON PURPOSE. This mirrors cell C - brief form, no job token - because
 * webhook.php's tokenless branch is the one that used to record nothing at all.
 * /o/t/try/ is the token-carrying half of the same test.
 *
 * ACCESS. 404 without the correct ?k=<OFFER_TEST_KEY>. A wrong key, a missing
 * key or an unconfigured secret are all indistinguishable from "this page does
 * not exist": no hint that a $1 price exists anywhere on the site.
 */
declare(strict_types=1);

require_once '/var/www/sites/trywebwiz/private/webwiz_lib.php';

if (!ww_offer_test_key_ok($_GET['k'] ?? '')) {
    // Genuine 404, not 403: 403 confirms the path is real and worth attacking.
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Cache-Control: no-store');
    exit('<!doctype html><meta charset="utf-8"><title>Not found</title><h1>404 Not Found</h1>');
}

$WW_VARIANT = 't';
require __DIR__ . '/../_offer.php';
