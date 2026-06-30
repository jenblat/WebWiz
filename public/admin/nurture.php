<?php
// /admin/nurture.php — Nurture admin: Overview, Contacts, Templates, Activity, Contact detail.
// Brand-matched to the rest of /admin: same topbar nav, CSS variables, card chrome.
declare(strict_types=1);
require '/var/www/sites/trywebwiz/private/webwiz_lib.php';
require '/var/www/sites/trywebwiz/public/api/_nurture.php';

require_once '/var/www/sites/trywebwiz/public/api/_session.php'; ww_session_start();

$me = !empty($_SESSION['uid']) ? ww_user_by_id((int)$_SESSION['uid']) : null;
$logged_in = (bool)$me;
$is_admin  = $logged_in && ($me['role'] ?? '') === 'admin';
if (!$is_admin) { header('Location: /admin/'); exit; }

$db = ww_db();
ww_nurture_init_schema($db);

// --- Action handling ---
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $cid = (int)($_POST['cid'] ?? 0);
    $act = (string)$_POST['action'];
    if ($cid > 0) {
        if ($act === 'pause') {
            $until = trim((string)($_POST['pause_until'] ?? ''));
            if ($until !== '' && preg_match('~^\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2}(:\d{2})?)?$~', $until)) {
                if (strlen($until) === 10) $until .= ' 09:00:00';
                ww_nurture_set_status($db, $cid, 'paused', $until);
                $flash = "Paused this contact. Sends resume after $until.";
            } else $flash = 'Pick a date to pause until.';
        }
        elseif ($act === 'not_interested') { ww_nurture_set_status($db, $cid, 'not_interested'); $flash = 'Marked not interested. No more sends.'; }
        elseif ($act === 'unsubscribe')     { ww_nurture_set_status($db, $cid, 'unsubscribed');   $flash = 'Unsubscribed. No more sends ever.'; }
        elseif ($act === 'reactivate')      {
            $db->prepare("UPDATE nurture_contacts SET status = 'active', pause_until = NULL, next_send_at = datetime('now'), updated_at = datetime('now') WHERE id = ?")->execute([$cid]);
            $flash = 'Reactivated. Picks up at next cron tick.';
        }
        elseif ($act === 'purchased') { ww_nurture_set_status($db, $cid, 'purchased'); $flash = 'Marked purchased.'; }
    }
    $back = $_SERVER['HTTP_REFERER'] ?? '/admin/nurture.php';
    if (strpos($back, '://') !== false && !str_starts_with($back, 'https://trywebwiz.com')) $back = '/admin/nurture.php';
    $sep = strpos($back, '?') === false ? '?' : '&';
    header('Location: ' . $back . $sep . 'flashed=' . urlencode($flash));
    exit;
}
$flash = (string)($_GET['flashed'] ?? '');

$view = (string)($_GET['view'] ?? 'overview');
$valid_views = ['overview','contacts','templates','activity','contact'];
if (!in_array($view, $valid_views, true)) $view = 'overview';

// ----- helpers -----
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
/** Compact relative time. Single short string. */
function fmt_rel($ts): string {
    if (!$ts) return '<span style="opacity:0.4;">&mdash;</span>';
    $t = strtotime((string)$ts . ' UTC'); if (!$t) return h($ts);
    $diff = time() - $t;
    if ($diff >= 0) {
        if ($diff < 60)        return 'just now';
        if ($diff < 3600)      { $n = (int)floor($diff/60);    return $n . ' min ago'; }
        if ($diff < 86400)     { $n = (int)floor($diff/3600);  return $n . ' hr ago'; }
        if ($diff < 86400*30)  { $n = (int)floor($diff/86400); return $n . ($n === 1 ? ' day ago' : ' days ago'); }
        return gmdate('M j', $t);
    }
    $d = -$diff;
    if ($d < 60)    return 'in moments';
    if ($d < 3600)  { $n = (int)floor($d/60);    return 'in ' . $n . ' min'; }
    if ($d < 86400) { $n = (int)floor($d/3600);  return 'in ' . $n . ' hr'; }
    $n = (int)floor($d/86400);
    return 'in ' . $n . ($n === 1 ? ' day' : ' days');
}
/** "in 2 days · Jul 1" — for future scheduled times. */
function fmt_next($ts): string {
    if (!$ts) return '<span style="opacity:0.4;">&mdash;</span>';
    $t = strtotime((string)$ts . ' UTC'); if (!$t) return h($ts);
    $rel = fmt_rel($ts);
    if (strip_tags($rel) === '—') return $rel;
    return h($rel) . ' <span style="opacity:0.55;font-size:11px;">&middot; ' . h(gmdate('M j', $t)) . '</span>';
}
/** Absolute date only, e.g. "Jun 29, 8:27 PM UTC". For tooltips / contact detail. */
function fmt_abs($ts): string {
    if (!$ts) return '<span style="opacity:0.4;">&mdash;</span>';
    $t = strtotime((string)$ts . ' UTC'); if (!$t) return h($ts);
    return h(gmdate('M j, g:i A', $t)) . ' UTC';
}
/** Backwards-compat shim — earlier code paths called fmt_dt. */
function fmt_dt($ts, bool $with_rel = true): string {
    return $with_rel ? fmt_rel($ts) : fmt_abs($ts);
}
function status_pill(string $s): string {
    $map = [
        'active'         => ['ok',    'active'],
        'paused'         => ['warn',  'paused'],
        'unsubscribed'   => ['err',   'unsubscribed'],
        'not_interested' => ['muted', 'not interested'],
        'purchased'      => ['admin', 'purchased'],
        'bounced'        => ['err',   'bounced'],
    ];
    $cls = $map[$s] ?? ['muted', $s];
    return '<span class="pill ' . $cls[0] . '">' . htmlspecialchars($cls[1]) . '</span>';
}

$counts = [];
foreach (['active','paused','unsubscribed','purchased','not_interested','bounced'] as $s) {
    $st = $db->prepare("SELECT COUNT(*) FROM nurture_contacts WHERE status = ?");
    $st->execute([$s]);
    $counts[$s] = (int)$st->fetchColumn();
}
$counts['all'] = array_sum($counts);

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Nurture &middot; WebWiz admin</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<style>
  /* Copied from /admin/index.php shell so this page matches exactly. */
  :root{--cream:#FFF8E7;--paper:#F8EFD3;--yellow:#F7C84A;--teal:#3FCFA8;--navy:#12184A;--display:Nunito,system-ui,sans-serif;--body:Inter,system-ui,sans-serif;}
  *{box-sizing:border-box;margin:0;padding:0;}
  body{font-family:var(--body),system-ui,sans-serif;background:var(--cream);color:var(--navy);font-size:15px;line-height:1.5;}
  a{color:var(--navy);}
  header.topbar{background:var(--navy);color:var(--cream);padding:12px 24px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;}
  header.topbar .brand{display:flex;align-items:center;gap:10px;font-family:var(--display);font-weight:900;font-size:20px;letter-spacing:-0.02em;}
  header.topbar .brand img{height:32px;width:auto;}
  header.topbar .brand .dot{color:var(--yellow);}
  header.topbar .brand small{font-family:ui-monospace,monospace;font-size:10px;letter-spacing:0.18em;text-transform:uppercase;color:var(--cream);opacity:0.65;margin-left:8px;}
  header.topbar nav a{color:var(--cream);text-decoration:none;font-family:var(--display);font-weight:700;font-size:13px;margin-left:14px;opacity:0.78;letter-spacing:0.02em;}
  header.topbar nav a.on{color:var(--yellow);opacity:1;}
  header.topbar nav a:hover{opacity:1;}
  header.topbar .me{font-size:12px;font-family:ui-monospace,monospace;letter-spacing:0.06em;color:var(--cream);opacity:0.6;}
  main{max-width:1180px;margin:0 auto;padding:32px 24px 96px;}
  h1{font-family:var(--display);font-weight:900;font-size:34px;letter-spacing:-0.03em;margin-bottom:6px;}
  h2{font-family:var(--display);font-weight:900;font-size:20px;letter-spacing:-0.02em;margin:32px 0 14px;}
  p.sub{opacity:0.65;font-size:14px;margin-bottom:22px;}
  .pill{display:inline-block;font-family:var(--display);font-weight:700;font-size:11px;letter-spacing:0.1em;text-transform:uppercase;padding:3px 10px;border-radius:999px;border:2px solid var(--navy);}
  .pill.ok{background:var(--teal);color:var(--navy);}
  .pill.warn{background:#ffd29c;color:var(--navy);}
  .pill.err{background:#ffb3b3;color:var(--navy);}
  .pill.muted{background:#fff;color:var(--navy);opacity:0.7;}
  .pill.admin{background:var(--yellow);color:var(--navy);}
  .stat-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:14px;}
  @media(max-width:900px){.stat-grid{grid-template-columns:repeat(2,1fr);}}
  .stat{background:#fff;border:3px solid var(--navy);border-radius:16px;padding:18px;box-shadow:5px 5px 0 var(--yellow);}
  .stat .lbl{font-family:ui-monospace,monospace;font-size:11px;letter-spacing:0.16em;text-transform:uppercase;color:var(--navy);opacity:0.65;}
  .stat .val{font-family:var(--display);font-weight:900;font-size:32px;letter-spacing:-0.03em;color:var(--navy);margin-top:4px;line-height:1;}
  .stat .sub{font-size:12px;color:var(--navy);opacity:0.55;margin-top:6px;}
  table.t{width:100%;border-collapse:collapse;background:#fff;border:3px solid var(--navy);border-radius:14px;overflow:hidden;}
  table.t th{font-family:var(--display);font-weight:900;font-size:11px;letter-spacing:0.14em;text-transform:uppercase;text-align:left;background:var(--navy);color:var(--cream);padding:11px 14px;}
  table.t td{padding:10px 14px;border-top:1px solid #f0e8d0;font-size:14px;vertical-align:top;}
  table.t tr:nth-child(even) td{background:#fffaee;}
  .btn{font-family:var(--display);font-weight:900;font-size:12px;padding:7px 13px;border-radius:999px;background:var(--navy);color:var(--cream);text-decoration:none;border:0;cursor:pointer;display:inline-block;}
  .btn.ghost{background:transparent;color:var(--navy);border:2px solid var(--navy);}
  .btn.danger{background:#c62828;color:#fff;}
  .btn.warn{background:#F7C84A;color:#12184A;border:2px solid #12184A;}
  form.action{display:inline;margin-right:6px;}
  .tabs{display:inline-flex;background:#fff;border:3px solid var(--navy);border-radius:14px;padding:4px;box-shadow:5px 5px 0 var(--yellow);margin-bottom:18px;flex-wrap:wrap;}
  .tabs a{display:inline-block;padding:8px 16px;border-radius:10px;font-family:var(--display);font-weight:900;font-size:12px;letter-spacing:0.05em;text-decoration:none;color:var(--navy);opacity:0.7;}
  .tabs a.on{background:var(--navy);color:var(--cream);opacity:1;}
  .filter-pills{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;}
  .filter-pills a{display:inline-block;padding:6px 12px;border-radius:999px;background:#fff;border:2px solid var(--navy);font-family:var(--display);font-weight:700;font-size:12px;color:var(--navy);text-decoration:none;letter-spacing:0.04em;}
  .filter-pills a.on{background:var(--navy);color:var(--cream);}
  .filter-pills a .n{opacity:0.65;margin-left:6px;font-weight:400;}
  .flash{padding:12px 16px;background:var(--teal);color:var(--navy);border:2px solid var(--navy);border-radius:10px;margin-bottom:18px;font-size:14px;font-weight:600;}
  .warn-box{padding:12px 16px;background:#ffd29c;color:var(--navy);border:2px solid var(--navy);border-radius:10px;margin-bottom:18px;font-size:14px;}
  .funnel{background:#fff;border:3px solid var(--navy);border-radius:16px;padding:18px;box-shadow:6px 6px 0 var(--yellow);}
  .funnel .step{display:flex;align-items:center;gap:14px;padding:10px 0;border-bottom:1px solid #f0e8d0;}
  .funnel .step:last-child{border:0;}
  .funnel .step .num{width:38px;height:38px;background:var(--paper);border:2px solid var(--navy);border-radius:50%;display:flex;align-items:center;justify-content:center;font-family:var(--display);font-weight:900;color:var(--navy);}
  .funnel .step .name{font-weight:700;flex:1;}
  .funnel .step .bar{height:6px;background:rgba(18,24,74,0.08);border-radius:3px;overflow:hidden;margin-top:6px;width:180px;}
  .funnel .step .bar > div{height:100%;background:var(--teal);}
  .funnel .step .count{font-family:var(--display);font-weight:900;font-size:22px;}
  .actions-cell{white-space:nowrap;}
  .actions-cell form{margin:0 4px 4px 0;display:inline-block;vertical-align:top;}
  .actions-cell input[type=date],.actions-cell select{padding:4px 6px;border:2px solid var(--navy);border-radius:8px;font-size:12px;font-family:var(--body);background:#fff;color:var(--navy);}
  .help-row{background:var(--paper);border:2px solid var(--navy);border-radius:12px;padding:14px 18px;margin-bottom:18px;font-size:13px;line-height:1.55;}
  .help-row strong{font-family:var(--display);}
  .help-row .key{display:inline-block;background:#fff;border:2px solid var(--navy);border-radius:6px;padding:1px 8px;font-family:ui-monospace,monospace;font-size:11px;margin:0 4px;font-weight:700;}
  .timeline{margin:0;padding:0;list-style:none;}
  .timeline li{padding:11px 0;border-bottom:1px solid #f0e8d0;display:flex;gap:12px;align-items:flex-start;font-size:13px;}
  .timeline li:last-child{border:0;}
  .timeline .dot{width:12px;height:12px;border-radius:50%;margin-top:5px;flex-shrink:0;border:2px solid var(--navy);}
  .timeline .dot.send{background:var(--navy);}
  .timeline .dot.open{background:var(--teal);}
  .timeline .dot.click{background:var(--yellow);}
  .timeline .when{opacity:0.55;font-size:11px;font-family:ui-monospace,monospace;text-align:right;min-width:130px;}
  .templ-card{background:#fff;border:3px solid var(--navy);border-radius:16px;padding:20px;margin:0 0 18px;box-shadow:5px 5px 0 var(--yellow);}
  .templ-card h3{font-family:var(--display);font-weight:900;font-size:18px;margin-bottom:4px;letter-spacing:-0.01em;}
  .templ-card .meta{font-size:12px;opacity:0.6;margin-bottom:14px;font-family:ui-monospace,monospace;}
  .templ-card .subject{font-weight:700;font-size:14px;background:var(--paper);border:2px solid var(--navy);padding:10px 14px;border-radius:10px;margin-bottom:14px;}
  .templ-card iframe{width:100%;height:780px;border:2px solid var(--navy);border-radius:12px;background:#FFF8E7;}
  a.contact{color:var(--navy);font-weight:600;text-decoration:none;}
  a.contact:hover{text-decoration:underline;}
  .row-mini{display:flex;gap:14px;font-size:12px;opacity:0.7;margin-top:4px;}
  .preview-link{font-family:ui-monospace,monospace;font-size:11px;}
</style>
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
</head>
<body>
<header class="topbar">
  <div class="brand">
    <img src="https://i.imgur.com/7OdNLrM.png" alt="Wizzy">
    <span>WebWiz<span class="dot">.</span> <small>admin</small></span>
  </div>
  <nav>
    <a href="/admin/">Dashboard</a>
    <a href="/admin/?tab=stats">Stats</a>
    <a href="/admin/?tab=customers">Customers</a>
    <a href="/admin/?tab=sites">Sites</a>
    <a href="/admin/?tab=jobs">Jobs</a>
    <a href="/admin/?tab=prospects">Prospects</a>
    <a href="/admin/nurture.php" class="on">Nurture</a>
    <a href="/admin/?tab=users">Users</a>
    <a href="/admin/?tab=templates">Templates</a>
    <a href="/admin/?tab=settings">Settings</a>
  </nav>
  <div class="me">
    <?= h($me['email']) ?> &middot; <span class="pill admin" style="font-size:9px;padding:2px 8px;">admin</span>
    &nbsp; <a href="/admin/?action=logout" style="color:var(--yellow);font-family:var(--display);font-weight:700;font-size:12px;text-decoration:none;">Log out</a>
  </div>
</header>

<main>
  <h1>Nurture.</h1>
  <p class="sub">Post-gen email follow-ups for everyone who used /try. Brevo is the send transport only. Cadence + templates live in WebWiz.</p>

  <div class="tabs">
    <a href="?view=overview"  class="<?= $view==='overview' ?'on':'' ?>">Overview</a>
    <a href="?view=contacts"  class="<?= $view==='contacts' ?'on':'' ?>">Contacts</a>
    <a href="?view=templates" class="<?= $view==='templates'?'on':'' ?>">Templates</a>
    <a href="?view=activity"  class="<?= $view==='activity' ?'on':'' ?>">Activity log</a>
  </div>

  <?php if ($flash !== ''): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>
  <?php if (ww_nurture_mailing_address($db) === ''): ?>
    <div class="warn-box"><strong>Cron is paused.</strong> Set <code>settings.nurture_mailing_address</code> in Settings to enable sends.</div>
  <?php endif; ?>

<?php

// ============================================================
// OVERVIEW
// ============================================================
if ($view === 'overview') {
    $sends_total  = (int)$db->query("SELECT COUNT(*) FROM nurture_sends WHERE status = 'sent'")->fetchColumn();
    $sends_today  = (int)$db->query("SELECT COUNT(*) FROM nurture_sends WHERE status = 'sent' AND sent_at >= datetime('now','start of day')")->fetchColumn();
    $sends_7d     = (int)$db->query("SELECT COUNT(*) FROM nurture_sends WHERE status = 'sent' AND sent_at >= datetime('now','-7 days')")->fetchColumn();
    $opens_total  = (int)$db->query("SELECT COALESCE(SUM(open_count),0)  FROM nurture_sends")->fetchColumn();
    $clicks_total = (int)$db->query("SELECT COALESCE(SUM(click_count),0) FROM nurture_sends")->fetchColumn();
    $open_rate    = $sends_total > 0 ? round(100 * (int)$db->query("SELECT COUNT(*) FROM nurture_sends WHERE first_opened_at IS NOT NULL")->fetchColumn() / $sends_total, 1) : 0;
    $click_rate   = $sends_total > 0 ? round(100 * (int)$db->query("SELECT COUNT(*) FROM nurture_sends WHERE click_count > 0")->fetchColumn() / $sends_total, 1) : 0;
    $next_send    = $db->query("SELECT MIN(next_send_at) FROM nurture_contacts WHERE status='active' AND next_send_at IS NOT NULL")->fetchColumn();
?>
  <div class="stat-grid">
    <div class="stat"><div class="lbl">Active</div><div class="val"><?= $counts['active'] ?></div><div class="sub">of <?= $counts['all'] ?> total</div></div>
    <div class="stat"><div class="lbl">Sends total</div><div class="val"><?= $sends_total ?></div><div class="sub"><?= $sends_today ?> today &middot; <?= $sends_7d ?> last 7d</div></div>
    <div class="stat"><div class="lbl">Open rate</div><div class="val"><?= $open_rate ?>%</div><div class="sub"><?= $opens_total ?> total opens</div></div>
    <div class="stat"><div class="lbl">Click rate</div><div class="val"><?= $click_rate ?>%</div><div class="sub"><?= $clicks_total ?> total clicks</div></div>
    <div class="stat"><div class="lbl">Purchased</div><div class="val"><?= $counts['purchased'] ?></div><div class="sub">from nurture</div></div>
  </div>

  <div class="help-row" style="margin-top:18px;">
    <strong>Next scheduled send:</strong>
    <?php if ($next_send): ?>
      <?= fmt_dt($next_send, true) ?>
    <?php else: ?>
      <span style="opacity:0.5;">no active contacts due</span>
    <?php endif; ?>
    &middot; cron runs hourly via <span class="key">api/cron_nurture.php?key=...</span>
  </div>

  <h2>Funnel by step</h2>
  <div class="funnel">
    <?php
    $steps = [];
    foreach ($db->query("SELECT current_step, COUNT(*) c FROM nurture_contacts WHERE status='active' GROUP BY current_step ORDER BY current_step")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $steps[(int)$r['current_step']] = (int)$r['c'];
    }
    $max = max(array_values($steps) ?: [1]);
    $names = [
        0 => 'Enrolled (waiting for step 1)',
        1 => "Step 1 sent (Day 2) — The free website we made for {{company}}",
        2 => 'Step 2 sent (Day 6) — A note on the free site',
        3 => 'Step 3 sent (Day 12) — Want anything changed?',
        4 => "Step 4 sent (Day 20) — We'll handle the domain",
        5 => "Step 5 sent (Day 30) — I'll stop crowding your inbox",
    ];
    for ($s = 0; $s <= 5; $s++):
        $c = $steps[$s] ?? 0;
        $pct = $max > 0 ? min(100, round(100 * $c / $max)) : 0;
    ?>
    <div class="step">
      <div class="num"><?= $s ?></div>
      <div style="flex:1;">
        <div class="name"><?= h($names[$s] ?? "Step $s") ?></div>
        <div class="bar"><div style="width:<?= $pct ?>%"></div></div>
      </div>
      <div class="count"><?= $c ?></div>
    </div>
    <?php endfor;
    $mc = 0; foreach ($steps as $sk => $sv) if ($sk >= 6) $mc += $sv;
    $pct = $max > 0 ? min(100, round(100 * $mc / $max)) : 0;
    ?>
    <div class="step">
      <div class="num">6+</div>
      <div style="flex:1;">
        <div class="name">Monthly recurring (alternates step 6 / 7 / 6 / 7 forever, every 30 days)</div>
        <div class="bar"><div style="width:<?= $pct ?>%"></div></div>
      </div>
      <div class="count"><?= $mc ?></div>
    </div>
  </div>

  <h2>Recent activity</h2>
  <ul class="timeline">
    <?php
    $sends = $db->query("
        SELECT s.id, s.contact_id, s.step, s.subject, s.sent_at, s.brevo_message_id, 'send' AS kind, c.name AS cname, c.email AS cemail
          FROM nurture_sends s JOIN nurture_contacts c ON c.id = s.contact_id
         WHERE s.status = 'sent' ORDER BY s.sent_at DESC LIMIT 25
    ")->fetchAll(PDO::FETCH_ASSOC);
    $events = $db->query("
        SELECT e.id, e.contact_id, e.send_id, e.type AS kind, e.target, e.occurred_at, c.name AS cname, c.email AS cemail, s.step
          FROM nurture_events e JOIN nurture_contacts c ON c.id = e.contact_id
          LEFT JOIN nurture_sends s ON s.id = e.send_id
         ORDER BY e.occurred_at DESC LIMIT 25
    ")->fetchAll(PDO::FETCH_ASSOC);
    $feed = [];
    foreach ($sends as $r)  { $r['_when'] = $r['sent_at'];     $feed[] = $r; }
    foreach ($events as $r) { $r['_when'] = $r['occurred_at']; $feed[] = $r; }
    usort($feed, fn($a,$b) => strcmp((string)$b['_when'], (string)$a['_when']));
    $feed = array_slice($feed, 0, 25);
    foreach ($feed as $r):
        $dot = $r['kind'] === 'send' ? 'send' : ($r['kind'] === 'open' ? 'open' : 'click');
        $contact_link = '/admin/nurture.php?view=contact&cid=' . (int)$r['contact_id'];
        $label = '';
        if ($r['kind'] === 'send')      $label = '<strong>Sent</strong> step ' . (int)$r['step'] . ' &rarr; <a class="contact" href="' . h($contact_link) . '">' . h($r['cname'] ?: $r['cemail']) . '</a> &middot; <em>' . h($r['subject']) . '</em>';
        elseif ($r['kind'] === 'open')  $label = '<strong>Opened</strong> step ' . (int)($r['step'] ?? 0) . ' &middot; <a class="contact" href="' . h($contact_link) . '">' . h($r['cname'] ?: $r['cemail']) . '</a>';
        else                            $label = '<strong>Clicked</strong> step ' . (int)($r['step'] ?? 0) . ' &middot; <a class="contact" href="' . h($contact_link) . '">' . h($r['cname'] ?: $r['cemail']) . '</a> &rarr; <span class="preview-link">' . h(substr((string)$r['target'], 0, 70)) . '</span>';
    ?>
    <li><span class="dot <?= $dot ?>"></span><div style="flex:1;"><?= $label ?></div><span class="when"><?= fmt_dt($r['_when'], false) ?></span></li>
    <?php endforeach;
    if (!$feed): ?><li style="opacity:0.5;">No activity yet. The first sends will fire on the next cron tick after <?= h(fmt_dt($next_send, false)) ?>.</li><?php endif; ?>
  </ul>

<?php }

// ============================================================
// CONTACTS
// ============================================================
elseif ($view === 'contacts') {
    $filter = (string)($_GET['filter'] ?? 'all');
    $allowed_filters = ['all','active','paused','unsubscribed','purchased','not_interested','bounced'];
    if (!in_array($filter, $allowed_filters, true)) $filter = 'all';
    if ($filter === 'all') {
        $rows = $db->query("
            SELECT c.*,
                   (SELECT COUNT(*) FROM nurture_sends s WHERE s.contact_id = c.id AND s.status='sent') sends,
                   (SELECT COALESCE(SUM(open_count),0)  FROM nurture_sends s WHERE s.contact_id = c.id) opens,
                   (SELECT COALESCE(SUM(click_count),0) FROM nurture_sends s WHERE s.contact_id = c.id) clicks
              FROM nurture_contacts c ORDER BY c.created_at DESC LIMIT 500
        ")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $st = $db->prepare("
            SELECT c.*,
                   (SELECT COUNT(*) FROM nurture_sends s WHERE s.contact_id = c.id AND s.status='sent') sends,
                   (SELECT COALESCE(SUM(open_count),0)  FROM nurture_sends s WHERE s.contact_id = c.id) opens,
                   (SELECT COALESCE(SUM(click_count),0) FROM nurture_sends s WHERE s.contact_id = c.id) clicks
              FROM nurture_contacts c WHERE c.status = ? ORDER BY c.created_at DESC LIMIT 500
        ");
        $st->execute([$filter]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }
?>
  <div class="filter-pills">
    <a href="?view=contacts&filter=all" class="<?= $filter==='all'?'on':'' ?>">All <span class="n"><?= $counts['all'] ?></span></a>
    <?php foreach (['active','paused','unsubscribed','purchased','not_interested','bounced'] as $s): ?>
      <a href="?view=contacts&filter=<?= h($s) ?>" class="<?= $filter===$s?'on':'' ?>"><?= h(str_replace('_',' ',$s)) ?> <span class="n"><?= $counts[$s] ?></span></a>
    <?php endforeach; ?>
  </div>
  <table class="t">
    <thead>
      <tr>
        <th>Name</th>
        <th>Business</th>
        <th>Email</th>
        <th>Status</th>
        <th>Step</th>
        <th>Sends</th>
        <th>Opens / Clicks</th>
        <th>Next send</th>
        <th>Enrolled</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><a class="contact" href="?view=contact&cid=<?= (int)$r['id'] ?>"><?= h($r['name']) ?: '&mdash;' ?></a></td>
        <td><?= h($r['company']) ?: '&mdash;' ?></td>
        <td style="font-family:ui-monospace,monospace;font-size:12px;"><?= h($r['email']) ?></td>
        <td><?= status_pill($r['status']) ?>
          <?php if (!empty($r['pause_until']) && $r['status']==='paused'): ?>
            <div style="font-size:11px;opacity:0.65;margin-top:4px;">until <?= h(gmdate('M j', strtotime((string)$r['pause_until'].' UTC'))) ?></div>
          <?php endif; ?>
        </td>
        <td><?= (int)$r['current_step'] ?></td>
        <td><?= (int)$r['sends'] ?></td>
        <td><?= (int)$r['opens'] ?> / <?= (int)$r['clicks'] ?></td>
        <td style="white-space:nowrap;font-size:13px;"><?= $r['status']==='active' ? fmt_next($r['next_send_at']) : '<span style="opacity:0.4;">&mdash;</span>' ?></td>
        <td style="white-space:nowrap;font-size:13px;" title="<?= h($r['created_at']) ?> UTC"><?= fmt_rel($r['created_at']) ?></td>
        <td class="actions-cell">
          <?php if ($r['status'] !== 'active'): ?>
            <form method="post" class="action"><input type="hidden" name="action" value="reactivate"><input type="hidden" name="cid" value="<?= (int)$r['id'] ?>"><button class="btn ghost">Reactivate</button></form>
          <?php else: ?>
            <form method="post" class="action" onsubmit="return this.pause_until.value!='';">
              <input type="hidden" name="action" value="pause"><input type="hidden" name="cid" value="<?= (int)$r['id'] ?>">
              <select name="pause_until" title="Sends pause now and resume on this date">
                <option value="">Pause for&hellip;</option>
                <option value="<?= date('Y-m-d', strtotime('+1 week')) ?>">1 week</option>
                <option value="<?= date('Y-m-d', strtotime('+2 weeks')) ?>">2 weeks</option>
                <option value="<?= date('Y-m-d', strtotime('+1 month')) ?>">1 month</option>
                <option value="<?= date('Y-m-d', strtotime('+3 months')) ?>">3 months</option>
              </select>
              <button class="btn warn">Pause</button>
            </form>
            <form method="post" class="action"><input type="hidden" name="action" value="not_interested"><input type="hidden" name="cid" value="<?= (int)$r['id'] ?>"><button class="btn ghost">Not interested</button></form>
            <form method="post" class="action"><input type="hidden" name="action" value="purchased"><input type="hidden" name="cid" value="<?= (int)$r['id'] ?>"><button class="btn">Mark purchased</button></form>
            <form method="post" class="action"><input type="hidden" name="action" value="unsubscribe"><input type="hidden" name="cid" value="<?= (int)$r['id'] ?>"><button class="btn danger">Unsubscribe</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="10" style="text-align:center;padding:48px;opacity:0.5;">No contacts in this view.</td></tr><?php endif; ?>
    </tbody>
  </table>

<?php }

// ============================================================
// TEMPLATES — render via iframe srcdoc so previews show the real branded HTML
// ============================================================
elseif ($view === 'templates') {
    $sample = [
        'id'          => 0,
        'name'        => 'Maria',
        'email'       => 'maria@mariastortillas.com',
        'company'     => "Maria's Tortilla Co.",
        'token'       => '967d31e3e8094242d483540d', // a real token with showcase.jpg
        'preview_url' => 'https://trywebwiz.com/try/?t=sample-preview-token',
    ];
    $unsub_fake = 'https://trywebwiz.com/api/unsubscribe.php?c=0&sig=preview';
    $addr = ww_nurture_mailing_address($db);
    $steps = [
        1 => 'Step 1 — Day 2',
        2 => 'Step 2 — Day 6',
        3 => 'Step 3 — Day 12',
        4 => 'Step 4 — Day 20',
        5 => 'Step 5 — Day 30',
        6 => 'Step 6 — Monthly A (every 30 days, even)',
        7 => 'Step 7 — Monthly B (every 30 days, odd)',
    ];
    echo '<p class="sub" style="margin-bottom:18px;">Each template merged for sample contact <strong>Maria</strong> at <strong>'.h($sample['company']).'</strong>. Showcase image card uses Scale Construction\'s real screenshot as the sample.</p>';
    foreach ($steps as $s => $title) {
        $tpl  = ww_nurture_template($s);
        $subj = ww_nurture_apply_merge($tpl['subject'], $sample);
        $html = ww_nurture_render_html($tpl, $sample, $unsub_fake, $addr);
        ?>
        <div class="templ-card">
          <h3><?= h($title) ?></h3>
          <div class="meta">From: Wizzy from WebWiz &lt;wizzy@trywebwiz.com&gt; &middot; Reply-to: hello@trywebwiz.com</div>
          <div class="subject">Subject: <?= h($subj) ?></div>
          <iframe srcdoc="<?= h($html) ?>" sandbox="" title="Step <?= $s ?> preview"></iframe>
        </div>
        <?php
    }
}

// ============================================================
// ACTIVITY LOG
// ============================================================
elseif ($view === 'activity') {
    $type = (string)($_GET['type'] ?? 'all');
    $page = max(1, (int)($_GET['p'] ?? 1));
    $per  = 50;
    $off  = ($page - 1) * $per;
    if ($type === 'sends') {
        $rows = $db->query("
            SELECT 'send' AS kind, s.id AS rid, s.contact_id, s.step, s.subject, s.status, s.sent_at AS _when, s.brevo_message_id, s.open_count, s.click_count,
                   c.name AS cname, c.email AS cemail
              FROM nurture_sends s JOIN nurture_contacts c ON c.id = s.contact_id
             ORDER BY s.sent_at DESC LIMIT $per OFFSET $off
        ")->fetchAll(PDO::FETCH_ASSOC);
    } elseif (in_array($type, ['open','click'], true)) {
        $st = $db->prepare("
            SELECT 'event' AS kind, e.id AS rid, e.contact_id, e.send_id, e.type AS etype, e.target, e.ip, e.user_agent, e.occurred_at AS _when, s.step,
                   c.name AS cname, c.email AS cemail
              FROM nurture_events e JOIN nurture_contacts c ON c.id = e.contact_id
              LEFT JOIN nurture_sends s ON s.id = e.send_id
             WHERE e.type = ?
             ORDER BY e.occurred_at DESC LIMIT $per OFFSET $off
        ");
        $st->execute([$type]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $s = $db->query("
            SELECT 'send' AS kind, s.id AS rid, s.contact_id, s.step, s.subject, s.status, s.sent_at AS _when, '' AS etype, '' AS target, s.open_count, s.click_count,
                   c.name AS cname, c.email AS cemail
              FROM nurture_sends s JOIN nurture_contacts c ON c.id = s.contact_id
             ORDER BY s.sent_at DESC LIMIT $per
        ")->fetchAll(PDO::FETCH_ASSOC);
        $e = $db->query("
            SELECT 'event' AS kind, e.id AS rid, e.contact_id, ev.step AS step, '' AS subject, '' AS status, e.occurred_at AS _when, e.type AS etype, e.target, 0 AS open_count, 0 AS click_count,
                   c.name AS cname, c.email AS cemail
              FROM nurture_events e
              JOIN nurture_contacts c ON c.id = e.contact_id
              LEFT JOIN nurture_sends ev ON ev.id = e.send_id
             ORDER BY e.occurred_at DESC LIMIT $per
        ")->fetchAll(PDO::FETCH_ASSOC);
        $rows = array_merge($s, $e);
        usort($rows, fn($a,$b) => strcmp((string)$b['_when'], (string)$a['_when']));
        $rows = array_slice($rows, $off, $per);
    }
?>
  <div class="filter-pills">
    <a href="?view=activity&type=all"   class="<?= $type==='all'   ?'on':'' ?>">All</a>
    <a href="?view=activity&type=sends" class="<?= $type==='sends' ?'on':'' ?>">Sends</a>
    <a href="?view=activity&type=open"  class="<?= $type==='open'  ?'on':'' ?>">Opens</a>
    <a href="?view=activity&type=click" class="<?= $type==='click' ?'on':'' ?>">Clicks</a>
  </div>
  <table class="t">
    <thead><tr><th>When</th><th>Event</th><th>Contact</th><th>Step</th><th>Detail</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= fmt_dt($r['_when'], true) ?></td>
        <td>
          <?php if ($r['kind'] === 'send'): ?>
            <span class="pill ok">Sent</span>
            <?php if (($r['status'] ?? '') !== 'sent'): ?><div style="font-size:11px;color:#842029;margin-top:4px;"><?= h($r['status']) ?></div><?php endif; ?>
          <?php elseif (($r['etype'] ?? '') === 'open'): ?>
            <span class="pill ok">Open</span>
          <?php else: ?>
            <span class="pill warn">Click</span>
          <?php endif; ?>
        </td>
        <td><a class="contact" href="?view=contact&cid=<?= (int)$r['contact_id'] ?>"><?= h($r['cname'] ?: $r['cemail']) ?></a><div style="font-size:11px;opacity:0.55;font-family:ui-monospace,monospace;"><?= h($r['cemail']) ?></div></td>
        <td><?= (int)$r['step'] ?></td>
        <td>
          <?php if ($r['kind'] === 'send'): ?>
            <em><?= h($r['subject']) ?></em>
            <div class="row-mini"><span>opens: <?= (int)$r['open_count'] ?></span><span>clicks: <?= (int)$r['click_count'] ?></span></div>
          <?php elseif (($r['etype'] ?? '') === 'click'): ?>
            <a class="preview-link" href="<?= h($r['target']) ?>" target="_blank"><?= h(substr((string)$r['target'], 0, 90)) ?>&hellip;</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" style="text-align:center;padding:48px;opacity:0.5;">No activity yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
  <?php if (count($rows) === $per): ?>
    <p style="margin-top:16px;text-align:center;"><a class="btn ghost" href="?view=activity&type=<?= h($type) ?>&p=<?= $page+1 ?>">Next page &rarr;</a></p>
  <?php endif; ?>

<?php }

// ============================================================
// CONTACT DETAIL
// ============================================================
elseif ($view === 'contact') {
    $cid = (int)($_GET['cid'] ?? 0);
    $st = $db->prepare("SELECT * FROM nurture_contacts WHERE id = ? LIMIT 1");
    $st->execute([$cid]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) {
        echo '<p>Contact not found. <a href="?view=contacts" class="btn ghost">Back to contacts</a></p>';
    } else {
        $sends = $db->prepare("SELECT * FROM nurture_sends WHERE contact_id = ? ORDER BY id ASC");
        $sends->execute([$cid]);
        $sends_rows = $sends->fetchAll(PDO::FETCH_ASSOC);
        $events = $db->prepare("SELECT * FROM nurture_events WHERE contact_id = ? ORDER BY id ASC");
        $events->execute([$cid]);
        $events_rows = $events->fetchAll(PDO::FETCH_ASSOC);
        $sent_total = 0; $opens = 0; $clicks = 0;
        foreach ($sends_rows as $s) {
            if (($s['status'] ?? '') === 'sent') $sent_total++;
            $opens  += (int)($s['open_count']  ?? 0);
            $clicks += (int)($s['click_count'] ?? 0);
        }
?>
  <p style="margin:0 0 12px;"><a href="?view=contacts" style="color:var(--navy);opacity:0.7;font-weight:600;">&larr; Back to contacts</a></p>
  <h1 style="margin-bottom:14px;"><?= h($c['name']) ?: '(no name)' ?> &middot; <?= status_pill($c['status']) ?></h1>
  <div class="stat-grid">
    <div class="stat"><div class="lbl">Email</div><div class="val" style="font-size:14px;font-family:ui-monospace,monospace;font-weight:700;"><?= h($c['email']) ?></div></div>
    <div class="stat"><div class="lbl">Business</div><div class="val" style="font-size:16px;"><?= h($c['company']) ?: '&mdash;' ?></div></div>
    <div class="stat"><div class="lbl">Step</div><div class="val"><?= (int)$c['current_step'] ?></div><div class="sub">next: <?= h(fmt_dt($c['next_send_at'], false)) ?></div></div>
    <div class="stat"><div class="lbl">Sends</div><div class="val"><?= $sent_total ?></div><div class="sub"><?= $opens ?> opens &middot; <?= $clicks ?> clicks</div></div>
    <div class="stat"><div class="lbl">Source</div><div class="val" style="font-size:16px;"><?= h($c['source']) ?></div></div>
  </div>

  <div class="actions-cell" style="margin:18px 0 24px;">
    <?php if ($c['status'] !== 'active'): ?>
      <form method="post" class="action"><input type="hidden" name="action" value="reactivate"><input type="hidden" name="cid" value="<?= $cid ?>"><button class="btn ghost">Reactivate</button></form>
    <?php else: ?>
      <form method="post" class="action" onsubmit="return this.pause_until.value!='';">
        <input type="hidden" name="action" value="pause"><input type="hidden" name="cid" value="<?= $cid ?>">
        <input type="date" name="pause_until" min="<?= date('Y-m-d') ?>" required>
        <button class="btn warn">Pause</button>
      </form>
      <form method="post" class="action"><input type="hidden" name="action" value="not_interested"><input type="hidden" name="cid" value="<?= $cid ?>"><button class="btn ghost">Not interested</button></form>
      <form method="post" class="action"><input type="hidden" name="action" value="unsubscribe"><input type="hidden" name="cid" value="<?= $cid ?>"><button class="btn danger">Unsubscribe</button></form>
      <form method="post" class="action"><input type="hidden" name="action" value="purchased"><input type="hidden" name="cid" value="<?= $cid ?>"><button class="btn warn">Mark purchased</button></form>
    <?php endif; ?>
    <?php if (!empty($c['preview_url'])): ?>
      <a class="btn ghost" href="<?= h($c['preview_url']) ?>" target="_blank" style="margin-left:6px;">Open preview &rarr;</a>
    <?php endif; ?>
  </div>

  <h2>Timeline</h2>
  <ul class="timeline">
    <?php
    $stream = [];
    foreach ($sends_rows as $s) { $s['_kind'] = 'send';  $s['_when'] = $s['sent_at']; $stream[] = $s; }
    foreach ($events_rows as $e) { $e['_kind'] = $e['type']; $e['_when'] = $e['occurred_at']; $stream[] = $e; }
    usort($stream, fn($a,$b) => strcmp((string)$a['_when'], (string)$b['_when']));
    foreach ($stream as $row):
        if ($row['_kind'] === 'send'):
    ?>
      <li>
        <span class="dot send"></span>
        <div style="flex:1;">
          <strong>Sent step <?= (int)$row['step'] ?></strong> &middot; <em><?= h($row['subject']) ?></em>
          <div class="row-mini">
            <span>status: <?= h($row['status'] ?? 'unknown') ?></span>
            <span>opens: <?= (int)($row['open_count'] ?? 0) ?></span>
            <span>clicks: <?= (int)($row['click_count'] ?? 0) ?></span>
          </div>
        </div>
        <span class="when"><?= h(fmt_dt($row['_when'], false)) ?></span>
      </li>
    <?php elseif ($row['_kind'] === 'open'): ?>
      <li><span class="dot open"></span><div style="flex:1;"><strong>Opened</strong></div><span class="when"><?= h(fmt_dt($row['_when'], false)) ?></span></li>
    <?php else: ?>
      <li><span class="dot click"></span><div style="flex:1;"><strong>Clicked</strong> &rarr; <a class="preview-link" href="<?= h($row['target']) ?>" target="_blank"><?= h(substr((string)$row['target'], 0, 90)) ?>&hellip;</a></div><span class="when"><?= h(fmt_dt($row['_when'], false)) ?></span></li>
    <?php endif; endforeach;
    if (!$stream): ?><li style="opacity:0.5;">No activity yet for this contact.</li><?php endif; ?>
  </ul>
<?php
    }
}
?>

</main>
</body>
</html>
