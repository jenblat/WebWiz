<?php
// /api/_gitprobe.php — Initialize git repo in /var/www/sites/trywebwiz and push
// to jenblat/WebWiz. Token read from secrets.php at call-time, never echoed or
// saved to .git/config.
//
// Usage: curl 'https://trywebwiz.com/api/_gitprobe.php?key=gitpush'
// Required: secrets.php must contain GITHUB_TOKEN (a Personal Access Token with
//   contents:write scope on jenblat/WebWiz).
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
if (($_GET['key'] ?? '') !== 'gitpush') { http_response_code(403); echo 'forbidden'; exit; }

@set_time_limit(120);

$root = '/var/www/sites/trywebwiz';
$secrets = require $root . '/secrets.php';
$token = (string)($secrets['GITHUB_TOKEN'] ?? '');
if ($token === '') {
    echo "ERROR: GITHUB_TOKEN missing from secrets.php.\n";
    echo "Add via SeedSite Secrets UI:\n";
    echo "  key   = GITHUB_TOKEN\n";
    echo "  value = <Personal Access Token, fine-grained, contents:write on jenblat/WebWiz>\n";
    exit;
}
$token_len = strlen($token);
echo "GITHUB_TOKEN found (length $token_len). Proceeding.\n\n";

// 1) .gitignore — never commit secrets, generated content, caches, or backups
$gitignore = <<<'IGN'
# WebWiz .gitignore — keep secrets, generated content, caches, and backups out of git.
secrets.php
secrets.local.php

# SQLite DBs + queued JSON
data/
logs/
*.sqlite
*.sqlite3
*.db
pending_magic/

# Generated previews (per-user gen output)
public/preview/

# Image cache + showcase screenshots
public/data/imgcache/
public/showcase_cache/

# Backup files written by SeedSite atomic writes
*.bak
*.bak.*
.bak/

# Temp + log files
/tmp/
*.log

# Node
node_modules/
package-lock.json.bak

# OS
.DS_Store
Thumbs.db

# Editor
.idea/
.vscode/
*.swp
IGN;

file_put_contents($root . '/.gitignore', $gitignore);
echo ".gitignore written.\n";

// 2) Init repo if needed
$gitdir = $root . '/.git';
$git_user_email = 'wizzy@trywebwiz.com';
$git_user_name  = 'Wizzy at WebWiz';

function sh(string $cmd, string $cwd): string {
    return (string)shell_exec("cd " . escapeshellarg($cwd) . " && " . $cmd . " 2>&1");
}

if (!is_dir($gitdir)) {
    echo "Initializing new git repo...\n";
    echo sh('git init -b main', $root);
    echo sh('git config user.email ' . escapeshellarg($git_user_email), $root);
    echo sh('git config user.name '  . escapeshellarg($git_user_name),  $root);
    // Don't store the token-laden URL in remote config; we'll push to ephemeral URL.
    echo sh('git remote add origin https://github.com/jenblat/WebWiz.git', $root);
} else {
    echo "Repo already initialized.\n";
    echo sh('git config user.email ' . escapeshellarg($git_user_email), $root);
    echo sh('git config user.name '  . escapeshellarg($git_user_name),  $root);
    // Ensure remote points at clean URL (no token)
    echo sh('git remote set-url origin https://github.com/jenblat/WebWiz.git || git remote add origin https://github.com/jenblat/WebWiz.git', $root);
}

// Configure safe directory so git doesn't complain about www-data ownership
echo sh('git config --global --add safe.directory ' . escapeshellarg($root), $root);

// 3) Stage + commit
echo "\n=== git status (porcelain) ===\n";
$status = sh('git status --porcelain | head -50', $root);
echo $status;
echo "(line count: " . (substr_count($status, "\n")) . ")\n";

echo "\n=== git add ===\n";
echo sh('git add -A', $root);

echo "\n=== git commit ===\n";
$msg = 'WebWiz state ' . gmdate('Y-m-d H:i') . ' UTC — nurture engine + image-pipeline fixes + meta capi';
$commit_out = sh('git commit -m ' . escapeshellarg($msg), $root);
echo $commit_out;

// 4) Push using ephemeral token URL (token NOT saved to config)
// We use GIT_ASKPASS so the token never appears in shell history or process args.
$askpass_path = '/tmp/ww_git_askpass_' . bin2hex(random_bytes(4)) . '.sh';
file_put_contents($askpass_path, "#!/bin/sh\necho " . escapeshellarg($token) . "\n");
chmod($askpass_path, 0700);

$push_cmd = 'GIT_TERMINAL_PROMPT=0 '
          . 'GIT_ASKPASS=' . escapeshellarg($askpass_path) . ' '
          . 'GIT_USERNAME=x-access-token '
          . 'git -c credential.helper="" push '
          . 'https://x-access-token@github.com/jenblat/WebWiz.git '
          . 'HEAD:refs/heads/main --set-upstream';

echo "\n=== git push ===\n";
$push_out = sh($push_cmd, $root);
// Mask any accidental token echo in the output (defensive)
$push_out = str_replace($token, '<REDACTED>', $push_out);
echo $push_out . "\n";

@unlink($askpass_path);

echo "\n=== git log --oneline -5 ===\n";
echo sh('git log --oneline -5', $root);

echo "\n=== Final remote (should be CLEAN, no token) ===\n";
echo sh('git remote -v', $root);

echo "\nDONE. Verify in browser: https://github.com/jenblat/WebWiz/commits/main\n";
