<?php
// Public "magic link" real-time generator.
// Accepts GET (existing cold-email links) OR POST (new /try ad-funnel page).
// Inputs: website (required), name (optional), email (optional), description (optional),
//         v / variants (1-3, default from settings).
//
// PHILOSOPHY: the preview is a FILE ON DISK at /preview/<token>/v1/index.html. That's
// what the user sees. The SQLite rows (prospects/jobs/previews/magic_hits) are admin
// metadata. If SQLite is briefly busy at persist time, we MUST NOT block the user — we
// queue the metadata to a pending-writes directory and let a worker sync it later.
// Better to ship a working preview without admin metadata than to fail the user.
declare(strict_types=1);

@set_time_limit(180);
ignore_user_abort(false);
header('Content-Type: application/json');

require_once '/var/www/sites/trywebwiz/private/worker.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input') ?: '';
    if ($raw !== '') {
        $d = json_decode($raw, true);
        if (is_array($d)) {
            foreach ($d as $k => $v) { if (!isset($_GET[$k])) $_GET[$k] = $v; }
        }
    }
    foreach ($_POST as $k => $v) { if (!isset($_GET[$k])) $_GET[$k] = $v; }
}

// ---------- Notify-form quick capture ----------
// User submitted the email-notify card while gen is in progress. Stash the
// email keyed by IP so the gen flow can send a "your site is ready" email
// when it finishes. Return immediately so this hits before the per-IP lock.
if (isset($_GET['notify_email']) && $_GET['notify_email'] !== '' && !isset($_GET['website']) && !isset($_GET['url'])) {
    $notify_email_in = trim((string)$_GET['notify_email']);
    if (filter_var($notify_email_in, FILTER_VALIDATE_EMAIL)) {
        $ip_raw = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
        $ip_first = trim(explode(',', $ip_raw)[0]);
        @file_put_contents('/tmp/wwnotify_' . substr(sha1($ip_first), 0, 16) . '.txt', $notify_email_in);
        echo json_encode(['ok' => true, 'queued' => true]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'invalid email']);
    }
    exit;
}

$db = ww_db();
try { $db->exec('PRAGMA busy_timeout = 30000'); } catch (Throwable $e) {}

// Drain any pending_magic metadata files that previous requests couldn't persist
// (they fell back to disk when SQLite was briefly busy after litespeed_finish_request).
// This runs BEFORE the long Anthropic call so we use the calm DB window. Tight 1s
// budget so we never noticeably delay the user.
(function () use ($db) {
    $pendingDir = '/var/www/sites/trywebwiz/data/pending_magic';
    if (!is_dir($pendingDir)) return;
    $files = glob($pendingDir . '/*.json') ?: [];
    if (!$files) return;
    $deadline = microtime(true) + 1.0;
    foreach ($files as $f) {
        if (microtime(true) > $deadline) return;
        $raw = @file_get_contents($f);
        if (!$raw) { @unlink($f); continue; }
        $p = json_decode($raw, true);
        if (!is_array($p) || empty($p['token'])) { @unlink($f); continue; }
        try {
            $db->exec('BEGIN IMMEDIATE');
            $st = $db->prepare("INSERT INTO prospects (email, name, business_name, current_url, source, description) VALUES (?, ?, ?, ?, 'magic', ?)");
            $st->execute([$p['email'] ?? '', $p['name'] ?? '', $p['biz'] ?? '', (!empty($p['describe']) ? null : ($p['website'] ?? '')), (!empty($p['describe']) ? ($p['description'] ?? '') : null)]);
            $pid = (int)$db->lastInsertId();
            $st = $db->prepare("INSERT INTO jobs (type, prospect_id, customer_email, business_name, scrape_data, status, scheduled_for, token, generation_mode, item_status, total_cost_cents, completed_at, qa_status) VALUES ('outbound', ?, ?, ?, ?, 'ready', datetime('now'), ?, ?, 'done', ?, datetime('now'), 'magic')");
            $st->execute([$pid, $p['email'] ?? '', $p['biz'] ?? '', ($p['scrape_data'] ?? null), $p['token'], ($p['generation_mode'] ?? 'magic'), (int)round(((float)($p['cost'] ?? 0)) * 100)]);
            $jid = (int)$db->lastInsertId();
            $st = $db->prepare("INSERT INTO previews (job_id, variant_n, html_path, qa_score, qa_pass, qa_issues) VALUES (?, ?, ?, NULL, NULL, NULL)");
            foreach (($p['variants'] ?? [1]) as $vn) { $st->execute([$jid, (int)$vn, '/preview/' . $p['token'] . '/v' . (int)$vn . '/index.html']); }
            $st = $db->prepare("INSERT INTO magic_hits (ip, token) VALUES (?, ?)");
            $st->execute([$p['ip'] ?? '', $p['token']]);
            $db->exec('COMMIT');
            @unlink($f);
            // Enroll into nurture too (the live path does this; the drainer must
            // mirror it or backfilled generations never enter the cadence).
            try {
                if (!empty($p['email']) && filter_var($p['email'], FILTER_VALIDATE_EMAIL)) {
                    require_once __DIR__ . '/_nurture.php';
                    ww_nurture_upsert_contact($db, [
                        'name'        => $p['name'] ?? '',
                        'email'       => $p['email'],
                        'company'     => $p['biz'] ?? '',
                        'website'     => $p['website'] ?? '',
                        'token'       => $p['token'],
                        'preview_url' => 'https://trywebwiz.com/try/?t=' . $p['token'],
                        'source'      => 'try',
                    ]);
                }
            } catch (Throwable $ne) { ml_debug('drain nurture enroll failed: ' . $ne->getMessage()); }
        } catch (Throwable $e) {
            try { $db->exec('ROLLBACK'); } catch (Throwable $ee) {}
            return; // DB busy — give up backfill, try next request
        }
    }
})();

function ml_sget(PDO $db, string $k, string $d = ''): string { $s = $db->prepare("SELECT value FROM settings WHERE key=?"); $s->execute([$k]); $r = $s->fetchColumn(); return $r === false ? $d : (string)$r; }

/**
 * Build a list of business-appropriate Imagen prompts to fill in when the scrape
 * is thin. Returns [['prompt' => str, 'ar' => str, 'label' => str], ...].
 * Each prompt mentions the business name and is tailored to the detected type.
 */
function magic_build_image_brief(string $biz, string $description, array $scrape, int $have, int $needed): array {
    $biz_clean = trim(preg_replace('~\s+~', ' ', $biz));
    $blob = strtolower(
        (string)($scrape['title'] ?? '') . ' ' .
        (string)($scrape['description'] ?? '') . ' ' .
        $description . ' ' .
        implode(' ', (array)($scrape['h1'] ?? [])) . ' ' .
        implode(' ', (array)($scrape['h2'] ?? [])) . ' ' .
        implode(' ', array_slice((array)($scrape['paragraphs'] ?? []), 0, 10))
    );

    // Detect site type by keyword density (lightweight version of the e-commerce
    // detector — just for picking image prompt themes).
    $type = 'generic';
    if (preg_match('~\b(shop|cart|product|buy|order online|wholesale|free shipping|add to cart)\b~', $blob)) $type = 'retail';
    elseif (preg_match('~\b(menu|reservation|table|restaurant|cafe|bakery|coffee|brunch|dinner)\b~', $blob)) $type = 'restaurant';
    elseif (preg_match('~\b(inspection|inspector|repair|install|service call|estimate|quote)\b~', $blob)) $type = 'service';
    elseif (preg_match('~\b(plumber|plumbing|hvac|electrician|electrical|roofing|landscaping)\b~', $blob)) $type = 'trade';
    elseif (preg_match('~\b(designer|photographer|portfolio|agency|brand|creative)\b~', $blob)) $type = 'creative';
    elseif (preg_match('~\b(dentist|doctor|clinic|therapy|massage|wellness|chiropractor|spa)\b~', $blob)) $type = 'wellness';
    elseif (preg_match('~\b(law|lawyer|attorney|legal|consult)\b~', $blob)) $type = 'professional';
    elseif (preg_match('~\b(gym|fitness|trainer|yoga|class|coach)\b~', $blob)) $type = 'fitness';
    elseif (preg_match('~\b(salon|barber|stylist|haircut|beauty)\b~', $blob)) $type = 'salon';

    // Prompt templates by type. Each line: [prompt suffix, ar, short label]
    $templates = [
        'retail' => [
            ["studio product photography of {biz} products, clean cream backdrop, soft natural light, top-down flat lay, photorealistic", '4:3', 'Product flat-lay'],
            ["lifestyle shot of {biz} product being used in a sunlit kitchen, warm tones, shallow depth of field, photorealistic", '16:9', 'In-context lifestyle'],
            ["packaging close-up of {biz} branded product on rustic wooden surface, golden hour lighting, photorealistic", '4:3', 'Packaging close-up'],
            ["happy customer at home unboxing a {biz} package, candid moment, warm light, photorealistic", '4:3', 'Customer unboxing'],
            ["overhead arrangement of {biz} product flavors on neutral background, editorial food photography, photorealistic", '16:9', 'Flavor lineup'],
            ["small business workshop where {biz} products are made, real people working, documentary photography, photorealistic", '4:3', 'Behind the scenes'],
            ["clean minimal product banner for {biz}, brand photography, photorealistic", '16:9', 'Brand banner'],
            ["customer holding {biz} product with subtle smile, soft window light, lifestyle photography, photorealistic", '3:4', 'Customer portrait'],
        ],
        'restaurant' => [
            ["close-up of a signature dish from {biz}, fresh ingredients, natural light, restaurant photography, photorealistic", '4:3', 'Signature dish'],
            ["warm interior of {biz}, cozy lighting, wood and brick textures, restaurant photography, photorealistic", '16:9', 'Restaurant interior'],
            ["chef plating food in the kitchen of {biz}, motion blur, documentary style, photorealistic", '4:3', 'Chef at work'],
            ["overhead spread of multiple dishes on a rustic table at {biz}, communal style, food photography, photorealistic", '16:9', 'Family-style spread'],
            ["smiling customers eating at {biz}, candid moment, warm light, lifestyle photography, photorealistic", '4:3', 'Customers dining'],
            ["beautifully poured drink at {biz}, dramatic backlight, beverage photography, photorealistic", '3:4', 'Signature drink'],
        ],
        'service' => [
            ["professional service worker from {biz} in branded uniform inspecting a residential property, daylight, documentary photography, photorealistic", '4:3', 'Inspector at work'],
            ["tools of the trade laid out neatly, {biz} truck visible in the background, photorealistic", '16:9', 'Tools and truck'],
            ["before-and-after split of a residential repair completed by {biz}, photorealistic", '16:9', 'Before / after'],
            ["{biz} technician explaining findings to a homeowner in a bright living room, candid, photorealistic", '4:3', 'Customer consultation'],
            ["close-up hands of {biz} technician using diagnostic equipment, focused expression, photorealistic", '4:3', 'Hands at work'],
            ["{biz} branded service van parked in a suburban driveway, golden hour, photorealistic", '16:9', 'Branded vehicle'],
            ["completion documentation: report on tablet with {biz} branding, homeowner reviewing, photorealistic", '3:4', 'Final report'],
        ],
        'trade' => [
            ["{biz} contractor installing equipment in a residential setting, focused, professional, documentary photography, photorealistic", '4:3', 'Install in progress'],
            ["overhead shot of {biz} job site with materials laid out organized, photorealistic", '16:9', 'Job site'],
            ["close-up of skilled hands working on a residential project, {biz} logo on shirt, photorealistic", '4:3', 'Skilled hands'],
            ["before-and-after of a home improvement completed by {biz}, photorealistic", '16:9', 'Before / after'],
            ["{biz} truck and crew in front of a finished project, satisfied customers, golden hour, photorealistic", '16:9', 'Project completion'],
            ["customer shaking hands with {biz} contractor in a freshly renovated room, warm light, photorealistic", '4:3', 'Customer handshake'],
        ],
        'creative' => [
            ["clean editorial shot of {biz} portfolio work on a designer's desk, minimal styling, photorealistic", '4:3', 'Portfolio piece'],
            ["{biz} creative working in a sunlit studio, focused on their craft, photorealistic", '4:3', 'Creative at work'],
            ["close-up of crafted detail from {biz} project, beautiful texture, photorealistic", '3:4', 'Detail shot'],
            ["wide editorial of completed {biz} project displayed in context, photorealistic", '16:9', 'Project hero'],
            ["{biz} creative reviewing work with a client, candid creative-direction moment, photorealistic", '4:3', 'Client review'],
        ],
        'wellness' => [
            ["calm wellness practitioner from {biz} with relaxed client in a sunlit treatment room, photorealistic", '16:9', 'Treatment session'],
            ["minimalist interior of {biz} treatment space, plants, natural materials, soft daylight, photorealistic", '16:9', 'Calm interior'],
            ["close-up of practitioner's hands during a {biz} treatment, soothing, photorealistic", '4:3', 'Treatment hands'],
            ["welcoming reception at {biz}, plants and warm lighting, photorealistic", '4:3', 'Reception'],
            ["smiling client leaving {biz} feeling refreshed, candid lifestyle, photorealistic", '4:3', 'Happy client'],
        ],
        'professional' => [
            ["professional consultation at {biz}, attorney and client across a polished desk, modern office, photorealistic", '16:9', 'Consultation'],
            ["confident professional from {biz} in a modern office, natural window light, editorial portrait, photorealistic", '3:4', 'Professional portrait'],
            ["clean modern law office or consultancy interior at {biz}, photorealistic", '16:9', 'Office interior'],
            ["close-up of contracts and documents being reviewed at {biz}, focused hands, photorealistic", '4:3', 'Documents close-up'],
        ],
        'fitness' => [
            ["athletic client training at {biz} gym with coach guiding, motion energy, photorealistic", '16:9', 'Training session'],
            ["clean modern fitness studio at {biz}, equipment ready, golden hour light, photorealistic", '16:9', 'Studio interior'],
            ["close-up of athletic hands gripping training equipment at {biz}, photorealistic", '4:3', 'In-action detail'],
            ["smiling fit person leaving {biz} gym feeling accomplished, candid lifestyle, photorealistic", '4:3', 'Happy member'],
        ],
        'salon' => [
            ["stylist at {biz} working on a client's hair, modern salon interior, natural light, photorealistic", '4:3', 'Stylist at work'],
            ["clean modern interior of {biz} salon with mirror, plants, soft light, photorealistic", '16:9', 'Salon interior'],
            ["close-up of styled hair with stylist's hands at {biz}, photorealistic", '4:3', 'Style detail'],
            ["happy client checking finished look in the mirror at {biz}, candid moment, photorealistic", '4:3', 'Happy client'],
        ],
        'generic' => [
            ["professional team at {biz} collaborating in a bright modern workspace, candid, photorealistic", '16:9', 'Team at work'],
            ["{biz} owner or founder in a confident editorial portrait, natural window light, photorealistic", '3:4', 'Founder portrait'],
            ["clean modern interior of {biz} office or storefront, photorealistic", '16:9', 'Workplace'],
            ["satisfied customer interacting with {biz}, candid moment, warm light, photorealistic", '4:3', 'Customer moment'],
            ["close-up of the craft or product that defines {biz}, editorial detail, photorealistic", '4:3', 'Signature detail'],
            ["wide environmental shot showing the city or neighborhood where {biz} operates, golden hour, photorealistic", '16:9', 'Local context'],
        ],
    ];

    $list = $templates[$type] ?? $templates['generic'];
    // Always interleave 1-2 generic shots for variety
    if ($type !== 'generic') $list = array_merge($list, array_slice($templates['generic'], 0, 2));

    $out = [];
    foreach ($list as [$prompt, $ar, $label]) {
        $prompt = str_replace('{biz}', $biz_clean, $prompt);
        $out[] = ['prompt' => $prompt, 'ar' => $ar, 'label' => $label];
        if (count($out) >= $needed) break;
    }
    return $out;
}

/**
 * Build /api/genimg.php URLs for each prompt and fire them in parallel so the
 * Imagen calls run and the cache files land on disk. Returns the list of URLs
 * (Sonnet can use them in HTML; cache will hit instantly).
 */
function magic_pregenerate_images(array $prompts): array {
    if (!$prompts) return [];
    $urls = [];
    foreach ($prompts as $p) {
        $q = http_build_query(['prompt' => $p['prompt'], 'ar' => $p['ar'], 'l' => $p['label']]);
        $urls[] = ['url' => '/api/genimg.php?' . $q, 'label' => $p['label']];
    }
    // NOTE: intentionally NON-blocking. The genimg URLs are deterministic, so
    // Sonnet has everything it needs immediately. Only the images Sonnet actually
    // places land in the final HTML; those get warmed in parallel post-response
    // (pre-warm block) or rendered on demand by genimg.php. Skipping the blocking
    // warm here shaves ~7s off every generation.
    return $urls;
}

function ml_fail(string $m, int $code = 400) { http_response_code($code); echo json_encode(['error' => $m]); exit; }
function ml_debug(string $m) {
    @file_put_contents('/tmp/wwmagic_debug.log', '[' . date('Y-m-d H:i:s') . ' pid=' . getmypid() . '] ' . $m . "\n", FILE_APPEND);
}
// Timing log: clean phase-by-phase breakdown, append-only. Read via /api/_t.php?k=...
function ml_time(string $phase, float $dt_seconds, array $extra = []) {
    $line = sprintf('[%s pid=%d] %-20s %7.2fs%s',
        date('Y-m-d H:i:s'),
        getmypid(),
        $phase,
        $dt_seconds,
        $extra ? ' | ' . json_encode($extra) : ''
    );
    @file_put_contents('/tmp/wwmagic_timing.log', $line . "\n", FILE_APPEND);
}

if (ml_sget($db, 'magic_link_enabled', '1') !== '1') ml_fail('Instant preview is currently turned off.', 403);

$website     = trim((string)($_GET['website'] ?? $_GET['url'] ?? ''));
$name        = trim((string)($_GET['name'] ?? ''));
$email       = trim((string)($_GET['email'] ?? ''));
$company     = trim((string)($_GET['company'] ?? ''));
$description = trim((string)($_GET['description'] ?? ''));
$v           = (int)($_GET['v'] ?? $_GET['variants'] ?? ml_sget($db, 'magic_default_variants', '1'));
$v = max(1, min(3, $v ?: 1));

$describe = ((string)($_GET['describe'] ?? '') === '1') || ($website === '' && $description !== '');
if ($describe) {
    $website = '';
    if ($company === '') ml_fail('Tell Wizzy your business name.');
    if (mb_strlen($description) < 20) ml_fail('Tell Wizzy a couple of sentences about the business so Wizzy has something to design from.');
} else {
    if ($website === '') ml_fail('Add your website so Wizzy has something to start from.');
    if (!preg_match('~^https?://~i', $website)) $website = 'https://' . $website;
    if (!filter_var($website, FILTER_VALIDATE_URL)) ml_fail('That website URL looks invalid.');
}
$gen_mode = $describe ? 'describe' : 'magic';

$db->exec("CREATE TABLE IF NOT EXISTS magic_hits (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT, token TEXT, created_at TEXT DEFAULT (datetime('now')))");
try { $pc = $db->query("PRAGMA table_info(prospects)")->fetchAll(PDO::FETCH_COLUMN, 1); if (!in_array('description', (array)$pc, true)) $db->exec("ALTER TABLE prospects ADD COLUMN description TEXT"); } catch (Throwable $e) {}

$ip = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
$ip = trim(explode(',', $ip)[0]);
ml_debug("START ip=$ip website=$website");

// ---- Privileged bypass (owner/team): skip the in-flight lock AND all rate limits. ----
// True when: a logged-in admin session, OR the request IP is in the magic_bypass_ips
// allowlist, OR a valid magic_bypass_key is supplied (?ww_key= / ww_key cookie / X-WW-Key header).
$is_admin_bypass = false;
try {
    if (session_status() === PHP_SESSION_NONE) {
        require_once '/var/www/sites/trywebwiz/public/api/_session.php'; ww_session_start();
    }
    if (!empty($_SESSION['uid']) && function_exists('ww_user_by_id')) {
        $admin_u = ww_user_by_id((int)$_SESSION['uid']);
        if ($admin_u && ($admin_u['role'] ?? '') === 'admin') {
            $is_admin_bypass = true;
            ml_debug("bypass: admin session uid={$admin_u['id']} email={$admin_u['email']}");
        }
    }
} catch (Throwable $e) { /* fall through to normal limits */ }
if (!$is_admin_bypass) {
    $allow_ips = array_filter(array_map('trim', explode(',', (string)ml_sget($db, 'magic_bypass_ips', ''))));
    if ($allow_ips && in_array($ip, $allow_ips, true)) { $is_admin_bypass = true; ml_debug("bypass: allowlist ip=$ip"); }
}
if (!$is_admin_bypass) {
    $bypass_key = (string)ml_sget($db, 'magic_bypass_key', '');
    $req_key = (string)($_GET['ww_key'] ?? $_COOKIE['ww_key'] ?? $_SERVER['HTTP_X_WW_KEY'] ?? '');
    if ($bypass_key !== '' && hash_equals($bypass_key, $req_key)) {
        $is_admin_bypass = true; ml_debug("bypass: key");
        @setcookie('ww_key', $req_key, time() + 31536000, '/'); // remember a year so the team keeps access
    }
}

// Per-IP in-flight lock (prevents same-user double-submits). Skipped for privileged
// users so the owner/team can run as many concurrent generations as they want.
$ip_lock_fp = null;
$ip_lock_path = '/tmp/wwmagic_' . substr(sha1($ip), 0, 16) . '.lock';
if (!$is_admin_bypass) {
    $ip_lock_fp = @fopen($ip_lock_path, 'c');
    if (!$ip_lock_fp || !flock($ip_lock_fp, LOCK_EX | LOCK_NB)) {
        $age = is_file($ip_lock_path) ? (time() - filemtime($ip_lock_path)) : 999;
        if ($age >= 0 && $age < 180) {
            ml_fail('Wizzy is still working on your last request. Hang tight — refreshing the page will not help.', 429);
        }
        if ($ip_lock_fp) { @fclose($ip_lock_fp); }
        @unlink($ip_lock_path);
        $ip_lock_fp = @fopen($ip_lock_path, 'c');
        if ($ip_lock_fp) { flock($ip_lock_fp, LOCK_EX | LOCK_NB); }
    }
    register_shutdown_function(function () use (&$ip_lock_fp, $ip_lock_path) {
        if ($ip_lock_fp) { @flock($ip_lock_fp, LOCK_UN); @fclose($ip_lock_fp); }
        @unlink($ip_lock_path);
    });
}

if (!$is_admin_bypass) {
    $perIp = (int)ml_sget($db, 'magic_rl_per_ip_hour', '3');
    $daily = (int)ml_sget($db, 'magic_rl_daily_cap', '100');
    if ($perIp > 0) { $c = $db->prepare("SELECT COUNT(*) FROM magic_hits WHERE ip=? AND created_at > datetime('now','-1 hour')"); $c->execute([$ip]); if ((int)$c->fetchColumn() >= $perIp) ml_fail('You have reached the limit for now. Please try again a bit later.', 429); }
    if ($daily > 0) { if ((int)$db->query("SELECT COUNT(*) FROM magic_hits WHERE created_at > datetime('now','start of day')")->fetchColumn() >= $daily) ml_fail('We have hit today\'s capacity for instant previews. Please try again tomorrow.', 429); }
}

$T0 = microtime(true);
// ---- ASYNC MODE: create the token up front, return it in <1s, generate in the background. ----
// Gated behind ?async=1 (or POST async:1) so the live sync flow is untouched until proven.
$async = ((string)($_GET['async'] ?? '') === '1');
$token = bin2hex(random_bytes(12));
$dir = '/var/www/sites/trywebwiz/public/preview/' . $token;
if ($async) {
    @mkdir($dir, 0755, true);
    @file_put_contents($dir . '/status.json', json_encode(['status' => 'building', 'ts' => time()]));
    echo json_encode(['ok' => true, 'token' => $token, 'building' => true, 'status_url' => '/api/gen_status.php?t=' . $token]);
    if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
    elseif (function_exists('litespeed_finish_request')) { @litespeed_finish_request(); }
    else { @ob_flush(); @flush(); }
    @set_time_limit(240);
    ignore_user_abort(true);
    ml_debug("ASYNC token=$token returned; generating in background");
}
try {
    ml_debug('scrape begin');
    $tS = microtime(true);
    if ($describe) {
        $scrape = ['images'=>[], 'title'=>$company, 'description'=>$description, 'h1'=>[], 'h2'=>[], 'h3'=>[], 'paragraphs'=>[], 'links'=>[], 'colors'=>[], 'videos'=>[]];
        ml_debug('describe mode: scrape skipped');
    } else {
        try {
            $scrape = scrape_multi($website);
        } catch (Throwable $scrapeErr) {
            // Never dead-end a visitor on a scrape failure (weak/failed SSL,
            // unreachable, blocked, timeout). Fall back to DESCRIBE mode and build
            // from the business name + whatever description the user gave us.
            ml_debug('scrape FAILED (' . $scrapeErr->getMessage() . ') -> describe fallback for ' . $website);
            $describe = true;
            $gen_mode = 'describe';
            if (mb_strlen(trim($description)) < 20) {
                $description = trim($company . ' — a professional business. Design a clean, credible, conversion-focused site; specific details were not available from the website.');
            }
            $scrape = ['images'=>[], 'title'=>$company, 'description'=>$description, 'h1'=>[], 'h2'=>[], 'h3'=>[], 'paragraphs'=>[], 'links'=>[], 'colors'=>[], 'videos'=>[]];
        }
    }
    $scrape_dt = microtime(true)-$tS;
    ml_debug(sprintf('scrape done %.2fs imgs=%d', $scrape_dt, count($scrape['images'] ?? [])));
    ml_time('PHASE_1_scrape', $scrape_dt, ['images' => count($scrape['images'] ?? []), 'website' => $website]);

    $biz = trim((string)($scrape['business_name'] ?? ''));
    if ($biz === '') { $biz = trim((string)($scrape['h1'][0] ?? '')); }
    if ($biz === '') { $t = trim((string)($scrape['title'] ?? '')); if ($t !== '') $biz = trim((string)preg_split('~[|\-\x{2013}\x{2014}:]~u', $t)[0]); }
    if ($biz === '') { $biz = preg_replace('~^www\.~', '', (string)(parse_url($website, PHP_URL_HOST) ?: 'Your Business')); }
    $industry = '';
    if ($describe && $company !== '') $biz = $company;
    // Soft-source detection: parallel HEAD over scraped images to capture
    // Content-Length, then flag is_soft for any image whose bytes/MP-at-requested-
    // dimensions ratio is under 0.30. Wix/Squarespace/etc happily upscale tiny
    // uploads to huge dimensions; this catches them so they don't end up as a
    // pixelated hero or founder portrait.
    try {
        $mh = curl_multi_init(); $handles = []; $idx_by_url = [];
        $cand_imgs = $scrape['images'] ?? [];
        foreach ($cand_imgs as $idx => $img) {
            if (!empty($img['is_icon']) || !empty($img['is_logo']) || !empty($img['is_thumb'])) continue;
            $u = (string)($img['url'] ?? '');
            if ($u === '') continue;
            $ch = curl_init($u);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY => true, CURLOPT_HEADER => true, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; WebWizScrape)',
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[] = $ch; $idx_by_url[(int)$ch] = $idx;
        }
        $deadline = microtime(true) + 6.0;
        do { curl_multi_exec($mh, $running); if ($running > 0) curl_multi_select($mh, 0.3); } while ($running > 0 && microtime(true) < $deadline);
        $soft_count = 0;
        foreach ($handles as $ch) {
            $len = (int)curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
            $idx = $idx_by_url[(int)$ch] ?? null;
            if ($idx !== null && isset($cand_imgs[$idx]) && $len > 0) {
                $url_i = (string)$cand_imgs[$idx]['url'];
                $wa = (int)($cand_imgs[$idx]['width_hint'] ?? 0);
                $ha = (int)($cand_imgs[$idx]['height_hint'] ?? 0);
                if (function_exists('ww_image_is_soft') && ww_image_is_soft($url_i, $len, $wa, $ha)) {
                    $cand_imgs[$idx]['is_soft'] = true;
                    $soft_count++;
                }
            }
            curl_multi_remove_handle($mh, $ch); curl_close($ch);
        }
        curl_multi_close($mh);
        $scrape['images'] = $cand_imgs;
        ml_debug("soft-source detect: flagged $soft_count of " . count($cand_imgs));
    } catch (Throwable $e) { ml_debug('soft detect failed: ' . $e->getMessage()); }

    $usable = array_values(array_filter($scrape['images'] ?? [], fn($i) => empty($i['is_logo']) && empty($i['is_thumb']) && empty($i['is_team_card']) && empty($i['is_icon']) && empty($i['is_soft'])));

    // Save the REAL scraped images so the editor can offer "use my real photos" later.
    $scrape_data_json = null;
    if (!$describe) {
        $sd_imgs = [];
        foreach ((array)($scrape['images'] ?? []) as $im) {
            $u = (string)($im['url'] ?? ''); if ($u === '') continue;
            $sd_imgs[] = ['url' => $u, 'alt' => mb_substr((string)($im['alt'] ?? ''), 0, 120), 'logo' => !empty($im['is_logo']), 'portrait' => (!empty($im['is_portrait']) || !empty($im['is_cutout']) || !empty($im['is_team_card']))];
            if (count($sd_imgs) >= 40) break;
        }
        $scrape_data_json = json_encode(['url' => $website, 'title' => mb_substr((string)($scrape['title'] ?? ''), 0, 200), 'logo' => $scrape['logo'] ?? null, 'images' => $sd_imgs], JSON_UNESCAPED_SLASHES);
    }

    // ---- Proactive Imagen pre-generation ----
    // If the scrape gave us thin pickings (clipart-heavy sites, brand-new sites,
    // single-page WordPress with one stock photo), pre-generate enough business-
    // appropriate photos to give Sonnet a real pool to work with. Pre-warms the
    // genimg cache as a side effect.
    $IMAGE_TARGET = (int)ml_sget($db, 'magic_image_target', '7');
    $usable_count = count($usable);
    if ($usable_count < $IMAGE_TARGET) {
        $needed = min($IMAGE_TARGET - $usable_count, 8); // hard cap 8 = ~$0.32/gen at Imagen Fast pricing
        $prompts = magic_build_image_brief($biz, $description, $scrape, $usable_count, $needed);
        $proactive_urls = magic_pregenerate_images($prompts);
        foreach ($proactive_urls as $idx => $pu) {
            $usable[] = [
                'url'          => $pu['url'],
                'alt'          => $pu['label'],
                'width_hint'   => 1280,
                'height_hint'  => 960,
                'is_logo'      => false,
                'is_thumb'     => false,
                'is_team_card' => false,
                'is_cutout'    => false,
                'is_portrait'  => false,
                'is_icon'      => false,
                'is_generated' => true,
            ];
        }
        ml_debug("proactive imagen: scraped=$usable_count target=$IMAGE_TARGET generated=" . count($proactive_urls));
    }
    $system = build_system_prompt($industry, count($usable));

    // ---- Strategic framework prompt block ----
    // This is the FIRST and most important block. Tells Sonnet to think like a
    // marketer: WHO is the visitor, WHAT do they want, design for THAT.
    $system .= "\n\n------\nSTRATEGIC FRAMEWORK — READ THIS BEFORE WRITING ANY HTML:\n\n"
            . "You are a senior product marketer + designer at a small-business web studio. Your one job is to design a homepage that achieves the BUSINESS GOAL of THIS particular business. You are NOT making editorial art. You are NOT making a magazine. You are making a homepage that converts.\n\n"
            . "STEP 1 — Identify the business goal. Look at the scraped content and decide which of these the visitor came to do (pick exactly ONE primary, optionally one secondary):\n"
            . "  - BUY: e-commerce, retail, snacks, apparel, beauty, packaged goods → visitor came to SHOP. Primary action: add product to cart.\n"
            . "  - ORDER FOOD: restaurant, cafe, bakery, food truck → visitor came to view menu / order / find hours.\n"
            . "  - RESERVE: restaurant with reservations, salon, spa, event venue → visitor came to BOOK A TIME.\n"
            . "  - GET QUOTE: contractor, plumber, lawyer, mover, B2B service → visitor came to REQUEST WORK.\n"
            . "  - BOOK APPOINTMENT: dentist, therapist, gym, tutor → visitor came to schedule.\n"
            . "  - HIRE / SEE WORK: designer, photographer, agency, portfolio → visitor came to SEE THE WORK.\n"
            . "  - SIGN UP: SaaS, app, newsletter, course → visitor came to CREATE AN ACCOUNT.\n"
            . "  - WHOLESALE INQUIRY: B2B supplier, distributor → visitor came to REQUEST BULK ORDER.\n"
            . "  - LEARN + DECIDE: brand-story-first companies (alcohol, cosmetics, expensive items) → visitor came to UNDERSTAND THE BRAND before buying.\n\n"
            . "STEP 2 — Build the above-the-fold (first 100vh) around that goal. The visitor MUST see the primary action without scrolling. The hero is one screen tall. It must include:\n"
            . "  - A SHORT headline (≤6 words, ≤2 lines) — short enough to fit at 56-80px desktop / 36-44px mobile WITHOUT clipping. If your headline is long, REWRITE IT SHORTER. Use clamp(36px, 6vw, 72px) for the headline so it never overflows.\n"
            . "  - The PRIMARY CTA button matching the business goal (Shop the bars / Order online / Book a table / Get a quote / See our work / Start free trial / Request a sample).\n"
            . "  - A trust line near the CTA (free shipping / 4.9★ / since 1992 / 200+ clients).\n"
            . "  - A visual that shows the PRODUCT or WORK (not a founder portrait, not a wall of text, not a contact form).\n\n"
            . "STEP 3 — The second screen (100vh-200vh) must show the BUSINESS GOAL CONTENT. For retail: the product grid. For restaurant: the menu. For service: the services list with quote CTAs. For portfolio: the work grid. DO NOT put a brand-story section here. Brand story comes LATER, after the visitor has seen what they can buy/book/hire.\n\n"
            . "STEP 4 — Brand story, founder photo, history, our-values content goes in the LOWER HALF of the page, NOT the top. The visitor decides whether to read brand story AFTER seeing what's for sale.\n\n"
            . "FORBIDDEN AT ANY GOAL:\n"
            . "  - Oversized magazine headlines that clip out of the hero box. If text doesn't fit, write shorter copy. Headlines are the FIRST thing to make smaller, not stylize bigger.\n"
            . "  - 'Get in Touch', 'Welcome', 'About Us', 'Our Story', 'Hello' as the PRIMARY hero headline (those are fine as section labels lower on the page).\n"
            . "  - Generic 'Learn more' / 'Read more' as the PRIMARY hero CTA. Use the goal-specific verb.\n"
            . "  - Stat-grid blocks of abstract numbers (31+ YEARS / 100% PLANT-BASED) as a major section. A small trust bar is fine; a giant stats hero is editorial-magazine padding.\n"
            . "  - Inventing URLs, fake products, fake testimonials with quotes the scrape didn't include. Use real scraped content; for missing visuals use /api/genimg.php (rule 8 below).\n"
            . "  - Putting a REAL person's name (a named attorney, founder, doctor, agent, or team member) on an AI-generated or stock photo. If you have that person's REAL scraped photo, use it; if you do NOT have their real photo, use NO photo for them (a clean initials/monogram avatar or a name-only card is fine) - NEVER a fabricated face labelled with a real person's name.\n"
            . "  - Inventing specific facts the source did not state: no 'Since 19XX' / 'Established 19XX' / 'Serving since ...', no 'X years in business/experience', no 'N+ cases won / clients served / \$X recovered', no fabricated review counts, star ratings, or 'as seen in' logos. Only assert numbers, dates, awards, and proof that actually appear in the scraped content.\n"
            . "  - SVG placeholder fallbacks in production. Never write an <img src> to a URL you're not sure resolves. If you don't have a real image, use /api/genimg.php with a specific prompt.\n\n"
            . "LOGO TREATMENT:\n"
            . "  - If the scraped logo is on a white/light square and the page background is cream/dark, DO NOT put the raw logo flat on that background — it'll look like a sticker. Either: (a) put it inside a clean white pill/rounded-rect badge with a tight border so the white is intentional, or (b) wrap it in a circle of the brand color, or (c) use the wordmark text instead of the image logo if the wordmark reads clearly. Never let logos float as ugly white rectangles on cream.\n"
            . "  - In the footer, prefer a TEXT wordmark of the business name in the brand font, NOT a re-use of the small logo image. If you do use an image, use the same logo URL as the nav.\n\n"
            . "HERO TYPOGRAPHY (NON-NEGOTIABLE):\n"
            . "  - Headline font-size: clamp(36px, 6vw, 72px) for the MAIN hero line. Subhead: 18-22px. No headline at 100px+.\n"
            . "  - The full hero (headline + subhead + CTAs + trust line) must fit within ONE screen height (100vh) at desktop AND mobile. Test mentally: at 800px tall viewport, does it ALL fit? If not, shrink type or shorten copy.\n"
            . "  - Use a CSS line-clamp or max-height on the headline container if needed.\n\n"
            . "Apply the strategic framework FIRST, then apply the quality hardening rules below. When the two conflict, strategic framework wins.\n";

    // ---- Quality hardening prompt block (appended on the /try gen path) ----
    // Sonnet keeps producing layouts that look bad on mobile and have small
    // images floating in big cards. This block forces concrete rules:
    //   - Every card with an image renders the image as a FILLING background
    //     (object-fit:cover, fills 100% of its container — no small floating
    //     images centered in white).
    //   - Every section must have a background color or texture — no plain
    //     white sections that read as "empty".
    //   - Mobile (375px) must be designed FIRST: equal padding left/right,
    //     headings shrink, stacks vertically, no horizontal scroll.
    //   - At least one full-bleed image hero or banner per page.
    $system .= "\n\n------\nQUALITY HARDENING RULES (NON-NEGOTIABLE):\n"
            . "1. NO SMALL FLOATING IMAGES INSIDE CARDS. Every card with an image must render the image as a FILLING photo: <div style=\"aspect-ratio:4/3;overflow:hidden\"><img style=\"width:100%;height:100%;object-fit:cover;display:block\"></div>. The image fills the entire card top. NEVER center a small image inside a tall white card with padding around it.\n"
            . "2. EVERY SECTION MUST HAVE VISIBLE BACKGROUND. Alternate sections between cream/paper/dark tones, gradient washes, or photo overlays. A plain white section with just text and a few small images looks empty and unprofessional. Backgrounds make the page feel built.\n"
            . "3. MOBILE-FIRST. Design for 375px width FIRST. Equal horizontal padding left/right (16-20px). All grids collapse to 1 column. Heading sizes shrink to 36-42px on mobile. No horizontal scroll. No content cut off on the right edge. Stat numbers, big type, hero — all must look intentional on a phone, not awkwardly centered with one number bigger than the other.\n"
            . "4. FULL-BLEED HERO IS MANDATORY. The hero section MUST be exactly 100vw wide (or 100% inside a 100vw container — never narrower). The hero IMAGE must use position:absolute; inset:0; width:100%; height:100%; object-fit:cover so it fills the entire hero box edge-to-edge with no side gaps and no letterboxing. The hero text sits on top of this image via a positioned overlay div. If you use a <video> in the hero, same rules apply: position:absolute; inset:0; width:100%; height:100%; object-fit:cover; autoplay; muted; loop; playsinline. NEVER center a small image with margin around it as a hero. NEVER show dark side panels next to a hero photo.\n"
            . "5. CARDS MUST FILL. If you draw a card grid, the cards must use 100% of their grid cell width AND have substantial visual content (image filling + tagline label + headline + 2-3 line description + optional CTA arrow). No half-empty cards.\n"
            . "6. EQUAL HORIZONTAL PADDING. The left and right padding inside every container, card, section MUST be equal. Use CSS variables or simple values like padding: 24px or padding: 16px 20px so left and right match.\n"
            . "7. NEVER use pure white (#FFFFFF) as a section background unless explicitly intended. Prefer cream (#FFF8E7), paper, soft tints of the brand color, or photo backgrounds.\n"
            . "7b. ICONS ARE NEVER PHOTOS. If a scraped image URL contains 'icon', 'logo', '-checklist', '-check', 'house-umbrella', or has tiny dimensions in the filename (e.g. -100x100, -150x150, -200x180), it is CLIPART, not photography. NEVER use these as service-card photos, product-card photos, hero photos, or section backgrounds. Replace them with /api/genimg.php (see rule 8) using a SPECIFIC photorealistic prompt that fits the slot. Examples of clipart filenames you must NOT use as card photos: house-check.jpg, house-umbrella.jpg, check-list.jpg, radon-icon.jpg, internachi_blue_gold.png. Real-photo card examples: photo of an inspector with a clipboard examining a roof / cozy living room with a magnifying glass over a wall / thermal imaging camera in a contractor's hand, etc.\n"
            . "8. NEVER LEAVE AN EMPTY IMAGE SLOT. When the scraped images are not enough for every card/grid/section, use this URL to dynamically generate a photorealistic image:\n"
            . "   <img src=\"/api/genimg.php?prompt=URL_ENCODED_DESCRIPTION&ar=4:3&l=label\" alt=\"...\">\n"
            . "   - prompt: a specific, photorealistic description (encode with URL-encoding — spaces become %20, commas %2C). Example prompts (already URL-encoded):\n"
            . "       /api/genimg.php?prompt=interior%20of%20a%20warm%20family%20bakery%2C%20fresh%20bread%20on%20wooden%20shelves%2C%20morning%20light%2C%20photorealistic&ar=4:3\n"
            . "       /api/genimg.php?prompt=steel%20fabrication%20worker%20welding%20I-beam%2C%20sparks%20flying%2C%20industrial%20workshop%2C%20documentary%20photo&ar=16:9\n"
            . "       /api/genimg.php?prompt=close-up%20of%20a%20red%20vintage%20barber%20chair%20in%20a%20well-lit%20shop%2C%20warm%20tones%2C%20photorealistic&ar=4:3\n"
            . "   - ar (aspect ratio): use 1:1 for square cards, 4:3 for standard cards (DEFAULT), 16:9 for wide hero/banner, 3:4 for portrait cards, 9:16 for mobile-tall.\n"
            . "   - Use this whenever a scraped image is missing for a project tile, service card, gallery slot, about section, or background. ALWAYS write a SPECIFIC scene-based prompt (not generic 'business image'). Mention what's in the frame, lighting, mood, style.\n"
            . "   - Up to 8 generated images per page. Free on repeat hits within a site (cached).\n"
            . "   - DO NOT generate fake headshots of specific employees. For team/staff sections where no real photos exist, use generic-scene images (a worker on a job site, a craftsperson at their bench) rather than 'meet John Smith' portraits.\n"
            . "   - DO NOT use empty src, broken URLs, or placeholder strings like 'image.jpg'. ALWAYS use /api/genimg.php when scraped images run out.\n"
            . "Apply these throughout the design. These rules OVERRIDE any softer guidance above.\n";

    // ---- Industry auto-detect + prompt injection ----
    // Match scraped text against keywords in the `industries` table (admin-managed
    // at /admin/?tab=templates). Inject the best-match industry's voice/hero/
    // sections/CTAs/image rules into the system prompt so the gen feels tailored
    // to the business type instead of one-size-fits-all.
    $detected_industry_slug = '';
    try {
        $exists = (int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='industries'")->fetchColumn();
        if ($exists) {
            $scrape_text = strtolower(trim(
                (string)($scrape['title'] ?? '') . ' ' .
                (string)($scrape['description'] ?? '') . ' ' .
                $website . ' ' .
                implode(' ', (array)($scrape['h1'] ?? [])) . ' ' .
                implode(' ', (array)($scrape['h2'] ?? [])) . ' ' .
                implode(' ', array_slice((array)($scrape['paragraphs'] ?? []), 0, 6))
            ));
            if ($scrape_text !== '') {
                // Skip generic words that match too many industries and cause false positives.
                // ("fast" → fast_casual, "service" → many, "shop" → retail+barber+bakery, etc.)
                $generic_words = ['fast', 'shop', 'service', 'services', 'sales', 'support', 'repair',
                                  'business', 'local', 'office', 'pro', 'expert', 'professional', 'team'];
                $industries = $db->query("SELECT slug, name, voice, hero, sections, banned, ctas, images, keywords FROM industries WHERE active=1")->fetchAll(PDO::FETCH_ASSOC);
                $best_score = 0; $best = null;
                foreach ($industries as $row) {
                    $kw = explode(',', (string)$row['keywords']);
                    $score = 0;
                    foreach ($kw as $w) {
                        $w = trim(strtolower($w));
                        if ($w === '' || in_array($w, $generic_words, true)) continue;
                        // Use word-boundary match to avoid partial-substring false positives
                        if (preg_match('~\b' . preg_quote($w, '~') . '~i', $scrape_text)) $score++;
                    }
                    if ($score > $best_score) { $best_score = $score; $best = $row; }
                }
                // REQUIRE at least 2 distinct keyword matches before claiming a hit.
                // A single keyword match (especially with substrings) was triggering
                // "laser-way.com" → "fast_casual" because of one stray word match.
                if ($best && $best_score >= 2) {
                    $detected_industry_slug = $best['slug'];
                    $block = "\n\n------\nINDUSTRY: " . $best['name'] . " (auto-detected)\n";
                    if ($best['voice'])    $block .= "VOICE & TONE: "        . $best['voice']    . "\n";
                    if ($best['hero'])     $block .= "HERO TREATMENT: "      . $best['hero']     . "\n";
                    if ($best['sections']) $block .= "PREFERRED SECTIONS: "  . $best['sections'] . "\n";
                    if ($best['banned'])   $block .= "BANNED FOR THIS INDUSTRY: " . $best['banned'] . "\n";
                    if ($best['ctas'])     $block .= "CTA WORDING (use these, NEVER generic): " . $best['ctas'] . "\n";
                    if ($best['images'])   $block .= "IMAGE / FRAMING RULES: " . $best['images'] . "\n";
                    $block .= "Apply this throughout the design. If it conflicts with the general rules above, PREFER this industry-specific guidance. The site must feel like it belongs to this kind of business — not a generic landing page.\n";
                    $system .= $block;
                    ml_debug("industry detected: {$best['slug']} (score=$best_score)");
                }
            }
        }
    } catch (Throwable $e) { ml_debug('industry detect failed: ' . $e->getMessage()); }

    // ---- Site-type detection: e-commerce / retail ----
    // Editorial magazine layouts look wrong on shops. When we see cart/shop/buy
    // markers OR a /shop or /products nav link, override with a retail-first
    // design brief.
    try {
        $links_blob = '';
        foreach ((array)($scrape['links'] ?? []) as $lk) {
            $h = is_array($lk) ? (string)($lk['href'] ?? $lk['url'] ?? '') : (string)$lk;
            $links_blob .= ' ' . strtolower($h);
        }
        $sig_text = strtolower(trim(
            (string)($scrape['title'] ?? '') . ' ' .
            (string)($scrape['description'] ?? '') . ' ' .
            $website . ' ' .
            $links_blob . ' ' .
            implode(' ', (array)($scrape['h1'] ?? [])) . ' ' .
            implode(' ', (array)($scrape['h2'] ?? [])) . ' ' .
            implode(' ', array_slice((array)($scrape['paragraphs'] ?? []), 0, 16)) . ' ' .
            (isset($scrape['raw_html']) ? strtolower((string)$scrape['raw_html']) : '')
        ));
        $ecom_hits = 0;
        $ecom_signals = [
            'add to cart', 'shop now', 'shop all', 'add-to-cart', 'shopify', 'woocommerce',
            'free shipping', 'in stock', 'sold out', 'product collection', 'view product',
            'checkout', 'my cart', 'shopping cart', '/cart', '/shop', '/products', '/collections',
            'cdn.shopify', 'product-card', 'price__', 'sku ', 'wholesale inquiries', 'bulk order',
            'add-to-bag', 'add to bag', 'buy now', 'view cart', 'view shop',
        ];
        foreach ($ecom_signals as $s) if (strpos($sig_text, $s) !== false) $ecom_hits++;
        if (preg_match_all('~\$\s*\d{1,3}(?:[.,]\d{2})?~', $sig_text, $pm)) {
            if (count($pm[0]) >= 3) $ecom_hits += 2;
        }
        // Strong tie-breaker: if ANY nav/link points at /shop, /products, /collections,
        // or /cart, count as retail with low threshold. Small biz Wix sites often have
        // hero brand copy on the homepage but a clear Shop link in nav.
        $has_shop_link = (bool)preg_match('~(^|[/.])(shop|products|collections|cart|checkout)(/|$|\?)~', $links_blob);
        if ($has_shop_link) $ecom_hits += 2;

        if ($ecom_hits >= 2) {
            $ml = "\n\n------\nSITE TYPE: E-COMMERCE / RETAIL (auto-detected, signals=$ecom_hits)\n"
                . "This is a SHOP. The user came to BUY, not to read a brand story. These rules ABSOLUTELY OVERRIDE the editorial quality rules above.\n\n"
                . "HOMEPAGE HERO — NON-NEGOTIABLE:\n"
                . "- The hero headline MUST be a PRODUCT-LED or BENEFIT-LED line that makes the visitor want to buy. Examples that work: \"Bars that actually taste like dessert.\", \"Plant-based snacks built for athletes.\", \"100% organic. Zero compromise.\", \"Today's craving, delivered.\"\n"
                . "- THE HERO HEADLINE MUST NEVER BE: \"Get in Touch\", \"Contact Us\", \"Welcome to <brand>\", \"About Us\", \"Our Story\", \"Hello\", \"Hi there\", or any greeting/contact phrasing. Those belong on Contact or About pages, NOT on a retail homepage.\n"
                . "- The primary hero CTA MUST be a SHOPPING action: \"Shop now\", \"Shop the bars\", \"Order yours\", \"See what's in stock\", \"Browse the shop\". The secondary CTA can be \"Wholesale\" or \"How it's made\". NEVER \"Get in touch\", \"Learn more\", \"Read story\" as the primary hero CTA.\n"
                . "- Hero must show a PRODUCT photo (or product-in-use lifestyle shot) full-bleed. NOT a portrait of the founder. NOT a contact form. NOT a wall of text.\n"
                . "- Price-anchor or shipping line near the CTA (\"From \$0.50/serving\", \"Free shipping over \$40\").\n\n"
                . "REQUIRED SECTIONS (in roughly this order, top to bottom):\n"
                . "1. Slim TRUST BAR strip — free shipping / made-in-USA / certifications / years in business / ratings.\n"
                . "2. PRODUCT GRID — 3 to 6 tile cards. Each card MUST have: product photo filling the top, product name, short 1-line description, visible PRICE (use realistic $ amounts inferred from the scrape, or omit if unknown), and an \"Add to cart\" or \"View\" or \"Shop\" button. Use scraped product photos; use /api/genimg.php for any missing ones (prompts must describe the ACTUAL product type — e.g. 'flat-lay studio shot of date-and-nut energy bar in branded wrapper on cream backdrop, photorealistic').\n"
                . "3. CATEGORY tiles row (Bars / Bites / Bulk / Gifts / Wholesale, etc) if categories can be inferred.\n"
                . "4. CUSTOMER REVIEWS — 3-4 short ★★★★★ quotes with first name + city.\n"
                . "5. BRAND STORY — a SHORT section (one image + 2-3 paragraphs max), NOT the dominant treatment. This is where the editorial vibe is welcome, in a SECONDARY position.\n"
                . "6. NEWSLETTER signup OR a final shop CTA.\n"
                . "7. Footer with shop links + social + contact.\n\n"
                . "FORBIDDEN:\n"
                . "- Giant magazine pull-quote text as the dominant treatment.\n"
                . "- \"Stats grid\" sections with abstract numbers (31+ YEARS, 100% PLANT-BASED, 20+ YEARS MAKING ORGANICS) as a major section. A small trust bar is fine; an oversized stats hero is editorial-magazine, not retail.\n"
                . "- Contact form as the dominant section (a slim contact link in nav + footer is enough).\n"
                . "- \"About Us\" introducing the brand BEFORE showing products.\n"
                . "- Generic \"Learn more\" / \"Read more\" / \"Get in touch\" CTAs anywhere prominent.\n"
                . "- Listing the business name as the hero headline.\n\n"
                . "PRIMARY NAV (top of page): Shop · Products (or category names) · About · Wholesale (if applicable) · Contact. Always include a CART icon top-right next to the SHOP NOW button.\n";
            $system .= $ml;
            ml_debug("site type: ecommerce (signals=$ecom_hits)");
        }
    } catch (Throwable $e) { ml_debug('ecom detect failed: ' . $e->getMessage()); }

    if ($describe) {
        $contact_rule = ($email !== '')
            ? "CONTACT CTA: use the provided email (" . $email . ") for the primary contact / quote button (mailto). Do not invent a phone number or address."
            : "CONTACT CTA: use a generic \"Contact us\" / \"Request a quote\" button. Do NOT invent a phone number, address, or email.";
        $system .= "\n\n------\nDESCRIBE MODE — NO EXISTING WEBSITE WAS SCRAPED.\n"
                . "You are building this site from the business NAME (\"" . $biz . "\") and the owner's DESCRIPTION only. There is NO scraped content and NO existing images beyond an all-generated pool.\n"
                . "- SYNTHESIZE the site from the description: infer sensible services/offerings, value props, sections, and goal-appropriate CTAs that a business like this would have. In this mode you MUST invent reasonable structure and copy grounded in the description. THIS OVERRIDES any rule about \"only use scraped content / do not invent\" — here, creating the content is required.\n"
                . "- DO NOT fabricate SPECIFIC PROOF the owner did not state: NO named testimonials or quotes, NO star ratings or review counts, NO client logos, NO award badges, NO \"since 19XX\" / \"X years in business\" / \"N happy customers\" stat numbers, NO invented phone numbers, addresses, or URLs. If the description does not state a fact, do not assert it as fact. Prefer non-fabricated phrasing (\"Trusted local service\" not \"Trusted by 500+ customers\").\n"
                . "- LOGO: there is no logo image. Use a clean TEXT wordmark of the business name in the brand font.\n"
                . "- " . $contact_rule . "\n"
                . "- IMAGES: use /api/genimg.php for every photo, with specific prompts describing the ACTUAL offering from the description. Never leave an empty image slot; never reference a scraped image (there are none).\n";
        ml_debug('describe mode: system block injected');
    }
    $desc_block = '';
    if ($description !== '') {
        $clean = preg_replace('/[\x00-\x1F]+/', ' ', $description);
        $clean = mb_substr($clean, 0, 1500);
        $desc_block = "\n\n<business_notes_from_owner>\n" . $clean . "\n</business_notes_from_owner>\n"
                    . "Treat the notes above as the most authoritative description of the business. "
                    . "If the scraped content disagrees, prefer what the owner wrote. Reflect the tone "
                    . "and specifics (years in business, neighborhoods, signature offerings) in the hero and copy.";
    }

    $reqs = [];
    for ($i = 1; $i <= $v; $i++) {
        $user_content = build_user_prompt($scrape, $biz, $industry, $i) . $desc_block;
        $reqs[$i] = ['system' => $system, 'messages' => [['role' => 'user', 'content' => $user_content]]];
    }

    ml_debug('anthropic begin');
    $tA = microtime(true);
    $res = anthropic_multi('claude-sonnet-4-6', $reqs, 14000, 0.7, null, ['</html>']);
    $gen_dt = microtime(true)-$tA;
    ml_debug(sprintf('anthropic done %.2fs', $gen_dt));
    ml_time('PHASE_2_sonnet_gen', $gen_dt, ['variants' => $v, 'industry' => $detected_industry_slug]);
    // Track the user content of the first variant for later QA regen
    $first_user_content = $reqs[1]['messages'][0]['content'] ?? '';

    $htmls = []; $cost = 0.0; $rejected = [];
    foreach ($reqs as $i => $_) {
        $cost += (float)($res[$i]['cost_usd'] ?? 0);
        $cand = finalize_html($res[$i]['text'] ?? '');
        if (!$cand) { ml_debug("variant $i: finalize_html returned null (text_len=" . strlen($res[$i]['text'] ?? '') . ")"); continue; }
        $qg = quality_gate($cand);
        if ($qg['ok']) {
            $htmls[$i] = $cand;
        } else {
            ml_debug("variant $i: quality_gate REJECT reason='" . ($qg['reason'] ?? '?') . "' html_len=" . strlen($cand));
            $rejected[$i] = $cand;
        }
    }
    // Fallback: if quality_gate rejected ALL variants but at least one is
    // substantial HTML (>3KB, has body+footer-ish content), ship it anyway. A
    // good-enough site beats a hard failure for the user.
    if (!$htmls && $rejected) {
        $best = null; $best_len = 0;
        foreach ($rejected as $i => $cand) {
            if (strlen($cand) > $best_len) { $best = $i; $best_len = strlen($cand); }
        }
        if ($best !== null && $best_len > 3000 && stripos($rejected[$best], '<body') !== false) {
            ml_debug("FALLBACK: using rejected variant $best (len=$best_len) — quality_gate failed but HTML is substantial");
            $htmls[$best] = $rejected[$best];
        }
    }
    if (!$htmls) throw new Exception('generation produced no usable site');
    ksort($htmls);

    // 1) Write preview files (filesystem — this is what the USER sees)
    // token + dir already created before generation (async returns the token early)
    foreach ($htmls as $i => $html) { $d = $dir . '/v' . $i; if (!is_dir($d)) @mkdir($d, 0755, true); file_put_contents($d . '/index.html', ww_polish_html($html, $website)); }
    if (!is_file($dir . '/index.php')) file_put_contents($dir . '/index.php', "<?php\n\$_GET['t'] = basename(__DIR__);\nrequire __DIR__ . '/../index.php';\n");
    ml_debug("files written token=$token");

    // 2) Send response IMMEDIATELY. The user gets their preview. Don't make
    //    them wait on DB metadata writes — and never fail them on DB busy.
    if (!$async) {
        $response = ['ok' => true, 'token' => $token, 'url' => '/preview/' . $token . '/', 'preview_url' => '/preview/' . $token . '/v1/index.html', 'variants' => count($htmls), 'business' => $biz, 'business_name' => $biz];
        echo json_encode($response);
        // Flush so the user gets their response before we attempt DB writes.
        if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
        elseif (function_exists('litespeed_finish_request')) { @litespeed_finish_request(); }
        else { @ob_flush(); @flush(); }
    } else {
        @unlink($dir . '/status.json'); // index.html now exists = the ready signal for polling
    }
    ml_debug("RESPONSE SENT token=$token");
    ml_time('PHASE_3_user_seen', microtime(true)-$T0, ['token' => $token]);

    // ---- Conversion tracking: SERVER-SIDE "site generated" (reliable - the client JS can be missed) ----
    try {
        $db->prepare("INSERT INTO try_events (event, token, session_id, payload) VALUES ('gen_completed', ?, NULL, ?)")
           ->execute([$token, json_encode(['business'=>($biz ?: $company), 'mode'=>($describe ? 'describe' : 'magic'), 'website'=>$website])]);
    } catch (Throwable $e) { ml_debug('try_events gen_completed failed: ' . $e->getMessage()); }
    try {
        @require_once '/var/www/sites/trywebwiz/public/api/_meta.php';
        if (function_exists('ww_meta_send_event')) {
            ww_meta_send_event('SiteGenerated', ww_meta_event_id($token),
                ['email' => ($email !== '' ? $email : null), 'first_name' => ($name !== '' ? explode(' ', $name)[0] : null),
                 'client_ip_address' => $ip, 'client_user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 240)],
                ['content_name' => ($biz ?: $company), 'content_category' => 'site_generated'],
                'https://trywebwiz.com/try/?t=' . $token, 'website');
        }
    } catch (Throwable $e) { ml_debug('meta SiteGenerated failed: ' . $e->getMessage()); }

    // ====== POST-RESPONSE BACKGROUND PHASE ======
    // User already sees their preview. From here we silently improve it.
    // Reset the wall-clock so the QA + upscale phase has fresh budget.
    @set_time_limit(240);
    ignore_user_abort(true);

    // ---- Pre-warm Imagen image generation (moved post-response) ----
    // Warm the genimg URLs that ended up in the final HTML, in parallel, so the
    // JPEGs are cached before QA screenshots and before the user reloads. Runs
    // AFTER the response flush so the user is never kept waiting on it.
    $tPW = microtime(true);
    try {
        $genimg_urls = [];
        foreach ($htmls as $html) {
            if (preg_match_all('~/api/(?:genimg|img)\.php\?[^"\'\s<>]+~', $html, $m)) {
                foreach ($m[0] as $u) {
                    $genimg_urls[] = 'https://trywebwiz.com' . html_entity_decode($u);
                }
            }
        }
        $genimg_urls = array_values(array_unique($genimg_urls));
        $pw_count = 0;
        if ($genimg_urls) {
            $mh = curl_multi_init();
            $handles = [];
            foreach (array_slice($genimg_urls, 0, 10) as $u) {
                $ch = curl_init($u);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 9,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_HTTPHEADER     => ['user-agent: WebWiz-PreWarm/1.0'],
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[] = $ch;
            }
            $deadline = microtime(true) + 7.0;
            do {
                curl_multi_exec($mh, $running);
                if ($running > 0) curl_multi_select($mh, 0.2);
            } while ($running > 0 && microtime(true) < $deadline);
            foreach ($handles as $ch) {
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($code === 200) $pw_count++;
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
            curl_multi_close($mh);
        }
        ml_time('PHASE_2_5_pre_warm', microtime(true) - $tPW, ['found' => count($genimg_urls), 'warmed' => $pw_count]);
        ml_debug("pre-warm: found=" . count($genimg_urls) . " warmed=$pw_count");
    } catch (Throwable $e) { ml_debug('pre-warm failed: ' . $e->getMessage()); }
    // Async only: now that images are warmed, drop the ready marker so the
    // poller opens the reveal on a fully-loaded page (no blank images popping in).
    if ($async) { @file_put_contents($dir . '/ready', '1'); ml_debug('async ready marker written (post pre-warm)'); }

    // ---- Phase 4: Visual QA loop (Sonnet vision check + auto-regen if fail) ----
    $tQA = microtime(true);
    try {
        $variant_url = 'https://trywebwiz.com/preview/' . $token . '/v1/index.html?qa=' . time();
        $shots = function_exists('ww_render_screenshots') ? ww_render_screenshots([1 => $variant_url], null) : [];
        $png = $shots[1] ?? null;
        $shot_dt = microtime(true)-$tQA;
        ml_time('PHASE_4a_screenshot', $shot_dt, ['ok' => $png ? true : false]);
        if ($png && function_exists('ww_visual_inspect')) {
            $tInspect = microtime(true);
            $verdict = ww_visual_inspect($png, $biz, null);
            ml_time('PHASE_4b_vision_inspect', microtime(true)-$tInspect, ['pass' => !empty($verdict['pass']), 'score' => $verdict['score'] ?? null]);
            ml_debug(sprintf('QA verdict pass=%s score=%s reason=%s',
                empty($verdict['pass'])?'no':'yes',
                $verdict['score'] ?? '?',
                substr((string)($verdict['summary'] ?? ''), 0, 200)
            ));
            if (!$async && empty($verdict['pass']) && function_exists('ww_qa_feedback') && isset($htmls[1])) {
                $tRegen = microtime(true);
                $fb = ww_qa_feedback($verdict['issues'] ?? []);
                $rreqs = [1 => ['system' => $system, 'messages' => [['role' => 'user', 'content' => $first_user_content . "\n\n" . $fb]]]];
                $rres = anthropic_multi('claude-sonnet-4-6', $rreqs, 14000, 0.5, null, ['</html>']);
                $rcand = finalize_html($rres[1]['text'] ?? '');
                if ($rcand && quality_gate($rcand)['ok']) {
                    file_put_contents($dir . '/v1/index.html', ww_polish_html($rcand, $website));
                    $htmls[1] = $rcand;
                    ml_time('PHASE_4c_qa_regen', microtime(true)-$tRegen, ['written' => true]);
                    ml_debug('QA regen written to disk');
                } else {
                    ml_time('PHASE_4c_qa_regen', microtime(true)-$tRegen, ['written' => false]);
                }
            }
        }
    } catch (Throwable $e) { ml_debug('QA phase failed: ' . $e->getMessage()); }
    ml_time('PHASE_4_total', microtime(true)-$tQA);

    // ---- Phase 5: Image upscale (Real-ESRGAN via Replicate) ----
    // Run after QA so we upscale the final HTML. Replicate calls are slow HTTP
    // but we already gave the user their preview — they'll see upscaled images
    // on next page reload.
    if (!$async && function_exists('ww_apply_upscale')) {
        $tU = microtime(true);
        try {
            foreach ($htmls as $i => $html) {
                $upscaled = ww_apply_upscale($html, null);
                if ($upscaled && $upscaled !== $html) {
                    file_put_contents($dir . '/v' . $i . '/index.html', ww_polish_html($upscaled, $website));
                    $htmls[$i] = $upscaled;
                }
            }
            ml_time('PHASE_5_upscale', microtime(true)-$tU, ['variants' => count($htmls)]);
        } catch (Throwable $e) { ml_debug('upscale phase failed: ' . $e->getMessage()); ml_time('PHASE_5_upscale', microtime(true)-$tU, ['error' => true]); }
    }
    ml_time('PHASE_TOTAL', microtime(true)-$T0, ['token' => $token]);

    // ---- Notify-ready email ----
    // If the user submitted the email-notify card during gen, send them the
    // "your site is ready" email now. Stashed by IP at the top of this file.
    try {
        $notify_path = '/tmp/wwnotify_' . substr(sha1($ip), 0, 16) . '.txt';
        if (is_file($notify_path)) {
            $notify_email_to = trim((string)@file_get_contents($notify_path));
            @unlink($notify_path);
            if ($notify_email_to !== '' && filter_var($notify_email_to, FILTER_VALIDATE_EMAIL)) {
                require_once __DIR__ . '/_email_templates.php';
                $secrets_email = require '/var/www/sites/trywebwiz/secrets.php';
                $brevo_key = $secrets_email['BREVO_API_KEY'] ?? '';
                if ($brevo_key !== '') {
                    $tpl = ww_email_preview_ready([
                        'business_name' => $biz,
                        'preview_url'   => 'https://trywebwiz.com/try/?t=' . $token,
                    ]);
                    $payload = [
                        'sender'      => ['name' => 'Wizzy at WebWiz', 'email' => 'wizzy@trywebwiz.com'],
                        'to'          => [['email' => $notify_email_to]],
                        'subject'     => $tpl['subject'],
                        'htmlContent' => $tpl['html'],
                        'replyTo'     => ['email' => 'hello@trywebwiz.com', 'name' => 'Wizzy at WebWiz'],
                    ];
                    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
                    curl_setopt_array($ch, [
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => json_encode($payload),
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT        => 15,
                        CURLOPT_HTTPHEADER     => ['accept: application/json', 'content-type: application/json', 'api-key: ' . $brevo_key],
                    ]);
                    $resp = curl_exec($ch);
                    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    ml_debug("notify-ready email http=$http to=$notify_email_to");
                }
            }
        }
    } catch (Throwable $e) { ml_debug('notify-ready email failed: ' . $e->getMessage()); }

    // 3) Now try to persist metadata. The user has already gotten their preview,
    //    so even if persist fails, they don't notice. We retry with growing
    //    backoff. If we still can't write after several attempts, drop the
    //    metadata to a pending JSON file for later sync.
    $persist_payload = [
        'token' => $token, 'email' => $email, 'name' => $name, 'biz' => $biz,
        'website' => $website, 'cost' => $cost, 'htmls_count' => count($htmls),
        'variants' => array_keys($htmls), 'ip' => $ip, 'created_at' => date('Y-m-d H:i:s'),
        'generation_mode' => $gen_mode, 'description' => $description, 'describe' => $describe ? 1 : 0,
        'scrape_data' => $scrape_data_json ?? null,
    ];
    $persist_ok = false;
    $maxAttempts = 12; // up to ~20s of retries
    $delay_us = 250000;
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {
            $db->exec('PRAGMA busy_timeout = 30000');
            $db->exec('BEGIN IMMEDIATE');
            $st = $db->prepare("INSERT INTO prospects (email, name, business_name, current_url, source, description) VALUES (?, ?, ?, ?, 'magic', ?)");
            $st->execute([$email, $name, $biz, ($describe ? null : $website), ($describe ? $description : null)]);
            $pid = (int)$db->lastInsertId();
            // Fold in the loading-screen Q&A answers if the visitor answered them.
            // qa.json existing implies /api/qa.php already created the qa_answers column,
            // so this is a plain UPDATE (no ALTER inside the transaction).
            try {
                $qaf = '/var/www/sites/trywebwiz/public/preview/' . $token . '/qa.json';
                if (is_file($qaf)) {
                    $qraw = json_decode((string)@file_get_contents($qaf), true);
                    $qans = (is_array($qraw) && !empty($qraw['answers']) && is_array($qraw['answers'])) ? $qraw['answers'] : null;
                    if ($qans) {
                        $qu = $db->prepare('UPDATE prospects SET qa_answers = ? WHERE id = ?');
                        $qu->execute([json_encode($qans, JSON_UNESCAPED_SLASHES), $pid]);
                    }
                }
            } catch (Throwable $e) { ml_debug('qa attach failed: ' . $e->getMessage()); }
            $st = $db->prepare("INSERT INTO jobs (type, prospect_id, customer_email, business_name, scrape_data, status, scheduled_for, token, generation_mode, item_status, total_cost_cents, completed_at, qa_status) VALUES ('outbound', ?, ?, ?, ?, 'ready', datetime('now'), ?, ?, 'done', ?, datetime('now'), 'magic')");
            $st->execute([$pid, $email, $biz, ($scrape_data_json ?? null), $token, $gen_mode, (int)round($cost * 100)]);
            $jid = (int)$db->lastInsertId();
            $st = $db->prepare("INSERT INTO previews (job_id, variant_n, html_path, qa_score, qa_pass, qa_issues) VALUES (?, ?, ?, NULL, NULL, NULL)");
            foreach ($htmls as $i => $_) {
                $st->execute([$jid, $i, '/preview/' . $token . '/v' . $i . '/index.html']);
            }
            $st = $db->prepare("INSERT INTO magic_hits (ip, token) VALUES (?, ?)");
            $st->execute([$ip, $token]);
            $db->exec('COMMIT');
            $persist_ok = true;
            ml_debug("persist OK attempt=$attempt pid=$pid jid=$jid");
            break;
        } catch (Throwable $e) {
            try { $db->exec('ROLLBACK'); } catch (Throwable $ee) {}
            $emsg = strtolower($e->getMessage());
            ml_debug(sprintf('persist attempt %d FAIL: %s', $attempt, $e->getMessage()));
            if (strpos($emsg, 'lock') === false && strpos($emsg, 'busy') === false) break;
            if ($attempt < $maxAttempts) { usleep($delay_us); $delay_us = (int)min(3000000, $delay_us * 1.4); }
        }
    }

    if (!$persist_ok) {
        // Queue for later sync — DB-busy must NEVER fail the user.
        $pendingDir = '/var/www/sites/trywebwiz/data/pending_magic';
        if (!is_dir($pendingDir)) { @mkdir($pendingDir, 0755, true); }
        $pendingFile = $pendingDir . '/' . $token . '.json';
        file_put_contents($pendingFile, json_encode($persist_payload, JSON_PRETTY_PRINT));
        ml_debug("PERSIST FALLBACK to file: $pendingFile");
    }

    // ---- Nurture-engine enrollment ----
    // If the user gave us an email, drop them into the nurture cadence (step 0,
    // next_send_at = +2 days). Existing contacts get refreshed with the new
    // token/preview/company. Wrapped so any failure never blocks the user.
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        try {
            require_once __DIR__ . '/_nurture.php';
            $purl = 'https://trywebwiz.com/try/?t=' . $token;
            $company_for_nurture = $company !== '' ? $company : ($biz ?? '');
            $cid = ww_nurture_upsert_contact($db, [
                'name'        => $name,
                'email'       => $email,
                'company'     => $company_for_nurture,
                'website'     => $website,
                'token'       => $token,
                'preview_url' => $purl,
                'source'      => 'try',
            ]);
            ml_debug("nurture upsert: cid=$cid email=$email");
        } catch (Throwable $e) { ml_debug('nurture upsert failed: ' . $e->getMessage()); }
    }
    exit;
} catch (Throwable $e) {
    $emsg = preg_replace('/[\x00-\x1F]+/', ' ', $e->getMessage());
    ml_debug('FAIL (caught): ' . $emsg);
    if (!empty($async) && !empty($token)) { @mkdir('/var/www/sites/trywebwiz/public/preview/' . $token, 0755, true); @file_put_contents('/var/www/sites/trywebwiz/public/preview/' . $token . '/status.json', json_encode(['status' => 'failed', 'error' => mb_substr($emsg, 0, 200), 'ts' => time()])); }
    try { if (isset($db)) $db->prepare("INSERT INTO try_events (event, token, session_id, payload) VALUES ('gen_failed', ?, NULL, ?)")->execute([(($token ?? '') ?: null), json_encode(['error'=>mb_substr($emsg,0,300), 'website'=>($website ?? ''), 'business'=>($company ?? '')])]); } catch (Throwable $te) {}
    // Operator alert on a genuine generation failure (throttled: 1 email / 10 min).
    try {
        $__al = '/tmp/wwmagic_alert.ts';
        $__last = is_file($__al) ? (int)@file_get_contents($__al) : 0;
        if (time() - $__last > 600 && function_exists('ww_send_email')) {
            @file_put_contents($__al, (string)time());
            $__sx = @include '/var/www/sites/trywebwiz/secrets.php';
            $__to = (is_array($__sx) && !empty($__sx['NOTIFY_EMAIL'])) ? $__sx['NOTIFY_EMAIL'] : 'ultimax97@gmail.com';
            $__in = (string)($_GET['website'] ?? $_GET['url'] ?? ($_GET['company'] ?? ''));
            $__html = '<h2 style="color:#b00">WebWiz generation failed</h2>'
                . '<p><b>Error:</b> ' . htmlspecialchars($emsg) . '</p>'
                . '<p><b>Input:</b> ' . htmlspecialchars($__in) . '</p>'
                . '<p><b>When:</b> ' . gmdate('Y-m-d H:i:s') . ' UTC &middot; IP ' . htmlspecialchars((string)($ip ?? '')) . '</p>'
                . '<p style="color:#666;font-size:13px">Immediate alert (throttled to 1 per 10 min). Full status: https://trywebwiz.com/api/health_check.php</p>';
            @ww_send_email(['email' => $__to, 'name' => 'WebWiz Ops'], 'WebWiz ALERT - generation failed', $__html);
        }
    } catch (Throwable $__ae) { ml_debug('alert email failed: ' . $__ae->getMessage()); }
    ml_fail('Generation failed: ' . $emsg, 500);
}
