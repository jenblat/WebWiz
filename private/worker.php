<?php
// /var/www/sites/trywebwiz/private/worker.php
// Cron (/etc/crontab, NOT /etc/cron.d): * * * * * www-data /usr/bin/php8.3 \
//   /var/www/sites/trywebwiz/private/worker.php >> .../logs/worker.log 2>&1
// MUST run as www-data. It was `nobody` until 2026-08-06, which cannot traverse
// private/ (750 www-data) — so php could not even open this file, cron's append
// to logs/worker.log (750 www-data) failed first, and the worker was silently
// dead from 2026-05-26 to 2026-08-06. Anything it writes (public/preview/*,
// the SQLite DB, logs/) is www-data-owned, so www-data is also the only user
// whose output the web server can serve.
// Single-instance (flock). Drains multiple queued jobs per run within a time budget.
// Each job generates 3 variants CONCURRENTLY (anthropic_multi), retrying only failures.

declare(strict_types=1);
// Relative to this file, not the absolute site path: in production both resolve to
// /var/www/sites/trywebwiz/private, and a git worktree can run the pipeline against
// its own copy of the libraries (the data paths below stay absolute on purpose).
require_once __DIR__ . '/webwiz_lib.php';
require_once __DIR__ . '/lib/anthropic.php';
require_once __DIR__ . '/lib/scrape.php';
require_once __DIR__ . '/lib/qa.php';
require_once __DIR__ . '/lib/replicate.php';
require_once __DIR__ . '/lib/batch.php';
require_once __DIR__ . '/lib/design.php';

set_time_limit(0);

const WORKER_MAX_RUN_SECONDS = 270;

// Only run the queue loop from cron (CLI). When required from a web request (e.g. the magic link),
// this file just provides the generation functions below.
if (PHP_SAPI === 'cli' && getenv('WW_LIB_ONLY') !== '1') {
$lock = fopen('/tmp/webwiz-worker.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "[worker] already running, exit\n";
    exit(0);
}

$db = ww_db();
$run_started = time();
$did = 0;

while ((time() - $run_started) < WORKER_MAX_RUN_SECONDS) {
    $row = $db->query(
        "SELECT * FROM jobs
         WHERE status = 'queued' AND COALESCE(generation_mode,'sync') <> 'batch' AND datetime(scheduled_for) <= datetime('now')
         ORDER BY id ASC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if (!$row) { if ($did === 0) echo "[worker] no jobs\n"; break; }
    process_job($db, $row);
    $did++;
}

// Batch pipeline (all CSV uploads): build queued uploads (scrape+submit) and poll in-flight batches.
try { ww_build_batches($db); } catch (Throwable $e) { echo "[batch] build error: ".$e->getMessage()."\n"; }
try { ww_poll_batches($db); } catch (Throwable $e) { echo "[batch] poll error: ".$e->getMessage()."\n"; }
// A single job can now run well past WORKER_MAX_RUN_SECONDS (three variants, each
// audited at ~35s, vision checked, then compared with the current site, plus any
// regeneration round). The flock above keeps the next cron tick from overlapping;
// the passes below are skipped when a long job already used the run's budget, so
// one slow job never stretches a run past two cron windows.
$elapsed = time() - $run_started;
if ($elapsed < WORKER_MAX_RUN_SECONDS) {
    try { ww_audit_missing($db, 2, max(30, min(120, WORKER_MAX_RUN_SECONDS - $elapsed))); } catch (Throwable $e) { echo "[audit] backfill error: ".$e->getMessage()."\n"; }
}
$elapsed = time() - $run_started;
if ($elapsed < WORKER_MAX_RUN_SECONDS) {
    try { ww_generate_missing_showcases($db, 20, max(30, min(150, WORKER_MAX_RUN_SECONDS - $elapsed))); } catch (Throwable $e) { echo "[showcase] error: ".$e->getMessage()."\n"; }
} else {
    echo "[worker] run used {$elapsed}s on jobs, skipping backfill passes this tick\n";
}

flock($lock, LOCK_UN);
fclose($lock);
echo "[worker] run complete, processed {$did} job(s)\n";
}

function process_job(PDO $db, array $row): void {
    $job_id = (int)$row['id'];
    echo "[worker] job #{$job_id} starting\n";
    $db->prepare("UPDATE jobs SET status='running', started_at=datetime('now') WHERE id=?")->execute([$job_id]);
    $cap = (float)($db->query("SELECT value FROM settings WHERE key='job_max_cost_usd'")->fetchColumn() ?: 1.50);

    try {
        $prospect = null;
        if ($row['prospect_id']) {
            $st = $db->prepare("SELECT * FROM prospects WHERE id = ?");
            $st->execute([$row['prospect_id']]);
            $prospect = $st->fetch(PDO::FETCH_ASSOC);
        }
        $url      = $prospect['current_url'] ?? ($row['scrape_data'] ?? '');
        $biz      = $prospect['business_name'] ?? $row['business_name'] ?? 'Their Business';
        $industry = $prospect['industry'] ?? '';
        if (!$url) throw new Exception('No current_url available for this job');

        echo "[worker]  scraping {$url}\n";
        $scrape = scrape_multi($url);
        $db->prepare("UPDATE jobs SET scrape_data=? WHERE id=?")->execute([json_encode($scrape), $job_id]);
        ww_prospect_store_contacts($db, $row['prospect_id'] ? (int)$row['prospect_id'] : null, $scrape);

        $usable = array_values(array_filter($scrape['images'] ?? [], fn($i) =>
            empty($i['is_logo']) && empty($i['is_thumb']) && empty($i['is_team_card'])));
        echo "[worker]  scrape: " . count($scrape['images'] ?? []) . " live images, " . count($usable) . " usable\n";

        $model  = $row['model'] ?: 'claude-sonnet-4-6';
        $system = build_system_prompt($industry, count($usable));

        // Client-specific art direction, written once per job and shared by all 3 variants.
        // The DNA below then makes each variant look different; the brief makes all three
        // look like THIS business. Failure here is non-fatal - the build proceeds without it.
        $total_cost = 0.0;
        $brief = ww_art_direction_brief($scrape, $biz, $industry, $job_id);
        $total_cost += (float)($brief['_cost_usd'] ?? 0);
        echo "[worker]  art direction brief: " . ($brief
            ? count($brief['sections'] ?? []) . " sections, " . count($brief['palette'] ?? []) . " palette entries"
            : "unavailable (proceeding without)") . "\n";

        // Deterministic per-job design DNA. Seeded from the token so regeneration is stable,
        // and guaranteed to differ across the 3 variants on every axis.
        $seed = (string)$row['token'];
        $dna  = [];
        $reqs = [];
        for ($v = 1; $v <= 3; $v++) {
            $dna[$v]  = ww_design_dna($seed, $v);
            $reqs[$v] = ['system' => $system, 'messages' => [['role' => 'user', 'content' => build_user_prompt($scrape, $biz, $industry, $v, $dna[$v], $brief)]]];
            echo "[worker]   v{$v} type: {$dna[$v]['type']['display']} / {$dna[$v]['type']['body']}\n";
        }
        echo "[worker]  generating 3 variants in parallel -> {$model}\n";
        $res = anthropic_multi($model, $reqs, 14000, 1.0, $job_id, ['</html>']);

        $htmls = [];
        $retry = [];
        foreach ($reqs as $v => $_) {
            $total_cost += (float)($res[$v]['cost_usd'] ?? 0);
            $cand = finalize_html($res[$v]['text'] ?? '');
            $gate = $cand ? quality_gate($cand) : ['ok' => false, 'reason' => 'no usable HTML'];
            if ($gate['ok']) { $htmls[$v] = $cand; }
            else { $retry[$v] = $gate['reason']; echo "[worker]   variant {$v} round1 failed: {$gate['reason']}\n"; }
        }

        if ($retry && $total_cost < $cap) {
            $rreqs = [];
            foreach ($retry as $v => $reason) {
                $rreqs[$v] = ['system' => $system, 'messages' => [['role' => 'user',
                    'content' => build_user_prompt($scrape, $biz, $industry, $v, $dna[$v], $brief) .
                        "\n\nIMPORTANT - your previous attempt failed the quality gate: {$reason}. Keep the SAME assigned art direction, but make it SHORTER and COMPLETE: end with </html>, include an <h1>, a <footer>, 4+ <section>/<article> elements, and 4+ distinct /api/img.php images. Cut the least essential section if needed to finish."]]];
            }
            echo "[worker]  retrying " . count($rreqs) . " variant(s) in parallel\n";
            $rres = anthropic_multi($model, $rreqs, 14000, 0.9, $job_id, ['</html>']);
            foreach ($rreqs as $v => $_) {
                $total_cost += (float)($rres[$v]['cost_usd'] ?? 0);
                $cand = finalize_html($rres[$v]['text'] ?? '');
                if ($cand && quality_gate($cand)['ok']) { $htmls[$v] = $cand; unset($retry[$v]); }
            }
        }

        if (count($htmls) < 1) throw new Exception("no variants passed the quality gate");
        if (count($htmls) < 3) echo "[worker]  shipping " . count($htmls) . "/3 variants (others failed the gate)\n";

        $public_dir = '/var/www/sites/trywebwiz/public/preview/' . $row['token'];
        ksort($htmls);
        $write_variant = function (int $v, string $html) use ($public_dir, $row): void {
            $dir = $public_dir . '/v' . $v;
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            file_put_contents($dir . '/index.html', $html);
        };
        foreach ($htmls as $v => $html) { $html = ww_apply_upscale($html, $job_id); $html = ww_polish_html($html, $url); $htmls[$v] = $html; $write_variant($v, $html); }
        $stub = $public_dir . '/index.php';
        if (!is_file($stub)) {
            file_put_contents($stub, "<?php\n\$_GET['t'] = basename(__DIR__);\nrequire __DIR__ . '/../index.php';\n");
        }

        // ---------- QUALITY GATES: audit, then vision, then compare ----------
        // 1. The audit (private/qa-tools/audit.js): 60+ checks in a real Chrome render at
        //    1440px and 390px, plus the truth checks against the scrape. Hard gate.
        // 2. The vision verdict (Sonnet on a full page screenshot): the second gate. It is
        //    skipped for a round when the audit already rejected the page, because the
        //    page is being regenerated anyway and the audit's feedback is exact.
        // 3. The compare: the same audit on the prospect's current site. A variant ships
        //    when it beats that site overall and loses no category. One regeneration with
        //    the losing categories named; if it still loses it ships flagged needs_review.
        $qa_enabled    = ((string)($db->query("SELECT value FROM settings WHERE key='visual_qa_enabled'")->fetchColumn()) === '1');
        $qa_max_retries= (int)($db->query("SELECT value FROM settings WHERE key='qa_max_retries'")->fetchColumn() ?: 2);
        $qa_block      = ((string)($db->query("SELECT value FROM settings WHERE key='qa_block_on_fail'")->fetchColumn()) === '1');
        $aud           = ww_audit_settings($db);
        $token         = (string)$row['token'];
        $facts         = $aud['enabled'] ? ww_audit_facts($scrape, (string)($prospect['description'] ?? '')) : null;
        $qa_results = []; $audit_results = []; $audit_runs = []; $compare_results = []; $gate_log = [];

        // Evaluate one variant: returns '' when it passes both gates, else the feedback for a regen.
        $evaluate = function (int $v) use (&$htmls, &$qa_results, &$audit_results, &$audit_runs, &$total_cost, $qa_enabled, $aud, $facts, $token, $biz, $job_id): string {
            ww_prewarm_images($htmls[$v]);
            $fb = '';
            if ($aud['enabled']) {
                if (isset($audit_runs[$v])) ww_audit_cleanup($audit_runs[$v]);
                $t0 = microtime(true);
                $run = ww_audit_variant($token, $v, $facts);
                $audit_runs[$v] = $run;
                $a = $run['audit'];
                $audit_results[$v] = $a;
                if ($a) {
                    $ok = (($a['verdict'] ?? '') === 'preview-ready') && ((int)($a['score'] ?? 0) >= $aud['min_score']);
                    echo "[worker]   v{$v}: audit " . ($ok ? 'PASS' : 'FAIL') . " score={$a['score']} ({$a['checks_run']} checks, " . round(microtime(true) - $t0) . "s)"
                       . (!$ok ? " fails=" . implode(',', (array)($a['fails'] ?? [])) : '') . " cost so far \$" . number_format($total_cost, 4) . "\n";
                    if (!$ok) {
                        foreach ((array)($a['checks'] ?? []) as $c) if (($c['status'] ?? '') === 'fail') echo "[worker]      fail {$c['id']}: " . mb_substr((string)$c['detail'], 0, 160) . "\n";
                        $fb = ww_audit_feedback($a, $aud['min_score']);
                    }
                } else {
                    echo "[worker]   v{$v}: audit unavailable (tool error), not blocking\n";
                }
            }
            if ($qa_enabled && $fb === '') {
                $url = 'https://trywebwiz.com/preview/' . $token . '/v' . $v . '/index.html?qa=' . microtime(true);
                $shots = ww_render_screenshots([$v => $url], $job_id);
                $png = $shots[$v] ?? null;
                if (!$png) { $qa_results[$v] = ['pass'=>true,'score'=>-1,'issues'=>[],'summary'=>'render-failed']; echo "[worker]   v{$v}: vision render failed, skipping\n"; }
                else {
                    $verdict = ww_visual_inspect($png, $biz, $job_id);
                    $qa_results[$v] = $verdict;
                    echo "[worker]   v{$v}: vision " . ($verdict['pass']?'PASS':'FAIL') . " score={$verdict['score']} - {$verdict['summary']}\n";
                    if (!$verdict['pass']) $fb = ww_qa_feedback($verdict['issues']);
                }
            } elseif ($qa_enabled) {
                $qa_results[$v] = ['pass'=>false,'score'=>-1,'issues'=>[],'summary'=>'audit failed first; vision skipped this round'];
            }
            return $fb;
        };
        // Regenerate the listed variants with their feedback. Writes the ones that pass the structural gate.
        $regen = function (array $fails, string $why) use (&$htmls, &$total_cost, $system, $scrape, $biz, $industry, $dna, $brief, $model, $job_id, $url, $write_variant): array {
            $rreqs = [];
            foreach ($fails as $v => $fb) {
                $rreqs[$v] = ['system'=>$system, 'messages'=>[['role'=>'user','content'=>build_user_prompt($scrape, $biz, $industry, $v, $dna[$v], $brief) . "\n\n" . $fb]]];
            }
            echo "[worker]  {$why}: regenerating " . count($rreqs) . " variant(s)\n";
            $rres = anthropic_multi($model, $rreqs, 14000, 0.9, $job_id, ['</html>']);
            $written = [];
            foreach ($rreqs as $v => $_) {
                $total_cost += (float)($rres[$v]['cost_usd'] ?? 0);
                $cand = finalize_html($rres[$v]['text'] ?? '');
                $g = $cand ? quality_gate($cand) : ['ok' => false, 'reason' => 'no usable HTML'];
                if ($g['ok']) { $cand = ww_polish_html($cand, $url); $htmls[$v] = $cand; $write_variant($v, $cand); $written[] = $v; }
                else echo "[worker]   v{$v}: regen failed the structural gate ({$g['reason']}), keeping the previous render\n";
            }
            return $written;
        };

        if ($qa_enabled || $aud['enabled']) {
            for ($round = 0; $round <= $qa_max_retries; $round++) {
                echo "[worker]  QA round {$round}: " . count($htmls) . " variant(s)\n";
                $fails = [];
                foreach ($htmls as $v => $_) { $fb = $evaluate($v); if ($fb !== '') $fails[$v] = $fb; }
                if (!$fails) break;
                if ($round >= $qa_max_retries) break;
                if ($total_cost >= $cap) { echo "[worker]  QA stop: cost cap \${$cap} reached\n"; break; }
                $regen($fails, 'QA round ' . $round);
            }
            // block-on-fail: drop still-failing variants, but never drop to zero
            $passes = function (int $v) use (&$qa_results, &$audit_results, $qa_enabled, $qa_block, $aud): bool {
                if ($qa_enabled && $qa_block && !($qa_results[$v]['pass'] ?? true)) return false;
                $a = $audit_results[$v] ?? null;
                if ($aud['enabled'] && $aud['block'] && $a && ((($a['verdict'] ?? '') !== 'preview-ready') || (int)($a['score'] ?? 0) < $aud['min_score'])) return false;
                return true;
            };
            $passing = [];
            foreach ($htmls as $v => $html) { if ($passes($v)) $passing[$v] = $html; }
            if ($passing && count($passing) < count($htmls)) {
                foreach ($htmls as $v => $_) {
                    if (!isset($passing[$v])) {
                        foreach ((glob($public_dir . '/v' . $v . '/*') ?: []) as $gf) @unlink($gf);
                        @rmdir($public_dir . '/v' . $v);
                        echo "[worker]   v{$v}: dropped (failed the gate after retries)\n";
                    }
                }
                $htmls = $passing;
            }
        }

        // ---------- COMPARE against the prospect's current site ----------
        $compare_lost = [];
        if ($aud['enabled'] && $aud['compare'] && $url !== '' && !empty($htmls)) {
            foreach ($htmls as $v => $_) {
                $t0 = microtime(true);
                $cmp = ww_compare_variant($token, $v, $url, $facts, $audit_runs[$v] ?? null);
                $compare_results[$v] = $cmp;
                if (!$cmp) { echo "[worker]   v{$v}: compare unavailable\n"; continue; }
                echo "[worker]   v{$v}: compare {$cmp['verdict']} ours={$cmp['ours']['score']} theirs={$cmp['theirs']['score']}" . (!empty($cmp['losing']) ? " loses=" . implode(',', $cmp['losing']) : '') . " (" . round(microtime(true) - $t0) . "s)\n";
                if (($cmp['verdict'] ?? '') === 'beats-current-site') continue;
                if (empty($cmp['losing']) || $total_cost >= $cap) { $compare_lost[$v] = $cmp; continue; }
                // One regeneration with the losing categories named, then re-run both gates and the compare.
                $written = $regen([$v => ww_compare_feedback($cmp)], 'compare v' . $v);
                if (!in_array($v, $written, true)) { $compare_lost[$v] = $cmp; continue; }
                $fb = $evaluate($v);
                $cmp2 = ww_compare_variant($token, $v, $url, $facts, $audit_runs[$v] ?? null);
                $compare_results[$v] = $cmp2 ?: $cmp;
                if ($cmp2) echo "[worker]   v{$v}: compare after regen {$cmp2['verdict']} ours={$cmp2['ours']['score']} theirs={$cmp2['theirs']['score']}" . (!empty($cmp2['losing']) ? " loses=" . implode(',', $cmp2['losing']) : '') . "\n";
                if (!$cmp2 || ($cmp2['verdict'] ?? '') !== 'beats-current-site' || $fb !== '') $compare_lost[$v] = $cmp2 ?: $cmp;
            }
        }
        foreach ($audit_runs as $r) ww_audit_cleanup($r);

        $any_fail = false;
        foreach ($htmls as $v => $_) {
            if ($qa_enabled && !($qa_results[$v]['pass'] ?? true)) $any_fail = true;
            $a = $audit_results[$v] ?? null;
            if ($a && ((($a['verdict'] ?? '') !== 'preview-ready') || (int)($a['score'] ?? 0) < $aud['min_score'])) $any_fail = true;
            if (isset($compare_lost[$v])) $any_fail = true;
        }
        $qa_status = (!$qa_enabled && !$aud['enabled']) ? 'disabled' : ($any_fail ? 'needs_review' : 'passed');

        ksort($htmls);
        foreach ($htmls as $v => $html) {
            $rel = '/preview/' . $row['token'] . '/v' . $v . '/index.html';
            $q = $qa_results[$v] ?? null;
            $a = $audit_results[$v] ?? null;
            $c = $compare_results[$v] ?? null;
            // Retried: these two writes land after ~10 minutes of work and the first test
            // run lost them to a plain "database is locked" (SQLITE_BUSY under the
            // hourly nurture tick), which threw the whole job away as failed.
            ww_db_write_retry(function () use ($db, $job_id, $v, $rel, $q, $a, $c) {
                $db->prepare("INSERT INTO previews (job_id, variant_n, html_path, qa_score, qa_pass, qa_issues, audit_score, audit_verdict, audit_json, compare_verdict, compare_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                   ->execute([$job_id, $v, $rel, $q['score'] ?? null, isset($q['pass']) ? ($q['pass']?1:0) : null, $q ? json_encode($q['issues']) : null,
                              $a ? (int)$a['score'] : null, $a ? (string)$a['verdict'] : null, ww_audit_for_db($a), $c ? (string)$c['verdict'] : null, ww_compare_for_db($c)]);
                return true;
            }, 10);
        }
        ww_db_write_retry(function () use ($db, $total_cost, $qa_status, $job_id) {
            $db->prepare("UPDATE jobs SET status='ready', completed_at=datetime('now'), total_cost_cents=?, qa_status=? WHERE id=?")
               ->execute([(int)round($total_cost * 100), $qa_status, $job_id]);
            return true;
        }, 10);
        $summary = [];
        foreach ($htmls as $v => $_) {
            $summary[] = "v{$v}" . (isset($audit_results[$v]['score']) ? " audit {$audit_results[$v]['score']}" : '') . (isset($compare_results[$v]['verdict']) ? " vs current " . ($compare_results[$v]['verdict'] === 'beats-current-site' ? 'wins' : 'loses ' . implode('/', (array)$compare_results[$v]['losing'])) : '');
        }
        echo "[worker] job #{$job_id} ready ({$qa_status}), " . count($htmls) . " variant(s), cost \$" . number_format($total_cost, 4) . ", " . implode('; ', $summary) . "\n";

    } catch (Throwable $e) {
        $msg = $e->getMessage();
        echo "[worker] job #{$job_id} FAILED: {$msg}\n";
        $db->prepare("UPDATE jobs SET status='failed', error=?, completed_at=datetime('now') WHERE id=?")
           ->execute([substr($msg, 0, 500), $job_id]);
    }
}

/** Public URL of the WebWiz Motion Kit, served from public/kit/. */
const WW_KIT_ORIGIN = 'https://trywebwiz.com';
const WW_KIT_DIR    = '/var/www/sites/trywebwiz/public/kit';

/**
 * The two kit tags, with a ?v=<filemtime> cache buster. The buster matters: a kit
 * update must reach every existing preview on the next load, and the 2026-05-24
 * stale-cache bug (browsers heuristically caching variant HTML) showed what happens
 * when an in-place file change does not change its URL. Falls back to the version
 * string when the files cannot be stat'ed (worktree checkout, fresh box).
 */
function ww_kit_tags(): string {
    $css = WW_KIT_DIR . '/webwiz-motion.css';
    $js  = WW_KIT_DIR . '/webwiz-motion.js';
    $vc  = (string)(@filemtime($css) ?: '1');
    $vj  = (string)(@filemtime($js) ?: '1');
    return '<link rel="stylesheet" href="' . WW_KIT_ORIGIN . '/kit/webwiz-motion.css?v=' . $vc . '">'
         . '<script defer src="' . WW_KIT_ORIGIN . '/kit/webwiz-motion.js?v=' . $vj . '"></script>';
}

/**
 * Derive the ambient colour hooks from the page's own CSS when the generator did not
 * set them. Picks the two most used saturated colours (a near-white page background
 * is always the most used colour and is invisible as a tint, so neutrals are skipped
 * unless nothing else exists) and emits them at 7% and 9% alpha, the same soft
 * values the prompt asks for.
 */
function ww_kit_ambient_vars(string $html): string {
    if (!preg_match_all('~<style\b[^>]*>(.*?)</style>~is', $html, $sm)) return '';
    $css = implode("\n", $sm[1]);
    $css = preg_replace('~/\*.*?\*/~s', '', $css) ?? $css;
    $counts = [];
    if (preg_match_all('/#([0-9a-f]{6}|[0-9a-f]{3})\b/i', $css, $hm)) {
        foreach ($hm[1] as $h) {
            if (strlen($h) === 3) $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
            $counts[strtolower($h)] = ($counts[strtolower($h)] ?? 0) + 1;
        }
    }
    if (preg_match_all('/rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})/i', $css, $rm, PREG_SET_ORDER)) {
        foreach ($rm as $r) {
            $h = sprintf('%02x%02x%02x', min(255, (int)$r[1]), min(255, (int)$r[2]), min(255, (int)$r[3]));
            $counts[$h] = ($counts[$h] ?? 0) + 1;
        }
    }
    if (!$counts) return '';
    arsort($counts);
    // array_keys() hands back an int for an all-digit hex like 123456, so normalise
    $rgb = function ($h): array { $h = str_pad((string)$h, 6, '0', STR_PAD_LEFT); return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))]; };
    $sat = function (array $c): float {
        $mx = max($c); $mn = min($c);
        return $mx === 0 ? 0.0 : ($mx - $mn) / $mx;
    };
    $lum = fn(array $c) => (0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2]) / 255;
    $brand = null; $second = null; $dark = null;
    foreach (array_keys($counts) as $h) {
        $h = str_pad((string)$h, 6, '0', STR_PAD_LEFT);
        $c = $rgb($h);
        $l = $lum($c);
        if ($l > 0.93 || $l < 0.04) continue;           // paper and ink are not tints
        if ($sat($c) >= 0.2) {
            if ($brand === null) { $brand = $c; continue; }
            if ($second === null && $h !== $brand) { $second = $c; }
        } elseif ($dark === null && $l < 0.45) {
            $dark = $c;
        }
        if ($brand !== null && $second !== null) break;
    }
    if ($brand === null) $brand = $dark ?? [0, 0, 0];
    if ($second === null) $second = $dark ?? $brand;
    $f = fn(array $c, string $a) => "rgba({$c[0]},{$c[1]},{$c[2]},{$a})";
    return '<style>:root{--ww-ambient-a:' . $f($brand, '.07') . ';--ww-ambient-b:' . $f($second, '.07') . ';--ww-shift:' . $f($brand, '.09') . ';}</style>';
}

/**
 * Inject the WebWiz Motion Kit into a generated document. Idempotent.
 *
 *  - The kit link and script go right after <head> opens, with absolute URLs because
 *    variants are served from several paths.
 *  - If <body> has no data-ambient, add it, so every page gets the soft brand field
 *    that moves with scroll even when the model forgot.
 *  - If :root does not define the three colour hooks, derive them from the page's own
 *    palette (ww_kit_ambient_vars). The generator is asked to set them itself; this is
 *    the safety net.
 *  - If the h1 has no data-words and is text only (no child elements), add it. The kit
 *    splits text-only headlines safely; one with child markup is left alone.
 */
function ww_inject_motion_kit(string $html): string {
    if (stripos($html, '/kit/webwiz-motion.css') === false) {
        $tags = ww_kit_tags();
        if (preg_match('~<head\b[^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $at = $m[0][1] + strlen($m[0][0]);
            $html = substr($html, 0, $at) . $tags . substr($html, $at);
        } elseif (preg_match('~<html\b[^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $at = $m[0][1] + strlen($m[0][0]);
            $html = substr($html, 0, $at) . '<head>' . $tags . '</head>' . substr($html, $at);
        }
    }
    if (!preg_match('~--ww-ambient-a\s*:~i', $html) || !preg_match('~--ww-ambient-b\s*:~i', $html) || !preg_match('~--ww-shift\s*:~i', $html)) {
        $vars = ww_kit_ambient_vars($html);
        if ($vars !== '' && stripos($html, '</head>') !== false) {
            $html = preg_replace('~</head>~i', $vars . '</head>', $html, 1) ?? $html;
        }
    }
    $html = preg_replace_callback('~<body\b([^>]*)>~i', function ($m) {
        return stripos($m[1], 'data-ambient') !== false ? $m[0] : '<body' . rtrim($m[1]) . ' data-ambient>';
    }, $html, 1) ?? $html;
    $html = preg_replace_callback('~<h1\b([^>]*)>(.*?)</h1>~is', function ($m) {
        if (stripos($m[1], 'data-words') !== false) return $m[0];
        if (stripos($m[2], 'data-words') !== false) return $m[0];
        if (preg_match('~<[a-z]~i', $m[2])) return $m[0];          // has child markup, leave it
        if (trim(strip_tags($m[2])) === '') return $m[0];
        return '<h1' . rtrim($m[1]) . ' data-words>' . $m[2] . '</h1>';
    }, $html, 1) ?? $html;
    return $html;
}

function finalize_html(string $text): ?string {
    $cand = extract_html($text);
    if (!$cand || stripos($cand, '<html') === false) return null;
    if (stripos($cand, '</html>') === false) $cand = rtrim($cand) . "\n</html>";
    // Force EAGER image loading: screenshot renderers (and full-page screenshots) do not scroll,
    // so loading="lazy" leaves below-the-fold images unloaded = blank boxes. Strip it.
    $cand = preg_replace('/\s*loading\s*=\s*([\x27"])lazy\1/i', '', $cand);
    // The motion kit: entrance motion, parallax, the ambient field. Every variant gets it.
    $cand = ww_inject_motion_kit($cand);
    // FAILSAFE REVEAL: belt and braces under the kit's own failsafes. Older generated pages
    // used their own IntersectionObserver entrance animations (.fade-up/.fade-in starting at
    // opacity:0), and the observer is unreliable - in screenshots, inside the preview iframe,
    // and for users who don't scroll - leaving whole sections permanently invisible (the
    // "empty section" / blank-box defect). The prompt now bans hand-rolled reveals, and the
    // kit guarantees nothing stays hidden (no JS = nothing hidden; with JS a 1.2s near
    // viewport reveal, a 6s full reveal, a print reveal and a scroll fallback). This script
    // stays as the last line of defence and now covers the kit's elements too.
    //
    // Timing matters. Legacy selectors are forced visible ~1s after load, as before. Kit
    // elements are handled on the kit's own schedule: near the viewport at ~1.2s, everything
    // at ~6.5s (via WebWizMotion.revealAll() when the kit loaded, inline styles if it did
    // not). Forcing every [data-reveal] visible at 1s would quietly delete the scroll
    // reveals for anyone who starts scrolling after that, which is the motion the whole
    // kit exists to deliver.
    // Identifiers are randomised per build. The previous version emitted a byte-identical
    // script (including a /*ww-reveal-failsafe*/ marker) into every page, so any two WebWiz
    // sites could be matched to each other by a single grep.
    $id = fn() => substr(str_shuffle('abcdefghijkmnopqrstuvwxyz'), 0, random_int(2, 4));
    [$fr, $fg, $ve, $vi, $vd, $vx, $fk, $vk, $vn] = [$id(), $id(), $id(), $id(), $id(), $id(), $id(), $id(), $id()];
    $t1 = random_int(1000, 1250); $t2 = random_int(2100, 2400); $t3 = random_int(3300, 3700);
    $k1 = random_int(1200, 1400); $k2 = random_int(6300, 6800);
    $legacy = '.fade-up,.fade-in,.reveal,.animate,.scroll-reveal';
    $kit    = '[data-reveal],[data-reveal-stagger] > *,[data-words] .ww-w';
    $failsafe = "\n<script>(function(){"
        . "function {$fr}(){try{var {$ve}=document.querySelectorAll('{$legacy}');for(var {$vi}=0;{$vi}<{$ve}.length;{$vi}++){{$ve}[{$vi}].classList.add('visible','active','in-view','show');{$ve}[{$vi}].style.opacity='1';{$ve}[{$vi}].style.transform='none';{$ve}[{$vi}].style.visibility='visible';}}catch({$vx}){}}"
        . "function {$fk}({$vn}){try{if(!{$vn}&&window.WebWizMotion&&window.WebWizMotion.revealAll){window.WebWizMotion.revealAll();}var {$vk}=document.querySelectorAll('{$kit}'),{$ve}=window.innerHeight*1.5;for(var {$vi}=0;{$vi}<{$vk}.length;{$vi}++){if({$vn}&&({$vk}[{$vi}].getBoundingClientRect().top>{$ve}||{$vk}[{$vi}].closest('.ww-in')))continue;{$vk}[{$vi}].style.opacity='1';{$vk}[{$vi}].style.transform='none';{$vk}[{$vi}].style.clipPath='none';{$vk}[{$vi}].style.visibility='visible';}}catch({$vx}){}}"
        . "var {$vd}=false;function {$fg}(){if({$vd})return;{$vd}=true;{$fr}();}"
        . "window.addEventListener('load',function(){setTimeout({$fg},{$t1});setTimeout(function(){{$fk}(true);},{$k1});});"
        . "document.addEventListener('DOMContentLoaded',function(){setTimeout({$fg},{$t2});});"
        . "setTimeout({$fg},{$t3});setTimeout(function(){{$fk}(false);},{$k2});"
        . "})();</script>\n";
    if (stripos($cand, '</body>') !== false) {
        $cand = preg_replace('/<\/body>/i', $failsafe . '</body>', $cand, 1);
    } else {
        $cand .= $failsafe;
    }
    return $cand;
}

function quality_gate(string $html): array {
    if (!preg_match('/<h1[\s>]/i', $html)) return ['ok' => false, 'reason' => 'missing <h1>'];
    // Count distinct images from BOTH the scrape proxy (/api/img.php) and the
    // Imagen generator (/api/genimg.php). Without genimg in this count, sites
    // with thin scrapes (where proactive Imagen filled the gap) would be wrongly
    // rejected as 'no images'.
    preg_match_all('~/api/img\.php\?u=([^"&\s]+)~i', $html, $ma);
    preg_match_all('~/api/genimg\.php\?[^"\s>]*prompt=([^&\s"]+)~i', $html, $mb);
    $distinct = array_unique(array_merge($ma[1] ?? [], $mb[1] ?? []));
    if (count($distinct) < 4) return ['ok' => false, 'reason' => 'only ' . count($distinct) . ' distinct images (need 4+)'];
    if (!preg_match('/<footer[\s>]/i', $html)) return ['ok' => false, 'reason' => 'missing <footer>'];
    // Count <article> as well as <section>. The old gate counted <section> only, which
    // quietly taught every build to emit the same nav -> N x section -> footer skeleton
    // (400/400 shipped pages used it, none used <article>/<main>).
    $sections = preg_match_all('/<(?:section|article)[\s>]/i', $html);
    if ($sections < 4) return ['ok' => false, 'reason' => "only {$sections} content sections (need 4+ <section>/<article>)"];
    // Motion is mandatory. The kit attributes cost nothing to write, and a page with
    // fewer than six reveal hooks reads as static next to one that unfolds. Counted
    // here, before the expensive render, so a bare page is retried on the cheap round.
    $reveals = preg_match_all('/\sdata-reveal(?:-stagger)?(?:=|\s|>)/i', $html);
    if ($reveals < 6) return ['ok' => false, 'reason' => "only {$reveals} data-reveal / data-reveal-stagger elements (need 6+; the motion kit attributes are mandatory)"];
    return ['ok' => true, 'reason' => ''];
}

function extract_html(string $text): ?string {
    $text = trim($text);
    if (preg_match('/```(?:html)?\s*([\s\S]+?)```/i', $text, $m)) $text = trim($m[1]);
    if (preg_match('/<(?:!doctype|html)[\s\S]+/i', $text, $m)) $text = $m[0];
    if (stripos($text, '<html') === false) return null;
    return $text;
}

/**
 * Strip em/en dashes from VISIBLE COPY. The em dash is the single most recognisable
 * "written by AI" tell in body text, and telling the model not to use one is not
 * reliable enough on its own — this is the deterministic backstop that runs on every
 * shipped page.
 *
 * Only text nodes are touched. <script> and <style> are cut out first (a dash inside
 * CSS content:"—" or a JS string must survive), and attributes are left alone, so this
 * can never corrupt markup, selectors or URLs.
 *
 * Replacement is a COMMA, not a period. A comma can never turn the second half into a
 * sentence fragment; the worst case is a mild comma splice, which reads as ordinary
 * informal marketing copy. A period would read better for two independent clauses and
 * badly for everything else, and we cannot tell which is which without parsing.
 */
function ww_dedash_copy(string $html): string {
    // Protect script/style blocks by swapping them for placeholders.
    $stash = [];
    $html = preg_replace_callback('~<(script|style)\b[^>]*>.*?</\1>~is', function ($m) use (&$stash) {
        $k = "\x01WWSTASH" . count($stash) . "\x02";
        $stash[$k] = $m[0];
        return $k;
    }, $html);

    $html = preg_replace_callback('~>([^<]+)<~', function ($m) {
        $t = $m[1];
        if (strpos($t, '&') !== false) {
            $t = str_ireplace(['&mdash;', '&ndash;', '&#8212;', '&#8211;', '&#x2014;', '&#x2013;'], '—', $t);
        }
        if (strpos($t, '—') === false && strpos($t, '–') === false && strpos($t, '--') === false) return $m[0];

        // Testimonial/quote attribution ("— Jane Smith"): drop the dash, never comma it.
        $t = preg_replace('~^(\s*)[—–]\s*~u', '$1', $t);
        // Numeric ranges (9–5, 2020–2024) read as a plain hyphen.
        $t = preg_replace('~(\d)\s*[—–]\s*(\d)~u', '$1-$2', $t);
        // Everything else: dash acting as punctuation, spaced or not, incl. ASCII "--".
        $t = preg_replace('~\s*(?:—|–|--)\s*~u', ', ', $t);
        // Tidy the seams the substitution can create.
        $t = preg_replace('~\s+,~u', ',', $t);
        $t = preg_replace('~,\s*,~u', ',', $t);
        $t = preg_replace('~,\s*([.!?;:])~u', '$1', $t);
        $t = preg_replace('~([.!?;:])\s*,~u', '$1', $t);
        $t = preg_replace('~,\s*$~u', '', $t);
        return '>' . $t . '<';
    }, $html);

    return $stash ? strtr($html, $stash) : $html;
}

/**
 * Post-generation polish applied to every shipped variant:
 *  - force the footer copyright year to the CURRENT year (never a stale/hardcoded one)
 *  - add UTM tracking to the "Designed by WebWiz" backlink so WebWiz can attribute traffic.
 *  - strip em/en dashes from visible copy (see ww_dedash_copy)
 */
/**
 * Keep a preview out of the search index.
 *
 * A preview is a single self-contained HTML file at /preview/<token>/v1/index.html
 * with no auth and, until 2026-08-09, no robots directive. 971 directories were
 * being publicly served and were fully crawlable: robots.txt disallowed only
 * /wp-admin/, /admin/ and /api/.
 *
 * The tag goes in the FILE rather than in server config or .htaccess on purpose.
 * This box runs OpenLiteSpeed, the static file is served directly without touching
 * PHP (so no header can be added at request time by our code), and a directive
 * that lives in the document travels with it no matter what serves it. robots.txt
 * is updated too, but that file is stamped "Managed by SeedSite SEO" and can be
 * regenerated from under us, so it cannot be the only defence.
 *
 * This is friction proportional to the price, not DRM. Anything rendered in a
 * browser can be copied, and this does not pretend otherwise - it stops the site
 * being FOUND by someone who was not given the link, which is the actual leak.
 */
function ww_noindex_html(string $html): string {
    if (stripos($html, 'name="robots"') !== false || stripos($html, "name='robots'") !== false) {
        return $html;
    }
    $tag = '<meta name="robots" content="noindex,nofollow,noarchive,noimageindex">';
    // Prefer just after <head>; fall back to before </head>, then to the top.
    if (preg_match('~<head\b[^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $at = $m[0][1] + strlen($m[0][0]);
        return substr($html, 0, $at) . $tag . substr($html, $at);
    }
    if (stripos($html, '</head>') !== false) {
        return preg_replace('~</head>~i', $tag . '</head>', $html, 1) ?? $html;
    }
    return $tag . $html;
}

/**
 * Replace stock-photo URLs the model invented with real generated photography.
 *
 * Measured 2026-08-10 across the 300 most recent shipped previews: 17 of them
 * (5.7%) carried an images.unsplash.com URL that was in NO source data. The
 * scrape never produced it and the Imagen pre-generation never produced it - the
 * model wrote a plausible-looking Unsplash photo id from memory and proxied it
 * through /api/img.php. Four separate problems come out of that:
 *
 *  1. The ids are guesses, and some are wrong. 1 of 12 sampled returned 404, and
 *     a 404 through img.php is served as the branded monogram placeholder, which
 *     is exactly the "empty image box" defect visual QA rejects a page for.
 *  2. It is generic stock. The entire pitch is that this is YOUR business rebuilt
 *     from YOUR content; a stock office photo is precisely what ChatGPT or
 *     Lovable would hand back, so it throws away the differentiator.
 *  3. The alt text is often unrelated to the business ("Alaska media landscape"
 *     on a creative services firm), because the id was recalled, not chosen.
 *  4. We had already paid to generate real photography for that page and the
 *     model ignored it, so ~$0.28 of Imagen spend per affected job bought
 *     nothing, and the shipped page hotlinks a third party we do not control.
 *
 * A prompt rule alone does not hold - the em dash proved that, 298 of 300 pages
 * carried one despite explicit instructions - so this is the deterministic
 * backstop and the prompt rule is the belt.
 *
 * Deliberately narrow. Only <img> tags, and only the known stock-photo hosts.
 * Scraped client images legitimately arrive from every CDN under the sun (Wix,
 * Squarespace, Cloudinary, shopify), so a general "external host" rule would
 * throw away real photos of the actual business. <iframe> is untouched, so
 * YouTube and Vimeo embeds still work.
 */
function ww_enforce_image_sources(string $html): string {
    $stock = '~(^|\.)(unsplash\.com|pexels\.com|pixabay\.com|shutterstock\.com|istockphoto\.com|gettyimages\.com)$~i';
    return preg_replace_callback('~<img\b[^>]*>~i', function ($m) use ($stock) {
        $tag = $m[0];
        if (!preg_match('~\bsrc\s*=\s*(["\'])(.*?)\1~is', $tag, $s)) return $tag;
        $src = html_entity_decode($s[2], ENT_QUOTES | ENT_HTML5);
        // Unwrap our own proxy so a stock URL hidden inside ?u= is still seen.
        $target = $src;
        if (preg_match('~/api/img\.php\?(.*)$~i', $src, $q)) {
            parse_str(html_entity_decode($q[1], ENT_QUOTES | ENT_HTML5), $params);
            if (!empty($params['u'])) $target = (string)$params['u'];
        }
        $host = (string)parse_url($target, PHP_URL_HOST);
        if ($host === '' || !preg_match($stock, $host)) return $tag;

        // Build the replacement prompt from the alt text, which is what the model
        // intended the picture to show. Falls back to the proxy's label.
        $alt = '';
        if (preg_match('~\balt\s*=\s*(["\'])(.*?)\1~is', $tag, $a)) {
            $alt = trim(html_entity_decode($a[2], ENT_QUOTES | ENT_HTML5));
        }
        if ($alt === '' && isset($params['l'])) $alt = trim((string)$params['l']);
        $alt = preg_replace('~\s+~u', ' ', $alt);
        if (mb_strlen($alt) < 4) $alt = 'professional photograph for a small business website';
        $prompt = mb_substr('Editorial photograph, natural light, realistic: ' . $alt, 0, 300);
        $new = '/api/genimg.php?prompt=' . rawurlencode($prompt) . '&ar=4:3&l=' . rawurlencode(mb_substr($alt, 0, 60));
        return preg_replace('~\bsrc\s*=\s*(["\']).*?\1~is', 'src="' . htmlspecialchars($new, ENT_QUOTES) . '"', $tag, 1);
    }, $html) ?? $html;
}

function ww_polish_html(string $html, string $clientUrl = ''): string {
    $html = ww_dedash_copy($html);
    $html = ww_noindex_html($html);
    $html = ww_enforce_image_sources($html);
    $year = date('Y');
    $html = preg_replace('/(©|&copy;|Copyright)\s*20\d{2}/iu', '${1} ' . $year, $html);
    $u = (strpos($clientUrl, '://') === false && $clientUrl !== '') ? 'https://' . $clientUrl : $clientUrl;
    $host = strtolower((string)parse_url($u, PHP_URL_HOST));
    $host = preg_replace('/^www\./', '', $host);
    $src  = ($host !== '' && strpos($host, '{') === false) ? rawurlencode($host) : 'client_site';
    $utm  = 'https://trywebwiz.com/?utm_source=' . $src . '&utm_medium=referral&utm_campaign=designed_by_webwiz';
    $html = preg_replace('~href=(["\x27])https?://(?:www\.)?trywebwiz\.com/?\1~i', 'href="' . $utm . '"', $html);
    return $html;
}

function build_system_prompt(string $industry, int $usable_img_count): string {
    $img_note = $usable_img_count >= 4
        ? "Source has {$usable_img_count} usable content images - use at least 4 distinct ones."
        : "Source has only {$usable_img_count} usable content images - supplement with thumbnail-tagged images to reach 4+ distinct images.";
    return <<<TXT
You are WebWiz, an elite designer building single-page marketing sites that rival Apple, Patagonia, Stripe, Linear, Aesop.

OUTPUT
Return ONLY a complete HTML5 document, no markdown, no commentary, no code fences. First character `<`, last character `>`. Must include <!DOCTYPE html>, <html>, <head>, <body>, end with </html>. Target ~5000 tokens.

ABSOLUTE RULES
1. ENTRANCE MOTION COMES ONLY FROM THE WEBWIZ MOTION KIT ATTRIBUTES (see MOTION IS MANDATORY below). The kit's CSS and JS are injected into your page for you; do not link them yourself. Never write your own opacity:0, visibility:hidden, transform based entrance, IntersectionObserver or @keyframes entrance. Your own CSS transitions are fine for hover states.
2. The kit already honours prefers-reduced-motion. Any hover transition of your own must be inside @media (prefers-reduced-motion: no-preference).
3. HTML COMPLETE - TOP PRIORITY. Close every tag and END WITH </html>. If running long, SHORTEN copy + CSS and DROP the FAQ section, but NEVER omit the <footer> or leave the document unclosed. A complete ~5000-token page beats a richer page that gets cut off. Keep CSS compact (group selectors, no redundant rules).
4. IMAGES ARE MANDATORY. Use a MINIMUM of 4 DISTINCT images via the proxy. Every image MUST be wrapped exactly like:
   <img src="/api/img.php?u=<URL-ENCODED-original>&l=<URL-ENCODED-short-label>" alt="...">  (do NOT add loading="lazy" - all images must load eagerly)
5. {$img_note}
6. EVERY image URL you use MUST come from the provided source data (images.photo / images.cutout / images.thumbnail / images.logo). All provided URLs are verified to load. Do NOT invent URLs. Do NOT reuse a URL twice.

IMAGE FRAMING - clients reject cropped people:
- LANDSCAPE PHOTOS (images.photo): scenes, offices, products. Container with aspect-ratio + overflow:hidden + object-fit:cover. Fine as hero/feature/full-bleed. CRITICAL - HEADS: if the photo contains PEOPLE, use object-position:center top (NEVER center or bottom) and keep the band tall (>=60vh for a hero, >=460px for a mid-page band) so heads are NEVER cut off at the top edge. A wide-short full-bleed band (height under ~420px) may ONLY use a photo with no faces near the top. Do NOT force a single tight head-and-shoulders portrait into a wide full-bleed band - put single portraits in a framed portrait card (aspect-ratio 3/4 or 4/5, object-fit:cover, object-position:center top) with the name/title beneath.
- CUTOUT / PORTRAIT / PERSON images (images.cutout, or anyone on a transparent/plain background): NEVER crop. Render with object-fit:contain inside a fixed-height box (e.g. height:420px) with a soft brand-tint/neutral background and padding, so the whole person is visible. NEVER use a cutout person as a full-bleed hero. NEVER put a face in a tight 1:1 or 16/9 cover crop.
- Hero: prefer a LANDSCAPE photo. If none exists, use a CSS gradient/SVG hero and put people photos lower in framed cards.

NO EMPTY SPACE / NO EMPTY IMAGE BOXES (clients reject these instantly)
- Every section must contain visible content. Never leave a section taller than ~40vh with nothing in it.
- NEVER create an image slot, card thumbnail, or photo box you cannot fill with a REAL provided image URL. An empty or solid-color/gray/tinted rectangle where a photo belongs is an AUTOMATIC REJECTION.
- If you do not have enough distinct images for a layout (e.g. a 3-card services/insights/blog grid, or an about/team photo), then REDESIGN that section to need fewer images, or make it text/icon/stat based, or drop it. Fewer cards with real images beats more cards with blank image areas.
- Do NOT build a "latest articles / insights / blog / news" card grid with image thumbnails unless you have a distinct real image for EVERY card.
- The founder/CEO/about photo is optional: only include a person photo if a real provided image exists for it; otherwise use a text-forward about block. Never leave a labeled-but-empty portrait frame.
- An image wrapper carrying data-parallax MUST have its own height set (a fixed height, min-height, or aspect-ratio). The kit sizes the image to fill the wrapper, so a wrapper with no height collapses to zero and the hero photo disappears.

MOTION IS MANDATORY
Every page moves. Entrance motion, a gentle background that shifts as you scroll, and depth on the hero are what separate this page from anything a prompt tool hands back. All of it is done with the WebWiz Motion Kit data attributes below; the kit is injected for you and guarantees nothing is ever stuck hidden.

| Where | Attribute |
| The h1 (text only, no child elements; if one word is coloured, wrap it in its own span and give that span data-words too) | data-words |
| The hero image wrapper (one img inside, wrapper has a fixed height or aspect-ratio) | data-parallax="0.12" |
| Every section's heading block, every feature row | data-reveal (one of: up, fade, left, right, scale, wipe) |
| Every grid of cards, services, logos, people, projects | data-reveal-stagger="90" |
| Two or three light background sections | data-bg-shift |
| body | data-ambient |
| The sticky header | data-nav |
| Cards and image tiles | data-lift, data-zoom |
| Real numbers from the source data only | data-count="1240" (data-count-prefix, data-count-suffix, data-count-decimals supported) |

Rules: one parallax hero only. data-words on the h1 and at most one other headline. Stagger grids, never body paragraphs. Never invent a number to have something to count. Set the colour hooks on :root from the palette you chose, soft tints only:
  --ww-ambient-a: rgba(<brand rgb>, .07); --ww-ambient-b: rgba(<neutral rgb>, .07); --ww-shift: rgba(<brand rgb>, .09);
Minimums the audit checks: six or more data-reveal elements, one or more data-reveal-stagger group, parallax or ambient or bg-shift present, reduced motion honoured (the kit does this), nothing hidden after load. The attributes cost almost nothing; if the page runs long, shorten copy and CSS, never drop the footer and never drop the attributes.

HEADER
- Sticky top nav: business name/logo left, 3-5 nav links (use scraped nav_links), 1-2 right-aligned CTAs.
- CTA TEXT MUST MATCH THE BUSINESS. NEVER "Sign In"/"Log In" unless the source clearly has an authenticated product.
  Inference: Agency -> "Book a Call"/"Get a Quote"; Restaurant -> "Reserve a Table"/"Order Online"; Ecommerce -> "Shop Now"; SaaS -> "Get Started"/"Try Free"; Law/medical -> "Get a Consultation"/"Book Appointment".

TYPOGRAPHY
- The user message ASSIGNS you exactly two Google Fonts for this build. Load and use only those two. Do not substitute, do not add a third family, and do not fall back to a generic favourite - your named body font must be first in every font-family stack.
- FORBIDDEN: Bagel Fat One, Lilita One, Modak, Concert One, Bowlby, Fredoka One, Boogaloo, novelty/kid-like.
- Choose tracking to suit the assigned faces. Do NOT apply the same tight negative letter-spacing to every headline by reflex.

DESIGN STANDARDS
- Contemporary, confident, magazine-quality. High contrast. Fully responsive at 375px. 2 primary CTAs above the fold.
- TAP TARGETS (audited at 390px): every link and button that is not a link inside a sentence must render at least 40px tall and 40px wide on a phone. Nav links, footer links, card links, pills and buttons all get padding or min-height:44px. 4 or more small targets fails the page.
- BUTTON CONTRAST (audited): button text against its button colour needs 4.5:1. White text on a mid-tone brand colour (coral, teal, gold, light blue) fails; use the brand colour's darkest shade or dark text on the light accent.
- COLOR CONTRAST (critical): any accent used as TEXT, numbers, small labels, wordmarks or thin UI on a DARK (navy/black/deep) background MUST be light and high-contrast - white, cream, or a bright gold. NEVER put a dark accent (dark red, maroon, burgundy, brown, navy) as TEXT on a dark background; that is unreadable. Reserve dark/saturated accents for solid-fill buttons/badges with white text, or as text on LIGHT backgrounds. Stat numbers and section labels sitting on a dark band must read clearly.
- STRUCTURE IS YOURS TO DECIDE. There is no house skeleton. Use real landmarks - <header>, <main>, <article>, <aside>, <footer> - not an undifferentiated stack of <section> tags. Section count, order and vertical rhythm come from the assigned art direction and the client brief, not from habit.

THIS MUST NOT LOOK MASS-PRODUCED
The single worst outcome is a page that could be swapped onto another company's website without anyone noticing. Specifically banned because they are the standard tells of a generated site:
- Copy: "Ready to Get Started", "Ready to Transform...", "What Our Clients Say", "Trusted By", "Everything You Need", "Why Choose Us", "Let's Build Something Together", "Elevate Your...", "Take Your X to the Next Level", or any headline that would fit any other company unchanged. Headlines must name something only this business could say.
- Layout: the default hero -> 3-icon-feature-row -> stats strip -> testimonial carousel -> full-width CTA band sequence.
- Decoration by reflex: a blurred radial-gradient blob behind the hero, a generic logo marquee, uniform drop-shadowed rounded cards in a 3-up grid. Use these ONLY if the assigned ornament language actually calls for them.
- Filler stats you cannot source. Never invent "500+ Projects" or "20 Years" unless that number appears in the source data.

WRITE LIKE A PERSON, NOT LIKE A MODEL
Prose gives away a generated page faster than the layout does. These are hard rules:
- NO EM DASHES OR EN DASHES. Not one, anywhere in visible copy. No "—", no "–", no " -- ". Use a comma, a full stop, or brackets. This is the single most recognisable tell there is, and it is checked.
- No abstract virtue headings. "Uncompromising Integrity", "Unwavering Commitment", "Relentless Excellence", "Built on Trust", "Our Core Values" say nothing. Name the actual thing the business does or promises: "We send the same report to you and the lender", "Owners see the maintenance invoices".
- No anaphora triads. "Every decision, every communication, every report..." and "From X to Y to Z..." are pure model cadence. Say it once, concretely.
- No "not just X, but Y" / "we don't just X, we Y" / "it's more than X, it's Y".
- Banned vocabulary: elevate, empower, unlock, seamless, robust, leverage, delve, realm, landscape, tapestry, testament, journey, curated, bespoke, holistic, synergy, "transform your", "take your X to the next level", "in today's fast-paced world", "at the end of the day".
- Vary sentence length. A three-word sentence next to a twenty-word one reads human; every sentence landing at 12-18 words reads generated.
- Prefer concrete nouns and real specifics from the source data (place names, services, numbers, hours, neighbourhoods) over adjectives. One true detail beats three confident adjectives.

FORBIDDEN
- Chatbots, popups, cookie banners. Fake testimonials. Lorem Ipsum. External JS frameworks. Links to URLs not in source data. "Sign In" on non-SaaS sites. Hand-rolled entrance animation of any kind (opacity:0, IntersectionObserver, keyframe entrances) - the kit attributes are the only way in. Empty sections. Cropped faces/bodies. Reusing an image URL. A mailto: or tel: that is not in the source data. Any phone, email, address, licence number, year or count that the source data does not contain.

QUALITY GATE (auto-checked): an <h1> with data-words, a <footer>, 4+ <section>/<article> elements, 4+ DISTINCT proxied images, 6+ data-reveal elements, data-ambient on body. The page is then rendered in Chrome at desktop and phone widths and audited by more than 60 checks (structure, copy, motion, mobile, performance, accessibility, and whether every contact detail, number and image traces back to the source data), and compared against the prospect's current site. It ships only when it passes and wins.

Industry: {$industry}
TXT;
}

function build_user_prompt(array $scrape, string $biz, string $industry, int $variant_n, array $dna = [], array $brief = []): string {
    $imgs = $scrape['images'] ?? [];
    // is_tiny is set by ww_filter_live_images() from the image's REAL measured
    // pixel size. It must be excluded from every pool that can become visible
    // content: a 72x81 sliced table fragment is not a photo, not a cutout and not
    // even a usable thumbnail. Letting those through is what scored a page 38/100
    // on 2026-08-08. is_icon was never excluded from the photo pool either, so a
    // clipart glyph could be picked as a hero. Logos are exempt: the nav wordmark
    // is legitimately small and is placed by rule, not chosen as photography.
    $content = fn($i) => empty($i['is_tiny']) && empty($i['is_icon']);
    $photo_imgs  = array_values(array_filter($imgs, fn($i) => $content($i) && empty($i['is_logo']) && empty($i['is_thumb']) && empty($i['is_team_card']) && empty($i['is_cutout']) && empty($i['is_portrait']) && empty($i['is_soft'])));
    $cutout_imgs = array_values(array_filter($imgs, fn($i) => $content($i) && (!empty($i['is_cutout']) || !empty($i['is_portrait'])) && empty($i['is_logo'])));
    $team_card_imgs = array_values(array_filter($imgs, fn($i) => $content($i) && !empty($i['is_team_card'])));
    $logo_imgs = array_values(array_filter($imgs, fn($i) => !empty($i['is_logo'])));
    $thumb_imgs = array_values(array_filter($imgs, fn($i) => $content($i) && !empty($i['is_thumb']) && empty($i['is_logo']) && empty($i['is_team_card']) && empty($i['is_cutout'])));
    $strip = fn($arr) => array_map(fn($i) => ['url' => $i['url'], 'alt' => $i['alt']], $arr);
    $scrape_summary = [
        'business_name' => $biz, 'industry' => $industry ?: 'unknown', 'current_url' => $scrape['url'] ?? '',
        'page_title' => $scrape['title'] ?? '', 'meta_desc' => $scrape['description'] ?? '',
        'logo_url' => $scrape['logo'] ?? null, 'brand_colors' => array_slice($scrape['colors'] ?? [], 0, 5),
        'contact_emails' => array_slice($scrape['emails'] ?? [], 0, 6), 'contact_phones' => array_slice($scrape['phones'] ?? [], 0, 6),
        'h1' => $scrape['h1'] ?? [], 'h2' => $scrape['h2'] ?? [], 'h3' => $scrape['h3'] ?? [],
        'paragraphs' => $scrape['paragraphs'] ?? [],
        'images' => [
            'photo' => $strip(array_slice($photo_imgs, 0, 12)),
            'cutout' => $strip(array_slice($cutout_imgs, 0, 6)),
            'thumbnail' => $strip(array_slice($thumb_imgs, 0, 6)),
            'team_card' => $strip(array_slice($team_card_imgs, 0, 4)),
            'logo' => $strip(array_slice($logo_imgs, 0, 3)),
        ],
        'videos' => $scrape['videos'] ?? [], 'nav_links' => $scrape['nav_links'] ?? [], 'extra_pages' => $scrape['extra_pages'] ?? [],
    ];
    $direction = $dna ? ww_dna_prompt_block($dna) : '';
    $brief_block = ww_brief_prompt_block($brief);
    $scrape_json = json_encode($scrape_summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return <<<TXT
Build a single-page website for **{$biz}** using the source data below. This is variant {$variant_n} of 3.

{$direction}
{$brief_block}
SOURCE DATA:

{$scrape_json}

IMAGE PICKING GUIDE
- images.photo = PRIMARY landscape photos. Safe as hero / full-bleed / cover feature images. If the photo shows PEOPLE: always object-position:center top with a tall band so heads are never cropped; put a tight single portrait in a framed portrait card (aspect 3/4-4/5, object-position center top) with name/title beneath - never stretched across a wide-short band.
- images.cutout = people on transparent/plain backgrounds OR portrait photos. object-fit:CONTAIN in a fixed-height framed card with brand-tint background. NEVER crop, NEVER full-bleed hero.
- images.thumbnail = small filler images for small cards.
- images.team_card = ONLY for testimonial cards (name/title baked in).
- images.logo = ONLY in the nav.
- Every URL is verified to load. NEVER use the same URL twice.
- NEVER invent an image URL. Every src you write must be copied verbatim from the
  lists above, or be a /api/genimg.php?prompt=... URL you construct. Writing a
  stock-photo URL from memory (images.unsplash.com/photo-..., pexels, pixabay,
  shutterstock, getty) is forbidden: those ids are guesses, some of them 404 into
  an empty grey box, and generic stock is the exact thing that makes a site look
  like every other AI site. If you need a picture that the source data does not
  contain, generate one with /api/genimg.php and describe it precisely.

REQUIREMENTS
- Complete HTML with embedded <style> and <script>. Finish the document - end with </html>.
- Follow the SECTION PLAN above if one was given: those sections, that order, those headlines. If no plan was given, decide the structure yourself from the source data - never fall back to the stock hero/features/stats/testimonials/CTA sequence.
- The hero composition is dictated by the assigned LAYOUT ARCHETYPE above, not by a default. Only put a photo in the hero if that archetype calls for one; if it does, use a LANDSCAPE image from images.photo, and never stretch a cutout person across it.
- Logo in nav: prefer logo_url; else business-name wordmark set in the assigned display face.
- Every section marked "needs a real image" must get its own distinct real image. Sections marked otherwise should be type-, rule- or colour-led - do not manufacture image slots you cannot fill.
- Write the body copy in the assigned COPY VOICE, using specifics drawn from the source paragraphs. No interchangeable marketing filler, no invented numbers.
- Footer with real business name, copyright, and "Designed by WebWiz" link to https://trywebwiz.com.
- 2 primary CTAs above the fold, worded for this business.
- Target ~5000 tokens. Completeness beats length.

IMAGE TAG FORMAT - copy exactly:
<img src="/api/img.php?u=<urlencoded-URL>&l=<urlencoded-label>" alt="...">  (NO loading="lazy")

CONTACT DETAILS: the source data carries contact_emails and contact_phones. A mailto: or tel: link may ONLY use one of those. If the list is empty, do not write a mailto: or tel: at all; link the contact CTA to a #contact section or the current_url instead. An invented email address on a prospect's own homepage has already cost a sale.

QUALITY GATE: <h1> with data-words, <footer>, 4+ <section>/<article> elements, 4+ DISTINCT /api/img.php URLs, 6+ data-reveal elements, data-ambient on body, no empty sections, no cropped people, no broken images, no contact detail or number that is not in the source data.

REMEMBER: output ONLY the HTML document. First character `<`, last character `>`. No commentary. END WITH </html>.
TXT;
}
