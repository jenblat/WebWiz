<?php
declare(strict_types=1);
require '/var/www/sites/trywebwiz/private/webwiz_lib.php';

session_start([
    'cookie_secure' => true,
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
]);

$secrets = ww_secrets();
$STRIPE_SECRET = $secrets['STRIPE_SECRET_KEY'] ?? '';

// ---------- Logout ----------
if (($_GET['action'] ?? '') === 'logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: /admin/?logged_out=1');
    exit;
}

// ---------- Login ----------
$login_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_email'])) {
    $em = strtolower(trim((string)$_POST['login_email']));
    $pw = (string)($_POST['login_password'] ?? '');
    $u  = ww_user_by_email($em);
    if ($u && password_verify($pw, $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        ww_db()->prepare('UPDATE users SET last_login_at = datetime("now") WHERE id = ?')->execute([$u['id']]);
        header('Location: /admin/');
        exit;
    } else {
        $login_error = 'Wrong email or password.';
        usleep(400000);
    }
}

$me = !empty($_SESSION['uid']) ? ww_user_by_id((int)$_SESSION['uid']) : null;
$logged_in = (bool)$me;
$is_admin  = $logged_in && $me['role'] === 'admin';

// ---------- Stripe REST helper ----------
function stripe_get(string $secret, string $path, array $query = []): ?array {
    $url = 'https://api.stripe.com/v1/' . ltrim($path, '/');
    if ($query) $url .= '?' . http_build_query($query);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $secret . ':',
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Stripe-Version: 2024-06-20'],
    ]);
    $r = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($r === false || $http >= 400) return null;
    return json_decode($r, true);
}

// ---------- Layout ----------
function shell_open(string $title, ?array $me = null, string $current = '', bool $is_admin = false) {
    $h = 'ww_h';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= $h($title) ?> · WebWiz admin</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<style>
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
  h1{font-family:var(--display);font-weight:900;font-size:34px;letter-spacing:-0.03em;margin-bottom:22px;}
  h2{font-family:var(--display);font-weight:900;font-size:20px;letter-spacing:-0.02em;margin:28px 0 12px;}
  .stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:14px;}
  @media(max-width:900px){.stat-grid{grid-template-columns:repeat(2,1fr);}}
  .stat{background:#fff;border:3px solid var(--navy);border-radius:16px;padding:18px;box-shadow:5px 5px 0 var(--yellow);}
  .stat .lbl{font-family:ui-monospace,monospace;font-size:11px;letter-spacing:0.16em;text-transform:uppercase;color:var(--navy);opacity:0.65;}
  .stat .val{font-family:var(--display);font-weight:900;font-size:32px;letter-spacing:-0.03em;color:var(--navy);margin-top:4px;line-height:1;}
  .stat .sub{font-size:12px;color:var(--navy);opacity:0.55;margin-top:6px;}
  table.t{width:100%;border-collapse:collapse;background:#fff;border:3px solid var(--navy);border-radius:14px;overflow:hidden;}
  table.t th{font-family:var(--display);font-weight:900;font-size:11px;letter-spacing:0.14em;text-transform:uppercase;text-align:left;background:var(--navy);color:var(--cream);padding:11px 14px;}
  table.t td{padding:10px 14px;border-top:1px solid #f0e8d0;font-size:14px;vertical-align:top;}
  table.t tr:nth-child(even) td{background:#fffaee;}
  .pill{display:inline-block;font-family:var(--display);font-weight:700;font-size:11px;letter-spacing:0.1em;text-transform:uppercase;padding:3px 10px;border-radius:999px;border:2px solid var(--navy);}
  .pill.ok{background:var(--teal);color:var(--navy);}
  .pill.warn{background:#ffd29c;color:var(--navy);}
  .pill.err{background:#ffb3b3;color:var(--navy);}
  .pill.muted{background:#fff;color:var(--navy);opacity:0.7;}
  .pill.admin{background:var(--yellow);color:var(--navy);}
  .pill.team_member{background:#fff;color:var(--navy);}
  .btn{font-family:var(--display);font-weight:900;font-size:13px;padding:8px 14px;border-radius:999px;background:var(--navy);color:var(--cream);text-decoration:none;border:0;cursor:pointer;display:inline-block;}
  .btn.ghost{background:transparent;color:var(--navy);border:2px solid var(--navy);}
  .btn.danger{background:#c62828;color:#fff;}
  form.action{display:inline;margin-right:6px;}
  .empty{padding:36px;text-align:center;color:var(--navy);opacity:0.55;}
  .form-card{background:#fff;border:3px solid var(--navy);border-radius:18px;padding:22px;box-shadow:8px 8px 0 var(--yellow);max-width:560px;margin-bottom:24px;}
  .form-card h3{font-family:var(--display);font-weight:900;font-size:16px;margin-bottom:12px;}
  .form-card label{display:block;font-family:var(--display);font-weight:900;font-size:11px;letter-spacing:0.14em;text-transform:uppercase;margin:10px 0 4px;}
  .form-card input, .form-card select{width:100%;padding:10px 12px;border:2px solid var(--navy);border-radius:10px;font-size:14px;font-family:var(--body);}
  .form-card .row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
  .info{background:var(--paper);border:2px solid var(--navy);padding:10px 12px;border-radius:10px;margin-bottom:14px;font-size:14px;}
  .err{background:#ffe5e5;border:2px solid #c62828;color:#7a1010;padding:10px 12px;border-radius:10px;margin-bottom:8px;font-size:14px;}
  .login-wrap{min-height:80vh;display:flex;align-items:center;justify-content:center;}
  .login{background:#fff;border:4px solid var(--navy);border-radius:24px;padding:36px;width:100%;max-width:400px;box-shadow:10px 10px 0 var(--yellow);}
  .login h1{font-size:26px;margin-bottom:6px;}
  .login p.sub{color:var(--navy);opacity:0.7;font-size:14px;margin-bottom:16px;}
  .login label{display:block;font-family:var(--display);font-weight:900;font-size:12px;letter-spacing:0.14em;text-transform:uppercase;margin:14px 0 6px;}
  .login input{width:100%;padding:13px 14px;border:3px solid var(--navy);border-radius:12px;font-size:15px;}
  .login button{width:100%;margin-top:18px;padding:14px;border:0;border-radius:999px;background:var(--navy);color:var(--cream);font-family:var(--display);font-weight:900;font-size:15px;cursor:pointer;}
</style>
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/favicon-16.png">
<link rel="icon" href="/favicon.ico">
<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
</head>
<body>
<?php if ($me): ?>
<header class="topbar">
  <div class="brand">
    <img src="https://i.imgur.com/7OdNLrM.png" alt="Wizzy">
    <span>WebWiz<span class="dot">.</span> <small>admin</small></span>
  </div>
  <nav>
    <a href="/admin/" class="<?= $current==='dash'?'on':'' ?>">Dashboard</a>
    <a href="/admin/?tab=stats" class="<?= $current==='stats'?'on':'' ?>">Stats</a>
    <a href="/admin/?tab=customers" class="<?= $current==='customers'?'on':'' ?>">Customers</a>
    <a href="/admin/?tab=sites" class="<?= $current==='sites'?'on':'' ?>">Sites</a>
    <a href="/admin/?tab=jobs" class="<?= $current==='jobs'?'on':'' ?>">Jobs</a>
    <a href="/admin/?tab=prospects" class="<?= $current==='prospects'?'on':'' ?>">Prospects</a>
    <?php if ($is_admin): ?>
      <a href="/admin/?tab=users" class="<?= $current==='users'?'on':'' ?>">Users</a>
    <?php endif; ?>
  </nav>
  <div class="me">
    <?= $h($me['email']) ?> · <span class="pill <?= $h($me['role']) ?>" style="font-size:9px;padding:2px 8px;"><?= $h($me['role']) ?></span>
    &nbsp; <a href="/admin/?action=logout" style="color:var(--yellow);font-family:var(--display);font-weight:700;font-size:12px;text-decoration:none;">Log out</a>
  </div>
</header>
<main>
<?php else: ?>
<main>
<?php endif; }

function shell_close() { echo '</main></body></html>'; }

// ---------- LOGIN PAGE ----------
if (!$logged_in) {
    shell_open('Login');
    ?>
    <div class="login-wrap"><div class="login">
      <h1>Sign in.</h1>
      <p class="sub">WebWiz admin</p>
      <?php if (!empty($_GET['logged_out'])): ?><div class="info">You're signed out.</div><?php endif; ?>
      <?php if ($login_error): ?><div class="err"><?= ww_h($login_error) ?></div><?php endif; ?>
      <form method="post" action="/admin/">
        <label for="login_email">Email</label>
        <input type="email" id="login_email" name="login_email" autocomplete="username" required>
        <label for="login_password">Password</label>
        <input type="password" id="login_password" name="login_password" autocomplete="current-password" required>
        <button type="submit">Sign in &rarr;</button>
      </form>
    </div></div>
    <?php
    shell_close();
    exit;
}

$tab = $_GET['tab'] ?? 'dash';

// ---------- USERS (admin-only) ----------
if ($tab === 'users') {
    if (!$is_admin) { http_response_code(403); shell_open('Forbidden', $me, 'users', $is_admin); echo '<h1>403</h1><p>Admins only.</p>'; shell_close(); exit; }

    $msg = '';
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['user_action'] ?? '';
        try {
            if ($action === 'create') {
                $email = strtolower(trim((string)($_POST['email'] ?? '')));
                $name  = trim((string)($_POST['name'] ?? ''));
                $role  = ($_POST['role'] ?? 'team_member') === 'admin' ? 'admin' : 'team_member';
                $pwd   = (string)($_POST['password'] ?? '');
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Bad email.');
                if (strlen($pwd) < 8) throw new Exception('Password must be 8+ characters.');
                if (!$name) throw new Exception('Name required.');
                ww_db()->prepare('INSERT INTO users (email, name, password_hash, role) VALUES (?, ?, ?, ?)')
                    ->execute([$email, $name, password_hash($pwd, PASSWORD_BCRYPT), $role]);
                $msg = 'Created ' . $email . '.';
            } elseif ($action === 'delete') {
                $id = (int)($_POST['user_id'] ?? 0);
                if ($id === (int)$me['id']) throw new Exception('Cannot delete yourself.');
                ww_db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
                $msg = 'User deleted.';
            } elseif ($action === 'role') {
                $id = (int)($_POST['user_id'] ?? 0);
                $role = ($_POST['role'] ?? 'team_member') === 'admin' ? 'admin' : 'team_member';
                if ($id === (int)$me['id'] && $role !== 'admin') throw new Exception('Cannot demote yourself.');
                ww_db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $id]);
                $msg = 'Role updated.';
            } elseif ($action === 'password') {
                $id = (int)($_POST['user_id'] ?? 0);
                $pwd = (string)($_POST['new_password'] ?? '');
                if (strlen($pwd) < 8) throw new Exception('Password must be 8+ characters.');
                ww_db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($pwd, PASSWORD_BCRYPT), $id]);
                $msg = 'Password updated.';
            }
        } catch (Throwable $e) { $err = $e->getMessage(); }
    }

    shell_open('Users', $me, 'users', $is_admin);
    echo '<h1>Users</h1>';
    if ($msg) echo '<div class="info">' . ww_h($msg) . '</div>';
    if ($err) echo '<div class="err">' . ww_h($err) . '</div>';
    ?>
    <div class="form-card">
      <h3>Add a user</h3>
      <form method="post">
        <input type="hidden" name="user_action" value="create">
        <div class="row">
          <div><label>Name</label><input type="text" name="name" required></div>
          <div><label>Email</label><input type="email" name="email" required></div>
        </div>
        <div class="row">
          <div><label>Role</label>
            <select name="role">
              <option value="team_member">Team member</option>
              <option value="admin">Admin</option>
            </select>
          </div>
          <div><label>Temp password (8+)</label><input type="text" name="password" required minlength="8"></div>
        </div>
        <div style="margin-top:16px;"><button class="btn" type="submit">Create user</button></div>
      </form>
    </div>
    <?php
    $rows = ww_db()->query('SELECT * FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    echo '<table class="t"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Last login</th><th>Created</th><th>Actions</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr>';
        echo '<td><strong>' . ww_h($r['name']) . '</strong></td>';
        echo '<td>' . ww_h($r['email']) . '</td>';
        echo '<td><span class="pill ' . ww_h($r['role']) . '">' . ww_h($r['role']) . '</span></td>';
        echo '<td>' . ww_h($r['last_login_at'] ?? '—') . '</td>';
        echo '<td>' . ww_h(date('M j, Y', strtotime($r['created_at']))) . '</td>';
        echo '<td>';
        $other = $r['role'] === 'admin' ? 'team_member' : 'admin';
        echo '<form class="action" method="post" onsubmit="return confirm(\'Change role to ' . $other . '?\');">'
            . '<input type="hidden" name="user_action" value="role"><input type="hidden" name="user_id" value="' . (int)$r['id'] . '"><input type="hidden" name="role" value="' . $other . '">'
            . '<button class="btn ghost" type="submit">Make ' . $other . '</button></form>';
        echo '<form class="action" method="post" onsubmit="this.elements.new_password.value=prompt(\'New password (8+ chars)\')||\'\';return this.elements.new_password.value.length>=8;">'
            . '<input type="hidden" name="user_action" value="password"><input type="hidden" name="user_id" value="' . (int)$r['id'] . '"><input type="hidden" name="new_password" value="">'
            . '<button class="btn ghost" type="submit">Reset password</button></form>';
        if ((int)$r['id'] !== (int)$me['id']) {
            echo '<form class="action" method="post" onsubmit="return confirm(\'Delete ' . ww_h($r['email']) . '?\');">'
                . '<input type="hidden" name="user_action" value="delete"><input type="hidden" name="user_id" value="' . (int)$r['id'] . '">'
                . '<button class="btn danger" type="submit">Delete</button></form>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table>';
    shell_close();
    exit;
}

// ---------- STATS ----------
if ($tab === 'stats') {
    shell_open('Stats', $me, 'stats', $is_admin);
    echo '<h1>Stats</h1>';

    $subs = stripe_get($STRIPE_SECRET, 'subscriptions', ['status' => 'all', 'limit' => 100]);
    $charges = stripe_get($STRIPE_SECRET, 'charges', ['limit' => 100]);

    $mrr_49 = 0; $mrr_99 = 0; $mrr_other = 0;
    $count_49 = 0; $count_99 = 0;
    $past_due = 0;
    foreach (($subs['data'] ?? []) as $s) {
        if (in_array($s['status'], ['active','trialing'], true)) {
            $monthly = 0;
            foreach (($s['items']['data'] ?? []) as $it) {
                $monthly += ($it['price']['unit_amount'] ?? 0) * ($it['quantity'] ?? 1);
            }
            if ($monthly === 4900) { $mrr_49 += 4900; $count_49++; }
            elseif ($monthly === 9900) { $mrr_99 += 9900; $count_99++; }
            else { $mrr_other += $monthly; }
        }
        if ($s['status'] === 'past_due') $past_due++;
    }
    $mrr_total = $mrr_49 + $mrr_99 + $mrr_other;
    $rev_total = 0; $one_time = 0;
    foreach (($charges['data'] ?? []) as $c) {
        if ($c['paid']) { $rev_total += $c['amount'] - $c['amount_refunded']; if (empty($c['invoice'])) $one_time++; }
    }
    $api_total = (float)(ww_db()->query('SELECT COALESCE(SUM(cost_usd), 0) FROM api_calls')->fetchColumn() ?? 0);
    $api_calls = (int)(ww_db()->query('SELECT COUNT(*) FROM api_calls')->fetchColumn() ?? 0);
    ?>
    <div class="stat-grid">
      <div class="stat"><div class="lbl">Total MRR</div><div class="val">$<?= number_format($mrr_total/100, 0) ?></div><div class="sub"><?= $count_49 + $count_99 ?> active</div></div>
      <div class="stat"><div class="lbl">$49 plan</div><div class="val"><?= $count_49 ?></div><div class="sub">$<?= number_format($mrr_49/100, 0) ?>/mo</div></div>
      <div class="stat"><div class="lbl">$99 plan</div><div class="val"><?= $count_99 ?></div><div class="sub">$<?= number_format($mrr_99/100, 0) ?>/mo</div></div>
      <div class="stat"><div class="lbl">Past due</div><div class="val"><?= $past_due ?></div><div class="sub">need attention</div></div>
    </div>
    <div class="stat-grid">
      <div class="stat"><div class="lbl">Lifetime revenue</div><div class="val">$<?= number_format($rev_total/100, 0) ?></div><div class="sub">last 100 payments</div></div>
      <div class="stat"><div class="lbl">One-time builds</div><div class="val"><?= $one_time ?></div><div class="sub">paid in full</div></div>
      <div class="stat"><div class="lbl">Claude API spend</div><div class="val">$<?= number_format($api_total, 2) ?></div><div class="sub"><?= $api_calls ?> calls</div></div>
      <div class="stat"><div class="lbl">Live sites</div><div class="val"><?= (int)ww_db()->query("SELECT COUNT(*) FROM live_sites WHERE status IN ('live','building')")->fetchColumn() ?></div><div class="sub">WebWiz hosted</div></div>
    </div>
    <p style="opacity:0.6;margin-top:18px;font-size:13px;">More charts and breakdowns coming once we have order data flowing.</p>
    <?php
    shell_close();
    exit;
}

// ---------- SITES ----------
if ($tab === 'sites') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['site_action'])) {
        $action  = $_POST['site_action'];
        $site_id = (int)($_POST['site_id'] ?? 0);
        $valid   = ['pause','resume','archive','restore'];
        if (in_array($action, $valid, true) && $site_id) {
            $new_status = ['pause'=>'paused','resume'=>'live','archive'=>'archived','restore'=>'live'][$action];
            ww_db()->prepare('UPDATE live_sites SET status = ? WHERE id = ?')->execute([$new_status, $site_id]);
            header('Location: /admin/?tab=sites&done=' . urlencode($action));
            exit;
        }
    }
    shell_open('Sites', $me, 'sites', $is_admin);
    echo '<h1>WebWiz client sites</h1>';
    if (!empty($_GET['done'])) echo '<div class="info">Site ' . ww_h($_GET['done']) . 'd.</div>';

    $rows = ww_db()->query('SELECT s.*, j.business_name AS biz, j.customer_email AS cust FROM live_sites s LEFT JOIN jobs j ON j.id = s.job_id ORDER BY s.id DESC')->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        echo '<div class="empty">No WebWiz client sites yet.<br><br>Sites appear here once a customer order finishes the AI generation flow and we provision their hosted site.<br>SeedSite system sites are intentionally excluded.</div>';
    } else {
        echo '<table class="t"><thead><tr><th>Site</th><th>Domain</th><th>Owner</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $st = $r['status'];
            $pill_cls = ['live'=>'ok','building'=>'warn','paused'=>'err','archived'=>'muted'][$st] ?? 'muted';
            echo '<tr>';
            echo '<td><strong>' . ww_h($r['biz'] ?? $r['slug']) . '</strong><br><small style="opacity:0.6;font-family:ui-monospace,monospace;">' . ww_h($r['slug']) . '</small></td>';
            echo '<td>' . ($r['domain'] ? '<a href="https://' . ww_h($r['domain']) . '" target="_blank">' . ww_h($r['domain']) . '</a>' : '<span class="pill muted">no domain</span>') . '</td>';
            echo '<td>' . ww_h($r['owner_email'] ?? $r['cust'] ?? '-') . '</td>';
            echo '<td><span class="pill ' . $pill_cls . '">' . ww_h($st) . '</span></td>';
            echo '<td>' . ww_h(date('M j, Y', strtotime($r['created_at']))) . '</td>';
            echo '<td>';
            $btns = [];
            if (in_array($st, ['live','building'], true)) $btns[] = ['pause','Pause','btn'];
            if ($st === 'paused')                          $btns[] = ['resume','Resume','btn'];
            if ($st !== 'archived')                        $btns[] = ['archive','Archive','btn ghost'];
            if ($st === 'archived')                        $btns[] = ['restore','Restore','btn'];
            foreach ($btns as $b) {
                echo '<form class="action" method="post"><input type="hidden" name="site_action" value="' . ww_h($b[0]) . '"><input type="hidden" name="site_id" value="' . (int)$r['id'] . '"><button class="' . $b[2] . '" type="submit" onclick="return confirm(\'' . $b[1] . ' ' . ww_h($r['slug']) . '?\')">' . $b[1] . '</button></form> ';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }
    shell_close();
    exit;
}

// ---------- CUSTOMERS ----------
if ($tab === 'customers') {
    shell_open('Customers', $me, 'customers', $is_admin);
    echo '<h1>Customers</h1>';
    $customers = stripe_get($STRIPE_SECRET, 'customers', ['limit' => 100]);
    $rows = $customers['data'] ?? [];
    if (!$rows) {
        echo '<div class="empty">No customers yet.</div>';
    } else {
        echo '<table class="t"><thead><tr><th>Customer</th><th>Email</th><th>Created</th><th>Subs</th><th>Total spent</th><th></th></tr></thead><tbody>';
        foreach ($rows as $c) {
            $subs = stripe_get($STRIPE_SECRET, 'subscriptions', ['customer' => $c['id'], 'status' => 'all', 'limit' => 5]);
            $sub_count = 0;
            foreach (($subs['data'] ?? []) as $s) if (in_array($s['status'], ['active','trialing','past_due'], true)) $sub_count++;
            $charges = stripe_get($STRIPE_SECRET, 'charges', ['customer' => $c['id'], 'limit' => 100]);
            $spent = 0;
            foreach (($charges['data'] ?? []) as $ch) if ($ch['paid']) $spent += $ch['amount'] - $ch['amount_refunded'];
            echo '<tr><td><strong>' . ww_h($c['name'] ?: ($c['metadata']['business_name'] ?? '—')) . '</strong></td>';
            echo '<td>' . ww_h($c['email'] ?? '—') . '</td>';
            echo '<td>' . ww_h(date('M j, Y', $c['created'])) . '</td>';
            echo '<td>' . ($sub_count ? '<span class="pill ok">' . $sub_count . ' active</span>' : '<span class="pill muted">none</span>') . '</td>';
            echo '<td><strong>$' . number_format($spent/100, 2) . '</strong></td>';
            echo '<td><a class="btn ghost" href="https://dashboard.stripe.com/customers/' . ww_h($c['id']) . '" target="_blank">Stripe &rarr;</a></td></tr>';
        }
        echo '</tbody></table>';
    }
    shell_close();
    exit;
}


// ---------- PROSPECTS (Quick-add + CSV upload + list) ----------
if ($tab === 'prospects') {
    // Chunked CSV upload receiver: the browser posts the file in <1MB pieces (php://input, governed by
    // post_max_size, NOT the 2M upload_max_filesize) so large Apollo lead lists upload reliably.
    if (($_GET['csv_chunk'] ?? '') === '1' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        $sid = preg_replace('/[^a-z0-9]/i', '', (string)($_GET['sid'] ?? ''));
        if (strlen($sid) < 8) { http_response_code(400); echo json_encode(['error' => 'bad sid']); exit; }
        $dir = '/var/www/sites/trywebwiz/data/csv_uploads';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        foreach (glob($dir . '/*.csv') ?: [] as $old) { if (@filemtime($old) < time() - 7200) @unlink($old); }
        $path = $dir . '/' . $sid . '.csv';
        $idx = (int)($_GET['idx'] ?? 0);
        $body = file_get_contents('php://input');
        if (file_put_contents($path, $body, $idx === 0 ? 0 : FILE_APPEND) === false) { http_response_code(500); echo json_encode(['error' => 'write failed']); exit; }
        echo json_encode(['ok' => true, 'total' => filesize($path)]); exit;
    }
    $msg=''; $err='';
    // Retry a failed (or any) job from the prospects table
    if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['prospect_action'] ?? '')==='retry') {
        $jid = (int)($_POST['job_id'] ?? 0);
        if ($jid) {
            $js = ww_db()->prepare("SELECT token FROM jobs WHERE id=?"); $js->execute([$jid]); $tok = (string)$js->fetchColumn();
            ww_db()->prepare("DELETE FROM previews WHERE job_id=?")->execute([$jid]);
            ww_db()->prepare("UPDATE jobs SET status='queued', error=NULL, started_at=NULL, completed_at=NULL, total_cost_cents=0, scheduled_for=datetime('now') WHERE id=?")->execute([$jid]);
            if ($tok && preg_match('/^[a-f0-9]+$/', $tok)) {
                $pd = '/var/www/sites/trywebwiz/public/preview/' . $tok;
                if (is_dir($pd)) {
                    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pd, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                    foreach ($it as $f) { $fp = $f->getPathname(); if ($f->isDir()) { @rmdir($fp); } else { @unlink($fp); } }
                    @rmdir($pd);
                }
            }
            header('Location: /admin/?tab=prospects&done=retry'); exit;
        }
    }
    $confirm = null; // staged-import summary, if a CSV was just parsed
    // Post/Redirect/Get: surface one-shot flash messages + the staged-import summary from the
    // session so refreshing the page never re-submits the upload/confirm forms.
    if (!empty($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }
    if (!empty($_SESSION['flash_err'])) { $err = $_SESSION['flash_err']; unset($_SESSION['flash_err']); }
    if (($_GET['staged'] ?? '') === '1' && !empty($_SESSION['pending_csv']) && !empty($_SESSION['pending_summary'])) {
        $confirm = $_SESSION['pending_summary'];
    }

    // ---- Export results CSV (preview links + showcase image) ----
    if (($_GET['export'] ?? '') === 'csv') {
        $idsParam = (string)($_GET['ids'] ?? '');
        $ids = array_values(array_filter(array_map('intval', explode(',', $idsParam)), fn($x) => $x > 0));
        $bexp = (int)($_GET['batch'] ?? 0);
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = ww_db()->prepare("SELECT p.business_name, p.name, p.first_name, p.last_name, p.email, p.current_url, j.status AS job_status, j.token AS job_token FROM prospects p LEFT JOIN jobs j ON j.id = (SELECT MAX(id) FROM jobs WHERE prospect_id = p.id) WHERE p.id IN ($ph) ORDER BY p.id DESC");
            $st->execute($ids);
            $erows = $st->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($bexp) {
            $st = ww_db()->prepare("SELECT p.business_name, p.name, p.first_name, p.last_name, p.email, p.current_url, j.status AS job_status, j.token AS job_token FROM jobs j JOIN prospects p ON p.id = j.prospect_id WHERE j.upload_batch_id = ? ORDER BY j.id");
            $st->execute([$bexp]);
            $erows = $st->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $erows = ww_db()->query("SELECT p.business_name, p.name, p.first_name, p.last_name, p.email, p.current_url, j.status AS job_status, j.token AS job_token FROM prospects p LEFT JOIN jobs j ON j.id = (SELECT MAX(id) FROM jobs WHERE prospect_id = p.id) ORDER BY p.id DESC")->fetchAll(PDO::FETCH_ASSOC);
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="webwiz-prospects-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['business_name','first_name','last_name','email','current_url','status','seed_site_website','showcase_image']);
        foreach ($erows as $r) {
            $st = $r['job_status'] ?? '';
            $preview = ''; $shot = '';
            if (!empty($r['job_token']) && in_array($st, ['ready','sent','picked'], true)) {
                $preview = 'https://trywebwiz.com/preview/' . $r['job_token'] . '/';
                $scf = '/var/www/sites/trywebwiz/public/preview/' . $r['job_token'] . '/showcase.jpg';
                $shot = is_file($scf) ? ('https://trywebwiz.com/preview/' . $r['job_token'] . '/showcase.jpg') : '';
            }
            $first = trim((string)($r['first_name'] ?? ''));
            $last  = trim((string)($r['last_name'] ?? ''));
            if ($first === '' && $last === '') {
                $full = trim((string)($r['name'] ?? ''));
                if ($full !== '') { $parts = preg_split('/\s+/', $full, 2); $first = $parts[0]; $last = $parts[1] ?? ''; }
            }
            fputcsv($out, [$r['business_name'], $first, $last, $r['email'], $r['current_url'], $st, $preview, $shot]);
        }
        fclose($out);
        exit;
    }

    // ---- CSV phase 2: user confirmed — create the staged sites ----
    if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['confirm_import'] ?? '')==='1' && !empty($_SESSION['pending_csv'])) {
        $staged = $_SESSION['pending_csv'];
        $label  = $_SESSION['pending_label'] ?? ('upload-' . date('Y-m-d_H:i'));
        unset($_SESSION['pending_csv'], $_SESSION['pending_label']);
        $db = ww_db();
        $ins_pros = $db->prepare("INSERT INTO prospects (email, name, business_name, current_url, source, first_name, last_name, title, email_status, industry, city, state, country, street, apollo_data) VALUES (?, ?, ?, ?, 'csv', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $ins_job  = $db->prepare("INSERT INTO jobs (type, prospect_id, customer_email, business_name, status, scheduled_for, token, generation_mode, upload_batch_id, item_status) VALUES ('outbound', ?, ?, ?, 'queued', datetime('now'), ?, 'batch', ?, 'queued')");
        $added = 0;
        $db->beginTransaction();
        // All CSV uploads generate via the Batch API (~50% cheaper, async). Group this upload as one batch row.
        $db->prepare("INSERT INTO upload_batches (label, source_tag, created_at, total_count, status, generation_mode) VALUES (?, 'csv', datetime('now'), ?, 'queued', 'batch')")->execute([$label, count($staged)]);
        $batch_id = (int)$db->lastInsertId();
        foreach ($staged as $r) {
            $raw = json_encode(array_filter([
                'first_name'=>$r['first'] ?? '', 'last_name'=>$r['last'] ?? '', 'title'=>$r['title'] ?? '',
                'email_status'=>$r['estat'] ?? '', 'industry'=>$r['industry'] ?? '', 'city'=>$r['city'] ?? '',
                'state'=>$r['state'] ?? '', 'country'=>$r['country'] ?? '', 'street'=>$r['street'] ?? '',
            ], fn($v) => $v !== '' && $v !== null));
            $ins_pros->execute([$r['email'], $r['name'], $r['biz'], $r['url'],
                $r['first'] ?? '', $r['last'] ?? '', $r['title'] ?? '', $r['estat'] ?? '', $r['industry'] ?? '',
                $r['city'] ?? '', $r['state'] ?? '', $r['country'] ?? '', $r['street'] ?? '', $raw]);
            $pid = (int)$db->lastInsertId();
            $token = bin2hex(random_bytes(12));
            $ins_job->execute([$pid, $r['email'], $r['biz'], $token, $batch_id]);
            $added++;
        }
        $db->commit();
        unset($_SESSION['pending_summary']);
        $_SESSION['flash_msg'] = "Queued $added sites for batch generation (Batch API, ~50% cheaper). They process in the background — check Batch history for progress and download results once a batch is done.";
        header('Location: /admin/?tab=prospects&imported=' . $added); exit;
    }

    // ---- CSV phase 1: file uploaded — parse, filter, estimate, stage for confirmation ----
    $csv_sid = preg_replace('/[^a-z0-9]/i', '', (string)($_POST['csv_sid'] ?? ''));
    $csv_chunked = ($_SERVER['REQUEST_METHOD']==='POST' && strlen($csv_sid) >= 8);
    if (($_SERVER['REQUEST_METHOD']==='POST' && !empty($_FILES['csv'])) || $csv_chunked) {
        $csv_tmp_to_unlink = null;
        try {
            if ($csv_chunked) {
                $tmp = '/var/www/sites/trywebwiz/data/csv_uploads/' . $csv_sid . '.csv';
                if (!is_file($tmp) || filesize($tmp) === 0) throw new Exception('Upload not found or empty — please re-select the file and try again.');
                $csv_tmp_to_unlink = $tmp;
                $_SESSION['pending_label'] = trim((string)($_POST['csv_name'] ?? '')) ?: ('upload-' . date('Y-m-d_H:i'));
            } else {
                $tmp = $_FILES['csv']['tmp_name'] ?? '';
                if (!$tmp || !is_uploaded_file($tmp)) throw new Exception('No file uploaded.');
                $_SESSION['pending_label'] = trim((string)($_FILES['csv']['name'] ?? '')) ?: ('upload-' . date('Y-m-d_H:i'));
            }
            $sig = (string)@file_get_contents($tmp, false, null, 0, 4);
            if (strncmp($sig, "PK\x03\x04", 4) === 0) throw new Exception('That looks like an Excel .xlsx file, not a CSV. The uploader normally auto-converts Excel to CSV — please re-select the file and try again. (Or in Excel/Sheets: File → Save As → CSV, then upload that.)');
            $h = fopen($tmp, 'r');
            if (!$h) throw new Exception('Cannot read file.');
            $header = fgetcsv($h);
            if (!$header) throw new Exception('The file appears to be empty.');
            // Flexible header mapping: works with Apollo + arbitrary exports. Normalize each header
            // (lowercase, strip non-alphanumerics) and match against alias lists, so we never require
            // exact column names. Only a company name + a website are mandatory; everything else enriches.
            $norm = function($s){ return preg_replace('~[^a-z0-9]~', '', strtolower(trim((string)$s))); };
            $hn = array_map($norm, $header);
            $find = function(array $aliases, $contains = false) use ($hn, $norm) {
                foreach ($aliases as $a) { $a = $norm($a); $i = array_search($a, $hn, true); if ($i !== false) return $i; }
                if ($contains) { foreach ($aliases as $a) { $a = $norm($a); if ($a === '') continue; foreach ($hn as $i => $h) { if ($h !== '' && (strpos($h, $a) !== false || strpos($a, $h) !== false)) return $i; } } }
                return null;
            };
            $i_biz    = $find(['company','companyname','businessname','business','organizationname','organization','accountname','account'], true);
            $i_url    = $find(['website','companywebsite','websiteurl','url','currenturl','domain','companydomain','primarydomain'], true);
            $i_email  = $find(['email','emailaddress','workemail','contactemail','primaryemail']);
            $i_estat  = $find(['emailstatus','emailverified','emailverification','verificationstatus','emailconfidence','emailvalidationstatus']);
            $i_first  = $find(['firstname','first','fname','givenname']);
            $i_last   = $find(['lastname','last','lname','surname','familyname']);
            $i_name   = $find(['name','fullname','contactname','personname']);
            $i_title  = $find(['title','jobtitle','position','role','headline']);
            $i_ind    = $find(['industry','companyindustry','sector','vertical']);
            $i_city   = $find(['companycity','city','contactcity']);
            $i_state  = $find(['companystate','state','region','province']);
            $i_country= $find(['companycountry','country']);
            $i_street = $find(['companyaddress','companystreet','streetaddress','street','address','addressline1']);
            $i_result = $find(['result','validity','validationresult','validationstatus']); // optional validity flag (Apollo-style)
            if ($i_biz === null || $i_url === null) {
                $seen = implode(', ', array_filter(array_map('trim', $header)));
                $seen = trim(mb_substr(preg_replace('/[^\x20-\x7E]/', ' ', $seen), 0, 200));
                throw new Exception('Could not find a company-name column and a website/URL column. I matched flexibly against names like Company / Business Name and Website / URL / Domain. Columns I saw: ' . ($seen ?: '(none)') . '. The file needs at least a company name and a website.');
            }
            $url_key = function($u) {
                $u = strtolower(trim((string)$u));
                $u = preg_replace('~^https?://~', '', $u);
                $u = preg_replace('~^www\.~', '', $u);
                return rtrim($u, '/');
            };
            $db = ww_db();
            $existing = [];
            foreach ($db->query("SELECT current_url FROM prospects") as $er) {
                $k = $url_key((string)$er['current_url']);
                if ($k) $existing[$k] = true;
            }
            $gv = function($row, $i){ return $i !== null ? trim((string)($row[$i] ?? '')) : ''; };
            $valid = []; $skipped = 0; $dupes = 0; $invalid = 0; $seen_csv = [];
            while (($row = fgetcsv($h)) !== false) {
                $biz    = $gv($row, $i_biz);
                $url    = $gv($row, $i_url);
                $result = strtolower($gv($row, $i_result));
                if ($result === 'invalid') { $invalid++; continue; }
                if (!$biz || !$url) { $skipped++; continue; }
                $email = $gv($row, $i_email);
                if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
                if (!preg_match('~^https?://~i', $url)) $url = 'https://' . $url;
                $k = $url_key($url);
                if ($k && (isset($existing[$k]) || isset($seen_csv[$k]))) { $dupes++; continue; }
                if ($k) $seen_csv[$k] = true;
                $first = $gv($row, $i_first); $last = $gv($row, $i_last);
                $name  = $gv($row, $i_name); if (!$name) $name = trim($first . ' ' . $last);
                $valid[] = [
                    'email'=>$email, 'name'=>$name, 'biz'=>$biz, 'url'=>$url,
                    'first'=>$first, 'last'=>$last, 'title'=>$gv($row, $i_title),
                    'estat'=>$gv($row, $i_estat), 'industry'=>$gv($row, $i_ind),
                    'city'=>$gv($row, $i_city), 'state'=>$gv($row, $i_state),
                    'country'=>$gv($row, $i_country), 'street'=>$gv($row, $i_street),
                ];
                if (count($valid) >= 10000) break; // staging safety cap (batch path handles larger lists)
            }
            fclose($h);
            if ($csv_tmp_to_unlink) @unlink($csv_tmp_to_unlink);
            if (!$valid) {
                throw new Exception('No new sites to create. ' . ($dupes ? "$dupes already in the system. " : '') . ($invalid ? "$invalid marked invalid. " : '') . ($skipped ? "$skipped incomplete rows." : ''));
            }
            $_SESSION['pending_csv'] = $valid;
            // CSV uploads generate via the Batch API: parallel + ~50% cheaper than the sync path.
            // Estimate cost from completed BATCH jobs; fall back to ~half the sync average, then a default.
            $bavg = ww_db()->query("SELECT AVG(total_cost_cents) FROM (SELECT total_cost_cents FROM jobs WHERE generation_mode='batch' AND status IN ('ready','sent','picked') AND total_cost_cents > 0 ORDER BY id DESC LIMIT 100)")->fetchColumn();
            if (!$bavg) {
                $anyavg = ww_db()->query("SELECT AVG(total_cost_cents) FROM (SELECT total_cost_cents FROM jobs WHERE status IN ('ready','sent','picked') AND total_cost_cents > 0 ORDER BY id DESC LIMIT 50)")->fetchColumn();
                $bavg = $anyavg ? ((float)$anyavg) * 0.5 : 30.0; // cents
            }
            $est_per = round(((float)$bavg) / 100, 2);
            // Time: batch generation runs in PARALLEL (no per-site wait). The practical limiter is
            // sequential site-scraping on the server; ~8s wall/site incl. the cron build budget.
            $batch_sec_per = (float)(ww_db()->query("SELECT value FROM settings WHERE key='est_batch_sec_per_site'")->fetchColumn() ?: 8);
            $_SESSION['pending_summary'] = [
                'count'    => count($valid),
                'dupes'    => $dupes,
                'invalid'  => $invalid,
                'skipped'  => $skipped,
                'est'      => count($valid) * $est_per,
                'est_per'  => $est_per,
                'batch'    => true,
                'min_total'=> (count($valid) * $batch_sec_per) / 60.0,
            ];
            header('Location: /admin/?tab=prospects&staged=1'); exit;
        } catch (Throwable $e) { $_SESSION['flash_err'] = (trim(preg_replace('/[\x00-\x1F]+/', ' ', $e->getMessage())) ?: 'Upload failed — please check the file and try again.'); header('Location: /admin/?tab=prospects&uperr=1'); exit; }
    }
    $secrets_check = ww_secrets();
    $has_places_key = !empty($secrets_check['GOOGLE_PLACES_API_KEY']);

    shell_open('Prospects', $me, 'prospects', $is_admin);
    echo '<h1>Prospects</h1>';
    if (($_GET['done'] ?? '') === 'retry') echo '<div class="info">Re-queued &mdash; it will regenerate within a minute.</div>';
    if ($msg) echo '<div class="info">' . ww_h($msg) . '</div>';
    if ($err) echo '<div class="err">' . ww_h($err) . '</div>';

    // Confirmation card after a CSV upload (estimate + confirm)
    if (!empty($confirm)) {
        $c = $confirm;
        $n = (int)$c['count'];
        echo '<div class="form-card" style="max-width:none;border-color:var(--teal);box-shadow:8px 8px 0 var(--teal);margin-bottom:24px;">';
        echo '<h3>Confirm import</h3>';
        echo '<p style="font-size:15px;margin-bottom:6px;">This will create <strong>' . $n . ' new site' . ($n === 1 ? '' : 's') . '</strong>.</p>';
        echo '<p style="font-size:14px;opacity:0.85;margin-bottom:4px;">Estimated AI cost: <strong>~$' . number_format((float)$c['est'], 2) . '</strong> (' . $n . ' &times; ~$' . number_format((float)$c['est_per'], 2) . '/site via the <strong>Batch API</strong> &mdash; ~50% cheaper than real-time).</p>';
        $mt = (float)($c['min_total'] ?? 0);
        $time_str = $mt >= 60 ? (number_format($mt/60, 1) . ' hours') : (round($mt) . ' min');
        echo '<p style="font-size:14px;opacity:0.85;margin-bottom:4px;">&#9201; Generated in the <strong>background, in parallel</strong> (not one-at-a-time). Generation itself is fast; the limiter is scraping each site, so expect roughly <strong>' . $time_str . '</strong> end-to-end for all ' . $n . ' on the current server.</p>';
        $notes = [];
        if ($c['dupes'])   $notes[] = $c['dupes'] . ' already in the system';
        if ($c['invalid']) $notes[] = $c['invalid'] . ' marked invalid';
        if ($c['skipped']) $notes[] = $c['skipped'] . ' incomplete rows';
        if ($notes) echo '<p style="font-size:13px;opacity:0.65;">Skipping: ' . ww_h(implode(', ', $notes)) . '.</p>';
        echo '<div style="margin-top:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">';
        echo '<form method="post" style="display:inline;"><input type="hidden" name="confirm_import" value="1"><button class="btn" type="submit">Confirm &amp; create ' . $n . ' site' . ($n === 1 ? '' : 's') . ' &rarr;</button></form>';
        echo '<a class="btn ghost" href="/admin/?tab=prospects">Cancel</a>';
        echo '</div></div>';
    }

    // ---- Batch history (buffered here, rendered BELOW the add forms) ----
    ob_start();
    $batches = ww_db()->query("SELECT * FROM upload_batches ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    $bcounts = [];
    foreach (ww_db()->query("SELECT upload_batch_id uid, COALESCE(item_status,'queued') st, COUNT(*) c FROM jobs WHERE upload_batch_id IS NOT NULL GROUP BY uid, st") as $cr) {
        $bcounts[(int)$cr['uid']][$cr['st']] = (int)$cr['c'];
    }
    $active_batches = false;
    $bpill = ['queued'=>'muted','generating'=>'warn','qa'=>'warn','done'=>'ok','failed'=>'err'];
    echo '<div style="margin-bottom:26px;">';
    echo '<h3 style="font-family:var(--display);font-weight:900;font-size:18px;margin:0 0 10px;">Batch history</h3>';
    if (!$batches) {
        echo '<div class="empty">No batch uploads yet. Upload a CSV above and it\'ll appear here while it generates.</div>';
    } else {
        echo '<table class="t"><thead><tr><th>List</th><th>Uploaded</th><th>Sites</th><th>Status</th><th>Progress</th><th>Results</th></tr></thead><tbody>';
        foreach ($batches as $b) {
            $bid = (int)$b['id']; $bs = $b['status'] ?: 'queued';
            if (in_array($bs, ['queued','generating','qa'], true)) $active_batches = true;
            $c = $bcounts[$bid] ?? [];
            $done = $c['done'] ?? 0; $fail = $c['failed'] ?? 0;
            $gen = ($c['generating'] ?? 0) + ($c['scraped'] ?? 0); $q = $c['queued'] ?? 0;
            $prog = [];
            if ($done) $prog[] = "$done done";
            if ($gen)  $prog[] = "$gen generating";
            if ($q)    $prog[] = "$q queued";
            if ($fail) $prog[] = "$fail failed";
            echo '<tr>';
            echo '<td><strong>' . ww_h($b['label']) . '</strong></td>';
            echo '<td>' . ww_h(date('M j, g:ia', strtotime($b['created_at']))) . '</td>';
            echo '<td>' . (int)$b['total_count'] . '</td>';
            echo '<td><span class="pill ' . ($bpill[$bs] ?? 'muted') . '">' . ww_h($bs) . '</span></td>';
            echo '<td style="font-size:13px;">' . ($prog ? ww_h(implode(', ', $prog)) : '&mdash;') . '</td>';
            echo '<td>';
            if ($bs === 'done') echo '<a class="btn ghost" style="padding:6px 12px;font-size:13px;" href="/admin/?tab=prospects&amp;export=csv&amp;batch=' . $bid . '">&darr; Download CSV</a>';
            else echo '<span style="font-size:12px;opacity:0.55;">generating&hellip;</span>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p style="font-size:12px;opacity:0.6;margin-top:8px;">Results CSV (business name, current site, status, preview link &amp; showcase image) unlocks per batch once it\'s done.</p>';
    }
    echo '</div>';
    if ($active_batches) echo '<script>setTimeout(function(){location.reload();},5000);</script>';
    $batch_history_html = ob_get_clean();
    ?>
    <style>
      .qa-card{background:#fff;border:3px solid var(--navy);border-radius:18px;padding:22px;box-shadow:8px 8px 0 var(--yellow);max-width:780px;margin-bottom:24px;position:relative;}
      .qa-card h3{font-family:var(--display);font-weight:900;font-size:16px;margin-bottom:6px;}
      .qa-card .sub{font-size:13px;color:var(--navy);opacity:0.7;margin-bottom:14px;}
      .qa-card label{display:block;font-family:var(--display);font-weight:900;font-size:11px;letter-spacing:0.14em;text-transform:uppercase;margin:10px 0 4px;color:var(--navy);}
      .qa-card input{width:100%;padding:10px 12px;border:2px solid var(--navy);border-radius:10px;font-size:14px;font-family:var(--body);background:#fff;}
      .qa-card input:focus{outline:none;background:#FFF8E7;}
      .qa-card .row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
      .qa-card .ac-wrap{position:relative;}
      .qa-card .ac-list{position:absolute;left:0;right:0;top:100%;margin-top:4px;background:#fff;border:2px solid var(--navy);border-radius:12px;max-height:340px;overflow-y:auto;z-index:50;box-shadow:6px 6px 0 var(--navy);display:none;}
      .qa-card .ac-list.show{display:block;}
      .qa-card .ac-item{padding:10px 14px;cursor:pointer;border-bottom:1px solid #f0e8d0;}
      .qa-card .ac-item:last-child{border-bottom:0;}
      .qa-card .ac-item:hover, .qa-card .ac-item.kbd{background:#FFF8E7;}
      .qa-card .ac-item .nm{font-family:var(--display);font-weight:900;font-size:14px;}
      .qa-card .ac-item .ad{font-size:12px;opacity:0.65;margin-top:2px;}
      .qa-card .ac-item .urls{font-size:11px;font-family:ui-monospace,monospace;opacity:0.55;margin-top:3px;}
      .qa-card .picked-meta{background:var(--paper);border:2px solid var(--navy);border-radius:10px;padding:10px 12px;margin-top:8px;font-size:13px;display:none;}
      .qa-card .picked-meta.show{display:block;}
      .qa-card .qa-msg{margin-top:12px;font-size:13px;}
      .qa-card .qa-msg.err{color:#a01;}
      .qa-card .qa-msg.ok{color:#0a5;}
      .qa-card .nokey-note{background:#fff5d6;border:2px dashed var(--navy);padding:10px 12px;border-radius:10px;font-size:12px;color:var(--navy);margin-bottom:14px;}
    </style>

    <style>
      .prospect-cols{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(0,1fr);gap:22px;align-items:stretch;margin-bottom:24px;}
      @media(max-width:1000px){.prospect-cols{grid-template-columns:1fr;}}
      .prospect-cols .qa-card,.prospect-cols .form-card{max-width:none;margin-bottom:0;}
      .prospect-cols .form-card{display:flex;flex-direction:column;}
      #csvDrop{flex:1;min-height:150px;border:2px dashed var(--navy);border-radius:12px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:24px;cursor:pointer;background:#fff;transition:background .15s,border-color .15s;}
      #csvDrop:hover,#csvDrop.drag{background:#FFF8E7;border-color:var(--teal);}
    </style>
    <div class="prospect-cols">
    <div class="qa-card">
      <h3>Quick add a single prospect</h3>
      <p class="sub">Type a business name to look it up on Google. Pick a match and we'll fill the website automatically. The generation job runs immediately.</p>

      <?php if (!$has_places_key): ?>
      <div class="nokey-note">
        <strong>Google Places not configured.</strong> Add <code>GOOGLE_PLACES_API_KEY</code> to <code>/var/www/sites/trywebwiz/secrets.php</code> to enable autocomplete. For now you can still add prospects manually by filling all fields below.
      </div>
      <?php endif; ?>

      <div class="ac-wrap">
        <label for="qa-name">Business name<?= $has_places_key ? ' (search Google)' : '' ?></label>
        <input type="text" id="qa-name" autocomplete="off" placeholder="<?= $has_places_key ? 'Try: Joe&apos;s Pizza, Le Bernardin, La Esquina NYC&hellip;' : 'BusySeed, Joe&apos;s Pizza, etc.' ?>">
        <div class="ac-list" id="qa-ac"></div>
      </div>

      <div class="picked-meta" id="qa-picked">
        <div id="qa-picked-address" style="font-weight:700;"></div>
        <div id="qa-picked-phone" style="opacity:0.7;margin-top:2px;"></div>
      </div>

      <label for="qa-url">Current website</label>
      <input type="url" id="qa-url" placeholder="https://example.com">

      <div class="row">
        <div>
          <label for="qa-contact">Contact name (optional)</label>
          <input type="text" id="qa-contact" placeholder="Owner&apos;s name if known">
        </div>
        <div>
          <label for="qa-email">Contact email (optional)</label>
          <input type="email" id="qa-email" placeholder="hello@business.com">
        </div>
      </div>

      <div class="row">
        <div>
          <label for="qa-industry">Industry (optional)</label>
          <input type="text" id="qa-industry" placeholder="Restaurant, law firm, etc.">
        </div>
        <div>
          <label for="qa-phone">Phone (optional)</label>
          <input type="text" id="qa-phone">
        </div>
      </div>

      <div style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <button class="btn" id="qa-submit" type="button">Add &amp; queue generation &rarr;</button>
        <button class="btn ghost" id="qa-clear" type="button">Clear</button>
        <span class="qa-msg" id="qa-msg"></span>
      </div>
    </div>

    <script>
    (function() {
      var nameEl   = document.getElementById('qa-name');
      var urlEl    = document.getElementById('qa-url');
      var contactEl= document.getElementById('qa-contact');
      var emailEl  = document.getElementById('qa-email');
      var indEl    = document.getElementById('qa-industry');
      var phoneEl  = document.getElementById('qa-phone');
      var hiddenPlaceId = '';
      var pickedAddress = '';
      var pickedMeta   = document.getElementById('qa-picked');
      var pickedAddr   = document.getElementById('qa-picked-address');
      var pickedPhone  = document.getElementById('qa-picked-phone');
      var ac           = document.getElementById('qa-ac');
      var msg          = document.getElementById('qa-msg');
      var submitBtn    = document.getElementById('qa-submit');
      var clearBtn     = document.getElementById('qa-clear');
      var hasKey       = <?= $has_places_key ? 'true' : 'false' ?>;
      var debounceId   = null;
      var kbdIndex     = -1;

      function hideAc() { ac.classList.remove('show'); ac.innerHTML = ''; kbdIndex = -1; }
      function showMsg(text, kind) {
        msg.innerHTML = text || '';
        msg.className = 'qa-msg' + (kind ? ' ' + kind : '');
      }
      function setIndustry(types, primary) {
        if (indEl.value.trim()) return;
        if (primary) { indEl.value = primary; return; }
        var goodTypes = (types || []).map(function(t){ return t.replace(/_/g, ' '); });
        if (goodTypes.length) indEl.value = goodTypes[0];
      }
      function pickResult(r) {
        nameEl.value = r.name || nameEl.value;
        if (r.website) urlEl.value = r.website;
        if (r.phone) phoneEl.value = r.phone;
        setIndustry(r.types, r.primary_type);
        hiddenPlaceId = r.place_id || '';
        pickedAddress = r.address || '';
        pickedAddr.textContent = pickedAddress;
        pickedPhone.textContent = r.phone || '';
        pickedMeta.classList.add('show');
        hideAc();
      }
      function escapeHtml(s){ return (s||'').replace(/[&<>"']/g, function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
      function renderResults(results) {
        ac.innerHTML = '';
        if (!results || !results.length) { hideAc(); return; }
        results.forEach(function(r, idx) {
          var item = document.createElement('div');
          item.className = 'ac-item';
          item.dataset.index = idx;
          item.innerHTML = '<div class="nm">' + escapeHtml(r.name) + '</div>' +
                           '<div class="ad">' + escapeHtml(r.address || '') + '</div>' +
                           (r.website ? '<div class="urls">' + escapeHtml(r.website) + '</div>' : '<div class="urls" style="color:#a01;">No website on Google</div>');
          item.addEventListener('mousedown', function(e) { e.preventDefault(); pickResult(r); });
          ac.appendChild(item);
        });
        ac.classList.add('show');
        kbdIndex = -1;
      }

      function search(q) {
        if (!hasKey) return;
        if (!q || q.length < 2) { hideAc(); return; }
        fetch('/api/places_search.php?q=' + encodeURIComponent(q))
          .then(function(r){ return r.json(); })
          .then(function(j){
            if (j.error) { showMsg('Places: ' + escapeHtml(j.error), 'err'); hideAc(); return; }
            renderResults(j.results || []);
          })
          .catch(function(e){ showMsg('Places fetch failed: ' + escapeHtml(e.message), 'err'); hideAc(); });
      }

      nameEl.addEventListener('input', function() {
        clearTimeout(debounceId);
        var q = nameEl.value.trim();
        debounceId = setTimeout(function(){ search(q); }, 280);
      });
      nameEl.addEventListener('blur', function() { setTimeout(hideAc, 200); });
      nameEl.addEventListener('keydown', function(e) {
        var items = ac.querySelectorAll('.ac-item');
        if (!items.length) return;
        if (e.key === 'ArrowDown') {
          e.preventDefault();
          kbdIndex = (kbdIndex + 1) % items.length;
          items.forEach(function(it, i){ it.classList.toggle('kbd', i === kbdIndex); });
        } else if (e.key === 'ArrowUp') {
          e.preventDefault();
          kbdIndex = (kbdIndex - 1 + items.length) % items.length;
          items.forEach(function(it, i){ it.classList.toggle('kbd', i === kbdIndex); });
        } else if (e.key === 'Enter') {
          if (kbdIndex >= 0 && kbdIndex < items.length) {
            e.preventDefault();
            items[kbdIndex].dispatchEvent(new MouseEvent('mousedown'));
          }
        } else if (e.key === 'Escape') {
          hideAc();
        }
      });

      clearBtn.addEventListener('click', function() {
        [nameEl, urlEl, contactEl, emailEl, indEl, phoneEl].forEach(function(el){ el.value = ''; });
        pickedMeta.classList.remove('show'); pickedAddr.textContent = ''; pickedPhone.textContent = '';
        hiddenPlaceId = ''; pickedAddress = ''; showMsg('', '');
        nameEl.focus();
      });

      submitBtn.addEventListener('click', function() {
        showMsg('', '');
        var biz = nameEl.value.trim();
        var url = urlEl.value.trim();
        if (!biz) { showMsg('Need a business name.', 'err'); return; }
        if (!url) { showMsg('Need a website URL — Google didn&apos;t return one. Add it manually.', 'err'); return; }
        submitBtn.disabled = true; submitBtn.style.opacity = '0.6';
        showMsg('Saving&hellip;', '');
        fetch('/api/prospect_add.php', {
          method: 'POST',
          headers: {'content-type':'application/json'},
          body: JSON.stringify({
            business_name: biz,
            current_url:   url,
            contact_name:  contactEl.value.trim(),
            email:         emailEl.value.trim(),
            phone:         phoneEl.value.trim(),
            address:       pickedAddress,
            industry:      indEl.value.trim(),
            place_id:      hiddenPlaceId,
          }),
        })
        .then(function(r){ return r.json(); })
        .then(function(j){
          submitBtn.disabled = false; submitBtn.style.opacity = '1';
          if (j.error) { showMsg(escapeHtml(j.error), 'err'); return; }
          showMsg('Added! Reloading&hellip;', 'ok');
          setTimeout(function(){ window.location.reload(); }, 700);
        })
        .catch(function(e){
          submitBtn.disabled = false; submitBtn.style.opacity = '1';
          showMsg('Save failed: ' + escapeHtml(e.message), 'err');
        });
      });
    })();
    </script>

    <div class="form-card">
      <h3>Upload prospect CSV</h3>
      <p style="font-size:13px;color:var(--navy);opacity:0.7;margin-bottom:10px;">Drop in an Apollo (or any) export &mdash; columns are auto-detected, so headers don't need exact names. Only a <strong>company name</strong> and a <strong>website/URL</strong> are required; we also capture first/last name, title, email + verification status, industry, and city/state/country/street when present. Rows flagged <code>result=invalid</code> and sites already in the system are skipped. You'll see a count + cost/time estimate to confirm before anything is created.</p>
      <form id="csvForm" method="post" style="display:flex;flex-direction:column;flex:1;">
        <label>CSV file</label>
        <div id="csvDrop">
          <div style="font-family:var(--display);font-weight:900;font-size:15px;">Drop your CSV or Excel file here</div>
          <div style="font-size:13px;opacity:0.65;margin-top:6px;">or click to choose &mdash; .csv, .xlsx or .xls</div>
          <div id="csvFileName" style="font-size:13px;margin-top:12px;font-weight:700;color:var(--navy);word-break:break-all;"></div>
        </div>
        <input type="file" id="csvFile" accept=".csv,.xlsx,.xls,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" style="display:none;">
        <input type="hidden" name="csv_sid" id="csvSid">
        <input type="hidden" name="csv_name" id="csvName">
        <div style="margin-top:14px;"><button class="btn" type="submit" id="csvBtn">Import &amp; queue generation &rarr;</button> <span id="csvProg" style="font-size:13px;opacity:0.75;margin-left:8px;"></span></div>
      </form>
      <script>(function(){
        var form=document.getElementById('csvForm'),file=document.getElementById('csvFile'),btn=document.getElementById('csvBtn'),prog=document.getElementById('csvProg'),sidEl=document.getElementById('csvSid'),nameEl=document.getElementById('csvName'),drop=document.getElementById('csvDrop'),fnEl=document.getElementById('csvFileName');
        if(!form) return;
        var chosen=null;
        function setFile(f){ chosen=f; fnEl.textContent=f?('Selected: '+f.name):''; if(prog)prog.textContent=''; }
        drop.addEventListener('click',function(){ file.click(); });
        file.addEventListener('change',function(){ if(file.files[0]) setFile(file.files[0]); });
        ['dragenter','dragover'].forEach(function(ev){ drop.addEventListener(ev,function(e){ e.preventDefault(); drop.classList.add('drag'); }); });
        ['dragleave','drop'].forEach(function(ev){ drop.addEventListener(ev,function(e){ e.preventDefault(); drop.classList.remove('drag'); }); });
        drop.addEventListener('drop',function(e){ var f=e.dataTransfer&&e.dataTransfer.files&&e.dataTransfer.files[0]; if(f) setFile(f); });
        function ww_loadXLSX(){ return new Promise(function(res,rej){ if(window.XLSX) return res(); var s=document.createElement('script'); s.src='https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js'; s.onload=function(){res();}; s.onerror=function(){rej(new Error('could not load the Excel reader'));}; document.head.appendChild(s); }); }
        async function ww_toCsv(f){ var ext=(f.name.split('.').pop()||'').toLowerCase(); if(ext!=='xlsx'&&ext!=='xls') return f; prog.textContent='Converting Excel to CSV…'; await ww_loadXLSX(); var buf=await f.arrayBuffer(); var wb=XLSX.read(new Uint8Array(buf),{type:'array'}); var ws=wb.Sheets[wb.SheetNames[0]]; var csv=XLSX.utils.sheet_to_csv(ws); return new File([csv], f.name.replace(/\.(xlsx|xls)$/i,'.csv'), {type:'text/csv'}); }
        form.addEventListener('submit',async function(e){
          if(sidEl.value) return; // chunks already uploaded -> let the normal POST go through
          e.preventDefault();
          var f=chosen||file.files[0];
          if(!f){prog.textContent='Choose or drop a CSV/Excel file first.';return;}
          btn.disabled=true;
          try{ f=await ww_toCsv(f); }catch(cv){ prog.textContent='Could not read that file: '+cv.message; btn.disabled=false; return; }
          var sid=(Date.now().toString(36)+Math.random().toString(36).slice(2,12)).replace(/[^a-z0-9]/g,'');
          var CHUNK=900*1024, total=Math.max(1,Math.ceil(f.size/CHUNK));
          try{
            for(var i=0;i<total;i++){
              prog.textContent='Uploading '+(i+1)+' / '+total+'…';
              var blob=f.slice(i*CHUNK,(i+1)*CHUNK);
              var r=await fetch('/admin/?tab=prospects&csv_chunk=1&sid='+sid+'&idx='+i,{method:'POST',body:blob,headers:{'Content-Type':'application/octet-stream'}});
              var j=await r.json().catch(function(){return {};});
              if(!r.ok||j.error) throw new Error(j.error||('chunk '+(i+1)+' failed'));
            }
            prog.textContent='Processing…';
            sidEl.value=sid; nameEl.value=f.name;
            form.submit();
          }catch(err){ prog.textContent='Upload failed: '+err.message; btn.disabled=false; }
        });
      })();</script>
    </div>
    </div><!--/prospect-cols-->
    <?php
    echo $batch_history_html ?? '';
    $q = trim((string)($_GET['q'] ?? ''));
    $sort = (($_GET['sort'] ?? 'created_desc') === 'created_asc') ? 'created_asc' : 'created_desc';
    $order = $sort === 'created_asc' ? 'ASC' : 'DESC';
    $pwhere = ''; $pparams = [];
    if ($q !== '') {
        $pwhere = "WHERE (p.business_name LIKE ? OR p.name LIKE ? OR p.email LIKE ? OR p.current_url LIKE ?)";
        $like = '%' . $q . '%'; $pparams = [$like, $like, $like, $like];
    }
    $psql = "SELECT p.*, j.status AS job_status, j.token AS job_token, j.id AS job_id FROM prospects p LEFT JOIN jobs j ON j.id = (SELECT MAX(id) FROM jobs WHERE prospect_id = p.id) $pwhere ORDER BY p.created_at $order, p.id $order LIMIT 500";
    $pst = ww_db()->prepare($psql); $pst->execute($pparams); $rows = $pst->fetchAll(PDO::FETCH_ASSOC);

    // Toolbar: search + create-date sort + export-selected
    $sortToggle = $sort === 'created_desc' ? 'created_asc' : 'created_desc';
    echo '<form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:14px;">';
    echo '<input type="hidden" name="tab" value="prospects"><input type="hidden" name="sort" value="' . ww_h($sort) . '">';
    echo '<input type="text" name="q" value="' . ww_h($q) . '" placeholder="Search business, contact, email or URL…" style="flex:1;min-width:240px;padding:9px 12px;border:2px solid var(--navy);border-radius:10px;font-size:14px;font-family:var(--body);">';
    echo '<button class="btn ghost" type="submit">Search</button>';
    if ($q !== '') echo '<a class="btn ghost" href="/admin/?tab=prospects">Clear</a>';
    echo '<a class="btn ghost" href="/admin/?tab=prospects&amp;q=' . urlencode($q) . '&amp;sort=' . $sortToggle . '">Created ' . ($sort === 'created_desc' ? '&darr; newest first' : '&uarr; oldest first') . '</a>';
    echo '<button class="btn" type="button" id="exportSel" disabled>Export selected (0)</button>';
    echo '</form>';

    if (!$rows) { echo '<div class="empty">' . ($q !== '' ? 'No prospects match &ldquo;' . ww_h($q) . '&rdquo;.' : 'No prospects imported yet.') . '</div>'; }
    else {
        echo '<table class="t" id="prospectsTable"><thead><tr><th style="width:28px;"><input type="checkbox" id="selAll" title="Select all"></th><th>Business</th><th>Contact</th><th>Email</th><th>Current site</th><th>Status</th><th>Created</th><th>Preview</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $st = $r['job_status'] ?? 'queued';
            $cls = ['queued'=>'muted','running'=>'warn','ready'=>'ok','sent'=>'ok','picked'=>'ok','failed'=>'err'][$st] ?? 'muted';
            echo '<tr><td><input type="checkbox" class="psel" value="' . (int)$r['id'] . '"></td>';
            echo '<td><strong>' . ww_h($r['business_name']) . '</strong></td>';
            echo '<td>' . ww_h($r['name']) . '</td>';
            echo '<td>' . ww_h($r['email']) . '</td>';
            echo '<td><a href="' . ww_h($r['current_url']) . '" target="_blank" style="font-size:13px;">' . ww_h(parse_url($r['current_url'], PHP_URL_HOST) ?: $r['current_url']) . '</a></td>';
            echo '<td><span class="pill ' . $cls . '">' . ww_h($st) . '</span></td>';
            echo '<td>' . ww_h(date('M j', strtotime($r['created_at']))) . '</td>';
            echo '<td>';
            if (!empty($r['job_token']) && in_array($st, ['ready','sent','picked'], true)) {
                echo '<a class="btn" href="/preview/' . ww_h($r['job_token']) . '" target="_blank">View &rarr;</a>';
            } elseif (in_array($st, ['queued','running'], true)) {
                echo '<span class="pill muted" style="font-size:10px;">generating</span>';
            } elseif ($st === 'failed' && !empty($r['job_id'])) {
                echo '<form method="post" style="display:inline;"><input type="hidden" name="prospect_action" value="retry"><input type="hidden" name="job_id" value="' . (int)$r['job_id'] . '"><button class="btn" type="submit">Retry &rarr;</button></form>';
            } else {
                echo '<span style="opacity:0.4;">&mdash;</span>';
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<script>(function(){var all=document.getElementById("selAll"),btn=document.getElementById("exportSel");function boxes(){return [].slice.call(document.querySelectorAll(".psel"));}function upd(){var n=boxes().filter(function(b){return b.checked;}).length;if(btn){btn.textContent="Export selected ("+n+")";btn.disabled=n===0;}}if(all)all.addEventListener("change",function(){boxes().forEach(function(b){b.checked=all.checked;});upd();});boxes().forEach(function(b){b.addEventListener("change",upd);});if(btn)btn.addEventListener("click",function(){var ids=boxes().filter(function(b){return b.checked;}).map(function(b){return b.value;});if(ids.length)window.location="/admin/?tab=prospects&export=csv&ids="+ids.join(",");});upd();})();</script>';
    }
    shell_close(); exit;
}

// ---------- JOBS ----------
if ($tab === 'jobs') {
    if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_POST['job_action'])) {
        $job_id = (int)($_POST['job_id'] ?? 0);
        if ($_POST['job_action'] === 'retry' && $job_id) {
            ww_db()->prepare("UPDATE jobs SET status='queued', error=NULL, started_at=NULL, completed_at=NULL WHERE id = ?")->execute([$job_id]);
            header('Location: /admin/?tab=jobs&done=retry'); exit;
        }
        if ($_POST['job_action'] === 'cancel' && $job_id) {
            ww_db()->prepare("UPDATE jobs SET status='archived' WHERE id = ?")->execute([$job_id]);
            header('Location: /admin/?tab=jobs&done=cancel'); exit;
        }
    }
    shell_open('Jobs', $me, 'jobs', $is_admin);
    echo '<h1>Generation queue</h1>';
    if (!empty($_GET['done'])) echo '<div class="info">Job ' . ww_h($_GET['done']) . '.</div>';

    $counts = [];
    foreach (['queued','running','ready','sent','picked','failed','archived'] as $s) {
        $counts[$s] = (int)ww_db()->query("SELECT COUNT(*) FROM jobs WHERE status = '$s'")->fetchColumn();
    }
    echo '<div class="stat-grid">';
    foreach (['queued'=>'Queued','running'=>'Running','ready'=>'Ready','sent'=>'Sent','picked'=>'Picked','failed'=>'Failed'] as $k=>$v) {
        echo '<div class="stat"><div class="lbl">' . $v . '</div><div class="val">' . $counts[$k] . '</div></div>';
    }
    echo '</div>';

    $rows = ww_db()->query('SELECT j.*, p.business_name AS biz, p.current_url FROM jobs j LEFT JOIN prospects p ON p.id = j.prospect_id ORDER BY j.id DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) { echo '<div class="empty">No jobs yet. Upload prospects to start generating.</div>'; }
    else {
        echo '<table class="t"><thead><tr><th>#</th><th>Type</th><th>Target</th><th>Status</th><th>Scheduled</th><th>Cost</th><th>Actions</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $st = $r['status'];
            $cls = ['queued'=>'muted','running'=>'warn','ready'=>'ok','sent'=>'ok','picked'=>'ok','failed'=>'err','archived'=>'muted'][$st] ?? 'muted';
            echo '<tr><td><strong>#' . (int)$r['id'] . '</strong></td>';
            echo '<td>' . ww_h($r['type']) . '</td>';
            echo '<td><strong>' . ww_h($r['biz'] ?? $r['business_name'] ?? '-') . '</strong><br><small style="opacity:0.6;">' . ww_h($r['customer_email']) . '</small></td>';
            echo '<td><span class="pill ' . $cls . '">' . ww_h($st) . '</span>';
            if ($r['error']) echo '<br><small style="opacity:0.7;color:#a01;">' . ww_h(substr($r['error'],0,80)) . '</small>';
            echo '</td>';
            echo '<td>' . ww_h($r['scheduled_for']) . '</td>';
            echo '<td>$' . number_format(($r['total_cost_cents']??0)/100, 2) . '</td>';
            echo '<td>';
            if (in_array($st, ['failed','archived'], true)) {
                echo '<form class="action" method="post"><input type="hidden" name="job_action" value="retry"><input type="hidden" name="job_id" value="' . (int)$r['id'] . '"><button class="btn" type="submit">Retry</button></form>';
            }
            if (in_array($st, ['queued','running','failed'], true)) {
                echo '<form class="action" method="post"><input type="hidden" name="job_action" value="cancel"><input type="hidden" name="job_id" value="' . (int)$r['id'] . '"><button class="btn ghost" type="submit">Cancel</button></form>';
            }
            if ($r['token'] && in_array($st, ['ready','sent','picked'], true)) {
                echo '<a class="btn ghost" href="/preview/' . ww_h($r['token']) . '" target="_blank">Preview</a>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }
    shell_close(); exit;
}

// ---------- DASHBOARD ----------
shell_open('Dashboard', $me, 'dash', $is_admin);
echo '<h1>Hi, ' . ww_h(explode(' ', $me['name'])[0]) . '.</h1>';
$mrr = 0; $active_subs = 0; $past_due = 0;
$subs = stripe_get($STRIPE_SECRET, 'subscriptions', ['status' => 'all', 'limit' => 100]);
foreach (($subs['data'] ?? []) as $s) {
    if (in_array($s['status'], ['active','trialing'], true)) {
        $active_subs++;
        foreach (($s['items']['data'] ?? []) as $it) $mrr += ($it['price']['unit_amount'] ?? 0) * ($it['quantity'] ?? 1);
    }
    if ($s['status'] === 'past_due') $past_due++;
}
$charges = stripe_get($STRIPE_SECRET, 'charges', ['limit' => 100]);
$rev_total = 0; $one_time_count = 0;
foreach (($charges['data'] ?? []) as $c) if ($c['paid']) { $rev_total += $c['amount'] - $c['amount_refunded']; if (empty($c['invoice'])) $one_time_count++; }
$api_total = (float)(ww_db()->query('SELECT COALESCE(SUM(cost_usd), 0) FROM api_calls')->fetchColumn() ?? 0);
?>
<div class="stat-grid">
  <div class="stat"><div class="lbl">Monthly recurring</div><div class="val">$<?= number_format($mrr/100, 0) ?></div><div class="sub">across <?= $active_subs ?> active subs</div></div>
  <div class="stat"><div class="lbl">Lifetime revenue</div><div class="val">$<?= number_format($rev_total/100, 0) ?></div><div class="sub">last 100 payments</div></div>
  <div class="stat"><div class="lbl">Active subs</div><div class="val"><?= $active_subs ?></div><div class="sub"><?= $past_due ?> past due</div></div>
  <div class="stat"><div class="lbl">API spend</div><div class="val">$<?= number_format($api_total, 2) ?></div><div class="sub">Anthropic running total</div></div>
</div>

<h2>Recent payments</h2>
<table class="t"><thead><tr><th>When</th><th>Customer</th><th>Amount</th><th>Status</th><th></th></tr></thead><tbody>
<?php
$recent = array_slice($charges['data'] ?? [], 0, 12);
if (!$recent) echo '<tr><td colspan="5"><div class="empty">No payments yet.</div></td></tr>';
foreach ($recent as $c) {
    $name = $c['billing_details']['name'] ?? ($c['metadata']['business_name'] ?? '—');
    $email = $c['billing_details']['email'] ?? '—';
    $cls = $c['paid'] ? 'ok' : ($c['status']==='failed' ? 'err' : 'warn');
    echo '<tr><td>' . ww_h(date('M j, g:ia', $c['created'])) . '</td><td><strong>' . ww_h($name) . '</strong><br><small style="opacity:0.6;">' . ww_h($email) . '</small></td><td><strong>$' . number_format($c['amount']/100, 2) . '</strong></td><td><span class="pill ' . $cls . '">' . ww_h($c['status']) . '</span></td><td><a class="btn ghost" target="_blank" href="https://dashboard.stripe.com/payments/' . ww_h($c['payment_intent'] ?? $c['id']) . '">Stripe &rarr;</a></td></tr>';
}
?>
</tbody></table>
<?php
shell_close();
