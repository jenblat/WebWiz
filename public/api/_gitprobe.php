<?php
// /api/_gitprobe.php — Push WebWiz to jenblat/WebWiz. Token read from secrets at
// call-time, never echoed or saved to .git/config.
//
// Usage: curl 'https://trywebwiz.com/api/_gitprobe.php?key=gitpush'
//        curl 'https://trywebwiz.com/api/_gitprobe.php?key=gitpush&force=1'  (DESTRUCTIVE)
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
if (($_GET['key'] ?? '') !== 'gitpush') { http_response_code(403); echo 'forbidden'; exit; }
@set_time_limit(120);

$root = '/var/www/sites/trywebwiz';
$secrets = require $root . '/secrets.php';
$token = (string)($secrets['GITHUB_TOKEN'] ?? '');
if ($token === '') { echo "ERROR: GITHUB_TOKEN missing from secrets.php\n"; exit; }
echo "GITHUB_TOKEN found (length " . strlen($token) . "). Proceeding.\n\n";

$force = ($_GET['force'] ?? '') === '1';

// .gitignore (kept here so re-runs refresh it)
$gitignore = <<<'IGN'
# WebWiz .gitignore
secrets.php
secrets.local.php
data/
logs/
*.sqlite
*.sqlite3
*.db
pending_magic/
public/preview/
public/data/imgcache/
public/showcase_cache/
*.bak
*.bak.*
.bak/
/tmp/
*.log
node_modules/
package-lock.json.bak
.DS_Store
Thumbs.db
.idea/
.vscode/
*.swp
IGN;
file_put_contents($root . '/.gitignore', $gitignore);

function sh(string $cmd, string $cwd, array $env = []): string {
    $envstr = '';
    foreach ($env as $k => $v) $envstr .= $k . '=' . escapeshellarg((string)$v) . ' ';
    return (string)shell_exec($envstr . 'cd ' . escapeshellarg($cwd) . ' && ' . $cmd . ' 2>&1');
}

// HOME must be set or git complains
$home = '/tmp/wwgit_home_' . bin2hex(random_bytes(3));
@mkdir($home, 0755, true);
$env = ['HOME' => $home];

$git_email = 'wizzy@trywebwiz.com';
$git_name  = 'Wizzy at WebWiz';

$gitdir = $root . '/.git';
if (!is_dir($gitdir)) {
    echo "Initializing repo...\n";
    echo sh('git init -b main', $root, $env);
}

echo sh('git config --global --add safe.directory ' . escapeshellarg($root), $root, $env);
echo sh('git config user.email ' . escapeshellarg($git_email), $root, $env);
echo sh('git config user.name '  . escapeshellarg($git_name),  $root, $env);
$remote_check = sh('git remote get-url origin', $root, $env);
if (stripos($remote_check, 'github.com/jenblat/WebWiz') === false) {
    echo sh('git remote remove origin', $root, $env);
    echo sh('git remote add origin https://github.com/jenblat/WebWiz.git', $root, $env);
}

// Stage + commit any local changes (idempotent)
echo "\n=== git add + commit ===\n";
echo sh('git add -A', $root, $env);
$msg = 'WebWiz state ' . gmdate('Y-m-d H:i') . ' UTC';
echo sh('git commit -m ' . escapeshellarg($msg) . ' --allow-empty', $root, $env);

// Set up ephemeral askpass so token never lands in args or config
$askpass = '/tmp/ww_git_askpass_' . bin2hex(random_bytes(4)) . '.sh';
file_put_contents($askpass, "#!/bin/sh\necho " . escapeshellarg($token) . "\n");
chmod($askpass, 0700);
$env_push = array_merge($env, [
    'GIT_TERMINAL_PROMPT' => '0',
    'GIT_ASKPASS'         => $askpass,
    'GIT_USERNAME'        => 'x-access-token',
]);

if ($force) {
    echo "\n=== git push --force (force=1 requested) ===\n";
    $push_out = sh(
        'git -c credential.helper="" push --force https://x-access-token@github.com/jenblat/WebWiz.git HEAD:refs/heads/main --set-upstream',
        $root, $env_push
    );
} else {
    // Standard path: fetch remote, rebase our work on top of it, then push.
    echo "\n=== git fetch origin ===\n";
    echo sh('git -c credential.helper="" fetch https://x-access-token@github.com/jenblat/WebWiz.git main:refs/remotes/origin/main', $root, $env_push);

    echo "\n=== git rebase origin/main (with --allow-unrelated-histories via merge fallback) ===\n";
    $rebase = sh('git rebase origin/main', $root, $env_push);
    echo $rebase;
    if (stripos($rebase, 'fatal') !== false || stripos($rebase, 'CONFLICT') !== false) {
        // Try merging unrelated histories (typical when remote has an auto-created README)
        echo sh('git rebase --abort', $root, $env_push);
        echo "\n=== Falling back to merge with --allow-unrelated-histories ===\n";
        echo sh('git merge origin/main --allow-unrelated-histories --no-edit -m ' . escapeshellarg('Merge initial GitHub history'), $root, $env_push);
    }

    echo "\n=== git push ===\n";
    $push_out = sh(
        'git -c credential.helper="" push https://x-access-token@github.com/jenblat/WebWiz.git HEAD:refs/heads/main --set-upstream',
        $root, $env_push
    );
}
echo str_replace($token, '<REDACTED>', $push_out) . "\n";

@unlink($askpass);
@shell_exec('rm -rf ' . escapeshellarg($home));

echo "\n=== git log --oneline -5 ===\n";
echo sh('git log --oneline -5', $root, $env);

echo "\n=== Final remote (should be CLEAN, no token) ===\n";
echo sh('git remote -v', $root, $env);

echo "\nVerify: https://github.com/jenblat/WebWiz/commits/main\n";
