<?php
// /var/www/sites/trywebwiz/private/lib/batch.php
// Batch generation pipeline for CSV-upload jobs (Anthropic Message Batches API, 50% cheaper).
// Reuses worker.php's scrape_multi / build_system_prompt / build_user_prompt / finalize_html / quality_gate.
// Two stages, both driven by the cron worker:
//   ww_build_batches()  - scrape an upload's prospects (time-bounded, incremental), then submit one batch set.
//   ww_poll_batches()   - when batches end, write results, re-batch failures ONCE, finalize + record cost.

declare(strict_types=1);

const WW_BATCH_BUILD_BUDGET = 35; // max seconds spent scraping per worker run

function ww_blog(string $m): void { echo "[batch] $m\n"; }

/** custom_id <-> (job, variant, round) */
function ww_cid(int $jid, int $v, int $round): string { return "j{$jid}v{$v}r{$round}"; }
function ww_parse_cid(string $cid): ?array {
    if (preg_match('~^j(\d+)v(\d+)r(\d+)$~', $cid, $m)) return ['job'=>(int)$m[1], 'v'=>(int)$m[2], 'round'=>(int)$m[3]];
    return null;
}

/**
 * BUILDER: pick the oldest queued upload, scrape its prospects incrementally (time-bounded).
 * Once every prospect in the upload is scraped (or failed), submit one Anthropic batch set
 * (3 requests per job) and move the upload to 'generating'.
 */
function ww_build_batches(PDO $db): void {
    $ub = $db->query("SELECT * FROM upload_batches WHERE status='queued' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$ub) return;
    $uid = (int)$ub['id'];

    $start = time();
    $jq = $db->prepare("SELECT * FROM jobs WHERE upload_batch_id=? AND item_status='queued' ORDER BY id ASC");
    $jq->execute([$uid]);
    foreach ($jq->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (time() - $start > WW_BATCH_BUILD_BUDGET) { ww_blog("upload #$uid: scrape budget hit, will continue next run"); return; }
        $jid = (int)$row['id'];
        try {
            $pros = null;
            if ($row['prospect_id']) { $s = $db->prepare("SELECT * FROM prospects WHERE id=?"); $s->execute([$row['prospect_id']]); $pros = $s->fetch(PDO::FETCH_ASSOC); }
            $url = $pros['current_url'] ?? '';
            if (!$url) throw new Exception('no current_url');
            $scrape = scrape_multi($url);
            $db->prepare("UPDATE jobs SET scrape_data=?, item_status='scraped' WHERE id=?")->execute([json_encode($scrape), $jid]);
            ww_blog("scraped job #$jid ($url)");
        } catch (Throwable $e) {
            $db->prepare("UPDATE jobs SET item_status='failed', status='failed', error=? WHERE id=?")->execute([substr('scrape: '.$e->getMessage(),0,500), $jid]);
            ww_blog("job #$jid scrape failed: ".$e->getMessage());
        }
    }

    $left = (int)$db->query("SELECT COUNT(*) FROM jobs WHERE upload_batch_id=$uid AND item_status='queued'")->fetchColumn();
    if ($left > 0) { ww_blog("upload #$uid: $left prospect(s) left to scrape"); return; }

    $scraped = $db->query("SELECT * FROM jobs WHERE upload_batch_id=$uid AND item_status='scraped'")->fetchAll(PDO::FETCH_ASSOC);
    if (!$scraped) { $db->prepare("UPDATE upload_batches SET status='failed' WHERE id=?")->execute([$uid]); ww_blog("upload #$uid: nothing scraped -> failed"); return; }

    $model = 'claude-sonnet-4-6';
    $requests = [];
    foreach ($scraped as $row) {
        $jid = (int)$row['id'];
        $scrape = json_decode($row['scrape_data'] ?: '{}', true) ?: [];
        $pros = null;
        if ($row['prospect_id']) { $s = $db->prepare("SELECT * FROM prospects WHERE id=?"); $s->execute([$row['prospect_id']]); $pros = $s->fetch(PDO::FETCH_ASSOC); }
        $biz = $pros['business_name'] ?? $row['business_name'] ?? 'Their Business';
        $industry = $pros['industry'] ?? '';
        $usable = array_values(array_filter($scrape['images'] ?? [], fn($i) => empty($i['is_logo']) && empty($i['is_thumb']) && empty($i['is_team_card'])));
        $system = build_system_prompt($industry, count($usable));
        for ($v = 1; $v <= 3; $v++) {
            $requests[ww_cid($jid, $v, 1)] = ['system'=>$system, 'messages'=>[['role'=>'user','content'=>build_user_prompt($scrape, $biz, $industry, $v)]]];
        }
    }
    ww_blog("upload #$uid: submitting ".count($requests)." requests (".count($scraped)." sites)");
    $res = anthropic_batch_create($model, $requests, 14000, 0.7, ['</html>']);
    if (empty($res['batch_ids'])) { ww_blog("upload #$uid: batch create FAILED: ".implode('; ', $res['errors'])); return; }

    $db->prepare("UPDATE jobs SET item_status='generating', status='running' WHERE upload_batch_id=? AND item_status='scraped'")->execute([$uid]);
    $db->prepare("UPDATE upload_batches SET status='generating', anthropic_batch_ids=?, last_polled_at=datetime('now') WHERE id=?")
       ->execute([json_encode($res['batch_ids']), $uid]);
    ww_blog("upload #$uid: created batch(es) ".implode(',', $res['batch_ids']));
}

/**
 * POLLER: for each generating upload, once all its Anthropic batches have ended, gather results,
 * finalize passing variants, re-batch failed/empty variants exactly once, then finalize the upload.
 */
function ww_poll_batches(PDO $db): void {
    $ubs = $db->query("SELECT * FROM upload_batches WHERE status IN ('generating','qa') ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ubs as $ub) {
        $uid = (int)$ub['id'];
        $bids = json_decode($ub['anthropic_batch_ids'] ?: '[]', true) ?: [];
        if (!$bids) continue;

        $allEnded = true;
        foreach ($bids as $bid) { $m = anthropic_batch_retrieve($bid); if (($m['processing_status'] ?? '') !== 'ended') { $allEnded = false; break; } }
        $db->prepare("UPDATE upload_batches SET last_polled_at=datetime('now') WHERE id=?")->execute([$uid]);
        if (!$allEnded) { ww_blog("upload #$uid: batches still processing"); continue; }

        // Gather all results; keep the highest round per (job,variant).
        $best = []; $maxRound = 1;
        foreach ($bids as $bid) {
            foreach (anthropic_batch_results($bid) as $cid => $r) {
                $p = ww_parse_cid($cid); if (!$p) continue;
                $key = $p['job'].'_'.$p['v'];
                if (!isset($best[$key]) || $p['round'] > $best[$key]['round']) $best[$key] = $r + ['round'=>$p['round']];
                if ($p['round'] > $maxRound) $maxRound = $p['round'];
            }
        }

        $jobs = $db->query("SELECT * FROM jobs WHERE upload_batch_id=$uid AND item_status='generating'")->fetchAll(PDO::FETCH_ASSOC);
        $rebatch = [];   // cid => request, for round 2
        $finalize = [];  // resolved jobs to write

        foreach ($jobs as $row) {
            $jid = (int)$row['id'];
            $scrape = json_decode($row['scrape_data'] ?: '{}', true) ?: [];
            $pros = null;
            if ($row['prospect_id']) { $s = $db->prepare("SELECT * FROM prospects WHERE id=?"); $s->execute([$row['prospect_id']]); $pros = $s->fetch(PDO::FETCH_ASSOC); }
            $biz = $pros['business_name'] ?? $row['business_name'] ?? 'Their Business';
            $industry = $pros['industry'] ?? '';
            $usable = array_values(array_filter($scrape['images'] ?? [], fn($i) => empty($i['is_logo']) && empty($i['is_thumb']) && empty($i['is_team_card'])));
            $system = build_system_prompt($industry, count($usable));

            $htmls = []; $cost = 0.0; $failed = [];
            for ($v = 1; $v <= 3; $v++) {
                $r = $best[$jid.'_'.$v] ?? null;
                if ($r) $cost += (float)($r['cost'] ?? 0);
                $cand = ($r && !empty($r['text'])) ? finalize_html($r['text']) : null;
                if ($cand && quality_gate($cand)['ok']) { $htmls[$v] = $cand; }
                else { $failed[$v] = true; }
            }

            if ($failed && $maxRound < 2) {
                foreach (array_keys($failed) as $v) {
                    $rebatch[ww_cid($jid, $v, 2)] = ['system'=>$system, 'messages'=>[['role'=>'user',
                        'content'=>build_user_prompt($scrape, $biz, $industry, $v) .
                        "\n\nYour previous attempt failed the quality gate. Output ONLY a complete HTML document: include <h1>, <footer>, 3+ <section> tags and 4+ /api/img.php images, and END with </html>."]]];
                }
            }
            $finalize[] = ['row'=>$row, 'htmls'=>$htmls, 'cost'=>$cost];
        }

        if ($rebatch) {
            $res = anthropic_batch_create('claude-sonnet-4-6', $rebatch, 14000, 0.6, ['</html>']);
            if (!empty($res['batch_ids'])) {
                $bids = array_merge($bids, $res['batch_ids']);
                $db->prepare("UPDATE upload_batches SET anthropic_batch_ids=? WHERE id=?")->execute([json_encode($bids), $uid]);
                ww_blog("upload #$uid: re-batched ".count($rebatch)." failed variant(s) -> ".implode(',', $res['batch_ids']));
                continue; // wait for round 2 on next poll
            }
            ww_blog("upload #$uid: re-batch create failed; finalizing with what passed");
        }

        $done = 0; $failedJobs = 0;
        foreach ($finalize as $f) {
            $row = $f['row']; $jid = (int)$row['id']; $htmls = $f['htmls']; $cost = $f['cost'];
            if (!$htmls) {
                $db->prepare("UPDATE jobs SET status='failed', item_status='failed', error='all variants failed quality gate', total_cost_cents=?, completed_at=datetime('now') WHERE id=?")
                   ->execute([(int)round($cost*100), $jid]);
                $failedJobs++; ww_blog("job #$jid: FAILED (no usable variants)"); continue;
            }
            $public_dir = '/var/www/sites/trywebwiz/public/preview/' . $row['token'];
            ksort($htmls);
            foreach ($htmls as $v => $html) {
                $dir = $public_dir . '/v' . $v;
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                file_put_contents($dir . '/index.html', $html);
            }
            $stub = $public_dir . '/index.php';
            if (!is_file($stub)) file_put_contents($stub, "<?php\n\$_GET['t'] = basename(__DIR__);\nrequire __DIR__ . '/../index.php';\n");
            foreach ($htmls as $v => $html) {
                $rel = '/preview/' . $row['token'] . '/v' . $v . '/index.html';
                $db->prepare("INSERT INTO previews (job_id, variant_n, html_path, qa_score, qa_pass, qa_issues) VALUES (?, ?, ?, NULL, NULL, NULL)")
                   ->execute([$jid, $v, $rel]);
            }
            $db->prepare("UPDATE jobs SET status='ready', item_status='done', completed_at=datetime('now'), total_cost_cents=?, qa_status='batch' WHERE id=?")
               ->execute([(int)round($cost*100), $jid]);
            $done++; ww_blog("job #$jid: ready (".count($htmls)." variants, \$".number_format($cost,4).")");
        }

        $tot   = (int)$db->query("SELECT COUNT(*) FROM jobs WHERE upload_batch_id=$uid")->fetchColumn();
        $doneN = (int)$db->query("SELECT COUNT(*) FROM jobs WHERE upload_batch_id=$uid AND item_status='done'")->fetchColumn();
        $failN = (int)$db->query("SELECT COUNT(*) FROM jobs WHERE upload_batch_id=$uid AND item_status='failed'")->fetchColumn();
        if ($doneN + $failN >= $tot) {
            $db->prepare("UPDATE upload_batches SET status=? WHERE id=?")->execute([$failN >= $tot ? 'failed' : 'done', $uid]);
            ww_blog("upload #$uid: COMPLETE ($doneN done, $failN failed of $tot)");
        }
    }
}
