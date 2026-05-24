<?php
// /var/www/sites/trywebwiz/private/lib/anthropic.php
// Claude API helper with token + cost tracking.

declare(strict_types=1);

// Pricing per million tokens (input/output) — Anthropic May 2026 list rates.
const ANTHROPIC_PRICING = [
    'claude-sonnet-4-6'      => ['in' => 3.0,  'out' => 15.0],
    'claude-opus-4-6'        => ['in' => 15.0, 'out' => 75.0],
    'claude-haiku-4-5-20251001' => ['in' => 1.0, 'out' => 5.0],
];

/**
 * Call Anthropic Messages API.
 * Returns ['text' => string, 'prompt_tokens' => int, 'completion_tokens' => int, 'cost_usd' => float, 'model' => string]
 * Throws on error.
 */
function anthropic_chat(string $model, array $messages, ?string $system = null, int $max_tokens = 4096, ?float $temperature = null, ?int $job_id = null, ?array $stop_sequences = null): array {
    $secrets = ww_secrets();
    $api_key = $secrets['ANTHROPIC_API_KEY'] ?? '';
    if (!$api_key) throw new Exception('ANTHROPIC_API_KEY not configured');

    $body = [
        'model'      => $model,
        'max_tokens' => $max_tokens,
        'messages'   => $messages,
    ];
    if ($system !== null)      $body['system'] = $system;
    if ($temperature !== null) $body['temperature'] = $temperature;
    if ($stop_sequences) $body['stop_sequences'] = $stop_sequences;

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_HTTPHEADER => [
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ],
    ]);
    $raw = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($raw === false) throw new Exception("Anthropic curl error: {$cerr}");
    $data = json_decode($raw, true);
    if ($http >= 400) {
        $msg = $data['error']['message'] ?? substr($raw, 0, 500);
        throw new Exception("Anthropic {$http}: {$msg}");
    }

    // Extract text from content blocks
    $text = '';
    foreach (($data['content'] ?? []) as $blk) {
        if (($blk['type'] ?? '') === 'text') $text .= $blk['text'];
    }

    $usage = $data['usage'] ?? [];
    $pt = (int)($usage['input_tokens'] ?? 0);
    $ct = (int)($usage['output_tokens'] ?? 0);

    $price = ANTHROPIC_PRICING[$model] ?? ANTHROPIC_PRICING['claude-sonnet-4-6'];
    $cost = ($pt / 1_000_000) * $price['in'] + ($ct / 1_000_000) * $price['out'];

    // Log to api_calls
    try {
        $stmt = ww_db()->prepare(
            "INSERT INTO api_calls (job_id, provider, model, prompt_tokens, completion_tokens, cost_usd) VALUES (?, 'anthropic', ?, ?, ?, ?)"
        );
        $stmt->execute([$job_id, $model, $pt, $ct, $cost]);
    } catch (Throwable $e) {
        error_log('[anthropic] log failed: ' . $e->getMessage());
    }

    return [
        'text' => $text,
        'prompt_tokens' => $pt,
        'completion_tokens' => $ct,
        'cost_usd' => $cost,
        'model' => $model,
    ];
}

/**
 * Fire multiple Anthropic Messages requests CONCURRENTLY via curl_multi.
 * $requests is keyed (e.g. by variant number); each value is ['system'=>?string, 'messages'=>array].
 * Returns same keys => ['text'=>string, 'cost_usd'=>float, 'http'=>int, 'prompt_tokens'=>int, 'completion_tokens'=>int].
 * Each successful call is logged to api_calls. Never throws on individual failures (returns empty text).
 */
function anthropic_multi(string $model, array $requests, int $max_tokens = 12000, ?float $temperature = null, ?int $job_id = null, ?array $stop_sequences = null): array {
    $secrets = ww_secrets();
    $api_key = $secrets['ANTHROPIC_API_KEY'] ?? '';
    if (!$api_key) throw new Exception('ANTHROPIC_API_KEY not configured');
    if (!$requests) return [];

    $mh = curl_multi_init();
    $handles = [];
    foreach ($requests as $k => $r) {
        $body = [
            'model'      => $model,
            'max_tokens' => $max_tokens,
            'messages'   => $r['messages'],
        ];
        if (!empty($r['system']))   $body['system'] = $r['system'];
        if ($temperature !== null)  $body['temperature'] = $temperature;
        if ($stop_sequences)        $body['stop_sequences'] = $stop_sequences;

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 240,
            CURLOPT_HTTPHEADER     => [
                'x-api-key: ' . $api_key,
                'anthropic-version: 2023-06-01',
                'content-type: application/json',
            ],
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$k] = $ch;
    }

    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh, 2.0);
    } while ($running && $status === CURLM_OK);

    $price = ANTHROPIC_PRICING[$model] ?? ANTHROPIC_PRICING['claude-sonnet-4-6'];
    $out = [];
    foreach ($handles as $k => $ch) {
        $raw  = curl_multi_getcontent($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);

        $text = ''; $pt = 0; $ct = 0; $cost = 0.0;
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if ($http < 400 && is_array($data)) {
            foreach (($data['content'] ?? []) as $blk) {
                if (($blk['type'] ?? '') === 'text') $text .= $blk['text'];
            }
            $pt = (int)($data['usage']['input_tokens'] ?? 0);
            $ct = (int)($data['usage']['output_tokens'] ?? 0);
            $cost = ($pt / 1_000_000) * $price['in'] + ($ct / 1_000_000) * $price['out'];
            try {
                ww_db()->prepare("INSERT INTO api_calls (job_id, provider, model, prompt_tokens, completion_tokens, cost_usd) VALUES (?, 'anthropic', ?, ?, ?, ?)")
                    ->execute([$job_id, $model, $pt, $ct, $cost]);
            } catch (Throwable $e) { error_log('[anthropic_multi] log failed: ' . $e->getMessage()); }
        } else {
            error_log("[anthropic_multi] request $k failed http=$http: " . substr((string)$raw, 0, 300));
        }
        $out[$k] = ['text' => $text, 'cost_usd' => $cost, 'http' => $http, 'prompt_tokens' => $pt, 'completion_tokens' => $ct];
    }
    curl_multi_close($mh);
    return $out;
}

/**
 * Vision call: send image(s) + text, get text back.
 * $images = [['media_type'=>'image/jpeg','data'=>base64], ...]
 * Returns ['text','cost_usd','prompt_tokens','completion_tokens','model']. Throws on hard error.
 */
function anthropic_vision(string $model, string $system, string $user_text, array $images, int $max_tokens = 1200, ?float $temperature = 0.0, ?int $job_id = null): array {
    $secrets = ww_secrets();
    $api_key = $secrets['ANTHROPIC_API_KEY'] ?? '';
    if (!$api_key) throw new Exception('ANTHROPIC_API_KEY not configured');

    $content = [];
    foreach ($images as $img) {
        $content[] = ['type'=>'image','source'=>['type'=>'base64','media_type'=>$img['media_type'] ?? 'image/jpeg','data'=>$img['data']]];
    }
    $content[] = ['type'=>'text','text'=>$user_text];

    $body = [
        'model'      => $model,
        'max_tokens' => $max_tokens,
        'system'     => $system,
        'messages'   => [['role'=>'user','content'=>$content]],
    ];
    if ($temperature !== null) $body['temperature'] = $temperature;

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => ['x-api-key: '.$api_key, 'anthropic-version: 2023-06-01', 'content-type: application/json'],
    ]);
    $raw = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); $cerr = curl_error($ch); curl_close($ch);
    if ($raw === false) throw new Exception("Anthropic vision curl error: $cerr");
    $data = json_decode($raw, true);
    if ($http >= 400) throw new Exception("Anthropic vision $http: ".($data['error']['message'] ?? substr((string)$raw,0,300)));
    $text = '';
    foreach (($data['content'] ?? []) as $blk) { if (($blk['type'] ?? '') === 'text') $text .= $blk['text']; }
    $pt = (int)($data['usage']['input_tokens'] ?? 0); $ct = (int)($data['usage']['output_tokens'] ?? 0);
    $price = ANTHROPIC_PRICING[$model] ?? ANTHROPIC_PRICING['claude-sonnet-4-6'];
    $cost = ($pt/1_000_000)*$price['in'] + ($ct/1_000_000)*$price['out'];
    try { ww_db()->prepare("INSERT INTO api_calls (job_id, provider, model, prompt_tokens, completion_tokens, cost_usd) VALUES (?, 'anthropic', ?, ?, ?, ?)")->execute([$job_id, $model.'-vision', $pt, $ct, $cost]); } catch (Throwable $e) {}
    return ['text'=>$text, 'cost_usd'=>$cost, 'prompt_tokens'=>$pt, 'completion_tokens'=>$ct, 'model'=>$model];
}
