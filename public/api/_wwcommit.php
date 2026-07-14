<?php
// One-shot commit helper. Self-deletes. Guarded by a key.
if (($_GET['k'] ?? '') !== 'wwc_9f3a2c7b1e') { http_response_code(403); exit('no'); }
header('Content-Type: application/json');
$root = '/var/www/sites/trywebwiz';
$sec = @include $root . '/secrets.php';
$token = '';
if (is_array($sec)) {
    foreach (['GITHUB_TOKEN','github_token','GH_TOKEN','gh_token'] as $k) { if (!empty($sec[$k])) { $token = $sec[$k]; break; } }
}
function run($c){ return trim((string)@shell_exec($c . ' 2>&1')); }
$top = run("git -C $root rev-parse --show-toplevel");
if ($top === '' || strpos($top, 'fatal') !== false) { echo json_encode(['error'=>'no git root','out'=>$top]); @unlink(__FILE__); exit; }
$branch = run("git -C " . escapeshellarg($top) . " rev-parse --abbrev-ref HEAD");
$remote = run("git -C " . escapeshellarg($top) . " remote get-url origin");
$status = run("git -C " . escapeshellarg($top) . " status --porcelain");
run("git -C " . escapeshellarg($top) . " add -A");
$msg = 'WebWiz /try: async reveal now waits for pre-warmed images and no longer rewrites the page after reveal (fixes blank-image pop-in + hero background swap); pre-warm also covers img.php URLs; removed public version chip (kept admin-only)';
$commit = run("git -C " . escapeshellarg($top) . " -c user.email=omar@busyseed.com -c user.name='WebWiz Bot' commit -m " . escapeshellarg($msg));
$push = 'skipped';
if ($token !== '') {
    $auth = base64_encode('x-access-token:' . $token);
    $push = run("git -C " . escapeshellarg($top) . " -c http.extraHeader=" . escapeshellarg('AUTHORIZATION: basic ' . $auth) . " push origin HEAD:" . escapeshellarg($branch));
}
echo json_encode([
    'top' => $top, 'branch' => $branch, 'remote' => $remote,
    'status_before' => $status, 'commit' => substr($commit, 0, 800), 'push' => substr($push, 0, 800)
], JSON_PRETTY_PRINT);
@unlink(__FILE__);
