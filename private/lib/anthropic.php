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
