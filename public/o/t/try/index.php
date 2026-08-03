<?php
/**
 * /o/t/try/ - GUARDED $1 LIVE-PAYMENT TEST CELL, builder (token) entry point.
 *
 * The token-carrying half of the $1 test, mirroring cells A and B: the visitor
 * runs the real AI builder, /api/magic.php writes a jobs row with
 * offer_variant='t', and /api/offer_checkout.php then prices the checkout from
 * that job row - the same authority a and b are priced from, with no key needed
 * at checkout time. metadata.token on the Stripe session is a real 24-hex job
 * token, so webhook.php takes its token branch.
 *
 * See /o/t/index.php for the tokenless half and the full rationale.
 *
 * ACCESS. 404 without the correct ?k=<OFFER_TEST_KEY>. The key is also what
 * lets magic.php persist offer_variant='t' (see ww_offer_variant_from_request()),
 * so a request that got past this wrapper by some other route still could not
 * mint a $1 job.
 */
declare(strict_types=1);

require_once '/var/www/sites/trywebwiz/private/webwiz_lib.php';

if (!ww_offer_test_key_ok($_GET['k'] ?? '')) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Cache-Control: no-store');
    exit('<!doctype html><meta charset="utf-8"><title>Not found</title><h1>404 Not Found</h1>');
}

$WW_OFFER = 't';
require '/var/www/sites/trywebwiz/public/try/index.php';
