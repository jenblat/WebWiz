<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
if (($_GET['key'] ?? '') !== 'commit') { http_response_code(403); echo 'forbidden'; exit; }
@set_time_limit(120);

$root = '/var/www/sites/trywebwiz';
$secrets = require $root . '/secrets.php';
$token = (string)($secrets['GITHUB_TOKEN'] ?? '');
if ($token === '') { echo "ERROR: GITHUB_TOKEN missing\n"; exit; }

function sh(string $cmd, string $cwd, array $env): string {
    $envstr = '';
    foreach ($env as $k => $v) $envstr .= $k . '=' . escapeshellarg((string)$v) . ' ';
    return (string)shell_exec($envstr . 'cd ' . escapeshellarg($cwd) . ' && ' . $cmd . ' 2>&1');
}
$home = '/tmp/wwgit_' . bin2hex(random_bytes(3));
@mkdir($home, 0755, true);
$env = ['HOME' => $home];

echo sh('git add -A', $root, $env);
$msg = 'Nurture: brand-matched emails, showcase image embed, admin shell + form polish';
echo sh('git commit -m ' . escapeshellarg($msg) . ' --allow-empty', $root, $env);

$push_url = 'https://x-access-token:' . $token . '@github.com/jenblat/WebWiz.git';
$out = sh('git push ' . escapeshellarg($push_url) . ' HEAD:refs/heads/main', $root, $env);
echo str_replace($token, '<REDACTED>', $out);

echo "\n=== log ===\n";
echo sh('git log --oneline -4', $root, $env);
@shell_exec('rm -rf ' . escapeshellarg($home));
