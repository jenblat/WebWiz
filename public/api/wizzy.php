<?php
// /var/www/sites/trywebwiz/public/api/wizzy.php
// Wizzy chat backend. Sonnet 4-6 conversational sales nudge.

declare(strict_types=1);
require '/var/www/sites/trywebwiz/private/webwiz_lib.php';
require '/var/www/sites/trywebwiz/private/lib/anthropic.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'POST only']));
}

$raw = file_get_contents('php://input') ?: '{}';
$req = json_decode($raw, true) ?: [];
$token   = preg_replace('/[^a-f0-9]/', '', (string)($req['token'] ?? ''));
$visitor = preg_replace('/[^A-Za-z0-9_]/', '', (string)($req['visitor_id'] ?? ''));
$message = trim((string)($req['message'] ?? ''));

if (!$token || !$visitor || !$message) exit(json_encode(['error' => 'Bad request']));
if (mb_strlen($message) > 2000) $message = mb_substr($message, 0, 2000);

$db = ww_db();

// Check global daily cap
$daily_cap = (float)($db->query("SELECT value FROM settings WHERE key='wizzy_daily_cap_usd'")->fetchColumn() ?: 50);
$enabled   = (string)($db->query("SELECT value FROM settings WHERE key='wizzy_enabled'")->fetchColumn() ?: '1');
if ($enabled !== '1') exit(json_encode(['reply' => "I'm taking a quick break. Please email hello@trywebwiz.com and someone will get back to you within an hour."]));
$today_spent = (float)$db->query("SELECT COALESCE(SUM(cost_usd), 0) FROM wizzy_chats WHERE date(created_at) = date('now')")->fetchColumn();
if ($today_spent >= $daily_cap) exit(json_encode(['reply' => "I'm popular today! Drop your email and someone human will follow up: hello@trywebwiz.com"]));

// Check per-visitor session limits
$turn_n = (int)$db->prepare("SELECT COALESCE(MAX(turn_n), 0) FROM wizzy_chats WHERE visitor_id = ? AND preview_token = ?")
    ->execute([$visitor, $token]) === false ? 0 : 0;
$st = $db->prepare("SELECT COALESCE(MAX(turn_n), 0) FROM wizzy_chats WHERE visitor_id = ? AND preview_token = ?");
$st->execute([$visitor, $token]);
$turn_n = (int)$st->fetchColumn();
if ($turn_n >= 12) {
    exit(json_encode(['reply' => "I'd love to keep chatting but I want to hand you to a human who can actually help. Email hello@trywebwiz.com or click Buy now above and someone will reach out within an hour."]));
}

// Per-preview daily spend cap ($2)
$preview_spent = (float)$db->prepare("SELECT COALESCE(SUM(cost_usd), 0) FROM wizzy_chats WHERE preview_token = ? AND date(created_at) = date('now')")
    ->execute([$token]) === false ? 0 : 0;
$st2 = $db->prepare("SELECT COALESCE(SUM(cost_usd), 0) FROM wizzy_chats WHERE preview_token = ? AND date(created_at) = date('now')");
$st2->execute([$token]);
$preview_spent = (float)$st2->fetchColumn();
if ($preview_spent >= 2.00) {
    exit(json_encode(['reply' => "Quick break to keep things snappy! Email hello@trywebwiz.com and we'll pick this up there."]));
}

// Pull job + scrape context
$st = $db->prepare("SELECT * FROM jobs WHERE token = ?");
$st->execute([$token]);
$job = $st->fetch(PDO::FETCH_ASSOC);
if (!$job) exit(json_encode(['error' => 'Unknown preview']));

$biz = $job['business_name'] ?: 'your business';
$first_name = '';
if ($job['prospect_id']) {
    $sp = $db->prepare("SELECT * FROM prospects WHERE id = ?");
    $sp->execute([$job['prospect_id']]);
    $pr = $sp->fetch(PDO::FETCH_ASSOC);
    if ($pr && !empty($pr['name'])) $first_name = explode(' ', $pr['name'])[0];
}

// ---- Build conversation history ----
$hist_stmt = $db->prepare("SELECT role, text FROM wizzy_chats WHERE visitor_id = ? AND preview_token = ? ORDER BY id ASC LIMIT 30");
$hist_stmt->execute([$visitor, $token]);
$hist = $hist_stmt->fetchAll(PDO::FETCH_ASSOC);

$messages = [];
foreach ($hist as $h) {
    $r = ($h['role'] === 'user') ? 'user' : 'assistant';
    $messages[] = ['role' => $r, 'content' => $h['text']];
}
$messages[] = ['role' => 'user', 'content' => $message];

$system = <<<TXT
You are Wizzy, a friendly, helpful, slightly playful design mascot from WebWiz Studio. You are chatting with {$first_name} from {$biz} about three new website designs we created for them.

YOUR PURPOSE
- Be warm, curious, never pushy.
- Answer questions about the designs, the company (WebWiz Studio), and what happens after they buy.
- Surface objections gently. If they want changes, note them and reassure that the account manager will incorporate them after purchase.
- When the visitor signals they want to move forward ("ok", "let's do it", "I'm in", "how do I buy", "yes"), end your reply with the special marker [[BUY_NOW]] on its own line. The UI will then surface the Stripe checkout button.

CONTEXT YOU KNOW
- The business name is "{$biz}".
- We built three different design directions: Variant 1 (Bold Editorial), Variant 2 (Modern Maximalist), Variant 3 (Refined Minimal).
- Build price is a flat \$499, one-time. Optional care plans: \$49/month (hosting) or \$99/month (hosting + 10 edits).
- Turnaround: roughly 2 weeks from kickoff call.
- After purchase, an account manager named Omar or Laura reaches out within 1 business day.

RULES
- Never promise specific delivery dates (always "around 2 weeks").
- Never discount, never offer pricing exceptions. Always route to a human.
- Never disparage competitors.
- Never ask for or echo credit card numbers, SSNs, or other sensitive data.
- Keep replies short: 1-3 sentences. Conversational, not corporate.
- If you don't know something, say so and offer to have a human follow up.
- Don't generate or describe new design variants on the fly. Note the request and pass it to the account manager.
TXT;

try {
    $resp = anthropic_chat('claude-sonnet-4-6', $messages, $system, 800, 0.7, null);
} catch (Throwable $e) {
    error_log('[wizzy] ' . $e->getMessage());
    exit(json_encode(['reply' => "Hmm, my brain hiccupped. Try once more?"]));
}

$reply_text = trim($resp['text']);
$buy = false;
if (preg_match('/\[\[BUY_NOW\]\]/i', $reply_text)) {
    $buy = true;
    $reply_text = trim(preg_replace('/\[\[BUY_NOW\]\]/i', '', $reply_text));
}

// Log both turns
$ins = $db->prepare("INSERT INTO wizzy_chats (visitor_id, preview_token, turn_n, role, text, prompt_tokens, completion_tokens, cost_usd) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
$ins->execute([$visitor, $token, $turn_n + 1, 'user', $message, 0, 0, 0]);
$ins->execute([$visitor, $token, $turn_n + 1, 'assistant', $reply_text, (int)$resp['prompt_tokens'], (int)$resp['completion_tokens'], (float)$resp['cost_usd']]);

$buy_url = $buy ? ('/start?b=' . urlencode($biz) . '&e=' . urlencode($job['customer_email'] ?? '') . '&from_wizzy=1') : null;

echo json_encode(['reply' => $reply_text, 'buy_url' => $buy_url]);
