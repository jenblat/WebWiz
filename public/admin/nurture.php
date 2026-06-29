<?php
// /admin/nurture.php — Nurture admin: Overview, Contacts, Templates, Activity, Contact detail.
declare(strict_types=1);
require '/var/www/sites/trywebwiz/private/webwiz_lib.php';
require '/var/www/sites/trywebwiz/public/api/_nurture.php';

session_start([
    'cookie_secure'   => true,
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
]);

$me = !empty($_SESSION['uid']) ? ww_user_by_id((int)$_SESSION['uid']) : null;
$logged_in = (bool)$me;
$is_admin  = $logged_in && ($me['role'] ?? '') === 'admin';
if (!$is_admin) { header('Location: /admin/'); exit; }

$db = ww_db();
ww_nurture_init_schema($db);

// --- Action handling (pause/unsubscribe/reactivate/etc) ---
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
                $flash = "Paused until $until.";
            } else $flash = 'Bad pause-until date format.';
        }
        elseif ($act === 'not_interested') { ww_nurture_set_status($db, $cid, 'not_interested'); $flash = 'Marked not_interested.'; }
        elseif ($act === 'unsubscribe')     { ww_nurture_set_status($db, $cid, 'unsubscribed');   $flash = 'Marked unsubscribed.'; }
        elseif ($act === 'reactivate')      {
            $db->prepare("UPDATE nurture_contacts SET status = 'active', pause_until = NULL, next_send_at = datetime('now'), updated_at = datetime('now') WHERE id = ?")->execute([$cid]);
            $flash = 'Reactivated. Will pick up at next cron.';
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
function ago($ts) {
    if (!$ts) return '—';
    $t = strtotime($ts . ' UTC'); if (!$t) return h($ts);
    $d = time() - $t;
    if ($d < 60)    return $d . 's ago';
    if ($d < 3600)  return floor($d/60) . 'm ago';
    if ($d < 86400) return floor($d/3600) . 'h ago';
    return floor($d/86400) . 'd ago';
}
function future($ts) {
    if (!$ts) return '—';
    $t = strtotime($ts . ' UTC'); if (!$t) return h($ts);
    $d = $t - time();
    if ($d < 0)     return 'now';
    if ($d < 3600)  return 'in ' . floor($d/60) . 'm';
    if ($d < 86400) return 'in ' . floor($d/3600) . 'h';
    return 'in ' . floor($d/86400) . 'd';
}
function status_pill(string $s) {
    return '<span class="status-pill s-' . h($s) . '">' . h($s) . '</span>';
}

$counts = [];
foreach (['active','paused','unsubscribed','purchased','not_interested','bounced'] as $s) {
    $st = $db->prepare("SELECT COUNT(*) FROM nurture_contacts WHERE status = ?");
    $st->execute([$s]);
    $counts[$s] = (int)$st->fetchColumn();
}
$counts['all'] = array_sum($counts);

?><!DOCTYPE html><html lang="en"><head>
<meta charset="utf-8">
<title>Nurture · WebWiz admin</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
  * { box-sizing: border-box; }
  body { margin: 0; padding: 28px 32px 48px; background: #FFF8E7; font-family: -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; color: #12184A; }
  h1 { font-family: 'Nunito',sans-serif; font-weight: 900; font-size: 30px; letter-spacing: -0.02em; margin: 0 0 4px; }
  h2 { font-family: 'Nunito',sans-serif; font-weight: 900; font-size: 20px; margin: 28px 0 14px; }
  .sub { opacity: 0.6; font-size: 14px; margin: 0 0 20px; }
  .topbar a { display:inline-block;margin-right:18px;color:#12184A;text-decoration:none;opacity:0.6;font-weight:600; }
  .topbar a.active { opacity: 1; border-bottom: 2px solid #12184A; padding-bottom: 4px; }
  .tabs { display:flex;gap:4px;flex-wrap:wrap;margin:24px 0 18px;background:#fff;padding:6px;border-radius:12px;display:inline-flex;box-shadow:0 1px 3px rgba(18,24,74,0.05); }
  .tabs a { display:inline-block;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;color:#12184A;opacity:0.65; }
  .tabs a.active { background:#12184A;color:#FFF8E7;opacity:1; }
  .grid { display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:0 0 22px; }
  .stat { background:#fff;border-radius:12px;padding:14px 16px;border:1px solid rgba(18,24,74,0.06); }
  .stat .label { font-size:11px;text-transform:uppercase;letter-spacing:0.08em;font-weight:700;opacity:0.55;margin-bottom:4px; }
  .stat .val { font-family:'Nunito',sans-serif;font-weight:900;font-size:28px;line-height:1; }
  .stat .meta { font-size:11px;opacity:0.6;margin-top:6px; }
  .funnel { background:#fff;border-radius:12px;padding:18px;border:1px solid rgba(18,24,74,0.06); }
  .funnel .step { display:flex;align-items:center;gap:14px;padding:10px 0;border-bottom:1px solid rgba(18,24,74,0.05); }
  .funnel .step:last-child { border-bottom:0; }
  .funnel .step .num { width:38px;height:38px;background:#F8EFD3;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:900;color:#12184A; }
  .funnel .step .name { font-weight:700;flex:1; }
  .funnel .step .count { font-family:'Nunito',sans-serif;font-weight:900;font-size:20px; }
  .funnel .step .bar { height:6px;background:rgba(18,24,74,0.08);border-radius:3px;overflow:hidden;margin-top:6px;width:160px; }
  .funnel .step .bar > div { height:100%;background:#3FCFA8; }
  table { width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(18,24,74,0.05);font-size:13px; }
  th, td { padding:10px 12px;text-align:left;border-bottom:1px solid rgba(18,24,74,0.05);vertical-align:top; }
  th { background:#F8EFD3;font-size:11px;text-transform:uppercase;letter-spacing:0.06em;font-weight:700; }
  tr:last-child td { border-bottom:0; }
  .status-pill { display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em; }
  .s-active { background:#E6F7F1;color:#0A6B53; }
  .s-paused { background:#FFF3CD;color:#856404; }
  .s-unsubscribed,.s-not_interested,.s-bounced { background:#f5e0e0;color:#842029; }
  .s-purchased { background:#DDE5FF;color:#1F3A93; }
  .counts { display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px; }
  .counts a { display:inline-block;padding:6px 12px;border-radius:999px;background:#fff;border:1px solid rgba(18,24,74,0.15);font-size:12px;font-weight:600;color:#12184A;text-decoration:none; }
  .counts a.active { background:#12184A;color:#FFF8E7;border-color:#12184A; }
  .counts a .n { opacity:0.7;margin-left:6px;font-weight:400; }
  .flash { padding:10px 14px;background:#E6F7F1;color:#0A6B53;border-radius:8px;margin-bottom:16px;font-size:14px; }
  .warn { padding:10px 14px;background:#FFF3CD;color:#856404;border-radius:8px;margin-bottom:16px;font-size:14px; }
  .actions form { display:inline-block;margin:0 4px 4px 0; }
  .actions input[type=date] { padding:4px 6px;border:1px solid rgba(18,24,74,0.18);border-radius:6px;font-size:12px; }
  .actions button { background:#12184A;color:#FFF8E7;border:0;padding:4px 10px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600; }
  .actions button.secondary { background:#F8EFD3;color:#12184A;border:1px solid rgba(18,24,74,0.12); }
  .actions button.danger { background:#842029; }
  .templ-card { background:#fff;border-radius:12px;padding:20px;margin:0 0 18px;border:1px solid rgba(18,24,74,0.06); }
  .templ-card h3 { margin:0 0 6px;font-family:'Nunito',sans-serif;font-weight:900;font-size:18px; }
  .templ-card .meta { font-size:12px;opacity:0.6;margin-bottom:14px; }
  .templ-card .subject { font-weight:700;font-size:15px;background:#F8EFD3;padding:10px 14px;border-radius:8px;margin-bottom:14px; }
  .templ-card .preview { background:#fff;border:1px solid rgba(18,24,74,0.08);border-radius:10px;padding:24px;font-size:14px;line-height:1.6;white-space:pre-wrap; }
  .timeline { margin:0;padding:0;list-style:none; }
  .timeline li { padding:10px 0;border-bottom:1px solid rgba(18,24,74,0.05);font-size:13px;display:flex;gap:12px;align-items:flex-start; }
  .timeline li:last-child { border:0; }
  .timeline .dot { width:10px;height:10px;border-radius:50%;margin-top:6px;flex-shrink:0; }
  .timeline .dot.send { background:#12184A; }
  .timeline .dot.open { background:#3FCFA8; }
  .timeline .dot.click { background:#F7C84A; }
  .timeline .when { opacity:0.55;font-size:11px;font-family:ui-monospace,monospace; }
  .preview-link { color:#12184A;font-family:ui-monospace,monospace;font-size:11px; }
  .row-mini { display:flex;gap:14px;font-size:12px;opacity:0.7;margin-top:4px; }
  .row-mini span { display:inline-flex;align-items:center;gap:4px; }
  a.contact { color:#12184A;font-weight:600;text-decoration:none; }
  a.contact:hover { text-decoration:underline; }
</style>
</head><body>

<div class="topbar" style="margin-bottom:8px;">
  <a href="/admin/">Dashboard</a>
  <a href="/admin/nurture.php" class="active">Nurture</a>
  <a href="/admin/?action=logout">Logout</a>
</div>

<h1>Nurture</h1>
<p class="sub">Activity, templates, and funnel for the /try post-gen email sequence.</p>

<div class="tabs">
  <a href="?view=overview"  class="<?= $view==='overview'?'active':'' ?>">Overview</a>
  <a href="?view=contacts"  class="<?= $view==='contacts'?'active':'' ?>">Contacts</a>
  <a href="?view=templates" class="<?= $view==='templates'?'active':'' ?>">Templates</a>
  <a href="?view=activity"  class="<?= $view==='activity'?'active':'' ?>">Activity log</a>
</div>

<?php if ($flash !== ''): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>
<?php if (ww_nurture_mailing_address($db) === ''): ?>
<div class="warn"><strong>Cron is paused</strong> — set <code>settings.nurture_mailing_address</code> to enable sends.</div>
<?php endif; ?>

<?php

// ============================================================
// OVERVIEW
// ============================================================
if ($view === 'overview') {
    $sends_total = (int)$db->query("SELECT COUNT(*) FROM nurture_sends WHERE status = 'sent'")->fetchColumn();
    $sends_today = (int)$db->query("SELECT COUNT(*) FROM nurture_sends WHERE status = 'sent' AND sent_at >= datetime('now','start of day')")->fetchColumn();
    $sends_7d    = (int)$db->query("SELECT COUNT(*) FROM nurture_sends WHERE status = 'sent' AND sent_at >= datetime('now','-7 days')")->fetchColumn();
    $opens_total = (int)$db->query("SELECT COALESCE(SUM(open_count),0)  FROM nurture_sends")->fetchColumn();
    $clicks_total= (int)$db->query("SELECT COALESCE(SUM(click_count),0) FROM nurture_sends")->fetchColumn();
    $purchased   = $counts['purchased'];
    $open_rate   = $sends_total > 0 ? round(100 * (int)$db->query("SELECT COUNT(*) FROM nurture_sends WHERE first_opened_at IS NOT NULL")->fetchColumn() / $sends_total, 1) : 0;
    $click_rate  = $sends_total > 0 ? round(100 * (int)$db->query("SELECT COUNT(*) FROM nurture_sends WHERE click_count > 0")->fetchColumn()         / $sends_total, 1) : 0;
?>
  <div class="grid">
    <div class="stat"><div class="label">Active contacts</div><div class="val"><?= $counts['active'] ?></div><div class="meta">of <?= $counts['all'] ?> total</div></div>
    <div class="stat"><div class="label">Sends total</div><div class="val"><?= $sends_total ?></div><div class="meta"><?= $sends_today ?> today &middot; <?= $sends_7d ?> last 7d</div></div>
    <div class="stat"><div class="label">Open rate</div><div class="val"><?= $open_rate ?>%</div><div class="meta"><?= $opens_total ?> total opens</div></div>
    <div class="stat"><div class="label">Click rate</div><div class="val"><?= $click_rate ?>%</div><div class="meta"><?= $clicks_total ?> total clicks</div></div>
    <div class="stat"><div class="label">Purchased</div><div class="val"><?= $purchased ?></div><div class="meta">converted from nurture</div></div>
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
        0 => 'Enrolled (step 0 — waiting for step 1)',
        1 => "Step 1 sent — Your {{company}} site is still up",
        2 => 'Step 2 sent — A quick note on how this works',
        3 => 'Step 3 sent — Want to change anything on it?',
        4 => "Step 4 sent — We'll even handle the domain",
        5 => "Step 5 sent — I'll stop crowding your inbox",
    ];
    for ($s = 0; $s <= 5; $s++):
        $c = $steps[$s] ?? 0;
        $pct = $max > 0 ? min(100, round(100 * $c / $max)) : 0;
    ?>
    <div class="step">
      <div class="num"><?= $s ?></div>
      <div style="flex:1;">
        <div style="font-weight:700;font-size:13px;"><?= h($names[$s] ?? "Step $s") ?></div>
        <div class="bar"><div style="width:<?= $pct ?>%"></div></div>
      </div>
      <div class="count"><?= $c ?></div>
    </div>
    <?php endfor;
    // Step 6+ monthly bucket
    $mc = 0; foreach ($steps as $sk => $sv) if ($sk >= 6) $mc += $sv;
    $pct = $max > 0 ? min(100, round(100 * $mc / $max)) : 0;
    ?>
    <div class="step">
      <div class="num">6+</div>
      <div style="flex:1;">
        <div style="font-weight:700;font-size:13px;">Monthly cadence (alternating A/B)</div>
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
    usort($feed, fn($a,$b) => strcmp($b['_when'], $a['_when']));
    $feed = array_slice($feed, 0, 30);
    foreach ($feed as $r):
        $dot = $r['kind'] === 'send' ? 'send' : ($r['kind'] === 'open' ? 'open' : 'click');
        $contact_link = '/admin/nurture.php?view=contact&cid=' . (int)$r['contact_id'];
        $label = '';
        if ($r['kind'] === 'send')  $label = '<strong>Sent</strong> step ' . (int)$r['step'] . ' to <a class="contact" href="' . h($contact_link) . '">' . h($r['cname'] ?: $r['cemail']) . '</a> &middot; <em>' . h($r['subject']) . '</em>';
        elseif ($r['kind'] === 'open')  $label = '<strong>Opened</strong> step ' . (int)($r['step'] ?? 0) . ' &middot; <a class="contact" href="' . h($contact_link) . '">' . h($r['cname'] ?: $r['cemail']) . '</a>';
        else                        $label = '<strong>Clicked</strong> step ' . (int)($r['step'] ?? 0) . ' &middot; <a class="contact" href="' . h($contact_link) . '">' . h($r['cname'] ?: $r['cemail']) . '</a> &rarr; <span class="preview-link">' . h(substr((string)$r['target'], 0, 80)) . '</span>';
    ?>
    <li><span class="dot <?= $dot ?>"></span><div style="flex:1;"><?= $label ?></div><span class="when"><?= h(substr((string)$r['_when'],0,16)) ?> UTC &middot; <?= ago($r['_when']) ?></span></li>
    <?php endforeach; ?>
    <?php if (!$feed): ?><li style="opacity:0.5;">No activity yet. Activity will appear here as the cron sends emails.</li><?php endif; ?>
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
  <div class="counts">
    <a href="?view=contacts&filter=all" class="<?= $filter==='all'?'active':'' ?>">All <span class="n"><?= $counts['all'] ?></span></a>
    <?php foreach (['active','paused','unsubscribed','purchased','not_interested','bounced'] as $s): ?>
      <a href="?view=contacts&filter=<?= h($s) ?>" class="<?= $filter===$s?'active':'' ?>"><?= h($s) ?> <span class="n"><?= $counts[$s] ?></span></a>
    <?php endforeach; ?>
  </div>
  <table>
    <thead><tr><th>Name</th><th>Company</th><th>Email</th><th>Status</th><th>Step</th><th>Sends</th><th>Opens</th><th>Clicks</th><th>Next</th><th>Created</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><a class="contact" href="?view=contact&cid=<?= (int)$r['id'] ?>"><?= h($r['name']) ?: '—' ?></a></td>
        <td><?= h($r['company']) ?: '—' ?></td>
        <td><?= h($r['email']) ?></td>
        <td><?= status_pill($r['status']) ?>
          <?php if (!empty($r['pause_until']) && $r['status']==='paused'): ?><div style="font-size:11px;opacity:0.7;">until <?= h(substr($r['pause_until'],0,16)) ?></div><?php endif; ?>
        </td>
        <td><?= (int)$r['current_step'] ?></td>
        <td><?= (int)$r['sends'] ?></td>
        <td><?= (int)$r['opens'] ?></td>
        <td><?= (int)$r['clicks'] ?></td>
        <td><?= future($r['next_send_at']) ?></td>
        <td><?= ago($r['created_at']) ?></td>
        <td class="actions">
          <?php if ($r['status'] !== 'active'): ?>
            <form method="post"><input type="hidden" name="action" value="reactivate"><input type="hidden" name="cid" value="<?= (int)$r['id'] ?>"><button class="secondary">Reactivate</button></form>
          <?php else: ?>
            <form method="post" onsubmit="return this.pause_until.value!='';">
              <input type="hidden" name="action" value="pause"><input type="hidden" name="cid" value="<?= (int)$r['id'] ?>">
              <input type="date" name="pause_until" min="<?= date('Y-m-d') ?>" required><button>Pause</button>
            </form>
            <form method="post"><input type="hidden" name="action" value="not_interested"><input type="hidden" name="cid" value="<?= (int)$r['id'] ?>"><button class="secondary">Not interested</button></form>
            <form method="post"><input type="hidden" name="action" value="unsubscribe"><input type="hidden" name="cid" value="<?= (int)$r['id'] ?>"><button class="danger">Unsubscribe</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="11" style="text-align:center;padding:48px;opacity:0.5;">No contacts in this view.</td></tr><?php endif; ?>
    </tbody>
  </table>
<?php }

// ============================================================
// TEMPLATES — preview every step rendered with sample merge data
// ============================================================
elseif ($view === 'templates') {
    $sample = [
        'name'        => 'Maria',
        'company'     => "Maria's Tortilla Co.",
        'preview_url' => 'https://trywebwiz.com/try?t=abc123sample',
    ];
    $steps = [
        1 => 'Step 1 — Day 2 (enrollment + 2 days)',
        2 => 'Step 2 — Day 6',
        3 => 'Step 3 — Day 12',
        4 => 'Step 4 — Day 20',
        5 => 'Step 5 — Day 30',
        6 => 'Step 6 (Monthly A) — every 30 days from prior send',
        7 => 'Step 7 (Monthly B) — every 30 days from prior send',
    ];
    echo '<p class="sub">Each template uses {{name}}, {{company}}, {{preview_url}} merge tags. Below is exactly what a contact named <strong>Maria</strong> from <strong>'.h($sample['company']).'</strong> would receive at each step.</p>';
    foreach ($steps as $s => $title) {
        $tpl = ww_nurture_template($s);
        $subj = ww_nurture_apply_merge($tpl['subject'], $sample);
        $body = ww_nurture_apply_merge($tpl['body'], $sample);
        ?>
        <div class="templ-card">
          <h3><?= h($title) ?></h3>
          <div class="meta">Sender: Wizzy from WebWiz &lt;wizzy@trywebwiz.com&gt; &middot; Reply-to: hello@trywebwiz.com</div>
          <div class="subject">Subject: <?= h($subj) ?></div>
          <div class="preview"><?= h($body) ?></div>
        </div>
        <?php
    }
}

// ============================================================
// ACTIVITY LOG — paginated stream of every send + open + click
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
        // unified — pull both then merge
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
  <div class="counts">
    <a href="?view=activity&type=all"   class="<?= $type==='all'?'active':'' ?>">All</a>
    <a href="?view=activity&type=sends" class="<?= $type==='sends'?'active':'' ?>">Sends</a>
    <a href="?view=activity&type=open"  class="<?= $type==='open'?'active':'' ?>">Opens</a>
    <a href="?view=activity&type=click" class="<?= $type==='click'?'active':'' ?>">Clicks</a>
  </div>
  <table>
    <thead><tr><th>When</th><th>Event</th><th>Contact</th><th>Step</th><th>Detail</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= h(substr((string)$r['_when'], 0, 16)) ?><div style="font-size:11px;opacity:0.55;"><?= ago($r['_when']) ?></div></td>
        <td>
          <?php if ($r['kind'] === 'send'): ?>
            <span class="status-pill s-active">Sent</span>
            <?php if (($r['status'] ?? '') !== 'sent'): ?><div style="font-size:11px;color:#842029;"><?= h($r['status']) ?></div><?php endif; ?>
          <?php elseif (($r['etype'] ?? '') === 'open'): ?>
            <span class="status-pill" style="background:#E6F7F1;color:#0A6B53;">Open</span>
          <?php else: ?>
            <span class="status-pill" style="background:#FFF3CD;color:#856404;">Click</span>
          <?php endif; ?>
        </td>
        <td><a class="contact" href="?view=contact&cid=<?= (int)$r['contact_id'] ?>"><?= h($r['cname'] ?: $r['cemail']) ?></a><div style="font-size:11px;opacity:0.55;"><?= h($r['cemail']) ?></div></td>
        <td><?= (int)$r['step'] ?></td>
        <td>
          <?php if ($r['kind'] === 'send'): ?>
            <em><?= h($r['subject']) ?></em>
            <div class="row-mini"><span>opens: <?= (int)$r['open_count'] ?></span><span>clicks: <?= (int)$r['click_count'] ?></span></div>
          <?php elseif (($r['etype'] ?? '') === 'click'): ?>
            <a class="preview-link" href="<?= h($r['target']) ?>" target="_blank"><?= h(substr((string)$r['target'], 0, 90)) ?>…</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" style="text-align:center;padding:48px;opacity:0.5;">No activity yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
  <?php if (count($rows) === $per): ?>
    <p style="margin-top:16px;text-align:center;"><a href="?view=activity&type=<?= h($type) ?>&p=<?= $page+1 ?>" style="color:#12184A;">Next page &rarr;</a></p>
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
        echo '<p>Contact not found. <a href="?view=contacts">Back to contacts</a></p>';
    } else {
        // Pull all sends + events
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
  <p style="margin:0 0 8px;"><a href="?view=contacts" style="color:#12184A;opacity:0.7;">&larr; Back to contacts</a></p>
  <h2 style="margin-top:0;"><?= h($c['name']) ?: '(no name)' ?> &middot; <?= status_pill($c['status']) ?></h2>
  <div class="grid">
    <div class="stat"><div class="label">Email</div><div class="val" style="font-size:14px;font-weight:600;font-family:ui-monospace,monospace;"><?= h($c['email']) ?></div></div>
    <div class="stat"><div class="label">Company</div><div class="val" style="font-size:16px;"><?= h($c['company']) ?: '—' ?></div></div>
    <div class="stat"><div class="label">Step</div><div class="val"><?= (int)$c['current_step'] ?></div><div class="meta">next: <?= future($c['next_send_at']) ?></div></div>
    <div class="stat"><div class="label">Sends</div><div class="val"><?= $sent_total ?></div><div class="meta"><?= $opens ?> opens &middot; <?= $clicks ?> clicks</div></div>
    <div class="stat"><div class="label">Source</div><div class="val" style="font-size:16px;"><?= h($c['source']) ?></div></div>
    <div class="stat"><div class="label">Preview</div><div class="val" style="font-size:13px;"><?php if ($c['preview_url']): ?><a class="preview-link" href="<?= h($c['preview_url']) ?>" target="_blank">view live</a><?php else: ?>—<?php endif; ?></div></div>
  </div>

  <div class="actions" style="margin:6px 0 20px;">
    <?php if ($c['status'] !== 'active'): ?>
      <form method="post"><input type="hidden" name="action" value="reactivate"><input type="hidden" name="cid" value="<?= $cid ?>"><button class="secondary">Reactivate</button></form>
    <?php else: ?>
      <form method="post" onsubmit="return this.pause_until.value!='';" style="display:inline-block;">
        <input type="hidden" name="action" value="pause"><input type="hidden" name="cid" value="<?= $cid ?>">
        <input type="date" name="pause_until" min="<?= date('Y-m-d') ?>" required><button>Pause</button>
      </form>
      <form method="post"><input type="hidden" name="action" value="not_interested"><input type="hidden" name="cid" value="<?= $cid ?>"><button class="secondary">Not interested</button></form>
      <form method="post"><input type="hidden" name="action" value="unsubscribe"><input type="hidden" name="cid" value="<?= $cid ?>"><button class="danger">Unsubscribe</button></form>
      <form method="post"><input type="hidden" name="action" value="purchased"><input type="hidden" name="cid" value="<?= $cid ?>"><button class="secondary">Mark purchased</button></form>
    <?php endif; ?>
  </div>

  <h2>Timeline</h2>
  <ul class="timeline">
    <?php
    // Merge sends + events into one chronological stream
    $stream = [];
    foreach ($sends_rows as $s) { $s['_kind'] = 'send';  $s['_when'] = $s['sent_at']; $stream[] = $s; }
    foreach ($events_rows as $e) { $e['_kind'] = $e['type']; $e['_when'] = $e['occurred_at']; $stream[] = $e; }
    usort($stream, fn($a,$b) => strcmp((string)$a['_when'], (string)$b['_when']));
    foreach ($stream as $row):
        if (($row['_kind']) === 'send'):
    ?>
      <li>
        <span class="dot send"></span>
        <div style="flex:1;">
          <strong>Sent step <?= (int)$row['step'] ?></strong> &middot; <em><?= h($row['subject']) ?></em>
          <div class="row-mini">
            <span>status: <?= h($row['status'] ?? 'unknown') ?></span>
            <span>opens: <?= (int)($row['open_count'] ?? 0) ?></span>
            <span>clicks: <?= (int)($row['click_count'] ?? 0) ?></span>
            <?php if (!empty($row['brevo_message_id'])): ?><span>msg: <code><?= h(substr($row['brevo_message_id'], 0, 18)) ?>…</code></span><?php endif; ?>
          </div>
        </div>
        <span class="when"><?= h(substr((string)$row['_when'], 0, 16)) ?> UTC</span>
      </li>
    <?php elseif ($row['_kind'] === 'open'): ?>
      <li>
        <span class="dot open"></span>
        <div style="flex:1;"><strong>Opened</strong><div class="row-mini"><span>IP: <code><?= h($row['ip']) ?></code></span></div></div>
        <span class="when"><?= h(substr((string)$row['_when'], 0, 16)) ?> UTC</span>
      </li>
    <?php else: ?>
      <li>
        <span class="dot click"></span>
        <div style="flex:1;"><strong>Clicked</strong> &rarr; <a class="preview-link" href="<?= h($row['target']) ?>" target="_blank"><?= h(substr((string)$row['target'], 0, 90)) ?>…</a></div>
        <span class="when"><?= h(substr((string)$row['_when'], 0, 16)) ?> UTC</span>
      </li>
    <?php endif; endforeach; ?>
    <?php if (!$stream): ?><li style="opacity:0.5;">No activity yet for this contact.</li><?php endif; ?>
  </ul>
<?php
    }
}
?>

</body></html>
