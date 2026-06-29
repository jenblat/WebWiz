<?php
// /api/_nurture.php — Nurture email engine library for /try leads.
// Brevo is SEND TRANSPORT ONLY. Cadence + templates owned here, never in Brevo.
// Direct HTTP access to this file returns nothing (function definitions only).
declare(strict_types=1);

const NURTURE_SENDER_NAME  = 'Wizzy from WebWiz';
const NURTURE_SENDER_EMAIL = 'wizzy@trywebwiz.com';
const NURTURE_REPLY_TO     = 'hello@trywebwiz.com';
const NURTURE_REPLY_NAME   = 'WebWiz';
const NURTURE_DOMAIN       = 'https://trywebwiz.com';

/** Idempotent schema creation. Safe to call on every request. */
function ww_nurture_init_schema(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS nurture_contacts (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            name          TEXT    DEFAULT '',
            email         TEXT    NOT NULL UNIQUE,
            company       TEXT    DEFAULT '',
            website       TEXT    DEFAULT '',
            token         TEXT    DEFAULT '',
            preview_url   TEXT    DEFAULT '',
            source        TEXT    DEFAULT 'try',
            status        TEXT    NOT NULL DEFAULT 'active',
            pause_until   TEXT,
            current_step  INTEGER NOT NULL DEFAULT 0,
            last_sent_at  TEXT,
            next_send_at  TEXT,
            created_at    TEXT    DEFAULT (datetime('now')),
            updated_at    TEXT    DEFAULT (datetime('now'))
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_nurture_status_send ON nurture_contacts(status, next_send_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_nurture_email ON nurture_contacts(email)");
    $db->exec("
        CREATE TABLE IF NOT EXISTS nurture_sends (
            id                INTEGER PRIMARY KEY AUTOINCREMENT,
            contact_id        INTEGER NOT NULL,
            step              INTEGER NOT NULL,
            subject           TEXT,
            brevo_message_id  TEXT,
            status            TEXT,
            sent_at           TEXT DEFAULT (datetime('now')),
            FOREIGN KEY (contact_id) REFERENCES nurture_contacts(id) ON DELETE CASCADE
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_nurture_sends_contact ON nurture_sends(contact_id)");

    // Extra columns on nurture_sends for open + click tracking (additive,
    // safe to retry — SQLite throws on duplicate so we wrap each).
    foreach ([
        "ALTER TABLE nurture_sends ADD COLUMN opened_at TEXT",
        "ALTER TABLE nurture_sends ADD COLUMN first_opened_at TEXT",
        "ALTER TABLE nurture_sends ADD COLUMN open_count INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE nurture_sends ADD COLUMN click_count INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE nurture_sends ADD COLUMN last_clicked_at TEXT",
    ] as $sql) {
        try { $db->exec($sql); } catch (Throwable $e) { /* column exists, fine */ }
    }

    // Granular events log — one row per open or click, with IP/UA + target.
    $db->exec("
        CREATE TABLE IF NOT EXISTS nurture_events (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            contact_id   INTEGER NOT NULL,
            send_id      INTEGER,
            type         TEXT    NOT NULL,
            target       TEXT    DEFAULT '',
            ip           TEXT    DEFAULT '',
            user_agent   TEXT    DEFAULT '',
            occurred_at  TEXT    DEFAULT (datetime('now')),
            FOREIGN KEY (contact_id) REFERENCES nurture_contacts(id) ON DELETE CASCADE
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_nurture_events_contact ON nurture_events(contact_id, occurred_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_nurture_events_send    ON nurture_events(send_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_nurture_events_type    ON nurture_events(type, occurred_at)");
}

/** HMAC for tracking links — same secret used for unsub, scope keeps them separate. */
function ww_nurture_track_sig(int $send_id, string $type, string $secret): string {
    return substr(hash_hmac('sha256', "track:{$type}:{$send_id}", $secret), 0, 16);
}

function ww_nurture_verify_track_sig(int $send_id, string $type, string $sig, string $secret): bool {
    if ($sig === '' || strlen($sig) > 32) return false;
    return hash_equals(ww_nurture_track_sig($send_id, $type, $secret), $sig);
}

/** Build the open-pixel URL for a given send. Tiny 1x1 transparent GIF. */
function ww_nurture_open_pixel_url(int $send_id, string $secret): string {
    return NURTURE_DOMAIN . '/api/track.php?e=' . $send_id . '&t=o&s=' . ww_nurture_track_sig($send_id, 'o', $secret);
}

/** Wrap a target URL in a click-tracking redirect for a given send. */
function ww_nurture_track_link(string $target_url, int $send_id, string $secret): string {
    $sig = ww_nurture_track_sig($send_id, 'c', $secret);
    return NURTURE_DOMAIN . '/api/track.php?e=' . $send_id . '&t=c&s=' . $sig . '&u=' . rawurlencode($target_url);
}

function ww_nurture_hmac_secret(PDO $db): string {
    $st = $db->prepare("SELECT value FROM settings WHERE key='nurture_hmac_secret'");
    $st->execute();
    $v = (string)$st->fetchColumn();
    if ($v !== '') return $v;
    try { $v = bin2hex(random_bytes(32)); }
    catch (Throwable $e) { $v = hash('sha256', uniqid('', true) . microtime() . mt_rand()); }
    $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('nurture_hmac_secret', ?)")->execute([$v]);
    return $v;
}

function ww_nurture_mailing_address(PDO $db): string {
    $st = $db->prepare("SELECT value FROM settings WHERE key='nurture_mailing_address'");
    $st->execute();
    return trim((string)$st->fetchColumn());
}

function ww_nurture_unsub_url(int $contact_id, string $secret): string {
    $sig = substr(hash_hmac('sha256', 'unsub:' . $contact_id, $secret), 0, 32);
    return NURTURE_DOMAIN . '/api/unsubscribe.php?c=' . $contact_id . '&sig=' . $sig;
}

function ww_nurture_verify_sig(int $contact_id, string $sig, string $secret): bool {
    if ($sig === '' || strlen($sig) > 64) return false;
    $expected = substr(hash_hmac('sha256', 'unsub:' . $contact_id, $secret), 0, 32);
    return hash_equals($expected, $sig);
}

/**
 * Cadence: step 1-5 offsets from ENROLLMENT (created_at), step 6+ = prior send + 30d.
 */
function ww_nurture_compute_next_send(string $created_at, int $step_just_sent, ?string $last_sent_at = null): string {
    $offsets = [1 => 2, 2 => 6, 3 => 12, 4 => 20, 5 => 30];
    $next_step = $step_just_sent + 1;
    if (isset($offsets[$next_step])) {
        $base = strtotime($created_at . ' UTC');
        return gmdate('Y-m-d H:i:s', $base + ($offsets[$next_step] * 86400));
    }
    $base = $last_sent_at ? strtotime($last_sent_at . ' UTC') : strtotime($created_at . ' UTC');
    return gmdate('Y-m-d H:i:s', $base + (30 * 86400));
}

/**
 * Step 1-5 are the initial sequence. Step 6+ alternates monthly A (even step) and B (odd step).
 * No em dashes anywhere. Wizzy is he/him. No AI/speed/automation language.
 */
function ww_nurture_template(int $step): array {
    if ($step === 1) return [
        'subject' => 'Your {{company}} site is still up, {{name}}',
        'body'    => "Hi {{name}},\n\nJust checking in. The website we built for {{company}} is still live and waiting for you here:\n\n{{preview_url}}\n\nIt's free to look at for as long as you like, and you only pay if you decide to keep it. Have a look and tell me what you think.\n\nWizzy from WebWiz",
    ];
    if ($step === 2) return [
        'subject' => 'A quick note on how this works',
        'body'    => "Hi {{name}},\n\nI know a free website can sound too good to be true, so here's the honest version. We're a small design studio. We build the site, host it, set up your domain, and handle the technical bits so you never have to. You just say yes.\n\nYour {{company}} site is right here whenever you want another look:\n\n{{preview_url}}\n\nWizzy from WebWiz",
    ];
    if ($step === 3) return [
        'subject' => 'Want to change anything on it?',
        'body'    => "Hi {{name}},\n\nNot quite right yet? That's the easy part. Tell our team what to change, whether it's different colors, your own photos, or new wording, and we'll make it yours.\n\nStart here and let us know what you'd tweak:\n\n{{preview_url}}\n\nWizzy from WebWiz",
    ];
    if ($step === 4) return [
        'subject' => "We'll even handle the domain",
        'body'    => "Hi {{name}},\n\nThe thing people worry about most is the technical setup. Don't. When you're ready, we point your domain, set up hosting and email, and include a domain on us. You never touch a single setting.\n\nYour site is still here:\n\n{{preview_url}}\n\nWizzy from WebWiz",
    ];
    if ($step === 5) return [
        'subject' => "I'll stop crowding your inbox",
        'body'    => "Hi {{name}},\n\nI don't want to be a pest, so I'll ease off and just check in now and then. Nothing changes on our end. The {{company}} site stays live and ready, and the offer stands whenever you are.\n\nIt's right here when you want it:\n\n{{preview_url}}\n\nWizzy from WebWiz",
    ];
    $is_a = ($step % 2) === 0;
    if ($is_a) return [
        'subject' => 'Still holding your site, {{name}}',
        'body'    => "Hi {{name}},\n\nQuick hello from Wizzy. Your {{company}} website is still live and still yours for the taking. No rush, just letting you know it hasn't gone anywhere.\n\n{{preview_url}}\n\nWizzy from WebWiz",
    ];
    return [
        'subject' => 'Your website is here whenever you are',
        'body'    => "Hi {{name}},\n\nJust keeping the light on. The site we built for {{company}} is ready when you are, and we're happy to change anything you like before it goes live.\n\n{{preview_url}}\n\nWizzy from WebWiz",
    ];
}

function ww_nurture_apply_merge(string $tpl, array $contact): string {
    $name = trim((string)($contact['name'] ?? ''));
    $company = trim((string)($contact['company'] ?? ''));
    $url = trim((string)($contact['preview_url'] ?? ''));
    if ($name === '') $name = 'there';
    if ($company === '') $company = 'your business';
    if ($url === '') $url = NURTURE_DOMAIN . '/try/';
    return strtr($tpl, [
        '{{name}}'        => $name,
        '{{company}}'     => $company,
        '{{preview_url}}' => $url,
    ]);
}

function ww_nurture_render_html(string $body_text, string $unsub_url, string $mailing_address): string {
    $body_html = '';
    foreach (preg_split('~\R~', $body_text) as $line) {
        $line = trim($line);
        if ($line === '') { $body_html .= "<br>\n"; continue; }
        $linked = preg_replace_callback('~(https?://\S+)~', function ($m) {
            $u = $m[1];
            return '<a href="' . htmlspecialchars($u, ENT_QUOTES) . '" style="color:#12184A;text-decoration:underline;">' . htmlspecialchars($u, ENT_QUOTES) . '</a>';
        }, htmlspecialchars($line, ENT_QUOTES));
        $body_html .= '<p style="margin:0 0 12px;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;font-size:16px;line-height:1.6;color:#12184A;">' . $linked . '</p>' . "\n";
    }
    $addr_html = $mailing_address !== ''
        ? '<div style="font-size:12px;color:#12184A;opacity:0.55;line-height:1.5;">' . nl2br(htmlspecialchars($mailing_address, ENT_QUOTES)) . '</div>'
        : '';
    $unsub_esc = htmlspecialchars($unsub_url, ENT_QUOTES);
    return <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:0;background:#FFF8E7;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;color:#12184A;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#FFF8E7;">
<tr><td align="center" style="padding:32px 16px;">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;width:100%;background:#fff;border:1px solid rgba(18,24,74,0.08);border-radius:14px;">
<tr><td style="padding:36px 36px 8px;">
{$body_html}
</td></tr>
<tr><td style="padding:24px 36px 28px;border-top:1px solid rgba(18,24,74,0.08);">
<div style="font-size:12px;color:#12184A;opacity:0.7;line-height:1.5;">
<a href="{$unsub_esc}" style="color:#12184A;opacity:0.85;text-decoration:underline;">Unsubscribe</a> &nbsp;&middot;&nbsp;
<a href="https://trywebwiz.com" style="color:#12184A;opacity:0.85;text-decoration:underline;">trywebwiz.com</a> &nbsp;&middot;&nbsp;
<a href="mailto:hello@trywebwiz.com" style="color:#12184A;opacity:0.85;text-decoration:underline;">hello@trywebwiz.com</a>
</div>
<div style="height:10px;"></div>
{$addr_html}
</td></tr>
</table>
</td></tr></table>
</body></html>
HTML;
}

function ww_nurture_upsert_contact(PDO $db, array $data): int {
    ww_nurture_init_schema($db);
    $email = strtolower(trim((string)($data['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return 0;

    $st = $db->prepare("SELECT id FROM nurture_contacts WHERE email = ? LIMIT 1");
    $st->execute([$email]);
    $existing_id = (int)$st->fetchColumn();

    if ($existing_id) {
        $upd = $db->prepare("
            UPDATE nurture_contacts
               SET name        = CASE WHEN ? <> '' THEN ? ELSE name END,
                   company     = CASE WHEN ? <> '' THEN ? ELSE company END,
                   website     = CASE WHEN ? <> '' THEN ? ELSE website END,
                   token       = CASE WHEN ? <> '' THEN ? ELSE token END,
                   preview_url = CASE WHEN ? <> '' THEN ? ELSE preview_url END,
                   updated_at  = datetime('now')
             WHERE id = ?
        ");
        $n = (string)($data['name'] ?? '');
        $c = (string)($data['company'] ?? '');
        $w = (string)($data['website'] ?? '');
        $t = (string)($data['token'] ?? '');
        $p = (string)($data['preview_url'] ?? '');
        $upd->execute([$n,$n,$c,$c,$w,$w,$t,$t,$p,$p,$existing_id]);
        return $existing_id;
    }

    $next = gmdate('Y-m-d H:i:s', time() + 2 * 86400);
    $ins = $db->prepare("
        INSERT INTO nurture_contacts (name, email, company, website, token, preview_url, source, status, current_step, next_send_at, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'active', 0, ?, datetime('now'), datetime('now'))
    ");
    $ins->execute([
        (string)($data['name'] ?? ''),
        $email,
        (string)($data['company'] ?? ''),
        (string)($data['website'] ?? ''),
        (string)($data['token'] ?? ''),
        (string)($data['preview_url'] ?? ''),
        (string)($data['source'] ?? 'try'),
        $next,
    ]);
    return (int)$db->lastInsertId();
}

function ww_nurture_send_one(PDO $db, array $contact, string $brevo_key, string $hmac_secret, string $mailing_address): array {
    $step = (int)$contact['current_step'] + 1;
    $tpl = ww_nurture_template($step);
    $subject_merged = ww_nurture_apply_merge($tpl['subject'], $contact);
    $body_merged    = ww_nurture_apply_merge($tpl['body'], $contact);
    $unsub_url      = ww_nurture_unsub_url((int)$contact['id'], $hmac_secret);

    // Reserve the send_id BEFORE building HTML so we can stamp the tracking
    // pixel + click-tracked links with it.
    $ins = $db->prepare("INSERT INTO nurture_sends (contact_id, step, subject, brevo_message_id, status) VALUES (?, ?, ?, NULL, 'pending')");
    $ins->execute([(int)$contact['id'], $step, $subject_merged]);
    $send_id = (int)$db->lastInsertId();

    // Plain-text body (no tracking — many clients don't render images, so this
    // stays clean and clickable as-is).
    $text_with_unsub = $body_merged . "\n\n----\nUnsubscribe: " . $unsub_url . ($mailing_address !== '' ? "\n\n" . $mailing_address : '');

    // HTML body — render then post-process to (1) wrap every external link
    // (other than the unsubscribe) in /api/track.php click tracking, and (2)
    // append the 1x1 open pixel just before </body>.
    $body_html = ww_nurture_render_html($body_merged, $unsub_url, $mailing_address);
    $body_html = preg_replace_callback(
        '~<a\s+href="([^"]+)"~i',
        function ($m) use ($send_id, $hmac_secret, $unsub_url) {
            $href = $m[1];
            // Don't track the unsub link or mailto: links.
            if (strcasecmp($href, $unsub_url) === 0) return $m[0];
            if (stripos($href, 'mailto:') === 0)     return $m[0];
            // Also skip if already a track.php URL (defensive).
            if (stripos($href, '/api/track.php') !== false) return $m[0];
            $tracked = ww_nurture_track_link(html_entity_decode($href, ENT_QUOTES), $send_id, $hmac_secret);
            return '<a href="' . htmlspecialchars($tracked, ENT_QUOTES) . '"';
        },
        $body_html
    );
    $pixel_url = ww_nurture_open_pixel_url($send_id, $hmac_secret);
    $pixel_tag = '<img src="' . htmlspecialchars($pixel_url, ENT_QUOTES) . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;opacity:0;">';
    $body_html = preg_replace('~</body>~i', $pixel_tag . '</body>', $body_html, 1);

    $payload = [
        'sender'      => ['name' => NURTURE_SENDER_NAME, 'email' => NURTURE_SENDER_EMAIL],
        'to'          => [['email' => $contact['email'], 'name' => trim((string)$contact['name']) ?: $contact['email']]],
        'replyTo'     => ['email' => NURTURE_REPLY_TO, 'name' => NURTURE_REPLY_NAME],
        'subject'     => $subject_merged,
        'htmlContent' => $body_html,
        'textContent' => $text_with_unsub,
        'headers'     => [
            'List-Unsubscribe'      => '<' . $unsub_url . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ],
    ];
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['accept: application/json', 'content-type: application/json', 'api-key: ' . $brevo_key],
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http >= 300 || $resp === false) {
        $err = is_string($resp) ? substr($resp, 0, 400) : 'curl failure';
        $db->prepare("UPDATE nurture_sends SET status = ? WHERE id = ?")->execute(['failed:' . $http, $send_id]);
        return ['ok' => false, 'message_id' => null, 'send_id' => $send_id, 'error' => $err];
    }

    $data = json_decode((string)$resp, true);
    $msg_id = is_array($data) && isset($data['messageId']) ? (string)$data['messageId'] : null;

    $now = gmdate('Y-m-d H:i:s');
    $next = ww_nurture_compute_next_send((string)$contact['created_at'], $step, $now);
    $db->beginTransaction();
    try {
        $db->prepare("UPDATE nurture_sends SET brevo_message_id = ?, status = 'sent', sent_at = ? WHERE id = ?")
           ->execute([$msg_id, $now, $send_id]);
        $db->prepare("UPDATE nurture_contacts SET current_step = ?, last_sent_at = ?, next_send_at = ?, updated_at = datetime('now') WHERE id = ?")
           ->execute([$step, $now, $next, (int)$contact['id']]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        error_log('[nurture] persist after send failed: ' . $e->getMessage());
    }
    return ['ok' => true, 'message_id' => $msg_id, 'send_id' => $send_id, 'error' => null];
}

function ww_nurture_due_contacts(PDO $db, int $limit = 50): array {
    ww_nurture_init_schema($db);
    $sql = "SELECT id, name, email, company, website, token, preview_url, current_step, last_sent_at, created_at
              FROM nurture_contacts
             WHERE status = 'active'
               AND next_send_at IS NOT NULL
               AND next_send_at <= datetime('now')
               AND (pause_until IS NULL OR pause_until = '' OR pause_until <= datetime('now'))
             ORDER BY next_send_at ASC
             LIMIT ?";
    $st = $db->prepare($sql);
    $st->bindValue(1, $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function ww_nurture_set_status(PDO $db, int $contact_id, string $status, ?string $pause_until = null): bool {
    $allowed = ['active','paused','unsubscribed','purchased','not_interested','bounced'];
    if (!in_array($status, $allowed, true)) return false;
    if ($status === 'paused' && $pause_until) {
        $st = $db->prepare("UPDATE nurture_contacts SET status = 'paused', pause_until = ?, updated_at = datetime('now') WHERE id = ?");
        return (bool)$st->execute([$pause_until, $contact_id]);
    }
    $st = $db->prepare("UPDATE nurture_contacts SET status = ?, updated_at = datetime('now') WHERE id = ?");
    return (bool)$st->execute([$status, $contact_id]);
}

function ww_nurture_match_for_checkout(PDO $db, ?string $token, ?string $email): ?int {
    if ($token !== null && $token !== '') {
        $st = $db->prepare("SELECT id FROM nurture_contacts WHERE token = ? LIMIT 1");
        $st->execute([$token]);
        $id = (int)$st->fetchColumn();
        if ($id) return $id;
    }
    if ($email !== null && $email !== '') {
        $st = $db->prepare("SELECT id FROM nurture_contacts WHERE email = ? LIMIT 1");
        $st->execute([strtolower(trim($email))]);
        $id = (int)$st->fetchColumn();
        if ($id) return $id;
    }
    return null;
}
