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
try { $db->exec('PRAGMA busy_timeout = 8000'); } catch (Throwable $e) {}

function ee_fetch_job(PDO $db, string $token) {
    $st = $db->prepare("SELECT id, edit_count, generation_mode, token, business_name FROM jobs WHERE token = ? LIMIT 1");
    $st->execute([$token]);
    return $st->fetch(PDO::FETCH_ASSOC);
}

$job = ee_fetch_job($db, $token);

// Recovery path: magic.php sometimes defers DB writes to a pending_magic JSON
// file when SQLite is briefly busy. If the user hits Send in the chat before
// the next gen request drains that queue, we'd 404 even though the preview
// is right there on disk. Drain THIS token's pending entry inline.
if (!$job) {
    $pending = '/var/www/sites/trywebwiz/data/pending_magic/' . $token . '.json';
    if (is_file($pending)) {
        try {
            $p = json_decode((string)@file_get_contents($pending), true);
            if (is_array($p) && !empty($p['token'])) {
                $db->exec('BEGIN IMMEDIATE');
                $st = $db->prepare("INSERT INTO prospects (email, name, business_name, current_url, source) VALUES (?, ?, ?, ?, 'magic')");
                $st->execute([$p['email'] ?? '', $p['name'] ?? '', $p['biz'] ?? '', $p['website'] ?? '']);
                $pid = (int)$db->lastInsertId();
                $st = $db->prepare("INSERT INTO jobs (type, prospect_id, customer_email, business_name, status, scheduled_for, token, generation_mode, item_status, total_cost_cents, completed_at, qa_status) VALUES ('outbound', ?, ?, ?, 'ready', datetime('now'), ?, 'magic', 'done', ?, datetime('now'), 'magic')");
                $st->execute([$pid, $p['email'] ?? '', $p['biz'] ?? '', $p['token'], (int)round(((float)($p['cost'] ?? 0)) * 100)]);
                $jid = (int)$db->lastInsertId();
                $st = $db->prepare("INSERT INTO previews (job_id, variant_n, html_path, qa_score, qa_pass, qa_issues) VALUES (?, ?, ?, NULL, NULL, NULL)");
                foreach (($p['variants'] ?? [1]) as $vn) { $st->execute([$jid, (int)$vn, '/preview/' . $p['token'] . '/v' . (int)$vn . '/index.html']); }
                $db->exec('COMMIT');
                @unlink($pending);
            }
        } catch (Throwable $e) {
            try { $db->exec('ROLLBACK'); } catch (Throwable $ee) {}
            error_log('[edit] pending drain failed: ' . $e->getMessage());
        }
        $job = ee_fetch_job($db, $token);
    }
}

// Last-resort fallback: if the preview file exists on disk but no DB row was
// created (e.g. magic.php crashed mid-persist), insert a minimal stub row so
// edits still work. INSERT OR IGNORE handles the race where another path
// inserted simultaneously.
if (!$job) {
    $preview_path = '/var/www/sites/trywebwiz/public/preview/' . $token . '/v1/index.html';
    if (is_file($preview_path)) {
        try {
            $db->prepare("INSERT OR IGNORE INTO jobs (type, status, scheduled_for, token, generation_mode, item_status, completed_at, qa_status, edit_count) VALUES ('outbound', 'ready', datetime('now'), ?, 'magic', 'done', datetime('now'), 'magic', 0)")
               ->execute([$token]);
        } catch (Throwable $e) { error_log('[edit] stub insert failed: ' . $e->getMessage()); }
        $job = ee_fetch_job($db, $token);
    }
}

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

    // ---- Post-edit image quality pass ----
    // Three layers:
    //  1. Real-ESRGAN upscale on small scraped images via ww_apply_upscale.
    //  2. Pre-warm new /api/genimg.php URLs Sonnet introduced.
    //  3. If the USER message asked about image quality, run an aggressive
    //     enhance pass that REPLACES stubbornly-small img.php URLs with
    //     Imagen-generated photos derived from the alt text.
    try {
        require_once '/var/www/sites/trywebwiz/private/lib/replicate.php';
        if (function_exists('ww_apply_upscale')) {
            $upscaled = ww_apply_upscale($text, (int)$job['id']);
            if ($upscaled && $upscaled !== $text) {
                file_put_contents($index, $upscaled);
                $text = $upscaled;
            }
        }

        // Detect "fix the image quality" intent in the user's edit message.
        $msg_lc = strtolower($message);
        $enhance_intent = (bool)preg_match('~\b(upscale|upsize|upsiz|higher\s*(?:res|resolution)|pixelat|low\s*(?:res|resolution|quality)|bigger\s*image|image\s*quality|crisp\w*|blurr?y|grainy|fuzzy|mush\w*|sharper)\b~', $msg_lc);
        if ($enhance_intent && function_exists('ww_img_cache_dims')) {
            $biz_name = (string)($job['business_name'] ?? '');
            $replacements = 0;
            // Find every img.php URL with its alt + src
            preg_match_all('~<img\s+[^>]*src="(/api/img\.php\?u=([^"&]+)(?:&[^"]*)?)"[^>]*alt="([^"]*)"[^>]*>~i', $text, $im, PREG_SET_ORDER);
            foreach ($im as $match) {
                $full_url = $match[1];
                $enc      = $match[2];
                $alt      = trim($match[3]);
                $orig     = urldecode($enc);
                $dims     = ww_img_cache_dims($orig);
                if (!$dims) continue;
                // Still too small AFTER upscale to feel crisp on hero/card. Below 800px → replace.
                if ((int)$dims[0] >= 800) continue;
                // Skip logos / certifications — replacing those would be lying.
                if (preg_match('~(logo|cert|badge|seal|award|recogni)~i', $alt . ' ' . $orig)) continue;
                // Build a contextual Imagen prompt from the alt text + business name.
                $prompt_text = $alt !== '' ? $alt : 'professional editorial photo for ' . $biz_name;
                $prompt_text .= ', photorealistic, professional photography, high resolution, natural lighting';
                $ar = '4:3';
                if (preg_match('~hero|background|banner|cover~i', $alt)) $ar = '16:9';
                elseif (preg_match('~portrait|founder|attorney|owner|headshot~i', $alt)) $ar = '3:4';
                $new_url = '/api/genimg.php?' . http_build_query(['prompt' => $prompt_text, 'ar' => $ar, 'l' => substr($alt, 0, 32)]);
                $text = str_replace($full_url, $new_url, $text);
                $replacements++;
                if ($replacements >= 6) break; // hard cap per edit to control cost
            }
            if ($replacements > 0) {
                file_put_contents($index, $text);
                error_log("[edit] enhance pass replaced $replacements img(s) with /api/genimg.php (intent detected)");
            }
        }
    } catch (Throwable $e) { error_log('[edit] upscale/enhance failed: ' . $e->getMessage()); }

    // Pre-warm /api/genimg.php URLs in parallel (8s budget).
    try {
        if (preg_match_all('~/api/genimg\.php\?[^"\'\s<>]+~', $text, $gm)) {
            $urls = array_values(array_unique($gm[0]));
            if ($urls) {
                $mh = curl_multi_init();
                $handles = [];
                foreach (array_slice($urls, 0, 10) as $u) {
                    $ch = curl_init('https://trywebwiz.com' . html_entity_decode($u, ENT_QUOTES));
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT        => 9,
                        CURLOPT_CONNECTTIMEOUT => 3,
                        CURLOPT_HTTPHEADER     => ['user-agent: WebWiz-EditWarm/1.0'],
                    ]);
                    curl_multi_add_handle($mh, $ch);
                    $handles[] = $ch;
                }
                $deadline = microtime(true) + 8.0;
                do {
                    curl_multi_exec($mh, $running);
                    if ($running > 0) curl_multi_select($mh, 0.3);
                } while ($running > 0 && microtime(true) < $deadline);
                foreach ($handles as $ch) { curl_multi_remove_handle($mh, $ch); curl_close($ch); }
                curl_multi_close($mh);
            }
        }
    } catch (Throwable $e) { error_log('[edit] genimg pre-warm failed: ' . $e->getMessage()); }

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
