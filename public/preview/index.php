<?php
// /var/www/sites/trywebwiz/public/preview/index.php
// Customer-facing preview gallery. URL: /preview/{token}/
// Single-variant tabbed view: header has tabs to switch between v1/v2/v3 + optional "Your current site".

declare(strict_types=1);
require '/var/www/sites/trywebwiz/private/webwiz_lib.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$token = '';
if (preg_match('~^/preview/([a-f0-9]{12,40})~i', $path, $m)) {
    $token = $m[1];
} elseif (!empty($_GET['t'])) {
    $token = preg_replace('/[^a-f0-9]/i', '', (string)$_GET['t']);
}
if (!$token) { http_response_code(404); exit('Preview not found.'); }

$db = ww_db();
$job = $db->prepare('SELECT * FROM jobs WHERE token = ? LIMIT 1');
$job->execute([$token]);
$job = $job->fetch(PDO::FETCH_ASSOC);

if (!$job || !in_array($job['status'], ['ready','sent','picked'], true)) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset=utf-8><title>Preview not ready</title>';
    echo '<body style="font-family:-apple-system,BlinkMacSystemFont,sans-serif;padding:60px 20px;text-align:center;background:#FFF8E7;color:#12184A;">';
    echo '<h1>Your previews are not ready yet.</h1><p>Check back soon, or reply to the email when it lands.</p>';
    exit;
}

$expiry_days = (int)$db->query("SELECT value FROM settings WHERE key='preview_expiry_days'")->fetchColumn() ?: 30;
$created = strtotime($job['completed_at'] ?? $job['created_at']);
if (time() - $created > $expiry_days * 86400) {
    echo '<!doctype html><meta charset=utf-8><title>Preview expired</title>';
    echo '<body style="font-family:-apple-system;padding:60px 20px;text-align:center;background:#FFF8E7;color:#12184A;">';
    echo '<h1>This preview has expired.</h1><p>Reply to our last email and we will regenerate it for you.</p>';
    exit;
}

$previews = $db->prepare('SELECT * FROM previews WHERE job_id = ? AND archived = 0 ORDER BY variant_n ASC');
$previews->execute([$job['id']]);
$previews = $previews->fetchAll(PDO::FETCH_ASSOC);
$previews = array_values(array_filter($previews, function($p) {
    return file_exists('/var/www/sites/trywebwiz/public' . $p['html_path']);
}));
if (!$previews) { http_response_code(404); exit('No variants found.'); }

$biz = $job['business_name'] ?: 'You';
$first_name = '';
$prospect = null;
$current_url = '';
if ($job['prospect_id']) {
    $st = $db->prepare('SELECT * FROM prospects WHERE id = ?');
    $st->execute([$job['prospect_id']]);
    $prospect = $st->fetch(PDO::FETCH_ASSOC);
    if ($prospect) {
        if (!empty($prospect['name'])) $first_name = explode(' ', $prospect['name'])[0];
        $current_url = trim((string)($prospect['current_url'] ?? ''));
        if ($current_url && !preg_match('~^https?://~i', $current_url)) $current_url = 'https://' . $current_url;
    }
}

$buy_url_base = '/start?b=' . urlencode($biz) . '&e=' . urlencode($job['customer_email'] ?? '');
if ($first_name) $buy_url_base .= '&n=' . urlencode($first_name);

$variant_names = [1 => 'Bold Editorial', 2 => 'Modern Maximalist', 3 => 'Refined Minimal'];

$tabs = [];
foreach ($previews as $idx => $p) {
    $v = (int)$p['variant_n'];
    $tabs[] = [
        'key'   => 'v' . $v,
        'label' => 'Variant ' . ($idx + 1),
        'sub'   => $variant_names[$v] ?? ('Direction ' . ($idx + 1)),
        'src'   => $p['html_path'] . '?v=' . (@filemtime('/var/www/sites/trywebwiz/public' . $p['html_path']) ?: time()),
        'variant' => $v,
        'is_original' => false,
    ];
}
if ($current_url) {
    $screenshot_url = 'https://s0.wp.com/mshots/v1/' . urlencode($current_url) . '?w=1400&h=2200';
    $tabs[] = [
        'key' => 'orig', 'label' => 'Your current site',
        'sub' => parse_url($current_url, PHP_URL_HOST) ?: $current_url,
        'src' => $screenshot_url, 'href' => $current_url,
        'variant' => 0, 'is_original' => true,
    ];
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Your new site, <?= ww_h($biz) ?> · WebWiz</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@700;900&family=Inter:wght@400;500;700&display=swap">
<style>
  :root{--cream:#FFF8E7;--paper:#F8EFD3;--yellow:#F7C84A;--teal:#3FCFA8;--navy:#12184A;--display:Nunito,system-ui,sans-serif;--body:Inter,system-ui,sans-serif;}
  *{box-sizing:border-box;margin:0;padding:0;}
  html, body{height:100%;}
  body{font-family:var(--body);background:var(--cream);color:var(--navy);line-height:1.5;display:flex;flex-direction:column;}

  .hdr{position:sticky;top:0;background:var(--cream);border-bottom:3px solid var(--navy);z-index:100;padding:12px 22px;display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:16px;}
  .hdr-brand{display:flex;align-items:center;gap:10px;font-family:var(--display);font-weight:900;font-size:22px;color:var(--navy);text-decoration:none;}
  .hdr-brand img{height:36px;width:auto;}
  .hdr-brand .dot{color:var(--yellow);}
  .hdr-brand small{display:block;font-family:ui-monospace,monospace;font-size:10px;letter-spacing:0.18em;text-transform:uppercase;opacity:0.6;margin-top:-2px;}
  .hdr-tabs{display:flex;gap:8px;flex-wrap:wrap;justify-content:center;}
  .tab{font-family:var(--display);font-weight:900;font-size:13px;letter-spacing:0.02em;padding:9px 16px;border-radius:999px;border:2.5px solid var(--navy);background:#fff;color:var(--navy);cursor:pointer;transition:all .15s ease;line-height:1.1;display:flex;flex-direction:column;align-items:center;gap:1px;}
  .tab .tab-sub{font-size:10px;font-weight:700;letter-spacing:0.08em;opacity:0.65;text-transform:uppercase;}
  .tab:hover{transform:translate(-1px,-1px);box-shadow:3px 3px 0 var(--navy);}
  .tab.active{background:var(--navy);color:var(--cream);box-shadow:4px 4px 0 var(--yellow);}
  .tab.active .tab-sub{opacity:0.85;color:var(--yellow);}
  .tab.original{border-style:dashed;}
  .hdr-cta{display:flex;gap:8px;flex-wrap:wrap;align-items:center;}
  .btn{font-family:var(--display);font-weight:900;font-size:13px;padding:11px 18px;border-radius:999px;border:0;cursor:pointer;text-decoration:none;display:inline-block;letter-spacing:0.02em;line-height:1;}
  .btn.primary{background:var(--navy);color:var(--cream);box-shadow:4px 4px 0 var(--yellow);}
  .btn.primary:hover{transform:translate(-1px,-1px);box-shadow:6px 6px 0 var(--yellow);}
  .btn.ghost{background:transparent;color:var(--navy);border:2.5px solid var(--navy);}
  .btn.ghost:hover{background:var(--navy);color:var(--cream);}
  @media (max-width:900px){
    .hdr{grid-template-columns:1fr;text-align:center;}
    .hdr-tabs{justify-content:flex-start;overflow-x:auto;padding-bottom:6px;}
    .hdr-cta{justify-content:center;}
    .tab{flex:0 0 auto;}
  }

  /* STAGE — iframes are now interactive (native scroll). The injected click capture inside fires Wizzy. */
  .stage{flex:1;position:relative;background:#fff;min-height:0;overflow:hidden;}
  .pane{position:absolute;inset:0;display:none;}
  .pane.active{display:block;}
  .pane iframe{width:100%;height:100%;border:0;background:#fff;}
  .pane.scroll-pane{overflow-y:auto;background:var(--cream);cursor:pointer;}
  .pane .shot-wrap{max-width:1400px;margin:0 auto;padding:24px 24px 80px;display:flex;flex-direction:column;gap:14px;align-items:center;}
  .pane .shot-note{background:var(--paper);border:2px solid var(--navy);border-radius:14px;padding:10px 18px;font-family:var(--display);font-weight:900;font-size:13px;display:flex;gap:14px;align-items:center;flex-wrap:wrap;justify-content:center;box-shadow:4px 4px 0 var(--yellow);}
  .pane .shot-img{width:100%;border:3px solid var(--navy);border-radius:14px;box-shadow:6px 6px 0 var(--navy);background:#fff;display:block;}
  .pane .shot-img.loading{min-height:60vh;background:repeating-linear-gradient(45deg,#fff,#fff 24px,#f6efd5 24px,#f6efd5 48px);}

  .nudge{position:fixed;bottom:120px;right:28px;background:var(--navy);color:var(--cream);padding:9px 16px;border-radius:999px;font-family:var(--display);font-weight:900;font-size:12px;letter-spacing:0.04em;box-shadow:4px 4px 0 var(--yellow);pointer-events:none;opacity:0;transition:opacity .25s;z-index:199;}
  .nudge.show{opacity:1;}

  .stage .loading{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:14px;background:var(--cream);font-family:var(--display);font-weight:900;color:var(--navy);z-index:4;}
  .stage .loading.hidden{display:none;}
  .stage .loading .dot-dot{display:inline-flex;gap:6px;}
  .stage .loading .dot-dot span{width:10px;height:10px;background:var(--navy);border-radius:50%;animation:dot 1.2s infinite ease-in-out;}
  .stage .loading .dot-dot span:nth-child(2){animation-delay:.15s;}
  .stage .loading .dot-dot span:nth-child(3){animation-delay:.3s;}
  @keyframes dot{0%,80%,100%{transform:scale(0.6);opacity:0.4;}40%{transform:scale(1);opacity:1;}}

  #wiz-bubble{position:fixed;bottom:22px;right:22px;width:78px;height:78px;border-radius:50%;background:var(--cream);border:4px solid var(--navy);box-shadow:6px 6px 0 var(--yellow);cursor:pointer;display:flex;align-items:center;justify-content:center;overflow:hidden;padding:0;z-index:200;transition:transform .2s;}
  #wiz-bubble:hover{transform:translate(-2px,-2px);}
  #wiz-bubble img{width:100%;height:100%;object-fit:cover;object-position:center;border-radius:50%;display:block;}

  #wiz-chat{position:fixed;bottom:110px;right:22px;width:380px;max-width:calc(100vw - 30px);height:560px;max-height:calc(100vh - 140px);background:#fff;border:4px solid var(--navy);border-radius:22px;box-shadow:10px 10px 0 var(--navy);display:none;flex-direction:column;z-index:201;overflow:hidden;}
  #wiz-chat.open{display:flex;}
  .wiz-head{padding:14px 18px;background:var(--navy);color:var(--cream);font-family:var(--display);font-weight:900;display:flex;align-items:center;gap:10px;}
  .wiz-head img{width:38px;height:38px;}
  .wiz-head .x{margin-left:auto;cursor:pointer;font-size:22px;opacity:0.7;}
  .wiz-msgs{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:10px;font-size:14px;}
  .wiz-msg{padding:10px 14px;border-radius:16px;max-width:88%;line-height:1.5;word-wrap:break-word;}
  .wiz-msg.user{background:var(--yellow);color:var(--navy);align-self:flex-end;font-weight:600;}
  .wiz-msg.bot{background:var(--paper);color:var(--navy);align-self:flex-start;border:2px solid var(--navy);}
  .wiz-msg.bot strong{font-weight:900;}
  .wiz-msg.bot em{font-style:italic;}
  .wiz-msg.bot code{background:#fff;padding:1px 6px;border-radius:6px;font-family:ui-monospace,monospace;font-size:12.5px;border:1px solid #d8cfa8;}
  .wiz-msg.bot a:not(.cta){color:var(--navy);font-weight:700;}
  .wiz-msg.bot .cta{display:inline-block;margin-top:8px;background:var(--navy);color:var(--cream);padding:10px 16px;border-radius:999px;text-decoration:none;font-family:var(--display);font-weight:900;font-size:13px;border:2px solid var(--navy);}
  .wiz-msg.bot .cta:hover{background:#0c1133;color:var(--cream);}
  .wiz-msg.bot .cta.ghost{background:#fff;color:var(--navy);}
  .wiz-msg.bot .cta.ghost:hover{background:var(--navy);color:var(--cream);}
  .wiz-form{display:flex;border-top:3px solid var(--navy);}
  .wiz-form input{flex:1;border:0;padding:14px 16px;font-family:var(--body);font-size:14px;background:#fff;color:var(--navy);}
  .wiz-form input:focus{outline:none;background:var(--cream);}
  .wiz-form button{background:var(--navy);color:var(--cream);border:0;padding:0 20px;font-family:var(--display);font-weight:900;cursor:pointer;}
</style>
</head>
<body data-token="<?= ww_h($token) ?>">

<header class="hdr">
  <a class="hdr-brand" href="/" title="WebWiz">
    <img src="/favicon-32.png" alt="Wizzy">
    <span>WebWiz<span class="dot">.</span><small>for <?= ww_h($biz) ?></small></span>
  </a>
  <nav class="hdr-tabs" role="tablist">
    <?php foreach ($tabs as $i => $t): ?>
      <button class="tab <?= $i === 0 ? 'active' : '' ?> <?= $t['is_original'] ? 'original' : '' ?>"
              role="tab"
              data-tab="<?= ww_h($t['key']) ?>"
              data-variant="<?= (int)$t['variant'] ?>"
              data-src="<?= ww_h($t['src']) ?>"
              data-original="<?= $t['is_original'] ? '1' : '0' ?>">
        <?= ww_h($t['label']) ?>
        <span class="tab-sub"><?= ww_h($t['sub']) ?></span>
      </button>
    <?php endforeach; ?>
  </nav>
  <div class="hdr-cta">
    <a class="btn ghost" id="customizeBtn" href="<?= ww_h($buy_url_base) ?>&variant=1&customize=1">Customize</a>
    <a class="btn primary" id="buyBtn" href="<?= ww_h($buy_url_base) ?>&variant=1">Buy now $499</a>
  </div>
</header>

<main class="stage" id="stage">
  <div class="loading" id="stageLoading">
    <img src="https://i.imgur.com/7OdNLrM.png" alt="Wizzy" style="width:64px;height:64px;">
    <div>Loading <span id="loadingLabel">Variant 1</span>…</div>
    <div class="dot-dot"><span></span><span></span><span></span></div>
  </div>
  <?php foreach ($tabs as $i => $t): ?>
    <?php if ($t['is_original']): ?>
      <div class="pane scroll-pane <?= $i === 0 ? 'active' : '' ?>" data-tab="<?= ww_h($t['key']) ?>" data-original="1">
        <div class="shot-wrap">
          <div class="shot-note">
            <span>📷 This is a screenshot of <?= ww_h($t['sub']) ?></span>
            <a class="btn ghost" href="<?= ww_h($t['href']) ?>" target="_blank" rel="noopener" style="padding:7px 13px;font-size:12px;">Open live site &rarr;</a>
          </div>
          <img class="shot-img loading" data-shot-src="<?= ww_h($t['src']) ?>" alt="Screenshot of <?= ww_h($t['sub']) ?>">
        </div>
      </div>
    <?php else: ?>
      <div class="pane <?= $i === 0 ? 'active' : '' ?>" data-tab="<?= ww_h($t['key']) ?>" data-original="0">
        <iframe
          <?= $i === 0 ? 'src="' . ww_h($t['src']) . '"' : '' ?>
          title="<?= ww_h($t['label']) ?>"
          data-src="<?= ww_h($t['src']) ?>"
          loading="lazy">
        </iframe>
      </div>
    <?php endif; ?>
  <?php endforeach; ?>
</main>

<div class="nudge" id="nudge">Click anywhere on the preview to chat with Wizzy</div>

<button id="wiz-bubble" title="Chat with Wizzy"><img id="wiz-img" src="/preview/wizzy-wave.gif" alt="Wizzy"></button>
<div id="wiz-chat" role="dialog" aria-label="Wizzy chat">
  <div class="wiz-head">
    <img src="https://i.imgur.com/7OdNLrM.png" alt="Wizzy">
    <span>Wizzy</span>
    <span class="x" onclick="wizClose()">&times;</span>
  </div>
  <div class="wiz-msgs" id="wizMsgs">
    <div class="wiz-msg bot">Hey <?= ww_h($first_name ?: 'there') ?>! I'm Wizzy. I helped pick the three directions you're looking at. Got questions, or want me to make changes? Just ask.</div>
  </div>
  <form class="wiz-form" onsubmit="return wizSend(event)">
    <input type="text" id="wizInput" placeholder="Ask Wizzy anything..." autocomplete="off">
    <button type="submit">Send</button>
  </form>
</div>

<script>
const TOKEN = document.body.dataset.token;
const BUY_BASE = <?= json_encode($buy_url_base) ?>;
const TABS = <?= json_encode(array_map(function($t){ return ['key'=>$t['key'],'label'=>$t['label'],'sub'=>$t['sub'],'variant'=>$t['variant'],'is_original'=>$t['is_original']]; }, $tabs)) ?>;

/* ----------------------------------------------------------------------
   CSS + JS injected into each iframe.
   - Force-show any opacity:0 reveal animations
   - Capture every click anywhere in the iframe and postMessage to parent
   This gives NATIVE scrolling + universal click-to-Wizzy.
---------------------------------------------------------------------- */
const IFRAME_OVERRIDE_CSS = `
  .fade-up,.fade-in,.fade,.reveal,.slide-up,.slide-in,[data-aos],[data-fade],[data-reveal],
  [class*="fade-up"],[class*="fade-in"],[class*="reveal"],[class*="slide-up"],[class*="slide-in"]{
    opacity:1!important;
    transform:none!important;
    visibility:visible!important;
  }
`;

function injectIframe(iframe) {
  try {
    const doc = iframe.contentDocument;
    if (!doc || !doc.head) return;
    if (doc.querySelector('style[data-ww-override]')) return;

    const style = doc.createElement('style');
    style.setAttribute('data-ww-override', '1');
    style.textContent = IFRAME_OVERRIDE_CSS;
    doc.head.appendChild(style);

    doc.querySelectorAll('.fade-up,.fade-in,.fade,.reveal,.slide-up,.slide-in').forEach(el => {
      el.classList.add('visible', 'in-view', 'animate');
    });

    // Click capture — any click inside the iframe asks parent to open Wizzy.
    const captureClick = (e) => {
      // Allow text selection / right-click context menu through
      if (e.button !== 0) return;
      e.preventDefault();
      e.stopPropagation();
      try { window.parent.postMessage({ type: 'ww-preview-click' }, '*'); } catch(_) {}
      return false;
    };
    doc.addEventListener('click', captureClick, true);
    // Also catch mousedown so we beat any native button handler
    doc.addEventListener('mousedown', (e) => {
      if (e.button !== 0) return;
      const target = e.target.closest('a, button, [role="button"], input[type="submit"]');
      if (target) e.preventDefault();
    }, true);
    // Anchors / buttons: also intercept the keyboard activation
    doc.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        const t = e.target.closest('a, button, [role="button"]');
        if (t) { e.preventDefault(); try { window.parent.postMessage({ type: 'ww-preview-click' }, '*'); } catch(_){} }
      }
    }, true);
  } catch(e) { /* cross-origin or not ready */ }
}

/* ----------------------------------------------------------------------
   Markdown -> HTML for Wizzy
---------------------------------------------------------------------- */
function wizEscape(s){return s.replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
function wizMd(text){
  let s = wizEscape(text);
  s = s.replace(/`([^`\n]+)`/g, '<code>$1</code>');
  s = s.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+|\/[^\s)]*)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
  s = s.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
  s = s.replace(/__([^_\n]+)__/g, '<strong>$1</strong>');
  s = s.replace(/(^|[^*])\*([^*\n]+)\*(?!\*)/g, '$1<em>$2</em>');
  s = s.replace(/(^|[^_])_([^_\n]+)_(?!_)/g, '$1<em>$2</em>');
  s = s.replace(/\n/g, '<br>');
  return s;
}

/* ----------------------------------------------------------------------
   Wizzy chat
---------------------------------------------------------------------- */
const wizChat = document.getElementById('wiz-chat');
const wizMsgs = document.getElementById('wizMsgs');
const wizInput = document.getElementById('wizInput');
document.getElementById('wiz-bubble').onclick = () => { wizChat.classList.add('open'); wizInput.focus(); };
// Replay the Wizzy wave GIF every 5s: it plays once (~2.8s) then rests, restarting on each tick.
(function(){var wi=document.getElementById('wiz-img');if(!wi)return;var base='/preview/wizzy-wave.gif';setInterval(function(){wi.src=base+'?t='+Date.now();},5000);})();
function wizClose() { wizChat.classList.remove('open'); }
function wizOpen() { wizChat.classList.add('open'); setTimeout(()=>wizInput.focus(),50); }

let visitorId = localStorage.getItem('ww_visitor_id');
if (!visitorId) { visitorId = 'v_' + Math.random().toString(36).slice(2, 14); localStorage.setItem('ww_visitor_id', visitorId); }

function wizAddBot(html){
  const m = document.createElement('div');
  m.className = 'wiz-msg bot';
  m.innerHTML = html;
  wizMsgs.appendChild(m);
  wizMsgs.scrollTop = wizMsgs.scrollHeight;
  return m;
}
function wizAddUser(text){
  const m = document.createElement('div');
  m.className = 'wiz-msg user';
  m.textContent = text;
  wizMsgs.appendChild(m);
  wizMsgs.scrollTop = wizMsgs.scrollHeight;
}

async function wizSend(e) {
  e.preventDefault();
  const txt = wizInput.value.trim();
  if (!txt) return false;
  wizInput.value = '';
  wizAddUser(txt);
  const thinking = wizAddBot('<span style="opacity:0.5;">Wizzy is thinking…</span>');
  try {
    const r = await fetch('/api/wizzy.php', {
      method: 'POST',
      headers: {'content-type':'application/json'},
      body: JSON.stringify({ token: TOKEN, visitor_id: visitorId, message: txt }),
    });
    const j = await r.json();
    thinking.innerHTML = wizMd(j.reply || j.error || '(no reply)');
    if (j.buy_url) {
      const a = document.createElement('a');
      a.className = 'cta';
      a.href = j.buy_url;
      a.textContent = 'Buy now →';
      thinking.appendChild(document.createElement('br'));
      thinking.appendChild(a);
    }
  } catch (err) {
    thinking.textContent = "Sorry, my brain hiccup'd. Try again?";
  }
  wizMsgs.scrollTop = wizMsgs.scrollHeight;
  return false;
}

/* ----------------------------------------------------------------------
   Tab switching
---------------------------------------------------------------------- */
const stageLoading = document.getElementById('stageLoading');
const loadingLabel = document.getElementById('loadingLabel');
const tabBtns = Array.from(document.querySelectorAll('.tab'));
const panes = Array.from(document.querySelectorAll('.pane'));
const buyBtn = document.getElementById('buyBtn');
const customizeBtn = document.getElementById('customizeBtn');

function attachIframeLoad(iframe) {
  iframe.addEventListener('load', () => {
    iframe.dataset.loaded = '1';
    injectIframe(iframe);
    setTimeout(() => injectIframe(iframe), 800);
    stageLoading.classList.add('hidden');
  });
}

function setActiveTab(key) {
  const def = TABS.find(t => t.key === key) || TABS[0];
  tabBtns.forEach(t => t.classList.toggle('active', t.dataset.tab === key));
  let activePane = null;
  panes.forEach(p => {
    const match = p.dataset.tab === key;
    if (match) { p.classList.add('active'); activePane = p; }
    else p.classList.remove('active');
  });
  if (activePane) {
    if (activePane.dataset.original === '1') {
      const img = activePane.querySelector('.shot-img');
      if (img && !img.src && img.dataset.shotSrc) {
        img.src = img.dataset.shotSrc;
        img.addEventListener('load', () => img.classList.remove('loading'), { once: true });
      }
    } else {
      const f = activePane.querySelector('iframe');
      if (f && !f.src && f.dataset.src) {
        attachIframeLoad(f);
        f.src = f.dataset.src;
      } else if (f && f.dataset.loaded === '1') {
        injectIframe(f); // Re-arm click capture
      }
    }
  }
  if (def.is_original) {
    buyBtn.style.display = 'none';
    customizeBtn.style.display = 'none';
  } else {
    buyBtn.style.display = '';
    customizeBtn.style.display = '';
    buyBtn.href = BUY_BASE + '&variant=' + def.variant;
    customizeBtn.href = BUY_BASE + '&variant=' + def.variant + '&customize=1';
  }
  loadingLabel.textContent = def.label;
  stageLoading.classList.remove('hidden');
  setTimeout(() => stageLoading.classList.add('hidden'), 3500);
}

tabBtns.forEach(t => t.addEventListener('click', () => setActiveTab(t.dataset.tab)));
const firstPane = panes[0];
if (firstPane) {
  if (firstPane.dataset.original === '1') {
    const img = firstPane.querySelector('.shot-img');
    if (img && img.dataset.shotSrc) {
      img.src = img.dataset.shotSrc;
      img.addEventListener('load', () => { img.classList.remove('loading'); stageLoading.classList.add('hidden'); }, { once: true });
    }
  } else {
    const f = firstPane.querySelector('iframe');
    if (f) attachIframeLoad(f);
  }
  setTimeout(() => stageLoading.classList.add('hidden'), 4500);
}

/* ----------------------------------------------------------------------
   Receive iframe click messages -> open Wizzy with context
---------------------------------------------------------------------- */
let nudgeCount = 0;
function fireWizzyPreviewNudge() {
  const activeKey = document.querySelector('.tab.active')?.dataset.tab;
  const active = TABS.find(t => t.key === activeKey) || TABS[0];
  wizOpen();
  let msg, showCta = true;
  if (active.is_original) {
    msg = `That's your **current site** — I'm showing it as a screenshot so you can compare it to the new directions. Switch to a variant above when you're ready, then hit **Buy now** to lock it in.`;
    showCta = false;
  } else {
    const variants = [
      `Heads up — this is a **preview**, so the buttons in the design aren't live yet. Want me to walk you through **${active.sub}**, or are you ready to buy it as-is?`,
      `That's the ${active.sub} direction. It's a static preview right now — tell me what you'd tweak, or hit **Buy now** above to lock it in.`,
      `Quick note: previews aren't interactive — they're a snapshot. Want me to **customize this one** or **buy as-is**?`,
    ];
    msg = variants[nudgeCount % variants.length];
    nudgeCount++;
  }
  const node = wizAddBot(wizMd(msg));
  if (showCta) {
    const wrap = document.createElement('div');
    wrap.style.cssText = 'margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;';
    const buy = document.createElement('a');
    buy.className = 'cta';
    buy.href = BUY_BASE + '&variant=' + active.variant;
    buy.textContent = 'Buy ' + active.sub + ' →';
    wrap.appendChild(buy);
    const cust = document.createElement('a');
    cust.className = 'cta ghost';
    cust.href = BUY_BASE + '&variant=' + active.variant + '&customize=1';
    cust.textContent = 'Customize';
    wrap.appendChild(cust);
    node.appendChild(wrap);
  }
}

window.addEventListener('message', (e) => {
  if (e && e.data && e.data.type === 'ww-preview-click') fireWizzyPreviewNudge();
});

// For the screenshot pane (original site), the iframe isn't there — listen for plain clicks on the pane.
document.querySelectorAll('.pane.scroll-pane').forEach(p => {
  p.addEventListener('click', fireWizzyPreviewNudge);
});

// Show a brief hint nudge a couple of seconds after first iframe load
const nudgeEl = document.getElementById('nudge');
setTimeout(() => {
  nudgeEl.classList.add('show');
  setTimeout(() => nudgeEl.classList.remove('show'), 3000);
}, 3500);

// Initial CTA wiring
const firstTab = TABS[0];
if (firstTab && !firstTab.is_original) {
  buyBtn.href = BUY_BASE + '&variant=' + firstTab.variant;
  customizeBtn.href = BUY_BASE + '&variant=' + firstTab.variant + '&customize=1';
}
</script>
</body>
</html>
