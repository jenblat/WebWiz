<?php
/**
 * lead_alert.php - the ops alert for a human-wanted lead.
 *
 * Why this exists: the original brief email carried four facts (token, contact
 * string, changes, notes) and none of them told you who the person was or
 * whether they had already tried to pay. Donovan Hyatt (token
 * 12d85a09094ed9941c6e7fb3, 2026-08-17) reached a LIVE $500 Stripe checkout and
 * bounced off it 11 seconds later, and the email that landed said only "saved
 * before payment, so follow up even if they do not check out". A person who hit
 * the pay screen and a person who never clicked looked identical in the inbox.
 *
 * So the alert now answers, in this order:
 *   1. Who do I email, right now, and at what address.
 *   2. Did they see a price, which price, and did they pay.
 *   3. What have they actually done since we built their site.
 *   4. What did they ask for, and is that ask new or a replay of old edits.
 *
 * Deliberately NOT in webwiz_lib.php: that file is mode 640 and every endpoint
 * requires it, so a bad edit there is a full site outage. This is a leaf file.
 *
 * All rendering is HTML-escaped at the point of output. The brief text is
 * visitor-supplied and lands in an inbox, so it is never trusted.
 */
declare(strict_types=1);

/**
 * Who gets ops alerts. NOTIFY_EMAILS (comma separated) wins, then the legacy
 * single NOTIFY_EMAIL, then a hardcoded floor so an alert can never go nowhere.
 * Adding a teammate is a secrets change, not a code change.
 */
function ww_alert_recipients(): array {
    $s = function_exists('ww_secrets') ? ww_secrets() : [];
    $raw = trim((string)($s['NOTIFY_EMAILS'] ?? ''));
    if ($raw === '') $raw = trim((string)($s['NOTIFY_EMAIL'] ?? ''));
    if ($raw === '') $raw = 'ultimax97@gmail.com';

    $out = [];
    foreach (preg_split('~[,;\s]+~', $raw) as $e) {
        $e = trim($e);
        if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) $out[strtolower($e)] = $e;
    }
    return array_values($out) ?: ['ultimax97@gmail.com'];
}

/** What this token was actually quoted, so the follow-up does not misquote it. */
function ww_lead_price_shown(?string $variant): string {
    switch ((string)$variant) {
        case 'a': return '$100 to build, then $50/month';
        case 'b':
        case 'u':
        case 'c': return 'Free build, then $50/month';
        case 't': return '$1.00 + $1.00/month (internal test cell)';
        default:  return '$500 to build, then $50/month after a 30 day trial (legacy funnel)';
    }
}

/**
 * Everything known about one token, from every table that touches the funnel.
 * Never throws: a missing table or column degrades that section, not the alert.
 */
function ww_lead_snapshot(PDO $db, string $token): array {
    $q = function (string $sql, array $args = [], bool $one = false) use ($db) {
        try {
            $st = $db->prepare($sql);
            $st->execute($args);
            return $one ? ($st->fetch(PDO::FETCH_ASSOC) ?: null) : $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return $one ? null : []; }
    };

    $snap = ['token' => $token, 'timeline' => [], 'stats' => []];

    $job = $q("SELECT id, business_name, customer_email, status, created_at, offer_variant,
                      edit_count, prospect_id
               FROM jobs WHERE token = ? LIMIT 1", [$token], true);
    $snap['job'] = $job;

    $snap['prospect'] = ($job && !empty($job['prospect_id']))
        ? $q("SELECT name, email, business_name, current_url FROM prospects WHERE id = ? LIMIT 1",
             [(int)$job['prospect_id']], true)
        : null;

    $snap['contact'] = $q("SELECT id, name, email, company, website, status, current_step,
                                  last_sent_at, next_send_at
                           FROM nurture_contacts WHERE token = ? LIMIT 1", [$token], true);

    $snap['lead'] = $q("SELECT status, stripe_session_id, created_at FROM offer_leads
                        WHERE token = ? ORDER BY id DESC LIMIT 1", [$token], true);

    // ---- Timeline -------------------------------------------------------
    // One chronological list, because "viewed 7 times over 5 weeks" is the
    // signal, and it is invisible when each table is read separately.
    $tl = [];

    foreach ($q("SELECT event, payload, created_at FROM try_events WHERE token = ? ORDER BY id", [$token]) as $e) {
        $p = json_decode((string)($e['payload'] ?? ''), true);
        $detail = '';
        switch ($e['event']) {
            case 'gen_completed':
                $detail = isset($p['duration_ms']) ? 'built in ' . round(((int)$p['duration_ms']) / 1000) . 's' : '';
                break;
            case 'reveal_viewed':
                $detail = !empty($p['recovered']) ? 'returning visit' : 'first look';
                break;
            case 'edit_used':
                $detail = isset($p['message']) ? '"' . (string)$p['message'] . '"' : '';
                break;
            case 'checkout_started':
                $detail = 'sent to Stripe';
                break;
            case 'brief_submitted':
                $detail = 'asked for a human';
                break;
        }
        $tl[] = ['at' => (string)$e['created_at'], 'what' => (string)$e['event'], 'detail' => $detail, 'kind' => 'site'];
    }

    foreach ($q("SELECT message, status, created_at FROM edit_log WHERE token = ? ORDER BY id", [$token]) as $e) {
        if (($e['status'] ?? '') === 'ok') continue; // the successful ones already show as edit_used
        $tl[] = ['at' => (string)$e['created_at'], 'what' => 'edit_failed',
                 'detail' => '"' . (string)$e['message'] . '" did not apply', 'kind' => 'problem'];
    }

    if (!empty($snap['contact']['id'])) {
        $cid = (int)$snap['contact']['id'];
        $sends = $q("SELECT step, subject, status, sent_at, open_count, click_count, last_clicked_at
                     FROM nurture_sends WHERE contact_id = ? ORDER BY id", [$cid]);
        $snap['sends'] = $sends;
        foreach ($sends as $s) {
            $plural = fn(int $n, string $w) => $n . ' ' . $w . ($n === 1 ? '' : 's');
            $bits = [];
            if ((int)$s['open_count']  > 0) $bits[] = $plural((int)$s['open_count'], 'open');
            if ((int)$s['click_count'] > 0) $bits[] = $plural((int)$s['click_count'], 'click');
            $tl[] = ['at' => (string)$s['sent_at'], 'what' => 'email_sent',
                     'detail' => 'step ' . (int)$s['step'] . ', "' . (string)$s['subject'] . '"'
                                 . ($bits ? ' (' . implode(', ', $bits) . ')' : ' (no opens)'),
                     'kind' => 'email'];
        }
        foreach ($q("SELECT type, target, occurred_at FROM nurture_events
                     WHERE contact_id = ? AND type = 'click' ORDER BY id", [$cid]) as $ev) {
            $tl[] = ['at' => (string)$ev['occurred_at'], 'what' => 'email_click',
                     'detail' => 'clicked through to their preview', 'kind' => 'hot'];
        }
    }

    usort($tl, fn($a, $b) => strcmp($a['at'], $b['at']));
    $snap['timeline'] = $tl;

    // ---- Headline numbers ----------------------------------------------
    $views = $clicks = $edits = 0;
    $ck_at = $first = $last = '';
    foreach ($tl as $r) {
        if ($r['what'] === 'reveal_viewed')   { $views++;  $last = $r['at']; if ($first === '') $first = $r['at']; }
        if ($r['what'] === 'email_click')     { $clicks++; }
        if ($r['what'] === 'edit_used')       { $edits++;  }
        if ($r['what'] === 'checkout_started'){ $ck_at = $r['at']; }
    }
    $paid = (($snap['lead']['status'] ?? '') === 'purchased');

    $snap['stats'] = [
        'views'          => $views,
        'first_view'     => $first,
        'last_view'      => $last,
        'email_clicks'   => $clicks,
        'edits'          => $edits,
        'checkout_at'    => $ck_at,
        'paid'           => $paid,
        'price_shown'    => ww_lead_price_shown($snap['job']['offer_variant'] ?? null),
        'sequence_done'  => (int)($snap['contact']['current_step'] ?? 0) >= 5,
    ];

    return $snap;
}

/** The one line that decides whether this email gets opened. */
function ww_lead_alert_subject(array $snap): string {
    $biz = (string)($snap['job']['business_name'] ?? 'Unknown business');
    $st  = $snap['stats'];
    if (!empty($st['paid']))       return 'WebWiz: ' . $biz . ' paid and wants changes';
    if (!empty($st['checkout_at'])) return 'WebWiz: ' . $biz . ' reached checkout and did not pay';
    return 'WebWiz: ' . $biz . ' wants a human';
}

/**
 * The alert body. $brief is the design_briefs row; $replayed says whether the
 * change list is the visitor's own new words or the prefill of their old edits,
 * because acting on a five week old list as if it were fresh wastes the call.
 */
function ww_lead_alert_html(array $snap, array $brief, bool $replayed = false): string {
    $h  = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $st = $snap['stats'];

    $biz   = (string)($snap['job']['business_name'] ?? ($snap['contact']['company'] ?? 'Unknown business'));
    $name  = (string)($snap['prospect']['name'] ?? ($snap['contact']['name'] ?? ''));
    $email = (string)($snap['job']['customer_email'] ?? ($snap['contact']['email'] ?? ($snap['prospect']['email'] ?? '')));
    $site  = (string)($snap['prospect']['current_url'] ?? ($snap['contact']['website'] ?? ''));
    $token = (string)$snap['token'];
    $prev  = 'https://trywebwiz.com/try/?t=' . $token;

    // Headline state. This is the whole point of the redesign.
    if (!empty($st['paid'])) {
        $band = ['#0b6b3a', 'They have paid. This is a customer asking for changes.'];
    } elseif (!empty($st['checkout_at'])) {
        $band = ['#a1140f', 'They reached the payment screen on ' . $st['checkout_at'] . ' and did not complete it.'];
    } else {
        $band = ['#12184A', 'They asked for a human. No payment attempt yet.'];
    }

    $row = fn($k, $v) => '<tr><td style="padding:6px 14px 6px 0;color:#666;font-size:13px;white-space:nowrap;vertical-align:top">'
        . $k . '</td><td style="padding:6px 0;font-size:14px;color:#111">' . $v . '</td></tr>';

    $out  = '<div style="font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;max-width:680px;margin:0 auto">';
    $out .= '<div style="background:' . $band[0] . ';color:#fff;padding:16px 20px;border-radius:8px 8px 0 0">'
          . '<div style="font-size:19px;font-weight:700">' . $h($biz) . '</div>'
          . '<div style="font-size:14px;opacity:.92;margin-top:4px">' . $h($band[1]) . '</div></div>';
    $out .= '<div style="border:1px solid #e4e4e8;border-top:0;border-radius:0 0 8px 8px;padding:20px">';

    // ---- Who to contact -------------------------------------------------
    $out .= '<h3 style="margin:0 0 8px;font-size:15px;color:#12184A">Reach out to</h3><table style="width:100%;border-collapse:collapse">';
    if ($name)  $out .= $row('Name', $h($name));
    if ($email) $out .= $row('Email', '<a href="mailto:' . $h($email) . '" style="color:#12184A;font-weight:700">' . $h($email) . '</a>');
    if ($site)  $out .= $row('Their site', '<a href="' . $h($site) . '" style="color:#12184A">' . $h($site) . '</a>');
    $out .= $row('Preview we built', '<a href="' . $h($prev) . '" style="color:#12184A">' . $h($prev) . '</a>');
    if (!empty($brief['contact'])) $out .= $row('They also left', $h((string)$brief['contact']));
    $out .= '</table>';

    // ---- Commercial state ----------------------------------------------
    $out .= '<h3 style="margin:20px 0 8px;font-size:15px;color:#12184A">Where they are</h3><table style="width:100%;border-collapse:collapse">';
    $out .= $row('Price they were shown', $h((string)$st['price_shown']));
    $out .= $row('Checkout', !empty($st['paid']) ? 'Paid'
        : (!empty($st['checkout_at']) ? '<b style="color:#a1140f">Started ' . $h((string)$st['checkout_at']) . ', abandoned</b>' : 'Never started'));
    $out .= $row('Site built', $h((string)($snap['job']['created_at'] ?? 'unknown')));
    $out .= $row('Times they came back', (int)$st['views'] . ' views'
        . ($st['first_view'] ? ', ' . $h((string)$st['first_view']) . ' to ' . $h((string)$st['last_view']) : ''));
    $out .= $row('Edits they made', (int)$st['edits']);
    $out .= $row('Email clicks', (int)$st['email_clicks']);
    if (!empty($snap['contact'])) {
        $out .= $row('Nurture', 'step ' . (int)$snap['contact']['current_step'] . ' of 5, status ' . $h((string)$snap['contact']['status'])
            . (!empty($st['sequence_done']) ? '<br><b style="color:#a1140f">Sequence finished. Nothing else will reach them automatically.</b>' : ''));
    }
    $out .= '</table>';

    // ---- The ask --------------------------------------------------------
    $out .= '<h3 style="margin:20px 0 8px;font-size:15px;color:#12184A">What they want changed</h3>';
    if ($replayed) {
        $out .= '<p style="margin:0 0 8px;padding:8px 12px;background:#fff6e5;border-left:3px solid #d98c00;font-size:13px;color:#5a4200">'
              . 'Heads up: this list is their earlier edit history, prefilled by the form and submitted unchanged. '
              . 'It is not necessarily a fresh request, so check the preview before promising work.</p>';
    }
    $out .= '<div style="font-size:14px;line-height:1.6;color:#111">' . nl2br($h((string)($brief['changes'] ?? ''))) . '</div>';
    if (!empty($brief['notes'])) {
        $out .= '<h3 style="margin:16px 0 6px;font-size:15px;color:#12184A">Anything else</h3>'
              . '<div style="font-size:14px;line-height:1.6;color:#111">' . nl2br($h((string)$brief['notes'])) . '</div>';
    }

    // ---- History --------------------------------------------------------
    $out .= '<h3 style="margin:20px 0 8px;font-size:15px;color:#12184A">Full engagement history</h3>';
    $out .= '<table style="width:100%;border-collapse:collapse;font-size:12.5px">';
    $tint = ['hot' => '#0b6b3a', 'problem' => '#a1140f', 'email' => '#666', 'site' => '#111'];
    foreach ($snap['timeline'] as $r) {
        $c = $tint[$r['kind']] ?? '#111';
        $out .= '<tr style="border-top:1px solid #f0f0f2">'
             .  '<td style="padding:5px 10px 5px 0;color:#888;white-space:nowrap;vertical-align:top">' . $h($r['at']) . '</td>'
             .  '<td style="padding:5px 10px 5px 0;color:' . $c . ';font-weight:700;white-space:nowrap;vertical-align:top">' . $h($r['what']) . '</td>'
             .  '<td style="padding:5px 0;color:#444">' . $h($r['detail']) . '</td></tr>';
    }
    $out .= '</table>';

    // ---- The nudge ------------------------------------------------------
    if (empty($st['paid']) && $email !== '') {
        $subj = rawurlencode('Your ' . $biz . ' website');
        $out .= '<div style="margin-top:22px;padding:14px 16px;background:#f4f7ff;border-radius:6px">'
              . '<div style="font-size:13px;color:#12184A;font-weight:700;margin-bottom:6px">Suggested next step</div>'
              . '<div style="font-size:13.5px;color:#333;line-height:1.6">'
              . (!empty($st['checkout_at'])
                    ? 'They wanted it enough to open the payment page. Ask what stopped them, and say plainly what it costs to go live today.'
                    : 'They asked for a human. Reply personally, confirm the changes, and give them one clear way to pay.')
              . '</div><div style="margin-top:10px">'
              . '<a href="mailto:' . $h($email) . '?subject=' . $subj . '" '
              . 'style="display:inline-block;background:#12184A;color:#fff;text-decoration:none;padding:9px 18px;border-radius:5px;font-size:13.5px;font-weight:700">'
              . 'Email ' . $h($name !== '' ? $name : $email) . '</a></div></div>';
    }

    $out .= '<p style="color:#888;font-size:11.5px;margin:18px 0 0">'
          . 'Brief saved before payment, so this person is reachable whether or not they check out. '
          . 'Token ' . $h($token) . '.</p>';
    $out .= '</div></div>';
    return $out;
}
