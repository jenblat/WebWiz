<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
if (($_GET['key'] ?? '') !== 'commit') { http_response_code(403); echo 'forbidden'; exit; }
@set_time_limit(120);
$root = '/var/www/sites/trywebwiz';
$secrets = require $root . '/secrets.php';
$token = (string)($secrets['GITHUB_TOKEN'] ?? '');
if ($token === '') { echo "ERROR: token missing\n"; exit; }
function sh(string $c, string $cwd, array $env): string {
    $e = '';
    foreach ($env as $k => $v) $e .= $k . '=' . escapeshellarg((string)$v) . ' ';
    return (string)shell_exec($e . 'cd ' . escapeshellarg($cwd) . ' && ' . $c . ' 2>&1');
}
$home = '/tmp/wwgit_' . bin2hex(random_bytes(3));
@mkdir($home, 0755, true);
$env = ['HOME' => $home];
echo sh('git add -A', $root, $env);
echo sh('git commit -m ' . escapeshellarg('Try: rotating testimonial card under Wizzy + compact admin dates') . ' --allow-empty', $root, $env);
$url = 'https://x-access-token:' . $token . '@github.com/jenblat/WebWiz.git';
$out = sh('git push ' . escapeshellarg($url) . ' HEAD:refs/heads/main', $root, $env);
echo str_replace($token, '<REDACTED>', $out);
echo "\n" . sh('git log --oneline -3', $root, $env);
@shell_exec('rm -rf ' . escapeshellarg($home));
