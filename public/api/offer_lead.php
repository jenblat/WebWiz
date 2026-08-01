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
    $db->exec("CREATE TABLE IF NOT EXISTS offer_leads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        variant TEXT NOT NULL,
        business TEXT NOT NULL,
        about TEXT,
        wants TEXT,
        contact TEXT NOT NULL,
        ip TEXT,
        user_agent TEXT,
        status TEXT NOT NULL DEFAULT 'new',
        created_at TEXT NOT NULL
    )");

    $ins = $db->prepare("INSERT INTO offer_leads
        (variant, business, about, wants, contact, ip, user_agent, status, created_at)
        VALUES (?,?,?,?,?,?,?,'new',datetime('now'))");

    // Retry on SQLITE_BUSY rather than losing a paid-for lead to a lock.
    ww_db_write_retry(function () use ($ins, $variant, $business, $about, $wants, $contact) {
        return $ins->execute([
            $variant, $business, $about, $wants, $contact,
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
        ]);
    });
    $lead_id = (int)$db->lastInsertId();

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
    } catch (Throwable $e) { /* swallow */ }

    echo json_encode(['ok' => true, 'id' => $lead_id]);

} catch (Throwable $e) {
    error_log('[offer_lead] ' . $e->getMessage());
    ol_fail('Could not save that. Please try again.', 500);
}
