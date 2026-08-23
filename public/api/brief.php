<?php
// /api/brief.php — "Have a human designer finish it" work order.
// POST { token, changes, notes, contact } -> saves the brief BEFORE payment so
// the designer has the work order (and nurture can reference it) even if the
// customer abandons checkout. GET ?t=<token> -> returns their edit history so
// the form can be pre-filled with what they already asked Wizzy for.
declare(strict_types=1);
header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once '/var/www/sites/trywebwiz/private/webwiz_lib.php';
$db = ww_db();

function br_schema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS design_briefs (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        token       TEXT NOT NULL,
        job_id      INTEGER,
        changes     TEXT,
        notes       TEXT,
        contact     TEXT,
        source      TEXT DEFAULT 'reveal',
        status      TEXT DEFAULT 'new',
        created_at  TEXT DEFAULT (datetime('now'))
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_briefs_token ON design_briefs(token)");
}
br_schema($db);

$token = '';
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $token = (string)($_GET['t'] ?? '');
    if (!preg_match('~^[a-f0-9]{24}$~', $token)) { echo json_encode(['ok'=>false,'error'=>'bad token']); exit; }
    // Everything they already asked Wizzy to change, oldest first, so the brief
    // opens pre-filled with their own words.
    $st = $db->prepare("SELECT message FROM edit_log WHERE token = ? AND message IS NOT NULL AND message <> '' ORDER BY id ASC");
    $st->execute([$token]);
    $msgs = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $m) {
        $m = trim((string)$m);
        // Skip the long decisive chip instructions - show the human-readable gist.
        if (mb_strlen($m) > 120) continue;
        if ($m !== '' && !in_array($m, $msgs, true)) $msgs[] = $m;
    }
    $biz = '';
    try {
        $j = $db->prepare("SELECT business_name FROM jobs WHERE token = ? LIMIT 1");
        $j->execute([$token]); $biz = (string)$j->fetchColumn();
    } catch (Throwable $e) {}
    echo json_encode(['ok'=>true,'requests'=>$msgs,'business'=>$biz]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST required']); exit; }

$raw  = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) $body = [];

$token   = trim((string)($body['token'] ?? ''));
if (!preg_match('~^[a-f0-9]{24}$~', $token)) { echo json_encode(['ok'=>false,'error'=>'bad token']); exit; }
$changes = trim(mb_substr((string)($body['changes'] ?? ''), 0, 4000));
$notes   = trim(mb_substr((string)($body['notes']   ?? ''), 0, 2000));
$contact = trim(mb_substr((string)($body['contact'] ?? ''), 0, 200));
$source  = trim(mb_substr((string)($body['source']  ?? 'reveal'), 0, 40));

$job_id = 0;
try { $j = $db->prepare("SELECT id FROM jobs WHERE token = ? LIMIT 1"); $j->execute([$token]); $job_id = (int)$j->fetchColumn(); } catch (Throwable $e) {}

$brief_id = 0;
try {
    $st = $db->prepare("INSERT INTO design_briefs (token, job_id, changes, notes, contact, source) VALUES (?,?,?,?,?,?)");
    $st->execute([$token, $job_id ?: null, $changes, $notes, $contact, $source]);
    $brief_id = (int)$db->lastInsertId();
} catch (Throwable $e) {
    error_log('[brief] save failed: ' . $e->getMessage());
    // Throttle 0: this is a visitor asking for a human designer. Every lost one matters.
    ww_report('brief', 'brief_save_failed', 'WebWiz design brief could not be saved',
        ['token' => $token], 'error', $e, 0);
    echo json_encode(['ok'=>false,'error'=>'Could not save that. Try again?']); exit;
}

// Funnel analytics
try {
    $db->prepare("INSERT INTO try_events (event, token, session_id, payload) VALUES ('brief_submitted', ?, NULL, ?)")
       ->execute([$token, json_encode(['brief_id'=>$brief_id,'len'=>mb_strlen($changes),'source'=>$source])]);
} catch (Throwable $e) {}

// Alert the team immediately - this is a hot lead who wants human help.
// The alert carries the contact details, the commercial state (did they see a
// price, did they reach Stripe) and the full engagement history, because the
// old four-line version could not tell an abandoned checkout from a cold click.
try {
    if (function_exists('ww_send_email')) {
        require_once '/var/www/sites/trywebwiz/private/lib/lead_alert.php';

        $snap  = ww_lead_snapshot($db, $token);
        $brief = ['changes' => $changes, 'notes' => $notes, 'contact' => $contact];

        // Did they write this, or did they submit the prefill of their old edits
        // unchanged? Same question the designer would ask first.
        $replayed = false;
        try {
            $pf = $db->prepare("SELECT message FROM edit_log WHERE token = ? AND message IS NOT NULL AND message <> '' ORDER BY id ASC");
            $pf->execute([$token]);
            $prior = [];
            foreach ($pf->fetchAll(PDO::FETCH_COLUMN) as $m) {
                $m = trim((string)$m);
                if ($m !== '' && mb_strlen($m) <= 120 && !in_array($m, $prior, true)) $prior[] = $m;
            }
            if ($prior) {
                $submitted = array_values(array_filter(array_map(
                    fn($l) => trim(ltrim(trim($l), "-* \t")), explode("\n", $changes)
                ), fn($l) => $l !== ''));
                $replayed = $submitted && !array_diff($submitted, $prior);
            }
        } catch (Throwable $e) { /* the flag is a nicety, never block the alert */ }

        $html    = ww_lead_alert_html($snap, $brief, $replayed);
        $subject = ww_lead_alert_subject($snap);
        $reply   = (string)($snap['job']['customer_email'] ?? '');

        // One send per recipient: a bad address for one teammate must not
        // silently swallow the other's copy.
        foreach (ww_alert_recipients() as $rcpt) {
            ww_send_email(['email' => $rcpt, 'name' => 'WebWiz Ops'], $subject, $html,
                          $reply !== '' ? $reply : null);
        }
    }
} catch (Throwable $e) {
    // The brief IS saved at this point, but nobody has been told about it. Silence here
    // means a hot lead sits in the table unread, which is indistinguishable from no lead.
    ww_report('brief', 'brief_notify_email_failed', 'WebWiz design brief saved but ops email failed',
        ['token' => $token, 'brief_id' => $brief_id], 'error', $e, 0);
}

echo json_encode(['ok'=>true,'brief_id'=>$brief_id]);
