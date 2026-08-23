<?php
/**
 * /api/offer_lead.php - lead capture for the unlisted price-test pages (/o/*).
 *
 * No generation, no payment. Stores the brief with its variant so the three
 * price points can be compared on the number that matters (leads per view),
 * emails the team, and records a try_event.
 */
declare(strict_types=1);
@set_time_limit(20);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once '/var/www/sites/trywebwiz/private/webwiz_lib.php';

function ol_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') ol_fail('POST only', 405);

$raw  = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) ol_fail('Bad request');

$variant  = substr(trim((string)($body['variant']  ?? '')), 0, 12);
$business = trim((string)($body['business'] ?? ''));
$about    = trim((string)($body['about']    ?? ''));
$wants    = trim((string)($body['wants']    ?? ''));
$contact  = trim((string)($body['contact']  ?? ''));

if ($business === '')            ol_fail('Please tell us your business name.');
if (mb_strlen($about) < 8)       ol_fail('A sentence about what you do, please.');
if (mb_strlen($contact) < 6)     ol_fail('We need an email or phone number to send it to.');

// Cheap length caps so a junk POST cannot bloat the row.
$business = mb_substr($business, 0, 200);
$about    = mb_substr($about,    0, 4000);
$wants    = mb_substr($wants,    0, 4000);
$contact  = mb_substr($contact,  0, 200);

try {
    $db = ww_db();

    // Own table: these are not prospects (no generation, no token) and mixing
    // them into prospects would distort the /try funnel numbers.
    // The schema now lives in ww_offer_leads_ensure() because offer_checkout.php
    // writes builder-cell (a/b) leads into the same table and the two callers
    // must not disagree about its shape.
    ww_offer_leads_ensure($db);

    $ins = $db->prepare("INSERT INTO offer_leads
        (variant, business, about, wants, contact, ip, user_agent, status, created_at, source)
        VALUES (?,?,?,?,?,?,?,'new',datetime('now'),'brief')");

    // Retry on SQLITE_BUSY rather than losing a paid-for lead to a lock.
    ww_db_write_retry(function () use ($ins, $variant, $business, $about, $wants, $contact) {
        return $ins->execute([
            $variant, $business, $about, $wants, $contact,
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
        ]);
    });
    $lead_id = (int)$db->lastInsertId();

    // ---- Nurture enrollment ----
    // offer_leads was a dead end: a row, a Stripe session, and nothing else ever.
    // Marketingcasey9@gmail.com (ids 17 and 18, 2026-08-08) opened Stripe twice at
    // $50, both expired unpaid, and heard nothing afterwards because she was never
    // in nurture_contacts. She is the closest anyone has come to buying.
    //
    // ENROLLED PAUSED ON PURPOSE. The live 5-step sequence is written for someone
    // who has a generated preview: step 1 is subject "The free website we made for
    // {{company}}", eyebrow "YOUR FREE WEBSITE IS STILL LIVE", body "this is the
    // website we built for X, free of charge, and it's still live", and the CTA
    // points at {{preview_url}}. A /o/ form lead has NO generated site and NO
    // preview_url, so that copy is a flat untruth with a broken button on the end.
    // Enrolling them active would have shipped exactly the mismatched copy we were
    // told not to ship. Paused means they are captured, deduped, visible in the
    // admin and reachable the moment a suitable sequence exists, and meanwhile a
    // human gets the notification email below.
    // TO ACTIVATE: write an offer-form sequence (no preview_url, no "we already
    // built it"), then flip these contacts to active.
    $nurture_id = 0;
    try {
        require_once __DIR__ . '/_nurture.php';
        if (function_exists('ww_nurture_upsert_contact')) {
            $nurture_id = (int)ww_nurture_upsert_contact($db, [
                'email'   => $contact,
                'name'    => '',
                'company' => $business,
                'source'  => 'offer_form',
                'status'  => 'paused',
            ]);
        }
    } catch (Throwable $e) {
        // A lead that is saved but not enrolled is recoverable; losing the lead is not.
        error_log('[offer_lead] nurture enroll failed: ' . $e->getMessage());
        ww_report('offer_lead', 'offer_lead_nurture_enroll_failed',
            'WebWiz offer lead saved but nurture enrollment failed', ['lead_id' => $lead_id], 'warning', $e, 0);
    }

    try {
        // try_events has no 'detail' column - variant goes in payload.
        $db->prepare("INSERT INTO try_events (event, payload, ip, user_agent) VALUES ('offer_lead', ?, ?, ?)")
           ->execute([
               json_encode(['variant' => $variant, 'lead_id' => $lead_id], JSON_UNESCAPED_SLASHES),
               substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
               substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
           ]);
    } catch (Throwable $e) { /* analytics must not fail the lead */ }

    // Notify the team. A failed email must not lose the lead - it is already saved.
    try {
        if (function_exists('ww_send_email')) {
            $s  = function_exists('ww_secrets') ? ww_secrets() : [];
            $to = !empty($s['NOTIFY_EMAIL']) ? $s['NOTIFY_EMAIL'] : 'ultimax97@gmail.com';
            $h  = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES);
            $html = '<h2>New offer-page lead (variant ' . $h($variant) . ')</h2>'
                  . '<table style="border-collapse:collapse;font-size:14px">'
                  . '<tr><td style="padding:3px 12px 3px 0">Business</td><td><b>' . $h($business) . '</b></td></tr>'
                  . '<tr><td style="padding:3px 12px 3px 0">Contact</td><td><b>' . $h($contact) . '</b></td></tr>'
                  . '<tr><td style="padding:3px 12px 3px 0">Variant</td><td><b>' . $h($variant) . '</b></td></tr>'
                  . '</table>'
                  . '<p><b>What they do:</b><br>' . nl2br($h($about)) . '</p>'
                  . ($wants !== '' ? '<p><b>What they want:</b><br>' . nl2br($h($wants)) . '</p>' : '')
                  . '<p style="color:#666;font-size:12px">Lead #' . $lead_id . ' &middot; no site was generated, this needs a human to build it.</p>';
            ww_send_email(['email' => $to, 'name' => 'WebWiz Ops'], 'New offer lead: ' . $business . ' (' . $variant . ')', $html);
        }
    } catch (Throwable $e) {
        // Lead is stored; the team just has not been told. Same failure shape as brief.php.
        ww_report('offer_lead', 'offer_lead_notify_email_failed',
            'WebWiz offer lead saved but ops email failed', ['lead_id' => $lead_id], 'error', $e, 0);
    }

    echo json_encode(['ok' => true, 'id' => $lead_id]);

} catch (Throwable $e) {
    error_log('[offer_lead] ' . $e->getMessage());
    ww_report('offer_lead', 'offer_lead_save_failed', 'WebWiz offer lead could not be saved',
        [], 'error', $e, 0);
    ol_fail('Could not save that. Please try again.', 500);
}
