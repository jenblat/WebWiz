<?php
// /api/cron_billing.php — runs the dunning schedule for lapsed subscriptions.
// Hourly from www-data's crontab. Sends warning 2 and warning 3, then suspends.
//
// Day 0 (the "your card didn't go through" email) is NOT sent here: it is sent
// by webhook.php the moment invoice.payment_failed arrives, so the customer
// hears about it immediately rather than up to an hour later.
//
// Everything is driven off billing_lifecycle.failed_at and each step stamps its
// own column, so a step can never fire twice and a missed run simply catches up
// on the next tick instead of skipping.
declare(strict_types=1);

require_once '/var/www/sites/trywebwiz/private/webwiz_lib.php';
require_once '/var/www/sites/trywebwiz/public/api/_billing.php';
require_once '/var/www/sites/trywebwiz/public/api/_email_templates.php';

@set_time_limit(120);
ignore_user_abort(true);

$secrets   = require '/var/www/sites/trywebwiz/secrets.php';
$brevo_key = (string)($secrets['BREVO_API_KEY'] ?? '');

// One run at a time. Overlapping runs could double-send a warning.
$lock = @fopen('/tmp/ww_billing.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Billing run skipped: another run in progress.\n";
    exit(0);
}

$db = ww_db();
ww_billing_init_schema($db);

function wwb_send(string $key, string $to, string $subject, string $html): bool {
    if ($key === '' || $to === '') return false;
    $payload = [
        'sender'      => ['name' => 'Wizzy at WebWiz', 'email' => 'wizzy@trywebwiz.com'],
        'to'          => [['email' => $to]],
        'subject'     => $subject,
        'htmlContent' => $html,
        'replyTo'     => ['email' => 'hello@trywebwiz.com', 'name' => 'Wizzy at WebWiz'],
    ];
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['accept: application/json', 'content-type: application/json', 'api-key: ' . $key],
    ]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http >= 300 || $resp === false) {
        error_log('[billing] send failed http=' . $http);
        return false;
    }
    return true;
}

/** Plain, non-punitive warning copy. The goal is a fixed card, not a told-off customer. */
function wwb_warning_html(string $business, string $invoice_url, int $days_left): string {
    $b   = htmlspecialchars($business !== '' ? $business : 'your website', ENT_QUOTES);
    $cta = $invoice_url !== '' ? htmlspecialchars($invoice_url, ENT_QUOTES) : 'mailto:hello@trywebwiz.com?subject=Update%20my%20card';
    $when = $days_left <= 1 ? 'tomorrow' : ('in ' . $days_left . ' days');
    return '<div style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;font-size:16px;line-height:1.55;color:#12184A;">'
         . '<p>Hi,</p>'
         . '<p>The monthly payment for <strong>' . $b . '</strong> has not gone through yet. It is usually an expired card, nothing more.</p>'
         . '<p>If it stays unpaid the site goes offline <strong>' . $when . '</strong>. Nothing is deleted and nothing is lost, it just stops being visible until the payment clears.</p>'
         . '<p><a href="' . $cta . '" style="display:inline-block;background:#F7C84A;color:#12184A;border:2px solid #12184A;border-radius:10px;padding:12px 22px;text-decoration:none;font-weight:800;">Update the payment</a></p>'
         . '<p>If something else is going on, or you want to stop, just reply to this email and tell me. No hard feelings and no cancellation fee.</p>'
         . '<p>Wizzy at WebWiz</p></div>';
}

function wwb_suspended_html(string $business, string $invoice_url): string {
    $b   = htmlspecialchars($business !== '' ? $business : 'your website', ENT_QUOTES);
    $cta = $invoice_url !== '' ? htmlspecialchars($invoice_url, ENT_QUOTES) : 'mailto:hello@trywebwiz.com?subject=Restore%20my%20site';
    return '<div style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;font-size:16px;line-height:1.55;color:#12184A;">'
         . '<p>Hi,</p>'
         . '<p><strong>' . $b . '</strong> is now offline because the hosting payment did not clear.</p>'
         . '<p><strong>Nothing has been deleted.</strong> Your site is exactly as you left it. Clear the payment and it is back within minutes, same address, same content.</p>'
         . '<p><a href="' . $cta . '" style="display:inline-block;background:#F7C84A;color:#12184A;border:2px solid #12184A;border-radius:10px;padding:12px 22px;text-decoration:none;font-weight:800;">Put it back online</a></p>'
         . '<p>If you would rather stop, reply and say so and I will send you your files.</p>'
         . '<p>Wizzy at WebWiz</p></div>';
}

$now = time();
$rows = $db->query("SELECT * FROM billing_lifecycle WHERE state = 'grace' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$w2 = $w3 = $susp = 0;

foreach ($rows as $r) {
    $failed = strtotime(((string)$r['failed_at']) . ' UTC');
    if (!$failed) continue;
    $age_days = ($now - $failed) / 86400;
    $email    = (string)$r['email'];
    $biz      = (string)$r['business_name'];
    $inv      = (string)$r['invoice_url'];
    $id       = (int)$r['id'];

    try {
        // Suspend last-to-first so a single run cannot both warn and suspend.
        if ($age_days >= WW_BILLING_SUSPEND_DAYS && empty($r['suspended_at'])) {
            $ok = ww_billing_suspend_site((string)$r['token'], $biz);
            ww_db_write_retry(function () use ($db, $id) {
                return $db->prepare("UPDATE billing_lifecycle SET state='suspended', suspended_at=datetime('now'), updated_at=datetime('now') WHERE id=?")
                          ->execute([$id]);
            });
            wwb_send($brevo_key, $email, 'Your site is offline', wwb_suspended_html($biz, $inv));
            error_log('[billing] suspended id=' . $id . ' token=' . (string)$r['token'] . ' file_moved=' . ($ok ? 'yes' : 'no'));
            $susp++;
            continue;
        }
        if ($age_days >= WW_BILLING_WARN3_DAYS && empty($r['warn3_at'])) {
            wwb_send($brevo_key, $email, 'Last reminder about ' . ($biz !== '' ? $biz : 'your site'), wwb_warning_html($biz, $inv, 1));
            // Stamp warn2 as well. A record that arrives here without it (the cron
            // was down, or the invoice failed more than 7 days before we saw it)
            // has skipped that step for good, and leaving the column empty made
            // the NEXT run fall through and send the softer "in N days" warning
            // AFTER the final one. Caught in testing: the 7-day record sent
            // warning 3, then warning 2 an hour later, in that order.
            ww_db_write_retry(function () use ($db, $id) {
                return $db->prepare("UPDATE billing_lifecycle SET warn3_at=datetime('now'), warn2_at=COALESCE(warn2_at, datetime('now')), updated_at=datetime('now') WHERE id=?")->execute([$id]);
            });
            $w3++;
            continue;
        }
        if ($age_days >= WW_BILLING_WARN2_DAYS && empty($r['warn2_at']) && empty($r['warn3_at'])) {
            $left = max(1, WW_BILLING_SUSPEND_DAYS - (int)floor($age_days));
            wwb_send($brevo_key, $email, 'A quick note about ' . ($biz !== '' ? $biz : 'your site'), wwb_warning_html($biz, $inv, $left));
            ww_db_write_retry(function () use ($db, $id) {
                return $db->prepare("UPDATE billing_lifecycle SET warn2_at=datetime('now'), updated_at=datetime('now') WHERE id=?")->execute([$id]);
            });
            $w2++;
        }
    } catch (Throwable $e) {
        // One bad row must never stop the rest of the run.
        error_log('[billing] row ' . $id . ' failed: ' . $e->getMessage());
    }
}

echo sprintf("Billing run: open=%d warn2=%d warn3=%d suspended=%d\n", count($rows), $w2, $w3, $susp);
