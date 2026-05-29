<?php
// /api/edit.php — Wizzy edit chat backend for the /try ad-funnel.
// POST { token, message } → reads current /preview/<token>/v1/index.html,
// asks Sonnet to apply the requested tweak, writes the updated HTML back,
// returns { ok, edits_remaining, preview_url, reply }.
// Enforces a 5-edit hard cap per token, server-side.
declare(strict_types=1);
@set_time_limit(0);
ignore_user_abort(true);
header('Content-Type: application/json');

require_once '/var/www/sites/trywebwiz/private/webwiz_lib.php';
require_once '/var/www/sites/trywebwiz/private/lib/anthropic.php';

const EDIT_CAP = 5;

function ee_fail(string $m, int $code = 400) { http_response_code($code); echo json_encode(['error' => $m]); exit; }

// ---- Parse body (JSON only — small payload) ----
$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) $body = [];
$token   = trim((string)($body['token']   ?? ''));
$message = trim((string)($body['message'] ?? ''));

if (!preg_match('~^[a-f0-9]{24}$~', $token)) ee_fail('Invalid token.');
if ($message === '' || mb_strlen($message) < 3) ee_fail('Tell Wizzy what to tweak.');
if (mb_strlen($message) > 600) ee_fail('Keep the request under 600 characters so Wizzy can focus.');

$db = ww_db();
$job = $db->prepare("SELECT id, edit_count, generation_mode, token, business_name FROM jobs WHERE token = ? LIMIT 1");
$job->execute([$token]);
$job = $job->fetch(PDO::FETCH_ASSOC);
if (!$job) ee_fail('Preview not found.', 404);
if (($job['generation_mode'] ?? '') !== 'magic') ee_fail('Edits are only available on instant previews.', 403);

$used = (int)$job['edit_count'];
if ($used >= EDIT_CAP) {
    echo json_encode([
        'ok' => false,
        'edits_remaining' => 0,
        'cap_hit' => true,
        'reply' => "That's all the tweaks I can do here. If you love where it's at, let's make it real. If it still needs work, my human teammates can take it from here once you launch it.",
    ]);
    exit;
}

$dir = '/var/www/sites/trywebwiz/public/preview/' . $token . '/v1';
$index = $dir . '/index.html';
if (!is_file($index)) ee_fail('Preview file missing.', 410);
$current_html = (string)file_get_contents($index);
if ($current_html === '') ee_fail('Preview file is empty.', 500);

// ---- Build the edit prompt ----
$system = <<<SYS
You are Wizzy, a senior web designer making a targeted edit to a single-page
small-business website. The user will tell you what they want changed. Apply
EXACTLY what they ask for — no other changes. Preserve all other sections,
copy, images, and structure. Keep the same overall design language, colors,
and fonts unless the edit asks you to change them.

OUTPUT FORMAT (strict):
1. Return ONLY the complete updated HTML document. Start with <!doctype html
   or <!DOCTYPE html> and end with </html>.
2. Do NOT include any explanation, markdown fences, or commentary.
3. Inline all CSS in a <style> block in <head>; inline all JS in <script>
   tags. No external files.
4. Keep image src URLs the same as the original unless the edit asks you
   to remove or replace them.
5. Keep the page mobile-responsive.
SYS;

$user_content = "Here is the current single-page HTML for the customer's site:\n\n<current_html>\n"
              . $current_html
              . "\n</current_html>\n\nThe customer's edit request:\n\n<request>\n"
              . $message
              . "\n</request>\n\nReturn the COMPLETE updated HTML document now.";

$messages = [['role' => 'user', 'content' => $user_content]];

try {
    $res = anthropic_chat('claude-sonnet-4-6', $messages, $system, 16000, 0.4, (int)$job['id'], ['</html>']);
    $text = (string)($res['text'] ?? '');
    if ($text === '') throw new Exception('empty model response');

    // Sonnet may stop right at the </html> stop sequence; restore it so the file is valid.
    if (stripos($text, '</html>') === false) $text .= '</html>';
    // Strip any accidental markdown fences.
    $text = preg_replace('~^\s*```(?:html)?\s*~i', '', $text);
    $text = preg_replace('~\s*```\s*$~', '', $text);
    // Sanity check: must contain a doctype or <html
    if (!preg_match('~<!doctype html|<html~i', $text)) throw new Exception('model did not return HTML');

    // Write the updated HTML back. Snapshot the old one so we can roll back if needed.
    $snap_dir = $dir . '/edits';
    @mkdir($snap_dir, 0755, true);
    @copy($index, $snap_dir . '/v' . ($used + 1) . '-prior.html');
    if (file_put_contents($index, $text) === false) throw new Exception('could not save updated preview');

    // Decrement counter
    $db->prepare("UPDATE jobs SET edit_count = edit_count + 1 WHERE id = ?")->execute([(int)$job['id']]);
    $remaining = max(0, EDIT_CAP - ($used + 1));

    echo json_encode([
        'ok' => true,
        'edits_remaining' => $remaining,
        'cap_hit' => $remaining === 0,
        'preview_url' => '/preview/' . $token . '/v1/index.html?e=' . ($used + 1),
        'reply' => $remaining === 0
            ? "Done — that was your last tweak. If you love it, let's make it real."
            : "Done. How's that look?",
    ]);
} catch (Throwable $e) {
    ee_fail('Edit failed: ' . preg_replace('/[\x00-\x1F]+/', ' ', $e->getMessage()), 500);
}
