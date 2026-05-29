<?php
// /api/upload.php — Asset upload for the /try ad-funnel edit flow.
// POST multipart with: token, logo (optional file), photos[] (optional, up to 3).
// Saves files into /preview/<token>/assets/, then queues a Wizzy edit that
// integrates them. Counts as 1 edit toward the 5-edit cap.
declare(strict_types=1);
@set_time_limit(0);
ignore_user_abort(true);
header('Content-Type: application/json');

require_once '/var/www/sites/trywebwiz/private/webwiz_lib.php';
require_once '/var/www/sites/trywebwiz/private/lib/anthropic.php';

const EDIT_CAP_U = 5;
const MAX_LOGO_BYTES   = 2 * 1024 * 1024;   // 2 MB
const MAX_PHOTO_BYTES  = 5 * 1024 * 1024;   // 5 MB
const MAX_PHOTOS       = 3;
const LOGO_MIMES   = ['image/png', 'image/svg+xml', 'image/jpeg'];
const PHOTO_MIMES  = ['image/png', 'image/jpeg', 'image/webp'];

function up_fail(string $m, int $code = 400) { http_response_code($code); echo json_encode(['error' => $m]); exit; }

$token = trim((string)($_POST['token'] ?? ''));
if (!preg_match('~^[a-f0-9]{24}$~', $token)) up_fail('Invalid token.');

$db = ww_db();
$job = $db->prepare("SELECT id, edit_count, generation_mode FROM jobs WHERE token = ? LIMIT 1");
$job->execute([$token]);
$job = $job->fetch(PDO::FETCH_ASSOC);
if (!$job) up_fail('Preview not found.', 404);
if (($job['generation_mode'] ?? '') !== 'magic') up_fail('Uploads are only available on instant previews.', 403);

$used = (int)$job['edit_count'];
if ($used >= EDIT_CAP_U) up_fail('You\'ve used all your edits for this preview.', 403);

$base_dir   = '/var/www/sites/trywebwiz/public/preview/' . $token;
$assets_dir = $base_dir . '/assets';
$preview    = $base_dir . '/v1/index.html';
if (!is_file($preview)) up_fail('Preview file missing.', 410);
@mkdir($assets_dir, 0755, true);

$saved = ['logo' => null, 'photos' => []];

// ---- LOGO (single file, key 'logo') ----
if (isset($_FILES['logo']) && is_array($_FILES['logo']) && (int)($_FILES['logo']['size'] ?? 0) > 0) {
    $f = $_FILES['logo'];
    if ((int)$f['error'] !== UPLOAD_ERR_OK) up_fail('Logo upload failed.');
    if ((int)$f['size'] > MAX_LOGO_BYTES)   up_fail('Logo is over the 2 MB limit.');
    $mime = (string)(@mime_content_type($f['tmp_name']) ?: $f['type']);
    if (!in_array($mime, LOGO_MIMES, true)) up_fail('Logo must be PNG, SVG, or JPG.');
    $ext  = ['image/png' => 'png', 'image/svg+xml' => 'svg', 'image/jpeg' => 'jpg'][$mime] ?? 'png';
    $name = 'logo.' . $ext;
    if (!@move_uploaded_file($f['tmp_name'], $assets_dir . '/' . $name)) up_fail('Could not save logo.');
    @chmod($assets_dir . '/' . $name, 0644);
    $saved['logo'] = '/preview/' . $token . '/assets/' . $name;
}

// ---- PHOTOS (multiple, key 'photos' []) ----
if (isset($_FILES['photos']) && is_array($_FILES['photos']['tmp_name'] ?? null)) {
    $n = count($_FILES['photos']['tmp_name']);
    $kept = 0;
    for ($i = 0; $i < $n && $kept < MAX_PHOTOS; $i++) {
        if ((int)($_FILES['photos']['size'][$i] ?? 0) === 0) continue;
        if ((int)$_FILES['photos']['error'][$i] !== UPLOAD_ERR_OK) continue;
        if ((int)$_FILES['photos']['size'][$i] > MAX_PHOTO_BYTES) up_fail('Photo ' . ($i + 1) . ' is over the 5 MB limit.');
        $tmp = (string)$_FILES['photos']['tmp_name'][$i];
        $mime = (string)(@mime_content_type($tmp) ?: $_FILES['photos']['type'][$i]);
        if (!in_array($mime, PHOTO_MIMES, true)) up_fail('Photos must be JPG, PNG, or WebP.');
        $ext  = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime] ?? 'jpg';
        $name = 'photo-' . ($kept + 1) . '.' . $ext;
        if (!@move_uploaded_file($tmp, $assets_dir . '/' . $name)) up_fail('Could not save photo ' . ($i + 1) . '.');
        @chmod($assets_dir . '/' . $name, 0644);
        $saved['photos'][] = '/preview/' . $token . '/assets/' . $name;
        $kept++;
    }
}

if (!$saved['logo'] && !$saved['photos']) up_fail('No files were uploaded.');

// ---- Trigger a single Wizzy edit that weaves the new assets into the page ----
$current_html = (string)file_get_contents($preview);
$asset_lines = [];
if ($saved['logo'])   $asset_lines[] = 'Logo: https://trywebwiz.com' . $saved['logo'];
if ($saved['photos']) foreach ($saved['photos'] as $p) $asset_lines[] = 'Photo: https://trywebwiz.com' . $p;
$asset_block = implode("\n", $asset_lines);

$system = <<<SYS
You are Wizzy, a senior web designer. The customer just uploaded real brand
assets (a logo and/or photos). Update the single-page HTML to use them:
- Logo: replace the current header/hero brand mark with the uploaded logo.
- Photos: replace the most prominent hero or feature images with the uploaded
  photos in a way that fits the layout. Keep aspect ratios sensible.
Preserve all other copy, structure, and design.

OUTPUT FORMAT (strict): Return ONLY the complete updated HTML document.
Start with <!DOCTYPE html> and end with </html>. No commentary or fences.
Inline all CSS and JS. No external files. Mobile-responsive.
SYS;

$user_content = "Here is the current HTML:\n\n<current_html>\n"
              . $current_html
              . "\n</current_html>\n\nUploaded assets to integrate:\n\n<assets>\n"
              . $asset_block
              . "\n</assets>\n\nReturn the COMPLETE updated HTML.";

try {
    $res = anthropic_chat('claude-sonnet-4-6', [['role' => 'user', 'content' => $user_content]], $system, 16000, 0.4, (int)$job['id'], ['</html>']);
    $text = (string)($res['text'] ?? '');
    if ($text === '') throw new Exception('empty model response');
    if (stripos($text, '</html>') === false) $text .= '</html>';
    $text = preg_replace('~^\s*```(?:html)?\s*~i', '', $text);
    $text = preg_replace('~\s*```\s*$~', '', $text);
    if (!preg_match('~<!doctype html|<html~i', $text)) throw new Exception('model did not return HTML');

    $snap_dir = $base_dir . '/v1/edits';
    @mkdir($snap_dir, 0755, true);
    @copy($preview, $snap_dir . '/v' . ($used + 1) . '-prior.html');
    if (file_put_contents($preview, $text) === false) throw new Exception('could not save updated preview');

    $db->prepare("UPDATE jobs SET edit_count = edit_count + 1 WHERE id = ?")->execute([(int)$job['id']]);
    $remaining = max(0, EDIT_CAP_U - ($used + 1));

    echo json_encode([
        'ok' => true,
        'edits_remaining' => $remaining,
        'cap_hit' => $remaining === 0,
        'logo_url' => $saved['logo'],
        'photo_urls' => $saved['photos'],
        'preview_url' => '/preview/' . $token . '/v1/index.html?e=' . ($used + 1),
        'reply' => $remaining === 0
            ? "Got 'em — and that was your last tweak. If you love it, let's make it real."
            : "Got it. I'll work these in.",
    ]);
} catch (Throwable $e) {
    up_fail('Asset integration failed: ' . preg_replace('/[\x00-\x1F]+/', ' ', $e->getMessage()), 500);
}
