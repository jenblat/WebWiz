<?php
// /api/qa.php — stores the loading-screen closing/qualifying questions the visitor
// answers while their site builds. POST JSON {token, answers:{k:v,...}, complete}.
// Always writes preview/<token>/qa.json (never lost, even before the DB job exists).
// Best-effort: also attaches answers to the prospect row via jobs.token -> prospect_id.
declare(strict_types=1);
header('Content-Type: application/json');
header('Cache-Control: no-store');

$raw = file_get_contents('php://input');
$in  = json_decode($raw ?: '[]', true);
if (!is_array($in)) $in = [];

$token = (string)($in['token'] ?? '');
if (!preg_match('~^[a-f0-9]{6,32}$~', $token)) { echo json_encode(['ok' => false, 'error' => 'bad token']); exit; }

$answers = (isset($in['answers']) && is_array($in['answers'])) ? $in['answers'] : [];
$complete = !empty($in['complete']) ? 1 : 0;

// Sanitize: keep at most 12 short string answers with simple keys.
$clean = [];
$n = 0;
foreach ($answers as $k => $v) {
    if ($n >= 12) break;
    $k = preg_replace('~[^a-z0-9_]~', '', strtolower((string)$k));
    if ($k === '') continue;
    if (is_array($v)) $v = implode(', ', $v);
    $clean[$k] = trim(mb_substr((string)$v, 0, 160));
    $n++;
}

$dir = '/var/www/sites/trywebwiz/public/preview/' . $token;
$payload = json_encode(
    ['token' => $token, 'answers' => $clean, 'complete' => $complete, 'updated_at' => date('c')],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
);
if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
@file_put_contents($dir . '/qa.json', $payload);

// Best-effort DB attach (works once the async job row has been persisted).
$db_ok = false;
try {
    $lib = '/var/www/sites/trywebwiz/private/webwiz_lib.php';
    if (is_file($lib)) {
        require_once $lib;
        if (function_exists('ww_db')) {
            $db = ww_db();
            // Ensure the column exists (safe, outside any transaction).
            $have = false;
            try { $db->query('SELECT qa_answers FROM prospects LIMIT 1'); $have = true; }
            catch (Throwable $e) { try { $db->exec('ALTER TABLE prospects ADD COLUMN qa_answers TEXT'); $have = true; } catch (Throwable $e2) {} }
            if ($have && $clean) {
                $st = $db->prepare('SELECT prospect_id FROM jobs WHERE token = ? ORDER BY id DESC LIMIT 1');
                $st->execute([$token]);
                $pid = $st->fetchColumn();
                if ($pid) {
                    $u = $db->prepare('UPDATE prospects SET qa_answers = ? WHERE id = ?');
                    $u->execute([json_encode($clean, JSON_UNESCAPED_SLASHES), (int)$pid]);
                    $db_ok = true;
                }
            }
        }
    }
} catch (Throwable $e) { /* non-fatal: qa.json is the source of truth */ }

echo json_encode(['ok' => true, 'saved' => count($clean), 'db' => $db_ok]);
