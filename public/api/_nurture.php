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
 * Structured templates rendered via the shared bold-editorial chrome from
 * _email_templates.php. Each template defines an eyebrow (star marquee),
 * a two-part hero headline, body paragraphs, and a CTA. Merge tags
 * {{name}} / {{company}} / {{preview_url}} are applied at send time.
 *
 * No em dashes anywhere. Wizzy is he/him. No AI/speed/automation language.
 *
 * Step 1-5 = front-loaded sequence. Step 6+ = monthly recurring,
 * alternating A (even step) and B (odd step).
 */
function ww_nurture_template(int $step): array {
    if ($step === 1) return [
        'subject'         => 'The free website we made for {{company}}',
        'eyebrow'         => 'YOUR FREE WEBSITE IS STILL LIVE',
        'hero_before'     => "It's still",
        'hero_emphasized' => 'here.',
        'paragraphs'      => [
            "Hi <strong>{{name}}</strong>, this is the website we built for <strong>{{company}}</strong>, free of charge, and it&rsquo;s still live and waiting for you to take a look.",
            "Have it as long as you like at no cost. You only pay if you decide to keep it and put it on your own domain.",
        ],
        'cta_label'       => 'See your free website',
        'cta_url'         => '{{preview_url}}',
        'subtext'         => 'Have a look and tell me what you think. Just hit reply.',
    ];
    if ($step === 2) return [
        'subject'         => 'A note on the free site we made for you',
        'eyebrow'         => 'HOW WEBWIZ WORKS',
        'hero_before'     => "The honest",
        'hero_emphasized' => 'version.',
        'paragraphs'      => [
            "Hi <strong>{{name}}</strong>, I know a free website sounds too good to be true, so here&rsquo;s how this actually works.",
            "We&rsquo;re a small design studio. We built this site for <strong>{{company}}</strong> hoping you&rsquo;d love it. When you&rsquo;re ready, we host it, set up your domain, and handle the technical bits. You just say yes.",
        ],
        'cta_label'       => 'See your free {{company}} site',
        'cta_url'         => '{{preview_url}}',
        'subtext'         => 'Questions? Reply to this email. A real human reads every one.',
    ];
    if ($step === 3) return [
        'subject'         => 'Want to change anything on the free site we made?',
        'eyebrow'         => 'WIZZY CAN TWEAK ANYTHING',
        'hero_before'     => 'Make',
        'hero_emphasized' => 'it yours.',
        'paragraphs'      => [
            "Hi <strong>{{name}}</strong>, not quite right yet? That&rsquo;s the easy part.",
            "Tell our team what to change on the free site we made for <strong>{{company}}</strong>, whether it&rsquo;s different colors, your own photos, or new wording, and we&rsquo;ll make it match.",
        ],
        'cta_label'       => 'Open {{company}} and tweak it',
        'cta_url'         => '{{preview_url}}',
        'subtext'         => 'You can chat with Wizzy right on the preview page.',
    ];
    if ($step === 4) return [
        'subject'         => "We'll even handle the domain for your free site",
        'eyebrow'         => 'WE HANDLE THE TECH BITS',
        'hero_before'     => "We've",
        'hero_emphasized' => 'got you.',
        'paragraphs'      => [
            "Hi <strong>{{name}}</strong>, the thing people worry about most is the technical setup. Don&rsquo;t.",
            "Your free <strong>{{company}}</strong> site is ready to go live whenever you are. We point your domain, set up hosting and email, and include a domain on us. You never touch a single setting.",
        ],
        'cta_label'       => 'Your free site is still here',
        'cta_url'         => '{{preview_url}}',
        'subtext'         => 'Got a domain in mind? Just tell us when you reply.',
    ];
    if ($step === 5) return [
        'subject'         => "I'll stop crowding your inbox",
        'eyebrow'         => 'TAKING A STEP BACK',
        'hero_before'     => "No",
        'hero_emphasized' => 'pressure.',
        'paragraphs'      => [
            "Hi <strong>{{name}}</strong>, I don&rsquo;t want to be a pest, so I&rsquo;ll ease off and just check in now and then.",
            "Nothing changes on our end. The free site we built for <strong>{{company}}</strong> stays live and ready, and the offer stands whenever you are.",
        ],
        'cta_label'       => "It's right here when you want it",
        'cta_url'         => '{{preview_url}}',
        'subtext'         => 'See you next month.',
    ];
    // Step 6+ monthly recurring. Even step = variant A, odd step = variant B.
    $is_a = ($step % 2) === 0;
    if ($is_a) return [
        'subject'         => 'Still holding your free site, {{name}}',
        'eyebrow'         => 'YOUR FREE WEBSITE IS STILL READY',
        'hero_before'     => 'Quick',
        'hero_emphasized' => 'hello.',
        'paragraphs'      => [
            "Hi <strong>{{name}}</strong>, quick hello from Wizzy.",
            "The free website we made for <strong>{{company}}</strong> is still live and still yours for the taking. No rush. Just letting you know it hasn&rsquo;t gone anywhere.",
        ],
        'cta_label'       => 'See your free {{company}} site',
        'cta_url'         => '{{preview_url}}',
        'subtext'         => 'Reply anytime. I read every one.',
    ];
    return [
        'subject'         => 'Your free website is here whenever you are',
        'eyebrow'         => "KEEPING THE LIGHT ON",
        'hero_before'     => 'Ready',
        'hero_emphasized' => 'when you are.',
        'paragraphs'      => [
            "Hi <strong>{{name}}</strong>, just keeping the light on.",
            "The free site we built for <strong>{{company}}</strong> is ready when you are, and we&rsquo;re happy to change anything you like before it goes live on your domain.",
        ],
        'cta_label'       => 'Open your free site',
        'cta_url'         => '{{preview_url}}',
        'subtext'         => 'No rush. Reply with anything you want changed.',
    ];
}

/** Build the showcase image URL if a screenshot exists for the contact's token. */
function ww_nurture_showcase_url(string $token): ?string {
    if ($token === '') return null;
    $token = preg_replace('~[^a-zA-Z0-9_-]~', '', $token);
    if ($token === '') return null;
    $path = '/var/www/sites/trywebwiz/public/preview/' . $token . '/showcase.jpg';
    if (is_file($path) && filesize($path) > 1000) {
        return 'https://trywebwiz.com/preview/' . $token . '/showcase.jpg';
    }
    return null;
}

/**
 * Image card block: clickable screenshot of the generated site with a
 * "Made for {company} for free" yellow badge below. Renders the same brand
 * chrome (navy border + yellow shadow + cream backing) as the order-summary card.
 */
function ww_email_image_card(string $img_url, string $href, string $company): string {
    $img_esc  = htmlspecialchars($img_url,  ENT_QUOTES);
    $href_esc = htmlspecialchars($href,     ENT_QUOTES);
    $biz_esc  = htmlspecialchars($company !== '' ? $company : 'you');
    return '<tr><td style="padding:22px 36px 4px;">'
         . '<a href="' . $href_esc . '" style="text-decoration:none;display:block;">'
         . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:3px solid #12184A;border-radius:18px;background:#F8EFD3;box-shadow:6px 6px 0 #F7C84A;">'
         . '<tr><td style="padding:10px;">'
         . '<img src="' . $img_esc . '" alt="The website Wizzy built for ' . $biz_esc . '" width="100%" style="display:block;width:100%;height:auto;border-radius:10px;border:2px solid #12184A;">'
         . '</td></tr>'
         . '<tr><td style="padding:6px 14px 12px;font-family:\'Nunito\',sans-serif;font-weight:900;font-size:11px;letter-spacing:0.22em;text-transform:uppercase;color:#12184A;text-align:center;">'
         . '&#9733;&nbsp; Built for ' . $biz_esc . ' &nbsp;&middot;&nbsp; Free to keep &nbsp;&#9733;'
         . '</td></tr>'
         . '</table>'
         . '</a>'
         . '</td></tr>';
}

/** Read the /try loading-screen Q&A answers for a contact's preview token. */
function ww_nurture_qa_answers(string $token): array {
    $token = preg_replace('~[^a-f0-9]~', '', strtolower($token));
    if ($token === '') return [];
    $f = '/var/www/sites/trywebwiz/public/preview/' . $token . '/qa.json';
    if (!is_file($f)) return [];
    $j = json_decode((string)@file_get_contents($f), true);
    if (!is_array($j) || empty($j['answers']) || !is_array($j['answers'])) return [];
    return $j['answers'];
}

/** Warm personalized paragraph from the visitor's Q&A answers. '' if none. */
function ww_nurture_qa_para(array $contact): string {
    $ans = ww_nurture_qa_answers((string)($contact['token'] ?? ''));
    if (!$ans) return '';
    $goal = '';
    foreach ($ans as $a) {
        // Handle both shapes: new array of {q,a}, and the legacy {key:answer} object.
        $v = is_array($a) ? trim((string)($a['a'] ?? '')) : trim((string)$a);
        if ($v !== '') { $goal = $v; break; }
    }
    if ($goal === '') return '';
    $goal = htmlspecialchars($goal, ENT_QUOTES);
    return "When you built this site, you told Wizzy the main job for it is to <strong>" . $goal . "</strong>. We kept that front and center, and it&rsquo;s all still here waiting for you.";
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

/**
 * Render a structured nurture template into the bold-editorial brand shell.
 * Uses the same chrome helpers as the transactional webhook emails so every
 * touch from WebWiz looks like the same brand.
 */
function ww_nurture_render_html(array $tpl, array $contact, string $unsub_url, string $mailing_address): string {
    require_once __DIR__ . '/_email_templates.php';

    $eyebrow   = ww_nurture_apply_merge($tpl['eyebrow'] ?? 'A NOTE FROM WIZZY', $contact);
    $h_before  = ww_nurture_apply_merge($tpl['hero_before'] ?? '', $contact);
    $h_emph    = ww_nurture_apply_merge($tpl['hero_emphasized'] ?? '', $contact);
    $h_after   = ww_nurture_apply_merge($tpl['hero_after'] ?? '', $contact);
    $cta_label = ww_nurture_apply_merge($tpl['cta_label'] ?? 'See your website', $contact);
    $cta_url   = ww_nurture_apply_merge($tpl['cta_url']   ?? NURTURE_DOMAIN . '/try/', $contact);
    $subtext   = ww_nurture_apply_merge($tpl['subtext']   ?? '', $contact);

    $body = ww_email_hero($h_before, $h_emph, $h_after);

    // Insert a clickable screenshot of the generated site directly under the
    // hero so the recipient can recognize their own preview at a glance.
    $token = (string)($contact['token'] ?? '');
    $showcase = ww_nurture_showcase_url($token);
    if ($showcase !== null && $cta_url !== '') {
        $body .= ww_email_image_card($showcase, $cta_url, (string)($contact['company'] ?? ''));
    }

    foreach (($tpl['paragraphs'] ?? []) as $i => $p) {
        $merged = ww_nurture_apply_merge($p, $contact);
        $body  .= ww_email_para($merged, $i === 0 ? 14 : 16);
        // After the greeting, on the early touches, weave in what they told Wizzy.
        if ($i === 0 && ((int)($contact['current_step'] ?? 0) + 1) <= 3) {
            $qa_para = ww_nurture_qa_para($contact);
            if ($qa_para !== '') $body .= ww_email_para($qa_para, 16);
        }
    }
    if ($cta_label !== '' && $cta_url !== '') {
        $body .= ww_email_cta($cta_label, $cta_url);
    }
    if ($subtext !== '') {
        $body .= ww_email_subtext($subtext);
    }

    // Unsubscribe + mailing address row, inside the card, above the brand footer
    $unsub_esc = htmlspecialchars($unsub_url, ENT_QUOTES);
    $addr_html = $mailing_address !== ''
        ? '<div style="font-size:11px;color:#12184A;opacity:0.55;line-height:1.5;margin-top:6px;">' . nl2br(htmlspecialchars($mailing_address, ENT_QUOTES)) . '</div>'
        : '';
    $body .= '<tr><td style="padding:6px 36px 18px;text-align:center;border-top:1px solid rgba(18,24,74,0.08);">'
           . '<div style="font-family:Inter,sans-serif;font-size:11px;color:#12184A;opacity:0.7;line-height:1.5;padding-top:14px;">'
           . '<a href="' . $unsub_esc . '" style="color:#12184A;opacity:0.85;text-decoration:underline;">Unsubscribe from these emails</a>'
           . '</div>'
           . $addr_html
           . '</td></tr>';

    $title = trim($h_before . ' ' . $h_emph . ' ' . $h_after) ?: 'A note from Wizzy';
    return ww_email_shell($eyebrow, $title, $body);
}

/** Plain-text fallback derived from the same structured template. */
function ww_nurture_render_text(array $tpl, array $contact, string $unsub_url, string $mailing_address): string {
    $out = '';
    foreach (($tpl['paragraphs'] ?? []) as $p) {
        $merged = ww_nurture_apply_merge($p, $contact);
        // Strip HTML tags for the text version
        $out .= trim(html_entity_decode(strip_tags($merged), ENT_QUOTES)) . "\n\n";
    }
    if (((int)($contact['current_step'] ?? 0) + 1) <= 3) {
        $qa_para = ww_nurture_qa_para($contact);
        if ($qa_para !== '') $out .= trim(html_entity_decode(strip_tags($qa_para), ENT_QUOTES)) . "\n\n";
    }
    $cta_url   = ww_nurture_apply_merge($tpl['cta_url']   ?? NURTURE_DOMAIN . '/try/', $contact);
    $cta_label = ww_nurture_apply_merge($tpl['cta_label'] ?? 'See your website', $contact);
    if ($cta_url !== '') {
        $out .= $cta_label . ":\n" . $cta_url . "\n\n";
    }
    $subtext = ww_nurture_apply_merge($tpl['subtext'] ?? '', $contact);
    if ($subtext !== '') $out .= trim(html_entity_decode(strip_tags($subtext), ENT_QUOTES)) . "\n\n";
    $out .= "Wizzy from WebWiz\n\n----\nUnsubscribe: " . $unsub_url;
    if ($mailing_address !== '') $out .= "\n\n" . $mailing_address;
    return $out;
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
    $unsub_url      = ww_nurture_unsub_url((int)$contact['id'], $hmac_secret);

    // Reserve the send_id BEFORE building HTML so we can stamp the tracking
    // pixel + click-tracked links with it.
    $ins = $db->prepare("INSERT INTO nurture_sends (contact_id, step, subject, brevo_message_id, status) VALUES (?, ?, ?, NULL, 'pending')");
    $ins->execute([(int)$contact['id'], $step, $subject_merged]);
    $send_id = (int)$db->lastInsertId();

    // Plain-text body for non-HTML clients
    $text_with_unsub = ww_nurture_render_text($tpl, $contact, $unsub_url, $mailing_address);

    // HTML body — render via the bold-editorial brand shell, then
    // (1) wrap every external link (other than the unsubscribe) in
    // /api/track.php click tracking and (2) append the 1x1 open pixel
    // just before </body>.
    $body_html = ww_nurture_render_html($tpl, $contact, $unsub_url, $mailing_address);
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
