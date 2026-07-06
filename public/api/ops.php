<?php
// /api/ops.php — internal ops/diagnostics endpoint (key-guarded, read-only).
// Exists so routine investigation (log tails, job lookups, read-only queries,
// lint, git status) never needs throwaway helper files. Remove or rotate the
// key when done. NEVER performs writes to the DB.
declare(strict_types=1);
header('Content-Type: application/json');
$KEY = 'ops_7f3a91c4e28b46d5a0c9';
if (!hash_equals($KEY, (string)($_GET['key'] ?? ''))) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }
require '/var/www/sites/trywebwiz/private/webwiz_lib.php';
function sh($c){ return trim((string)shell_exec($c.' 2>&1')); }
$cmd = (string)($_GET['cmd'] ?? '');
$out = ['cmd'=>$cmd];
try {
  $db = ww_db();
  if ($cmd === 'tail') {
    $map = ['magic'=>'/tmp/wwmagic_debug.log','health'=>'/var/www/sites/trywebwiz/logs/health.log','cron'=>'/var/www/sites/trywebwiz/logs/nurture_cron.log','edit'=>'/tmp/wwedit_debug.log'];
    $f = $map[(string)($_GET['file'] ?? 'magic')] ?? $map['magic'];
    $n = min(200, max(1, (int)($_GET['n'] ?? 40)));
    $out['file']=$f; $out['lines']=explode("\n", sh('tail -'.$n.' '.escapeshellarg($f)));
  } elseif ($cmd === 'q') {
    $sql = trim((string)($_GET['sql'] ?? ''));
    if (!preg_match('~^select\s~i', $sql) || preg_match('~\b(insert|update|delete|drop|alter|attach|replace|create)\b~i', $sql)) throw new Exception('read-only SELECT only');
    $out['rows'] = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
  } elseif ($cmd === 'job') {
    $tok = preg_replace('~[^a-f0-9]~','', (string)($_GET['token'] ?? ''));
    $j = $db->prepare("SELECT * FROM jobs WHERE token=?"); $j->execute([$tok]); $out['job']=$j->fetch(PDO::FETCH_ASSOC);
    if (!empty($out['job']['prospect_id'])) { $p=$db->prepare("SELECT id,email,business_name,current_url,description,source FROM prospects WHERE id=?"); $p->execute([$out['job']['prospect_id']]); $out['prospect']=$p->fetch(PDO::FETCH_ASSOC); }
    $out['edits_dir']=sh('ls -la /var/www/sites/trywebwiz/public/preview/'.$tok.'/v1/edits/ 2>&1');
    $out['assets_dir']=sh('ls -la /var/www/sites/trywebwiz/public/preview/'.$tok.'/assets/ 2>&1');
  } elseif ($cmd === 'lint') {
    $rel = preg_replace('~[^a-zA-Z0-9_./-]~','', (string)($_GET['file'] ?? ''));
    if (strpos($rel,'..')!==false) throw new Exception('bad path');
    $out['lint']=sh('php -l /var/www/sites/trywebwiz/public/'.$rel);
  } elseif ($cmd === 'git') {
    $out['status']=sh('cd /var/www/sites/trywebwiz && git status --short');
    $out['head']=sh('cd /var/www/sites/trywebwiz && git log -1 --pretty=format:"%h %s"');
  } elseif ($cmd === 'schema') {
    $t = preg_replace('~[^a-z_]~i','', (string)($_GET['t'] ?? ''));
    $out['columns']=$db->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC);
  } else {
    $out['usage']='cmd=tail|q|job|lint|git|schema';
  }
} catch (Throwable $e) { $out['error']=$e->getMessage(); }
echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
