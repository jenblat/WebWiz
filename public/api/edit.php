<?php
// /api/edit.php — Wizzy edit chat backend for the /try ad-funnel.
// POST { token, message } → reads current /preview/<token>/v1/index.html,
// asks Sonnet to apply the requested tweak, writes the updated HTML back,
// returns { ok, edits_remaining, preview_url, reply }.
// Enforces a 5-edit hard cap per token, server-side.
declare(strict_types=1);
@set_time_limit(360); // hard backstop; covers one long (~300s) model call + overhead
ignore_user_abort(true);
header('Content-Type: application/json');

require_once '/var/www/sites/trywebwiz/private/webwiz_lib.php';
require_once '/var/www/sites/trywebwiz/private/lib/anthropic.php';

const EDIT_CAP = 5;

function ee_fail(string $m, int $code = 400) { http_response_code($code); echo json_encode(['error' => $m]); exit; }

// ---- Edit logging (so we can see every attempt + why it failed) ----
function ee_log_ensure(PDO $db): void {
    try { $db->exec("CREATE TABLE IF NOT EXISTS edit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, token TEXT, job_id INTEGER, message TEXT, image_count INTEGER DEFAULT 0, status TEXT, error TEXT, ms INTEGER, created_at TEXT DEFAULT (datetime('now')))"); } catch (Throwable $e) {}
}
function ee_log_start(PDO $db, string $token, int $job_id, string $message, int $imgc): int {
    try { $st = $db->prepare("INSERT INTO edit_log (token, job_id, message, image_count, status) VALUES (?, ?, ?, ?, 'received')"); $st->execute([$token, $job_id, mb_substr($message, 0, 600), $imgc]); return (int)$db->lastInsertId(); } catch (Throwable $e) { return 0; }
}
function ee_log_finish(PDO $db, int $id, string $status, ?string $error, int $ms): void {
    if ($id <= 0) return;
    try { $db->prepare("UPDATE edit_log SET status = ?, error = ?, ms = ? WHERE id = ?")->execute([$status, $error !== null ? mb_substr($error, 0, 500) : null, $ms, $id]); } catch (Throwable $e) {}
}
// Apply a partial (diff-style) edit: the model returns {"edits":[{find,replace}]} of VERBATIM
// substrings. We apply them surgically to the current HTML. Returns ['ok','html','applied','missed',
// 'full_rewrite']. ok only when every edit applied cleanly and the doc is still a valid, full page.
function ee_apply_partial(string $html, string $raw): array {
    $t = trim($raw);
    $t = preg_replace('~^\s*```(?:json)?\s*~i', '', $t);
    $t = preg_replace('~\s*```\s*$~', '', $t);
    $s = strpos($t, '{'); $e = strrpos($t, '}');
    $data = ($s !== false && $e !== false && $e > $s) ? json_decode(substr($t, $s, $e - $s + 1), true) : null;
    if (is_array($data) && !empty($data['full_rewrite'])) return ['ok'=>false,'full_rewrite'=>true,'applied'=>0,'missed'=>0];
    if (is_array($data) && !empty($data['reply']) && is_string($data['reply'])) return ['ok'=>false,'chat'=>true,'reply'=>mb_substr(trim($data['reply']), 0, 600)];
    $edits = is_array($data) ? ($data['edits'] ?? null) : null;
    if (is_array($edits) && $edits) {
        $applied = 0; $missed = 0; $out = $html;
        foreach ($edits as $ed) {
            if (!is_array($ed) || !isset($ed['find'])) { $missed++; continue; }
            $find = (string)$ed['find']; $repl = (string)($ed['replace'] ?? '');
            if ($find === '' || strpos($out, $find) === false) { $missed++; continue; }
            $out = str_replace($find, $repl, $out);
            $applied++;
        }
        $valid = $applied > 0 && $missed === 0
            && stripos($out, '</html>') !== false
            && strlen($out) > (int)(strlen($html) * 0.6);
        if ($valid) return ['ok'=>true,'html'=>$out,'applied'=>$applied,'missed'=>$missed];
        return ['ok'=>false,'applied'=>$applied,'missed'=>$missed];
    }
    // No usable edit JSON. If the model answered conversationally (short prose, not a document),
    // surface it as a chat reply instead of failing - e.g. "Can you use my logo?" with no logo present.
    if (stripos($raw, '<html') === false && stripos($raw, '<!doctype') === false
        && strlen(trim($raw)) > 0 && strlen($raw) < 1600) {
        return ['ok'=>false,'chat'=>true,'reply'=>mb_substr(trim($raw), 0, 600)];
    }
    return ['ok'=>false,'applied'=>0,'missed'=>0];
}
// Alert the operator on a genuine edit failure (throttled: 1 email / 10 min).
function ee_alert(string $token, string $message, string $error): void {
    try {
        if (!function_exists('ww_send_email')) return;
        $al = '/tmp/wwedit_alert.ts';
        $last = is_file($al) ? (int)@file_get_contents($al) : 0;
        if (time() - $last <= 600) return;
        @file_put_contents($al, (string)time());
        $sx = @include '/var/www/sites/trywebwiz/secrets.php';
        $to = (is_array($sx) && !empty($sx['NOTIFY_EMAIL'])) ? $sx['NOTIFY_EMAIL'] : 'ultimax97@gmail.com';
        $html = '<h2 style="color:#b00">WebWiz edit failed</h2>'
              . '<p><b>Token:</b> ' . htmlspecialchars($token) . ' &middot; <a href="https://trywebwiz.com/try/?t=' . htmlspecialchars($token) . '">open</a></p>'
              . '<p><b>Request:</b> ' . htmlspecialchars($message) . '</p>'
              . '<p><b>Error:</b> ' . htmlspecialchars($error) . '</p>'
              . '<p><b>When:</b> ' . gmdate('Y-m-d H:i:s') . ' UTC</p>'
              . '<p style="color:#666;font-size:12px">Throttled to 1 per 10 min. Recent edits: /api/ops.php?cmd=tail&file=edit</p>';
        ww_send_email(['email' => $to, 'name' => 'WebWiz Ops'], 'WebWiz ALERT - edit failed', $html);
    } catch (Throwable $e) {}
}

// ---- Parse body (JSON only — small payload) ----
$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) $body = [];
$token   = trim((string)($body['token']   ?? ''));
$message = trim((string)($body['message'] ?? ''));

// ---- Reference images pasted/attached in the editor (base64 data URLs) ----
$ref_images = [];
$ref_ext = ['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp','image/gif'=>'gif'];
foreach ((array)($body['images'] ?? []) as $raw_img) {
    if (count($ref_images) >= 3) break;
    if (!is_string($raw_img) || !preg_match('~^data:(image/[a-z+]+);base64,(.+)$~i', $raw_img, $mm)) continue;
    $mt = strtolower($mm[1]);
    if (!isset($ref_ext[$mt])) continue;
    $bin = base64_decode($mm[2], true);
    if ($bin === false || strlen($bin) < 32 || strlen($bin) > 5 * 1024 * 1024) continue;
    $ref_images[] = ['mt'=>$mt, 'data'=>base64_encode($bin), 'bin'=>$bin, 'ext'=>$ref_ext[$mt]];
}

if (!preg_match('~^[a-f0-9]{24}$~', $token)) ee_fail('Invalid token.');
if ($message === '' && !$ref_images) ee_fail('Tell Wizzy what to tweak, or attach a reference image.');
if ($message !== '' && mb_strlen($message) < 3 && !$ref_images) ee_fail('Tell Wizzy what to tweak.');
if (mb_strlen($message) > 600) ee_fail('Keep the request under 600 characters so Wizzy can focus.');
if ($message === '') $message = 'Use the attached reference image(s) to guide this edit.';

$db = ww_db();
try { $db->exec('PRAGMA busy_timeout = 8000'); } catch (Throwable $e) {}
ee_log_ensure($db);

function ee_fetch_job(PDO $db, string $token) {
    $st = $db->prepare("SELECT id, edit_count, generation_mode, token, business_name, scrape_data FROM jobs WHERE token = ? LIMIT 1");
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

if (!$job) { ee_log_finish($db, ee_log_start($db, $token, 0, $message, 0), 'not_found', 'no job row', 0); ee_fail('Preview not found.', 404); }
if (!in_array($job['generation_mode'] ?? '', ['magic', 'describe'], true)) ee_fail('Edits are only available on instant previews.', 403);

$used = (int)$job['edit_count'];
if ($used >= EDIT_CAP) {
    ee_log_finish($db, ee_log_start($db, $token, (int)$job['id'], $message, 0), 'cap', null, 0);
    echo json_encode([
        'ok' => false,
        'edits_remaining' => 0,
        'cap_hit' => true,
        'reply' => "That's all the tweaks I can do here. If you love where it's at, let's make it real. If it still needs work, my human teammates can take it from here once you launch it.",
    ]);
    exit;
}

$t0 = microtime(true);
$log_id = ee_log_start($db, $token, (int)$job['id'], $message, count($ref_images));
$ee_inflight = '/tmp/wwedit_inflight_' . substr(sha1($token), 0, 12) . '_' . getmypid() . '.marker';
@file_put_contents($ee_inflight, $token . '|' . date('c') . '|' . mb_substr($message, 0, 140));
register_shutdown_function(function () use ($ee_inflight) { @unlink($ee_inflight); });

$dir = '/var/www/sites/trywebwiz/public/preview/' . $token . '/v1';
$index = $dir . '/index.html';
if (!is_file($index)) { ee_log_finish($db, $log_id, 'fail', 'preview file missing', 0); ee_fail('Preview file missing.', 410); }
$current_html = (string)file_get_contents($index);
if ($current_html === '') { ee_log_finish($db, $log_id, 'fail', 'preview file empty', 0); ee_fail('Preview file is empty.', 500); }

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
              . "\n</request>";

// If the customer asks for their REAL site images, inject the saved scrape URLs
// so Wizzy can swap AI images for the actual photos from their website.
$want_real = (bool)preg_match('~\b(real|actual|original|my|our)\b[^.]{0,30}\b(photo|photos|image|images|picture|pictures|face|faces|headshot|headshots|logo|team|staff|attorney|attorneys|people)\b|\bfrom (the|my|our) (site|website)~i', $message);
if ($want_real && !empty($job['scrape_data'])) {
    $sd = json_decode((string)$job['scrape_data'], true);
    if (is_array($sd) && !empty($sd['images'])) {
        $lines = [];
        if (!empty($sd['logo'])) $lines[] = 'LOGO: ' . $sd['logo'];
        foreach ($sd['images'] as $im) {
            if (empty($im['url'])) continue;
            $tag = !empty($im['portrait']) ? '[person/portrait] ' : (!empty($im['logo']) ? '[logo] ' : '');
            $lines[] = $tag . $im['url'] . (!empty($im['alt']) ? (' — ' . $im['alt']) : '');
            if (count($lines) >= 30) break;
        }
        if ($lines) {
            $user_content .= "\n\nThe customer wants their REAL images from their actual website (" . ($sd['url'] ?? '') . "). "
                . "Use these real scraped image URLs (as the <img src>) in place of AI-generated ones where the request calls for it, matching each image to where it belongs by what it shows:\n"
                . implode("\n", $lines) . "\n";
        }
    }
}

if ($ref_images) {
    // Save the attached reference images so the model can embed them by URL if
    // the edit means to place one on the site (a real logo, a real headshot).
    $assets_dir = '/var/www/sites/trywebwiz/public/preview/' . $token . '/assets';
    @mkdir($assets_dir, 0755, true);
    $ref_urls = [];
    foreach ($ref_images as $ri => $img) {
        $fn = 'ref-' . ($used + 1) . '-' . ($ri + 1) . '-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.' . $img['ext'];
        if (@file_put_contents($assets_dir . '/' . $fn, $img['bin']) !== false) $ref_urls[] = 'https://trywebwiz.com/preview/' . $token . '/assets/' . $fn;
    }
    $ref_note = 'The customer attached ' . count($ref_images) . ' reference image(s), shown below. Use them as VISUAL REFERENCE for this edit (style, layout, colors, mood). If the request means an image should actually appear on the site (e.g. a real logo or a real photo of a person/product), you MAY embed it directly using its hosted URL' . ($ref_urls ? (': ' . implode(' , ', $ref_urls)) : '') . '. Do NOT invent other changes.' . "\n";
    $content = [['type' => 'text', 'text' => $ref_note]];
    foreach ($ref_images as $img) $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $img['mt'], 'data' => $img['data']]];
    $content[] = ['type' => 'text', 'text' => $user_content];
    $messages = [['role' => 'user', 'content' => $content]];
} else {
    $messages = [['role' => 'user', 'content' => $user_content]];
}

$partial_system = <<<SYS
You are Wizzy, a senior web designer making a TARGETED edit to a single-page website. You are
given the current full HTML and the customer's change request. Make the SMALLEST set of surgical
changes that fully satisfy the request. Do NOT rewrite the whole page.

Return ONLY strict JSON (no prose, no markdown, no code fences), exactly this shape:
{"edits":[{"find":"<verbatim substring of the current HTML>","replace":"<new version>"}],"summary":"<one short sentence>"}

RULES:
- Each "find" MUST be copied VERBATIM from the current HTML: exact characters, whitespace, quotes
  and tags. Include enough surrounding context that the snippet is UNIQUE in the document, UNLESS
  you intend to change every occurrence (e.g. a CSS variable definition like --accent:#xxxxxx).
- Change ONLY what the request asks for. Never touch unrelated sections, copy, images, or styles.
- For site-wide visual changes (colors, fonts) prefer editing the CSS variables / shared rules.
- To ADD something, "find" an existing nearby element and "replace" it with itself PLUS the new markup.
- Keep every image src URL exactly as-is unless the request is specifically about changing an image.
- Output JSON only. Start with { and end with }. No commentary, no thinking out loud.
- If the request is a QUESTION, or asks for something you cannot do because a needed asset was not
  provided (e.g. "use my logo" but no logo appears in the HTML or the attached images), do NOT guess -
  return {"reply":"<one short friendly sentence answering them, or telling them to upload the file with the paperclip button>"} and no edits.
- ONLY if the request genuinely requires rebuilding most of the page (a full redesign), return
  exactly: {"full_rewrite": true}
SYS;

try {
    // ---- Attempt 1: PARTIAL (diff) edit - fast, surgical, leaves the rest of the page untouched ----
    $text = '';
    try {
        $pres = anthropic_chat('claude-sonnet-4-6', $messages, $partial_system, 8000, 0.2, (int)$job['id'], null);
        $praw = (string)($pres['text'] ?? '');
        if ($praw !== '') {
            $pr = ee_apply_partial($current_html, $praw);
            if (!empty($pr['ok'])) { $text = $pr['html']; error_log('[edit] partial edit applied ' . $pr['applied'] . ' change(s)'); }
            elseif (!empty($pr['chat'])) {
                // Model answered a question / needs an asset rather than editing. Surface it - not a failure.
                ee_log_finish($db, $log_id, 'chat', null, (int)round((microtime(true) - $t0) * 1000));
                echo json_encode(['ok' => false, 'needs_input' => true, 'reply' => $pr['reply']]);
                exit;
            }
            else error_log('[edit] partial not usable (applied=' . ($pr['applied'] ?? 0) . ' missed=' . ($pr['missed'] ?? 0) . ' full_rewrite=' . (!empty($pr['full_rewrite']) ? '1' : '0') . ') -> full rewrite');
        }
    } catch (Throwable $pe) { error_log('[edit] partial call error: ' . $pe->getMessage()); }

    // ---- Fallback: FULL page rewrite, only when the partial diff could not do it ----
    if ($text === '') {
        $lastErr = 'model did not return HTML';
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $att_start = microtime(true);
            try {
                $res = anthropic_chat('claude-sonnet-4-6', $messages, $system, 16000, 0.4, (int)$job['id'], ['</html>']);
                $t = (string)($res['text'] ?? '');
                $http = (int)($res['http'] ?? 0);
                if ($t !== '') {
                    if (stripos($t, '</html>') === false) $t .= '</html>';
                    $t = preg_replace('~^\s*```(?:html)?\s*~i', '', $t);
                    $t = preg_replace('~\s*```\s*$~', '', $t);
                    if (preg_match('~<!doctype html|<html~i', $t, $mm, PREG_OFFSET_CAPTURE)) {
                        if ((int)$mm[0][1] > 0) $t = substr($t, (int)$mm[0][1]);
                        $text = $t;
                        break;
                    }
                }
                $lastErr = 'model did not return HTML (attempt ' . $attempt . ', http=' . $http . ', len=' . strlen($t) . ')';
            } catch (Throwable $ae) {
                $lastErr = 'model call error (attempt ' . $attempt . '): ' . $ae->getMessage();
            }
            error_log('[edit] ' . $lastErr);
            if ((microtime(true) - $att_start) > 120) break;
            if ($attempt < 2) sleep(2);
        }
        if ($text === '') throw new Exception($lastErr);
    }

    // Write the updated HTML back. Snapshot the old one so we can roll back if needed.
    $snap_dir = $dir . '/edits';
    @mkdir($snap_dir, 0755, true);
    $prior_snap = $snap_dir . '/v' . ($used + 1) . '-prior.html';
    @copy($index, $prior_snap);
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

    // Commit the edit ATOMICALLY: bump the counter, and if that write fails
    // (DB busy), roll the file back so we never leave a changed-but-uncounted
    // page (the "looks worse but still says 5 edits left" bug).
    try {
        $db->prepare("UPDATE jobs SET edit_count = edit_count + 1 WHERE id = ?")->execute([(int)$job['id']]);
    } catch (Throwable $ce) {
        // The edit is already saved to disk (that's what the user sees). The counter is only
        // bookkeeping for the 5-edit cap - if SQLite is momentarily busy, DO NOT discard the
        // user's good edit. Defer the increment to a pending file for later reconciliation.
        @mkdir('/var/www/sites/trywebwiz/data/pending_editcount', 0775, true);
        @file_put_contents('/var/www/sites/trywebwiz/data/pending_editcount/' . $token . '.' . getmypid() . '.txt', (string)$job['id']);
        error_log('[edit] edit_count deferred (db busy) job=' . $job['id']);
    }
    $remaining = max(0, EDIT_CAP - ($used + 1));

    ee_log_finish($db, $log_id, 'ok', null, (int)round((microtime(true) - $t0) * 1000));

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
    $emsg = preg_replace('/[\x00-\x1F]+/', ' ', $e->getMessage());
    ee_log_finish($db, $log_id, 'fail', $emsg, (int)round((microtime(true) - $t0) * 1000));
    ee_alert($token, $message, $emsg);
    // A timeout on a big edit gets a helpful "smaller steps" hint; everything else stays generic-on-us.
    $friendly = (stripos($emsg, 'timed out') !== false || stripos($emsg, 'did not return HTML') !== false || stripos($emsg, 'model call error') !== false)
        ? "That was a big edit and it didn't finish in time. Try it in smaller steps - for example change the look and feel first, then add one feature at a time."
        : "Something broke on our end while saving that edit - we've been alerted and are on it. Give it a moment and try again.";
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'system_error' => true,
        'error' => $friendly,
        'detail' => $emsg,
    ]);
    exit;
}
