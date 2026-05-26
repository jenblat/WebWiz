<?php
// /api/webhook.php — Stripe webhook receiver.
// Verifies signature, logs every event, fires transactional emails.

declare(strict_types=1);

$secrets = require __DIR__ . '/../../secrets.php';
$WEBHOOK_SECRET = $secrets['STRIPE_WEBHOOK_SECRET'] ?? '';
$BREVO_KEY      = $secrets['BREVO_API_KEY'] ?? '';
$FROM_NAME      = $secrets['EMAIL_FROM_NAME'] ?? 'WebWiz';
$FROM_ADDR      = $secrets['EMAIL_FROM_ADDR'] ?? 'sales@busyseed.com';
$REPLY_TO       = $secrets['EMAIL_REPLY_TO']  ?? 'hello@trywebwiz.com';
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

// ---------- Brevo sender (supports multiple recipients in one call) ----------
function brevo_send(string $key, array $from, array $to, ?array $reply_to, string $subject, string $html, string $text = ''): bool {
    if ($key === '') return false;
    // $to can be a single ['email'=>..,'name'=>..] OR a list of those.
    $to_list = (isset($to['email'])) ? [$to] : array_values($to);
    if (!$to_list) return false;
    $payload = [
        'sender'      => $from,
        'to'          => $to_list,
        'subject'     => $subject,
        'htmlContent' => $html,
    ];
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

// ---------- Email templates ----------
function tpl_shell(string $title, string $body_html): string {
    return '<!doctype html><html><head><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#FFF8E7;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;color:#12184A;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#FFF8E7;padding:40px 20px;">'
        . '<tr><td align="center"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:580px;background:#fff;border:4px solid #12184A;border-radius:24px;box-shadow:8px 8px 0 #F7C84A;">'
        . '<tr><td style="padding:32px 32px 16px;">'
        . '<table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="padding-right:14px;vertical-align:middle;"><img src="https://i.imgur.com/7OdNLrM.png" alt="Wizzy" width="44" height="44" style="display:block;height:44px;width:44px;border:0;"></td><td style="vertical-align:middle;"><div style="font-family:Nunito,system-ui,sans-serif;font-weight:900;font-size:28px;color:#12184A;letter-spacing:-0.02em;">WebWiz<span style="color:#F7C84A;">.</span></div></td></tr></table>'
        . '</td></tr>'
        . '<tr><td style="padding:0 32px 32px;font-size:16px;line-height:1.55;">' . $body_html . '</td></tr>'
        . '<tr><td style="padding:24px 32px;border-top:2px solid #F8EFD3;font-size:12px;color:#12184A;opacity:0.7;text-align:center;font-family:ui-monospace,monospace;letter-spacing:0.12em;text-transform:uppercase;">'
        . 'WEBWIZ STUDIO &middot; <a href="https://trywebwiz.com" style="color:#12184A;">trywebwiz.com</a> &middot; <a href="mailto:hello@trywebwiz.com" style="color:#12184A;">hello@trywebwiz.com</a></td></tr>'
        . '</table></td></tr></table></body></html>';
}

function plan_label(?string $plan): string {
    return [
        'build_only'    => 'Build only — $499',
        'build_plus_49' => 'Build + Hosting & Care — $499 + $49/mo',
        'build_plus_99' => 'Build + Hosting & Care + Edits — $499 + $99/mo',
    ][$plan ?? ''] ?? 'Custom order';
}

function dollars(?int $cents): string {
    if ($cents === null) return '—';
    return '$' . number_format($cents / 100, 2);
}

// Resolve admin recipient list once for this request.
$ADMIN_TO = ww_admin_recipients($FALLBACK_ADMIN);

// ---------- Handlers ----------
$type = $event['type'] ?? '';
$obj  = $event['data']['object'] ?? [];

if ($type === 'checkout.session.completed') {
    $email   = $obj['customer_email'] ?? ($obj['customer_details']['email'] ?? null);
    $name    = $obj['customer_details']['name'] ?? ($obj['metadata']['contact_name'] ?? '');
    $biz     = $obj['metadata']['business_name'] ?? '';
    $plan    = $obj['metadata']['plan'] ?? null;
    $amount  = $obj['amount_total'] ?? null;
    $sid     = $obj['id'] ?? '';

    $body  = '<h1 style="font-family:Nunito,system-ui;font-weight:900;font-size:32px;margin:0 0 12px;letter-spacing:-0.02em;">You\'re in.</h1>';
    $body .= '<p>Thanks ' . htmlspecialchars($name ?: 'friend') . ' — your payment went through. Below is the receipt summary. We\'ll be in touch within one business day to schedule your kickoff call.</p>';
    $body .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:18px 0;border:2px solid #12184A;border-radius:14px;background:#F8EFD3;">';
    if ($biz)    $body .= '<tr><td style="padding:10px 16px;"><strong>Business</strong></td><td style="padding:10px 16px;text-align:right;">' . htmlspecialchars($biz) . '</td></tr>';
    $body .= '<tr><td style="padding:10px 16px;border-top:1px solid #00000022;"><strong>Plan</strong></td><td style="padding:10px 16px;text-align:right;border-top:1px solid #00000022;">' . htmlspecialchars(plan_label($plan)) . '</td></tr>';
    $body .= '<tr><td style="padding:10px 16px;border-top:1px solid #00000022;"><strong>Charged today</strong></td><td style="padding:10px 16px;text-align:right;border-top:1px solid #00000022;">' . dollars($amount) . '</td></tr>';
    $body .= '<tr><td style="padding:10px 16px;border-top:1px solid #00000022;"><strong>Receipt</strong></td><td style="padding:10px 16px;text-align:right;border-top:1px solid #00000022;font-family:ui-monospace,monospace;font-size:12px;">' . htmlspecialchars(substr($sid, 0, 24)) . '…</td></tr>';
    $body .= '</table>';
    $body .= '<h3 style="font-family:Nunito,system-ui;font-weight:900;font-size:18px;margin:24px 0 8px;">What happens next</h3>';
    $body .= '<ol style="padding-left:18px;margin:0;"><li>You hear from us within 1 business day to set up your 15-minute kickoff call.</li><li>We design and build your site (~10 business days).</li><li>You go live.</li></ol>';
    $body .= '<p style="margin-top:24px;">Questions? Reply to this email any time.</p>';

    if ($email) {
        brevo_send($BREVO_KEY,
            ['name' => $FROM_NAME, 'email' => $FROM_ADDR],
            ['email' => $email, 'name' => $name ?: ($biz ?: 'Friend')],
            ['email' => $REPLY_TO, 'name' => $FROM_NAME],
            'You\'re in! Welcome to WebWiz.',
            tpl_shell('You are in', $body)
        );
    }

    // Internal alert — to ALL role=admin users.
    $admin_body  = '<h2 style="margin:0 0 12px;">New WebWiz order</h2>';
    $admin_body .= '<p><strong>' . htmlspecialchars($biz ?: '(no business name)') . '</strong> &middot; ' . htmlspecialchars(plan_label($plan)) . ' &middot; ' . dollars($amount) . '</p>';
    $admin_body .= '<table role="presentation" cellpadding="6" cellspacing="0" style="margin:8px 0 16px;border-collapse:collapse;font-size:14px;">';
    foreach ([
        'Contact'      => $name,
        'Email'        => $email,
        'Phone'        => $obj['metadata']['phone'] ?? '',
        'Current site' => $obj['metadata']['current_site'] ?? '',
        'What they do' => $obj['metadata']['what_you_do'] ?? '',
        'Audience'     => $obj['metadata']['audience'] ?? '',
        'Inspiration'  => $obj['metadata']['inspiration'] ?? '',
        'Notes'        => $obj['metadata']['notes'] ?? '',
    ] as $k => $v) {
        $admin_body .= '<tr><td style="border:1px solid #ddd;background:#fafafa;font-weight:600;vertical-align:top;">' . htmlspecialchars($k) . '</td><td style="border:1px solid #ddd;">' . nl2br(htmlspecialchars((string)$v)) . '</td></tr>';
    }
    $admin_body .= '</table>';
    $admin_body .= '<p><a href="https://dashboard.stripe.com/payments/' . htmlspecialchars((string)($obj['payment_intent'] ?? '')) . '">View in Stripe</a> &middot; <a href="https://trywebwiz.com/admin/">WebWiz admin</a></p>';

    brevo_send($BREVO_KEY,
        ['name' => 'WebWiz alerts', 'email' => $FROM_ADDR],
        $ADMIN_TO,
        null,
        '[WebWiz] New order: ' . ($biz ?: $name ?: 'unknown'),
        tpl_shell('New order', $admin_body)
    );
}

elseif ($type === 'invoice.payment_failed') {
    $email = $obj['customer_email'] ?? null;
    $name  = $obj['customer_name']  ?? '';
    $amt   = $obj['amount_due']     ?? null;
    $body  = '<h1 style="font-family:Nunito,system-ui;font-weight:900;font-size:30px;margin:0 0 12px;letter-spacing:-0.02em;">Heads up — payment didn\'t go through.</h1>';
    $body .= '<p>Hey ' . htmlspecialchars($name ?: 'there') . ' — your card was declined for the ' . dollars($amt) . ' charge on your WebWiz care plan. No big deal, things happen.</p>';
    $body .= '<p>Stripe will retry automatically over the next few days. To skip the wait, update your card here:</p>';
    if (!empty($obj['hosted_invoice_url'])) {
        $body .= '<p style="margin:24px 0;"><a href="' . htmlspecialchars($obj['hosted_invoice_url']) . '" style="display:inline-block;background:#12184A;color:#FFF8E7;padding:14px 28px;border-radius:999px;text-decoration:none;font-family:Nunito,system-ui;font-weight:900;">Update payment method &rarr;</a></p>';
    }
    $body .= '<p>If your card has been replaced or the bank flagged it, this fixes it in a minute. Reply if you want a hand.</p>';
    if ($email) {
        brevo_send($BREVO_KEY,
            ['name' => $FROM_NAME, 'email' => $FROM_ADDR],
            ['email' => $email, 'name' => $name ?: 'Friend'],
            ['email' => $REPLY_TO, 'name' => $FROM_NAME],
            'Quick thing — your WebWiz card got declined',
            tpl_shell('Payment declined', $body)
        );
    }
    brevo_send($BREVO_KEY,
        ['name' => 'WebWiz alerts', 'email' => $FROM_ADDR],
        $ADMIN_TO,
        null,
        '[WebWiz] Payment failed: ' . ($email ?? 'unknown'),
        tpl_shell('Payment failed', '<p>Invoice failed for ' . htmlspecialchars($email ?? 'unknown') . ' — ' . dollars($amt) . '. Stripe will retry.</p><p><a href="' . htmlspecialchars($obj['hosted_invoice_url'] ?? '') . '">Hosted invoice</a></p>')
    );
}

elseif ($type === 'customer.subscription.deleted') {
    $email = $obj['customer_email'] ?? null;
    $name  = '';
    if (!$email && !empty($obj['customer'])) {
        $secrets2 = require __DIR__ . '/../../secrets.php';
        $ch = curl_init('https://api.stripe.com/v1/customers/' . urlencode($obj['customer']));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $secrets2['STRIPE_SECRET_KEY'] . ':',
            CURLOPT_TIMEOUT => 10,
        ]);
        $r = curl_exec($ch); curl_close($ch);
        $cust = json_decode($r, true);
        $email = $cust['email'] ?? null;
        $name  = $cust['name'] ?? '';
    }

    $body  = '<h1 style="font-family:Nunito,system-ui;font-weight:900;font-size:30px;margin:0 0 12px;letter-spacing:-0.02em;">Sorry to see you go.</h1>';
    $body .= '<p>Your WebWiz care plan has been cancelled. You won\'t be charged again.</p>';
    $body .= '<p>A couple of things to know:</p>';
    $body .= '<ul><li>Your site stays live until the end of the billing period.</li><li>After that, hosting goes offline. We can hand off the files for you to host elsewhere — just reply.</li><li>If you change your mind, signing up again is one click.</li></ul>';
    $body .= '<p>Anything we could have done differently? Hit reply and tell us — we read every one.</p>';
    if ($email) {
        brevo_send($BREVO_KEY,
            ['name' => $FROM_NAME, 'email' => $FROM_ADDR],
            ['email' => $email, 'name' => $name ?: 'Friend'],
            ['email' => $REPLY_TO, 'name' => $FROM_NAME],
            'Your WebWiz plan has been cancelled',
            tpl_shell('Plan cancelled', $body)
        );
    }
    brevo_send($BREVO_KEY,
        ['name' => 'WebWiz alerts', 'email' => $FROM_ADDR],
        $ADMIN_TO,
        null,
        '[WebWiz] Subscription cancelled: ' . ($email ?? 'unknown'),
        tpl_shell('Sub cancelled', '<p>' . htmlspecialchars($email ?? 'unknown') . ' just cancelled their care plan. Site goes offline at end of period.</p>')
    );
}

http_response_code(200);
echo 'ok';
