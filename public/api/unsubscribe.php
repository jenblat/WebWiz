<?php
// /api/unsubscribe.php — HMAC-verified unsubscribe endpoint for nurture emails.
declare(strict_types=1);

require_once __DIR__ . '/_nurture.php';
require_once __DIR__ . '/../../private/webwiz_lib.php';

$db = ww_db();
ww_nurture_init_schema($db);

$cid = (int)($_GET['c'] ?? 0);
$sig = trim((string)($_GET['sig'] ?? ''));
$secret = ww_nurture_hmac_secret($db);

$ok = $cid > 0 && ww_nurture_verify_sig($cid, $sig, $secret);

$status_label = '';
$status_color = '';
if ($ok) {
    // Look up first so we can show the email back to the user.
    $st = $db->prepare("SELECT email, status FROM nurture_contacts WHERE id = ? LIMIT 1");
    $st->execute([$cid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        // Handle the Brevo one-click POST flow same as GET (List-Unsubscribe-Post=One-Click).
        if ($row['status'] !== 'unsubscribed') {
            ww_nurture_set_status($db, $cid, 'unsubscribed');
        }
        $status_label = $row['email'];
        $status_color = '#0A6B53';
    } else {
        $ok = false;
    }
}

http_response_code($ok ? 200 : 400);
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $ok ? 'Unsubscribed' : 'Unsubscribe link invalid' ?> · WebWiz</title>
<style>
  body{margin:0;background:#FFF8E7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#12184A;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;}
  .card{max-width:520px;width:100%;background:#fff;border:1px solid rgba(18,24,74,0.08);border-radius:18px;padding:48px 36px;text-align:center;}
  h1{font-family:'Nunito',-apple-system,sans-serif;font-weight:900;font-size:36px;letter-spacing:-0.02em;margin:0 0 12px;color:#12184A;}
  p{font-size:16px;line-height:1.6;margin:0 0 12px;color:#12184A;opacity:0.8;}
  .email{color:<?= $status_color ?: '#12184A' ?>;font-weight:700;}
  a{color:#12184A;}
  .meta{margin-top:24px;font-size:12px;opacity:0.55;}
</style>
</head>
<body>
<div class="card">
  <?php if ($ok): ?>
    <h1>You're unsubscribed.</h1>
    <p>We won't email <span class="email"><?= htmlspecialchars($status_label) ?></span> from the WebWiz nurture sequence again.</p>
    <p>If you ever want your website preview back, just reply to any past email or visit <a href="https://trywebwiz.com/try/">trywebwiz.com/try</a>.</p>
  <?php else: ?>
    <h1>Link invalid.</h1>
    <p>This unsubscribe link is missing or has been tampered with. If you keep getting emails you don't want, reply to one of them and we'll handle it personally.</p>
  <?php endif; ?>
  <div class="meta">WebWiz Studio · <a href="mailto:hello@trywebwiz.com">hello@trywebwiz.com</a></div>
</div>
</body>
</html>
