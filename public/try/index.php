<?php
// /try/ — dual-mode landing.
// 1. /try/ (no params)            → ad-funnel form → loading → reveal → chat edits → make-it-real.
// 2. /try/?website=...             → existing cold-email magic-link loading screen.
// 3. /try/?t=<token>               → restore reveal view (cancel recovery from Stripe).
// 4. /try/?success=1&t=<token>     → success view (post-Stripe completion).
$website = trim((string)($_GET['website'] ?? $_GET['url'] ?? ''));
$name    = trim((string)($_GET['name'] ?? ''));
$tparam  = trim((string)($_GET['t'] ?? ''));
$success = (string)($_GET['success'] ?? '') === '1';
$is_magic = ($website !== '');

if ($is_magic) {
    $host    = $website ? (parse_url(preg_match('~^https?://~i', $website) ? $website : 'https://' . $website, PHP_URL_HOST) ?: $website) : '';
    $host    = preg_replace('~^www\.~', '', (string)$host);
    ?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Loading your website&hellip; · WebWiz</title>
<style>
  :root{--navy:#161a4a;--cream:#FFF8E7;--teal:#48c7c7;--yellow:#ffd23f;}
  *{box-sizing:border-box;} html,body{height:100%;}
  body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:var(--cream);color:var(--navy);display:flex;align-items:center;justify-content:center;text-align:center;padding:24px;}
  .card{max-width:560px;width:100%;}
  .wiz{width:130px;height:130px;border-radius:50%;background:#fff;border:5px solid var(--navy);box-shadow:8px 8px 0 var(--yellow);margin:0 auto 26px;display:flex;align-items:center;justify-content:center;overflow:hidden;}
  .wiz img{width:100%;height:100%;object-fit:cover;}
  h1{font-size:30px;font-weight:900;margin:0 0 10px;line-height:1.15;}
  .sub{font-size:16px;opacity:0.8;margin:0 0 26px;}
  .bar{height:10px;background:#e8e0c8;border-radius:99px;overflow:hidden;max-width:360px;margin:0 auto 16px;}
  .bar>span{display:block;height:100%;width:0;background:linear-gradient(90deg,var(--teal),var(--navy));border-radius:99px;transition:width .6s ease;}
  #status{font-size:15px;font-weight:700;min-height:22px;}
  .err{background:#fde8e8;border:2px solid #c0392b;color:#7a1f17;padding:14px 16px;border-radius:12px;margin-top:18px;font-size:14px;}
  .err a{color:var(--navy);font-weight:700;}
  .brand{margin-top:30px;font-size:12px;letter-spacing:.16em;text-transform:uppercase;opacity:.55;font-weight:800;}
</style></head>
<body>
  <div class="card">
    <div class="wiz"><img src="/preview/wizzy-wave.gif" alt="Wizzy"></div>
    <h1 id="head">Getting <?= htmlspecialchars($host ?: 'your', ENT_QUOTES) ?><?= $host ? "&rsquo;s" : '' ?> website ready&hellip;</h1>
    <p class="sub">Hang tight<?= $name ? ', ' . htmlspecialchars(explode(' ', $name)[0], ENT_QUOTES) : '' ?> &mdash; this usually takes under a minute.</p>
    <div class="bar"><span id="prog"></span></div>
    <div id="status">Starting&hellip;</div>
    <div id="errbox"></div>
    <div class="brand">Powered by WebWiz</div>
  </div>
<script>
(function(){
  var qs = new URLSearchParams(location.search);
  if(!qs.get('website') && !qs.get('url')){ document.getElementById('status').textContent=''; document.getElementById('errbox').innerHTML='<div class="err">No website provided. The link needs a <strong>website</strong> parameter.</div>'; return; }
  var steps=['Looking up the website&hellip;','Reading the brand, colors &amp; content&hellip;','Picking your colors&hellip;','Placing images &amp; copy&hellip;','Polishing the final touches&hellip;'];
  var si=0, pct=6, statusEl=document.getElementById('status'), progEl=document.getElementById('prog');
  progEl.style.width=pct+'%';
  var tick=setInterval(function(){ si=Math.min(si+1,steps.length-1); statusEl.innerHTML=steps[si]; pct=Math.min(pct+16,88); progEl.style.width=pct+'%'; }, 6000);
  statusEl.innerHTML=steps[0];
  fetch('/api/magic.php?'+qs.toString(),{headers:{'Accept':'application/json'}})
    .then(function(r){ return r.json().then(function(j){ return {ok:r.ok,j:j}; }); })
    .then(function(res){
      clearInterval(tick);
      if(res.j && res.j.ok && res.j.url){ progEl.style.width='100%'; statusEl.innerHTML='Ready! Opening your site&hellip;'; setTimeout(function(){ location.href=res.j.url; }, 600); }
      else { statusEl.textContent=''; var m=(res.j && res.j.error) ? res.j.error : 'Something went wrong generating the site.'; document.getElementById('errbox').innerHTML='<div class="err">'+m.replace(/</g,'&lt;')+'</div>'; }
    })
    .catch(function(e){ clearInterval(tick); statusEl.textContent=''; document.getElementById('errbox').innerHTML='<div class="err">Network error: '+String(e.message).replace(/</g,'&lt;')+'</div>'; });
})();
</script>
</body></html><?php
    exit;
}

// ===== AD-FUNNEL LANDING PAGE =====

// If we got back from Stripe (cancel or success), look up the token's job for
// hydration so we land on the reveal/success view with the preview ready.
$initial_view  = 'form';
$initial_token = '';
$initial_biz   = '';
$initial_edits = 5;
$initial_preview_url = '';
if (preg_match('~^[a-f0-9]{24}$~', $tparam)) {
    try {
        require_once '/var/www/sites/trywebwiz/private/webwiz_lib.php';
        $db = ww_db();
        $st = $db->prepare("SELECT business_name, edit_count, generation_mode FROM jobs WHERE token = ? LIMIT 1");
        $st->execute([$tparam]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && ($row['generation_mode'] ?? '') === 'magic') {
            $initial_token = $tparam;
            $initial_biz   = (string)$row['business_name'];
            $initial_edits = max(0, 5 - (int)$row['edit_count']);
            $initial_preview_url = '/preview/' . $tparam . '/v1/index.html';
            $initial_view  = $success ? 'success' : 'reveal';
        }
    } catch (Throwable $e) { /* fall through to form */ }
}
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Your new website. Free. · WebWiz</title>
<meta name="description" content="Tell Wizzy about your business and he’ll design your website in under a minute. Free to design. No credit card.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="image" href="/preview/wizzy-wave.gif">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Nunito:wght@700;800;900&display=swap" rel="stylesheet">
<style>
  :root{
    --teal:#00C4A7;--navy:#12184A;--yellow:#FFBE00;--cream:#FFF8E7;
    --wizbg:#f7f8f5; /* matches the Wizzy video background so the video blends seamlessly */
    --display:'Nunito',system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
    --body:'Inter',system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
  }
  *,*::before,*::after{box-sizing:border-box;}
  html,body{margin:0;padding:0;}
  body{font-family:var(--body);color:var(--navy);background:var(--cream);
    background-image:radial-gradient(rgba(18,24,74,0.07) 1.5px, transparent 1.5px);background-size:24px 24px;
    line-height:1.5;font-size:16px;-webkit-font-smoothing:antialiased;text-rendering:optimizeLegibility;min-height:100vh;}
  a{color:var(--navy);}
  /* visible keyboard focus ring (a11y) */
  :focus-visible{outline:2px solid var(--navy)!important;outline-offset:3px;border-radius:8px;}
  button:focus-visible,input:focus-visible,textarea:focus-visible,a:focus-visible{outline:2px solid var(--navy)!important;outline-offset:3px;}
  .net-banner{display:none;position:fixed;top:0;left:0;right:0;z-index:100;background:#fde2e2;color:#5a0808;border-bottom:2px solid #8a0e0e;padding:10px 16px;text-align:center;font-family:var(--body);font-weight:600;font-size:13px;}
  .net-banner.on{display:block;}

  .view{opacity:0;visibility:hidden;height:0;overflow:hidden;transition:opacity 400ms ease;}
  body[data-view="form"]    .view-form    { opacity:1;visibility:visible;height:auto;overflow:visible; }
  body[data-view="loading"] .view-loading { opacity:1;visibility:visible;height:auto;overflow:visible; }
  body[data-view="reveal"]  .view-reveal  { opacity:1;visibility:visible;height:auto;overflow:visible; }
  body[data-view="success"] .view-success { opacity:1;visibility:visible;height:auto;overflow:visible; }

  header.topbar{padding:20px 32px;display:flex;align-items:center;justify-content:space-between;}
  header.topbar .brand{font-family:var(--display);font-weight:900;font-size:22px;letter-spacing:-0.03em;color:var(--navy);text-decoration:none;}
  header.topbar .brand .dot{color:var(--yellow);}

  /* ----------- Hero ----------- */
  main{padding:24px 32px 80px;max-width:1280px;margin:0 auto;}
  .hero{display:grid;grid-template-columns:1.5fr 1fr;gap:48px;align-items:center;margin-top:24px;}
  .hero-copy{max-width:640px;}
  .eyebrow{display:inline-block;background:var(--yellow);color:var(--navy);border:2px solid var(--navy);border-radius:999px;padding:6px 14px;font-family:var(--body);font-weight:600;font-size:12px;letter-spacing:0.14em;text-transform:uppercase;margin-bottom:18px;}
  h1{font-family:var(--display);font-weight:900;font-size:72px;letter-spacing:-0.03em;line-height:1.02;color:var(--navy);margin:0 0 18px;}
  h1 .parens{display:block;font-size:0.55em;font-weight:800;letter-spacing:-0.02em;opacity:0.85;margin-top:6px;}
  .lead{font-size:22px;line-height:1.45;color:var(--navy);opacity:0.85;margin:0 0 28px;font-weight:400;}
  .form-card{background:var(--cream);border:2px solid var(--navy);border-radius:16px;padding:32px;box-shadow:6px 6px 0 var(--navy);max-width:560px;}
  .form-card label{display:block;font-family:var(--body);font-weight:600;font-size:14px;color:var(--navy);margin-bottom:8px;letter-spacing:0.01em;}
  .form-card label .opt{font-weight:400;font-size:12px;color:rgba(18,24,74,0.6);margin-left:6px;}
  .form-card input[type=text],.form-card textarea,.form-card input[type=email]{
    width:100%;background:var(--cream);border:2px solid var(--navy);border-radius:8px;
    padding:14px 16px;font-family:var(--body);font-size:16px;color:var(--navy);font-weight:400;outline:none;transition:box-shadow 120ms ease;}
  .form-card input[type=text],.form-card input[type=email]{height:48px;}
  .form-card textarea{min-height:108px;resize:vertical;line-height:1.45;}
  .form-card input:focus,.form-card textarea:focus{box-shadow:0 0 0 3px rgba(0,196,167,0.35);}
  .form-card .field+.field{margin-top:18px;}
  .form-card .err-msg{display:none;color:#8a0e0e;font-size:13px;font-weight:500;margin-top:6px;}
  .form-card .field.invalid input,.form-card .field.invalid textarea{border-color:#8a0e0e;}
  .form-card .field.invalid .err-msg{display:block;}

  .cta{display:flex;align-items:center;justify-content:center;width:100%;height:56px;margin-top:24px;background:var(--yellow);color:var(--navy);border:2px solid var(--navy);border-radius:12px;font-family:var(--display);font-weight:900;font-size:18px;letter-spacing:-0.01em;cursor:pointer;transition:transform 120ms ease,box-shadow 120ms ease;box-shadow:0 0 0 transparent;}
  .cta:hover{transform:translate(-2px,-2px);box-shadow:4px 4px 0 var(--navy);}
  .cta:active{transform:translate(0,0);box-shadow:0 0 0 var(--navy);}
  .cta[disabled]{opacity:0.55;cursor:not-allowed;transform:none;box-shadow:none;}
  .trust{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px;}
  .chip{display:inline-flex;align-items:center;gap:6px;background:var(--cream);border:2px solid var(--navy);border-radius:999px;padding:8px 16px;font-family:var(--body);font-weight:600;font-size:14px;color:var(--navy);}
  .chip .tick{color:var(--teal);font-weight:900;}
  .hero-mascot{position:relative;display:flex;justify-content:center;align-items:center;min-height:520px;}
  .wiz-circle{width:480px;height:480px;border-radius:50%;background:var(--wizbg);border:3px solid var(--navy);display:flex;align-items:center;justify-content:center;overflow:hidden;position:relative;box-shadow:8px 8px 0 var(--yellow);}
  .wiz-circle img{width:78%;height:78%;object-fit:contain;}
  .wizzy-vid{width:100%;height:100%;object-fit:contain;background:transparent;}
  .wiz-circle .wizzy-vid,.wiz-circle.success .wizzy-vid{width:78%;height:78%;}
  .wizzy-badge .wizzy-vid{width:78%;height:78%;}
  .loading-mascot .wizzy-vid{width:100%;height:100%;}
  .edit-header-wiz .wizzy-vid{width:90%;height:90%;}
  .conv-head .wiz-mini .wizzy-vid{width:90%;height:90%;}
  .sticker{position:absolute;top:8px;right:-4px;transform:rotate(12deg);background:var(--yellow);color:var(--navy);border:2px solid var(--navy);border-radius:14px;padding:10px 14px;font-family:var(--display);font-weight:900;font-size:14px;letter-spacing:-0.01em;box-shadow:4px 4px 0 var(--navy);line-height:1.1;text-align:center;}
  .sticker small{display:block;font-family:var(--body);font-weight:600;font-size:10px;letter-spacing:0.12em;text-transform:uppercase;margin-top:2px;opacity:0.85;}

  /* ----------- Loading ----------- */
  .view-loading{padding:0 24px 60px;}
  .loading-wrap{max-width:600px;margin:0 auto;text-align:center;}
  .loading-mascot{width:280px;height:280px;margin:0 auto 8px;display:flex;align-items:center;justify-content:center;background:var(--wizbg);border:3px solid var(--navy);border-radius:50%;overflow:hidden;box-shadow:8px 8px 0 var(--yellow);}
  .loading-mascot img{width:100%;height:100%;object-fit:contain;}
  @keyframes bob{0%,100%{transform:translateY(0);}50%{transform:translateY(-8px);}}
  .loading-h2{font-family:var(--display);font-weight:900;font-size:48px;letter-spacing:-0.02em;line-height:1.05;color:var(--navy);margin:24px 0 0;}
  .loading-sub{font-size:16px;color:rgba(18,24,74,0.7);margin:8px 0 0;}
  .progress-track{margin:24px auto 0;max-width:520px;height:12px;background:var(--cream);border:2px solid var(--navy);border-radius:999px;overflow:hidden;}
  .progress-fill{display:block;height:100%;width:5%;background:var(--teal);border-radius:999px;transition:width 600ms ease;}
  .loading-status{font-family:var(--body);font-weight:500;font-size:16px;color:var(--teal);margin:16px 0 0;min-height:22px;transition:opacity 250ms ease;}
  .powered-chip{display:inline-block;margin-top:32px;background:var(--navy);color:var(--cream);font-family:var(--body);font-weight:600;font-size:12px;letter-spacing:0.14em;text-transform:uppercase;padding:6px 14px;border-radius:999px;}
  .late-fallback{display:none;max-width:520px;margin:32px auto 0;background:var(--cream);border:2px solid var(--navy);border-radius:14px;padding:20px 22px;text-align:left;box-shadow:4px 4px 0 var(--navy);}
  .late-fallback.on{display:block;}
  .late-fallback p{margin:0 0 12px;font-size:15px;color:var(--navy);}
  .late-fallback .row{display:flex;gap:10px;flex-wrap:wrap;}
  .late-fallback input{flex:1;min-width:200px;height:46px;background:var(--cream);border:2px solid var(--navy);border-radius:8px;padding:0 14px;font-family:var(--body);font-size:15px;color:var(--navy);outline:none;}
  .late-fallback button{height:46px;padding:0 18px;background:var(--yellow);color:var(--navy);border:2px solid var(--navy);border-radius:8px;font-family:var(--display);font-weight:900;font-size:14px;cursor:pointer;}
  .loading-err{display:none;max-width:520px;margin:24px auto 0;background:#fde2e2;border:2px solid #8a0e0e;color:#5a0808;border-radius:12px;padding:14px 16px;font-size:14px;text-align:left;}
  .loading-err.on{display:block;}
  .loading-err .back-btn{display:inline-block;margin-top:10px;background:var(--navy);color:var(--cream);border:none;border-radius:8px;padding:8px 14px;font-family:var(--display);font-weight:900;font-size:13px;cursor:pointer;}

  /* ----------- Reveal + Edit Chat ----------- */
  .view-reveal{padding:16px;}
  .reveal-layout{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:24px;max-width:1600px;margin:0 auto;align-items:start;}
  .reveal-frame-wrap{position:relative;}
  .reveal-frame{width:100%;height:80vh;border:2px solid var(--navy);border-radius:16px;background:var(--cream);box-shadow:8px 8px 0 var(--yellow);overflow:hidden;}
  .reveal-frame iframe{width:100%;height:100%;border:0;display:block;background:var(--cream);}
  .wizzy-badge-wrap{position:absolute;top:-20px;left:-20px;display:flex;align-items:flex-start;gap:12px;z-index:5;}
  .wizzy-badge{width:80px;height:80px;border-radius:50%;background:var(--wizbg);border:3px solid var(--navy);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;box-shadow:4px 4px 0 var(--yellow);}
  .wizzy-badge img{width:78%;height:78%;object-fit:contain;}
  .speech-bubble{margin-top:16px;background:var(--cream);border:2px solid var(--navy);border-radius:12px;padding:10px 14px;font-family:var(--body);font-weight:600;font-size:14px;color:var(--navy);box-shadow:3px 3px 0 var(--navy);max-width:280px;position:relative;}
  .speech-bubble::before{content:'';position:absolute;left:-12px;top:14px;width:0;height:0;border-top:8px solid transparent;border-bottom:8px solid transparent;border-right:12px solid var(--navy);}
  .speech-bubble::after{content:'';position:absolute;left:-9px;top:16px;width:0;height:0;border-top:6px solid transparent;border-bottom:6px solid transparent;border-right:10px solid var(--cream);}

  .edit-panel{background:var(--cream);border:2px solid var(--navy);border-radius:16px;box-shadow:6px 6px 0 var(--navy);display:flex;flex-direction:column;position:sticky;top:16px;max-height:calc(100vh - 32px);min-height:520px;overflow:hidden;}
  .edit-header{padding:16px 18px;border-bottom:2px solid var(--navy);display:flex;align-items:center;gap:10px;background:var(--cream);}
  .edit-header-wiz{width:40px;height:40px;border-radius:50%;background:var(--wizbg);border:2px solid var(--navy);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;}
  .edit-header-wiz img{width:90%;height:90%;object-fit:contain;}
  .edit-header h3{flex:1;font-family:var(--display);font-weight:900;font-size:18px;color:var(--navy);margin:0;letter-spacing:-0.01em;}
  .edits-chip{background:var(--yellow);color:var(--navy);border:2px solid var(--navy);border-radius:999px;padding:6px 12px;font-family:var(--body);font-weight:700;font-size:12px;letter-spacing:0.04em;white-space:nowrap;}
  .edits-chip.zero{background:rgba(255,190,0,0.25);}

  .chat-history{flex:1;overflow-y:auto;padding:16px 18px;display:flex;flex-direction:column;gap:10px;}
  .chat-history:empty::before{content:'Tip: click a chip below or type your own tweak.';display:block;color:rgba(18,24,74,0.5);font-size:13px;font-style:italic;}
  .msg{font-family:var(--body);font-weight:400;font-size:14px;line-height:1.45;padding:10px 12px;border-radius:12px;max-width:75%;word-wrap:break-word;}
  .msg-user{background:var(--navy);color:var(--cream);align-self:flex-end;}
  .msg-wiz{background:var(--cream);border:2px solid var(--navy);color:var(--navy);align-self:flex-start;display:flex;gap:8px;align-items:flex-start;}
  .msg-wiz img.tinywiz{width:22px;height:22px;border-radius:50%;flex-shrink:0;}
  .msg-wiz.typing{font-style:italic;opacity:0.75;}

  .suggested-row{padding:12px 18px;border-top:1px solid rgba(18,24,74,0.15);display:flex;flex-wrap:wrap;gap:6px;}
  .suggested-row .sugchip{background:var(--cream);border:2px solid var(--navy);border-radius:999px;padding:6px 12px;font-family:var(--body);font-weight:600;font-size:13px;color:var(--navy);cursor:pointer;transition:background 120ms ease,color 120ms ease;line-height:1.2;}
  .suggested-row .sugchip:hover{background:var(--navy);color:var(--cream);}
  .suggested-row .sugchip.upload{background:var(--yellow);border-color:var(--navy);}
  .suggested-row .sugchip.upload.pulse{animation:pulseChip 1s ease 1;}
  @keyframes pulseChip{0%{transform:scale(0.85);}50%{transform:scale(1.08);}100%{transform:scale(1);}}

  .chat-input-row{padding:12px 18px 16px;border-top:2px solid var(--navy);background:var(--cream);}
  .chat-input-row textarea{width:100%;background:var(--cream);border:2px solid var(--navy);border-radius:8px;padding:10px 12px;font-family:var(--body);font-size:16px;color:var(--navy);min-height:60px;resize:vertical;outline:none;line-height:1.4;}
  .chat-input-row textarea:focus{box-shadow:0 0 0 3px rgba(0,196,167,0.35);}
  .chat-input-row .row{display:flex;gap:8px;margin-top:8px;justify-content:space-between;align-items:center;}
  .chat-input-row .iloveit{background:transparent;color:var(--navy);border:2px solid var(--navy);border-radius:8px;padding:8px 12px;font-family:var(--display);font-weight:900;font-size:13px;cursor:pointer;}
  .chat-input-row .iloveit:hover{background:var(--navy);color:var(--cream);}
  .chat-input-row .send-btn{height:40px;padding:0 18px;background:var(--yellow);color:var(--navy);border:2px solid var(--navy);border-radius:8px;font-family:var(--display);font-weight:900;font-size:14px;cursor:pointer;}
  .chat-input-row .send-btn[disabled]{opacity:0.55;cursor:not-allowed;}
  body[data-cap="hit"] .chat-input-row textarea{opacity:0.5;cursor:not-allowed;background:rgba(248,239,211,0.5);}
  body[data-cap="hit"] .suggested-row .sugchip{display:none;}

  /* When the conversion card takes over, hide the chat parts entirely. */
  body[data-conv="on"] .chat-history,
  body[data-conv="on"] .suggested-row,
  body[data-conv="on"] .chat-input-row{display:none;}
  body[data-conv="on"] .edit-panel{max-height:none;}

  /* ----------- Conversion (Phase 4) ----------- */
  .conv-card{display:none;padding:24px 22px;}
  body[data-conv="on"] .conv-card{display:block;}
  .conv-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:12px;}
  .conv-head h2{font-family:var(--display);font-weight:900;font-size:32px;color:var(--navy);margin:0;letter-spacing:-0.02em;line-height:1.05;}
  .conv-head .wiz-mini{width:54px;height:54px;border-radius:50%;background:var(--wizbg);border:2px solid var(--navy);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;}
  .conv-head .wiz-mini img{width:90%;height:90%;object-fit:contain;}
  .conv-lead{font-size:15px;color:var(--navy);margin:0 0 8px;}
  .conv-checklist{list-style:none;padding:0;margin:8px 0 0;}
  .conv-checklist li{display:flex;align-items:flex-start;gap:10px;padding:6px 0;font-size:14px;color:var(--navy);line-height:1.45;}
  .conv-checklist li .ck{color:var(--teal);font-weight:900;font-size:18px;line-height:1;flex-shrink:0;margin-top:2px;}
  .conv-price{margin-top:24px;background:var(--yellow);border:2px solid var(--navy);border-radius:16px;padding:20px;box-shadow:4px 4px 0 var(--navy);}
  .conv-price .big{font-family:var(--display);font-weight:900;font-size:48px;color:var(--navy);letter-spacing:-0.02em;line-height:1;}
  .conv-price .sub{font-size:16px;color:rgba(18,24,74,0.85);margin-top:4px;}
  .conv-price .note{font-size:12px;color:rgba(18,24,74,0.65);margin-top:6px;}
  .conv-cta{display:flex;align-items:center;justify-content:center;width:100%;height:56px;margin-top:24px;background:var(--yellow);color:var(--navy);border:2px solid var(--navy);border-radius:12px;font-family:var(--display);font-weight:900;font-size:18px;cursor:pointer;transition:transform 120ms ease,box-shadow 120ms ease;}
  .conv-cta:hover{transform:translate(-2px,-2px);box-shadow:4px 4px 0 var(--navy);}
  .conv-cta[disabled]{opacity:0.6;cursor:not-allowed;transform:none;}
  .conv-foot{text-align:center;font-size:12px;color:rgba(18,24,74,0.6);margin-top:12px;}
  .conv-err{display:none;background:#fde2e2;color:#5a0808;border:2px solid #8a0e0e;border-radius:8px;padding:10px 12px;font-size:13px;margin-top:12px;}
  .conv-err.on{display:block;}
  .conv-back{display:inline-block;margin-top:10px;background:transparent;color:var(--navy);text-decoration:underline;border:none;font-family:var(--body);font-weight:500;cursor:pointer;font-size:13px;padding:0;}

  /* ----------- Asset upload modal ----------- */
  .modal-backdrop{display:none;position:fixed;inset:0;background:rgba(18,24,74,0.5);z-index:50;align-items:center;justify-content:center;padding:16px;}
  .modal-backdrop.on{display:flex;}
  .upload-modal{background:var(--cream);border:2px solid var(--navy);border-radius:16px;box-shadow:8px 8px 0 var(--yellow);max-width:480px;width:100%;padding:24px;max-height:90vh;overflow:auto;}
  .upload-modal h3{font-family:var(--display);font-weight:900;font-size:24px;color:var(--navy);margin:0 0 6px;letter-spacing:-0.01em;}
  .upload-modal .um-sub{font-family:var(--body);font-size:14px;color:rgba(18,24,74,0.7);margin:0 0 18px;}
  .dropzone{border:2px dashed var(--navy);border-radius:12px;padding:18px 14px;text-align:center;background:rgba(255,248,231,0.5);color:rgba(18,24,74,0.7);font-family:var(--body);font-weight:500;font-size:14px;cursor:pointer;transition:background 120ms ease;margin-top:14px;}
  .dropzone.logo{min-height:120px;display:flex;align-items:center;justify-content:center;}
  .dropzone.photos{min-height:200px;display:flex;align-items:center;justify-content:center;flex-direction:column;}
  .dropzone:hover,.dropzone.drag{background:rgba(255,190,0,0.18);}
  .dropzone .picked{font-weight:600;color:var(--navy);}
  .dropzone input[type=file]{display:none;}
  .upload-modal .um-actions{display:flex;justify-content:space-between;align-items:center;margin-top:20px;gap:10px;}
  .upload-modal .never{background:none;border:none;color:var(--navy);text-decoration:underline;font-family:var(--body);font-weight:500;cursor:pointer;font-size:14px;padding:8px 0;}
  .upload-modal .send-up{background:var(--yellow);color:var(--navy);border:2px solid var(--navy);border-radius:10px;height:46px;padding:0 18px;font-family:var(--display);font-weight:900;font-size:15px;cursor:pointer;}
  .upload-modal .send-up[disabled]{opacity:0.55;cursor:not-allowed;}
  .upload-err{display:none;background:#fde2e2;color:#5a0808;border:2px solid #8a0e0e;border-radius:8px;padding:8px 12px;font-size:13px;margin-top:12px;}
  .upload-err.on{display:block;}

  /* ----------- Success ----------- */
  .view-success{padding:60px 24px 80px;}
  .success-wrap{max-width:640px;margin:0 auto;text-align:center;}
  .success-wrap .wiz-circle.success{margin:0 auto 28px;width:200px;height:200px;position:relative;}
  .success-wrap .wiz-circle.success::after{content:'👍';position:absolute;right:-18px;bottom:-6px;font-size:60px;transform:rotate(-12deg);}
  .success-wrap h1.h-set{font-family:var(--display);font-weight:900;font-size:72px;letter-spacing:-0.03em;line-height:1.02;color:var(--navy);margin:0 0 14px;}
  .success-wrap .s-lead{font-size:20px;color:var(--navy);opacity:0.9;margin:0 0 28px;line-height:1.5;}
  .success-wrap .s-row{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;}
  .success-wrap .s-row a{display:inline-flex;align-items:center;justify-content:center;height:48px;padding:0 22px;background:var(--yellow);color:var(--navy);border:2px solid var(--navy);border-radius:10px;font-family:var(--display);font-weight:900;font-size:15px;text-decoration:none;}
  .success-wrap .s-row a.ghost{background:transparent;}

  /* ----------- Footer ----------- */
  footer{border-top:1px solid var(--navy);padding:24px 32px;font-size:12px;color:rgba(18,24,74,0.7);}
  footer .row{max-width:1280px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}

  /* ---- Breakpoints ---- */
  @media (max-width:1100px){
    h1{font-size:60px;} .wiz-circle{width:380px;height:380px;} .hero{gap:32px;}
    .reveal-layout{grid-template-columns:minmax(0,1fr) 320px;gap:18px;}
  }
  @media (max-width:900px){
    .reveal-layout{grid-template-columns:1fr;}
    .edit-panel{position:relative;top:auto;max-height:none;min-height:0;}
    .reveal-frame{height:60vh;}
  }
  @media (max-width:768px){
    main{padding:16px 20px 60px;} header.topbar{padding:16px 20px;}
    .hero{grid-template-columns:1fr;gap:24px;}
    .hero-mascot{order:-1;min-height:0;margin-bottom:8px;}
    .wiz-circle{width:240px;height:240px;}
    .sticker{font-size:12px;padding:8px 12px;top:0;right:0;}
    h1{font-size:42px;} .lead{font-size:18px;}
    .form-card{padding:24px;box-shadow:4px 4px 0 var(--navy);}
    .cta{font-size:16px;height:52px;}
    footer{padding:18px 20px;} footer .row{flex-direction:column;text-align:center;}
    .loading-h2{font-size:32px;} .loading-mascot{width:200px;height:200px;}
    .reveal-frame{height:55vh;} .wizzy-badge{width:64px;height:64px;}
    .wizzy-badge-wrap{top:-14px;left:-10px;}
    .speech-bubble{font-size:13px;padding:8px 12px;max-width:200px;}
    .view-reveal{padding:12px;}
    .edit-panel{box-shadow:4px 4px 0 var(--navy);}
    .conv-head h2{font-size:26px;}
    .conv-price .big{font-size:40px;}
    .success-wrap h1.h-set{font-size:48px;}
    .success-wrap .s-lead{font-size:17px;}
    .success-wrap .wiz-circle.success{width:160px;height:160px;}
  }
  @media (max-width:375px){
    h1{font-size:36px;} .form-card{padding:20px;} .chip{font-size:13px;padding:7px 12px;}
    .loading-h2{font-size:28px;}
  }
</style>
</head>
<body data-view="<?= htmlspecialchars($initial_view, ENT_QUOTES) ?>" data-cap="<?= $initial_edits === 0 ? 'hit' : 'ok' ?>">

<div class="net-banner" id="netBanner" role="alert">You appear to be offline. Reconnect and we’ll keep going.</div>

<header class="topbar">
  <a href="/try" class="brand">WebWiz<span class="dot">.</span></a>
</header>

<!-- ===================== FORM VIEW ===================== -->
<main class="view view-form">
  <section class="hero">
    <div class="hero-copy">
      <span class="eyebrow">&#9733; The site&rsquo;s free</span>
      <h1>Your new website. Free.<span class="parens">(Going live is the only thing we charge for.)</span></h1>
      <p class="lead">Tell Wizzy about your business and he&rsquo;ll design it in under a minute.</p>

      <form class="form-card" id="tryForm" novalidate>
        <div class="field" data-field="website">
          <label for="website">What&rsquo;s your website?</label>
          <input type="text" id="website" name="website" inputmode="url" autocomplete="url" placeholder="yourbusiness.com" required>
          <div class="err-msg">Drop your website here so Wizzy has something real to start from.</div>
        </div>
        <div class="field" data-field="description">
          <label for="description">Tell Wizzy about your business</label>
          <textarea id="description" name="description" rows="4" placeholder="We&rsquo;re a family bakery in Pawtucket. Custom cakes, weekend pastries, been here 15 years."></textarea>
          <div class="err-msg">Give Wizzy a few sentences (at least 20 characters) so he can pick the right look.</div>
        </div>
        <button type="submit" class="cta" id="ctaBtn">Make my website &rarr;</button>
      </form>

      <div class="trust">
        <span class="chip"><span class="tick">&#10003;</span> Free to design</span>
        <span class="chip"><span class="tick">&#10003;</span> Live in 60 seconds</span>
        <span class="chip"><span class="tick">&#10003;</span> No credit card</span>
      </div>
    </div>

    <div class="hero-mascot">
      <div class="wiz-circle"><video class="wizzy-vid" autoplay muted playsinline preload="metadata" poster="/preview/wizzy-waving-poster.jpg" aria-label="Wizzy waving"><source src="/preview/wizzy-waving.webm" type="video/webm"><source src="/preview/wizzy-waving.mp4" type="video/mp4"><img src="/preview/wizzy-wave.gif" alt="Wizzy waving"></video></div>
      <div class="sticker">Made with care<small>by Wizzy</small></div>
    </div>
  </section>
</main>

<!-- ===================== LOADING VIEW ===================== -->
<main class="view view-loading">
  <div class="loading-wrap">
    <div class="loading-mascot"><video class="wizzy-vid wizzy-proc" autoplay muted playsinline preload="metadata" poster="/preview/wizzy-processing-poster.jpg" aria-label="Wizzy processing"><source src="/preview/wizzy-processing.webm" type="video/webm"><source src="/preview/wizzy-processing.mp4" type="video/mp4"><img src="/preview/wizzy-wave.gif" alt="Wizzy processing"></video></div>
    <h2 class="loading-h2" id="loadingHead">Wizzy is designing your site&hellip;</h2>
    <p class="loading-sub">Hang tight. He&rsquo;s working fast.</p>
    <div class="progress-track"><span class="progress-fill" id="progFill"></span></div>
    <p class="loading-status" id="loadingStatus">Picking your colors&hellip;</p>
    <p class="loading-elapsed" id="loadingElapsed" aria-live="polite" style="font-family:var(--body);font-weight:500;font-size:13px;color:rgba(18,24,74,0.55);margin:6px 0 0;letter-spacing:0.04em;">0s elapsed</p>
    <div class="powered-chip">Powered by WebWiz</div>
    <div class="late-fallback" id="lateFallback">
      <p>Wizzy&rsquo;s taking his time on this one. Want him to email you when it&rsquo;s ready?</p>
      <form class="row" id="notifyForm">
        <input type="email" id="notifyEmail" placeholder="you@yourbusiness.com" required>
        <button type="submit">Notify me</button>
      </form>
      <p class="loading-sub" id="notifySaid" style="display:none;margin-top:10px;color:var(--teal);font-weight:600;">Got it. We&rsquo;ll email you the moment it&rsquo;s ready.</p>
    </div>
    <div class="loading-err" id="loadingErr">
      <div id="loadingErrMsg"></div>
      <button type="button" class="back-btn" id="backToForm">&larr; Try again</button>
    </div>
  </div>
</main>

<!-- ===================== REVEAL + EDIT CHAT ===================== -->
<main class="view view-reveal">
  <div class="reveal-layout">
    <div class="reveal-frame-wrap">
      <div class="reveal-frame">
        <iframe id="previewFrame" src="<?= htmlspecialchars($initial_preview_url ?: 'about:blank', ENT_QUOTES) ?>" loading="eager" title="Your new website preview"></iframe>
      </div>
      <div class="wizzy-badge-wrap">
        <div class="wizzy-badge"><video class="wizzy-vid" autoplay muted playsinline preload="metadata" poster="/preview/wizzy-waving-poster.jpg" aria-label="Wizzy waving"><source src="/preview/wizzy-waving.webm" type="video/webm"><source src="/preview/wizzy-waving.mp4" type="video/mp4"><img src="/preview/wizzy-wave.gif" alt="Wizzy waving"></video></div>
        <div class="speech-bubble">Here&rsquo;s what I made you. What do you think?</div>
      </div>
    </div>

    <aside class="edit-panel">
      <div class="edit-header">
        <div class="edit-header-wiz"><video class="wizzy-vid" autoplay muted playsinline preload="metadata" poster="/preview/wizzy-waving-poster.jpg" aria-label="Wizzy waving"><source src="/preview/wizzy-waving.webm" type="video/webm"><source src="/preview/wizzy-waving.mp4" type="video/mp4"><img src="/preview/wizzy-wave.gif" alt="Wizzy waving"></video></div>
        <h3>Chat with Wizzy</h3>
        <span class="edits-chip <?= $initial_edits === 0 ? 'zero' : '' ?>" id="editsChip"><?= (int)$initial_edits ?> edit<?= $initial_edits === 1 ? '' : 's' ?> remaining</span>
      </div>

      <div class="chat-history" id="chatHistory" aria-live="polite"></div>

      <div class="suggested-row" id="suggestedRow">
        <span class="sugchip" data-fill="Change the colors">Change the colors</span>
        <span class="sugchip" data-fill="Make it feel more modern">Make it feel more modern</span>
        <span class="sugchip" data-fill="Add a section about our services">Add a section about our services</span>
        <span class="sugchip" data-fill="Update the contact info">Update the contact info</span>
      </div>

      <div class="chat-input-row">
        <textarea id="chatInput" placeholder="Tell Wizzy what to tweak..."<?= $initial_edits === 0 ? ' readonly' : '' ?>></textarea>
        <div class="row">
          <button type="button" class="iloveit" id="iLoveIt">I love it &rarr;</button>
          <button type="button" class="send-btn" id="chatSend"<?= $initial_edits === 0 ? ' disabled' : '' ?>>Send &rarr;</button>
        </div>
      </div>

      <!-- ============ Phase 4 conversion card (lives in same panel) ============ -->
      <div class="conv-card" id="convCard">
        <div class="conv-head">
          <h2>Want to make it real?</h2>
          <div class="wiz-mini"><video class="wizzy-vid" autoplay muted playsinline preload="metadata" poster="/preview/wizzy-waving-poster.jpg" aria-label="Wizzy waving"><source src="/preview/wizzy-waving.webm" type="video/webm"><source src="/preview/wizzy-waving.mp4" type="video/mp4"><img src="/preview/wizzy-wave.gif" alt="Wizzy waving"></video></div>
        </div>
        <p class="conv-lead">Wizzy&rsquo;s design is yours, free. To go live we&rsquo;ll:</p>
        <ul class="conv-checklist">
          <li><span class="ck">&#10003;</span> Polish the design by hand (real human designer review)</li>
          <li><span class="ck">&#10003;</span> Set up your domain and point it to your new site</li>
          <li><span class="ck">&#10003;</span> Host it on our servers and keep it running</li>
          <li><span class="ck">&#10003;</span> Set up your business email</li>
          <li><span class="ck">&#10003;</span> Be on call for small tweaks the first 30 days</li>
        </ul>
        <div class="conv-price">
          <div class="big">$500 once</div>
          <div class="sub">+ $50/month to host</div>
          <div class="note">Cancel hosting anytime. The site stays yours either way.</div>
        </div>
        <button type="button" class="conv-cta" id="convCta">Make it real &rarr;</button>
        <p class="conv-foot">We handle everything. You don&rsquo;t touch a thing.</p>
        <div class="conv-err" id="convErr"></div>
        <button type="button" class="conv-back" id="convBack">&larr; Not yet, more tweaks</button>
      </div>
    </aside>
  </div>
</main>

<!-- ===================== SUCCESS VIEW ===================== -->
<main class="view view-success">
  <div class="success-wrap">
    <div class="wiz-circle success" style="border:3px solid var(--navy);box-shadow:8px 8px 0 var(--yellow);">
      <video class="wizzy-vid" autoplay muted playsinline preload="metadata" poster="/preview/wizzy-waving-poster.jpg" aria-label="Wizzy waving"><source src="/preview/wizzy-waving.webm" type="video/webm"><source src="/preview/wizzy-waving.mp4" type="video/mp4"><img src="/preview/wizzy-wave.gif" alt="Wizzy waving"></video>
    </div>
    <h1 class="h-set">You&rsquo;re set.</h1>
    <p class="s-lead">Your site is on its way to going live. We&rsquo;ll be in touch within 24 hours.</p>
    <div class="s-row">
      <?php if ($initial_preview_url): ?>
        <a href="<?= htmlspecialchars($initial_preview_url, ENT_QUOTES) ?>" target="_blank" rel="noopener">View your preview</a>
      <?php endif; ?>
      <a class="ghost" href="mailto:hello@trywebwiz.com">Email us</a>
    </div>
  </div>
</main>

<!-- ===================== ASSET UPLOAD MODAL ===================== -->
<div class="modal-backdrop" id="uploadModalBackdrop" role="dialog" aria-modal="true" aria-labelledby="umTitle">
  <div class="upload-modal">
    <h3 id="umTitle">Got assets? Send &rsquo;em over.</h3>
    <p class="um-sub">Logo and up to 3 photos. We&rsquo;ll work them in.</p>
    <div class="dropzone logo" id="dzLogo" data-kind="logo">
      <div class="dz-inner"><strong>Drop your logo</strong><br><small>PNG or SVG, up to 2 MB</small></div>
      <input type="file" id="logoInput" accept=".png,.svg,image/png,image/svg+xml,image/jpeg">
    </div>
    <div class="dropzone photos" id="dzPhotos" data-kind="photos">
      <div class="dz-inner"><strong>Drop photos</strong><br><small>JPG or PNG, up to 5 MB each, max 3</small></div>
      <input type="file" id="photosInput" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" multiple>
    </div>
    <div class="upload-err" id="uploadErr"></div>
    <div class="um-actions">
      <button type="button" class="never" id="uploadCancel">Never mind</button>
      <button type="button" class="send-up" id="uploadSend">Send to Wizzy &rarr;</button>
    </div>
  </div>
</div>

<footer>
  <div class="row">
    <span>Built by humans (and one spider) who care about your site.</span>
    <span>&copy; 2026 WebWiz &middot; <a href="mailto:hello@trywebwiz.com">hello@trywebwiz.com</a></span>
  </div>
</footer>

<script>
window.__TRY_INIT__ = {
  token: <?= json_encode($initial_token) ?>,
  businessName: <?= json_encode($initial_biz) ?>,
  editsRemaining: <?= (int)$initial_edits ?>,
  view: <?= json_encode($initial_view) ?>,
  previewUrl: <?= json_encode($initial_preview_url) ?>,
  // anon session id (per browser tab, lasts until reload). Used to stitch funnel events together.
  sessionId: (function(){ try { var k='ww_try_sid'; var s=sessionStorage.getItem(k); if(s) return s; s='s_'+Math.random().toString(36).slice(2,12)+Date.now().toString(36); sessionStorage.setItem(k,s); return s; } catch(e){ return null; } })()
};
</script>
<script>
(function(){
  var INIT = window.__TRY_INIT__ || {};
  var body = document.body;
  var EDIT_CAP = 5;

  // ---------- Analytics (POST to /api/event.php, fire-and-forget) ----------
  function track(event, payload){
    try {
      var body = JSON.stringify({ event: event, token: (state && state.token) || INIT.token || null, session_id: INIT.sessionId || null, payload: payload || null });
      if (navigator.sendBeacon) {
        var blob = new Blob([body], { type: 'application/json' });
        navigator.sendBeacon('/api/event.php', blob);
      } else {
        fetch('/api/event.php', { method: 'POST', body: body, headers: {'Content-Type':'application/json'}, keepalive: true }).catch(function(){});
      }
    } catch(e){ /* never break UI */ }
  }
  // Network online/offline indicator
  var netBanner = document.getElementById('netBanner');
  function setOnline(on){ if (netBanner) netBanner.classList.toggle('on', !on); }
  window.addEventListener('online',  function(){ setOnline(true);  });
  window.addEventListener('offline', function(){ setOnline(false); });

  var form = document.getElementById('tryForm');
  var descField = form.querySelector('[data-field="description"]');
  var websiteField = form.querySelector('[data-field="website"]');
  var desc = document.getElementById('description');
  var web = document.getElementById('website');
  var ctaBtn = document.getElementById('ctaBtn');

  var loadingHead = document.getElementById('loadingHead');
  var loadingStatus = document.getElementById('loadingStatus');
  var progFill = document.getElementById('progFill');
  var lateFallback = document.getElementById('lateFallback');
  var notifyForm = document.getElementById('notifyForm');
  var notifyEmail = document.getElementById('notifyEmail');
  var notifySaid = document.getElementById('notifySaid');
  var loadingErr = document.getElementById('loadingErr');
  var loadingErrMsg = document.getElementById('loadingErrMsg');
  var backToForm = document.getElementById('backToForm');

  var previewFrame = document.getElementById('previewFrame');
  var editsChip = document.getElementById('editsChip');
  var chatHistory = document.getElementById('chatHistory');
  var suggestedRow = document.getElementById('suggestedRow');
  var chatInput = document.getElementById('chatInput');
  var chatSend = document.getElementById('chatSend');
  var iLoveIt = document.getElementById('iLoveIt');

  var convCard = document.getElementById('convCard');
  var convCta = document.getElementById('convCta');
  var convBack = document.getElementById('convBack');
  var convErr = document.getElementById('convErr');

  var umBackdrop = document.getElementById('uploadModalBackdrop');
  var dzLogo = document.getElementById('dzLogo');
  var dzPhotos = document.getElementById('dzPhotos');
  var logoInput = document.getElementById('logoInput');
  var photosInput = document.getElementById('photosInput');
  var uploadErr = document.getElementById('uploadErr');
  var uploadSend = document.getElementById('uploadSend');
  var uploadCancel = document.getElementById('uploadCancel');

  var state = {
    token: INIT.token || null,
    businessName: INIT.businessName || null,
    editsRemaining: typeof INIT.editsRemaining === 'number' ? INIT.editsRemaining : EDIT_CAP,
    sending: false
  };

  var statusMessages = ['Picking your colors…','Choosing fonts that fit you…','Writing your hero copy…','Laying out your homepage…','Wizzy is working fast…','Adding the finishing touches…'];
  var MIN_DESC = 20;
  var WEBSITE_RX = /^([\w-]+\.)+[a-z]{2,}([\/?#].*)?$/i;

  function validateWebsite(){
    var v = (web.value || '').trim(); if (v === '') return false;
    var stripped = v.replace(/^https?:\/\//i,'').replace(/^www\./i,'');
    return WEBSITE_RX.test(stripped);
  }
  function validateDesc(){ return ((desc.value || '').trim().length >= MIN_DESC); }
  desc.addEventListener('input', function(){ if (descField.classList.contains('invalid') && validateDesc()) descField.classList.remove('invalid'); });
  web.addEventListener('input', function(){ if (websiteField.classList.contains('invalid') && (web.value.trim() === '' || validateWebsite())) websiteField.classList.remove('invalid'); });

  function setView(v){ body.setAttribute('data-view', v); window.scrollTo({top:0, behavior:'smooth'}); }

  // ---------- loading ----------
  var statusIdx = 0, statusTimer = null, progressTimer = null, lateTimer = null, generating = false;
  var elapsedTimer = null, elapsedStart = 0;
  function startLoadingTickers(){
    // Tuned for a ~120s Sonnet generation window: progress drifts up smoothly
    // to ~92% over the 2 minutes (then jumps to 100% on success). Status
    // copy rotates every 8s so users see all 6 messages over the full wait.
    var pct = 4; progFill.style.width = pct + '%'; loadingStatus.textContent = statusMessages[0]; statusIdx = 0;
    // Visible elapsed timer (Omar's request to see how long the gen takes)
    elapsedStart = Date.now();
    var elapsedEl = document.getElementById('loadingElapsed');
    if (elapsedEl) elapsedEl.textContent = '0s elapsed';
    elapsedTimer = setInterval(function(){
      if (!elapsedEl) return;
      var s = Math.floor((Date.now() - elapsedStart) / 1000);
      var label = s < 60 ? (s + 's elapsed') : (Math.floor(s/60) + 'm ' + (s%60) + 's elapsed');
      elapsedEl.textContent = label;
    }, 1000);
    statusTimer = setInterval(function(){
      statusIdx = Math.min(statusIdx + 1, statusMessages.length - 1);
      loadingStatus.style.opacity = '0';
      setTimeout(function(){ loadingStatus.textContent = statusMessages[statusIdx]; loadingStatus.style.opacity = '1'; }, 220);
    }, 8000);
    // ~4% every 5s → ~92% in 110s (drift slows as we approach the cap so it
    // doesn't visibly stall mid-wait)
    progressTimer = setInterval(function(){
      var bump = pct < 70 ? 4 : (pct < 85 ? 2 : 1);
      pct = Math.min(pct + bump, 92);
      progFill.style.width = pct + '%';
    }, 5000);
    // Late-fallback ('want him to email you when it's ready?') stays at 90s
    // — that's when most generations should have landed; if not, give the
    // user the bail-out option.
    lateTimer = setTimeout(function(){ lateFallback.classList.add('on'); }, 45000);
  }
  function stopLoadingTickers(){
    if (statusTimer)   { clearInterval(statusTimer);   statusTimer = null; }
    if (progressTimer) { clearInterval(progressTimer); progressTimer = null; }
    if (lateTimer)     { clearTimeout(lateTimer);      lateTimer = null; }
    if (elapsedTimer)  { clearInterval(elapsedTimer);  elapsedTimer = null; }
  }
  function showLoadingError(msg){
    stopLoadingTickers();
    var clean = String(msg || 'Something went wrong on our end. Try again?');
    // Translate raw scrape errors into something a customer can act on.
    if (/Scrape failed \(0\)/i.test(clean) || /Scrape failed \(\)/i.test(clean)) {
      clean = "Wizzy couldn't reach that website. Double-check the URL, or try again in a minute if the site is just slow to respond.";
    } else if (/Scrape failed \(40\d\)/i.test(clean)) {
      clean = "That URL came back as not found. Make sure you typed it correctly.";
    } else if (/Scrape failed \(5\d\d\)/i.test(clean)) {
      clean = "The site is having server trouble right now. Give it a minute and try again.";
    }
    loadingErrMsg.textContent = clean;
    loadingErr.classList.add('on'); generating = false;
  }
  backToForm.addEventListener('click', function(){
    loadingErr.classList.remove('on'); lateFallback.classList.remove('on');
    progFill.style.width = '5%'; setView('form');
  });

  form.addEventListener('submit', function(e){
    e.preventDefault();
    if (generating) return;
    var descOk = validateDesc(); var webOk  = validateWebsite();
    descField.classList.toggle('invalid', !descOk); websiteField.classList.toggle('invalid', !webOk);
    if (!descOk) { desc.focus(); return; }
    if (!webOk)  { web.focus();  return; }

    track('form_submit', { has_website: !!web.value.trim(), description_length: desc.value.trim().length });
    track('gen_started');
    var __genT0 = Date.now();
    generating = true; ctaBtn.disabled = true;
    var hostGuess = '';
    try { var raw = web.value.trim().replace(/^https?:\/\//i,'').replace(/^www\./i,''); hostGuess = raw.split('/')[0].split('?')[0]; } catch(e){}
    loadingHead.innerHTML = 'Wizzy is designing ' + (hostGuess ? escapeHtml(hostGuess) : 'your site') + '…';
    setView('loading'); startLoadingTickers();

    fetch('/api/magic.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ website: web.value.trim(), description: desc.value.trim() })
    })
    .then(function(r){ return r.json().then(function(j){ return { ok: r.ok, body: j }; }); })
    .then(function(res){
      if (res.body && res.body.ok && (res.body.preview_url || res.body.url)) {
        progFill.style.width = '100%'; loadingStatus.textContent = 'Ready! Opening your preview…';
        state.token = res.body.token || null;
        state.businessName = res.body.business_name || res.body.business || 'your site';
        loadingHead.innerHTML = 'Wizzy is designing ' + escapeHtml(state.businessName) + '…';
        stopLoadingTickers();
        var previewUrl = res.body.preview_url || (res.body.url + 'v1/index.html');
        track('gen_completed', { duration_ms: Date.now() - __genT0 });
        setTimeout(function(){ previewFrame.src = previewUrl; setView('reveal'); chatInput.focus(); track('reveal_viewed'); }, 600);
      } else {
        var msg = (res.body && res.body.error) ? res.body.error : 'Generation failed. Try again?';
        track('gen_failed', { reason: String(msg).slice(0,140), duration_ms: Date.now() - __genT0 });
        showLoadingError(msg);
        ctaBtn.disabled = false;
      }
    })
    .catch(function(e){
      var em = e && e.message ? e.message : 'unknown';
      track('gen_failed', { reason: 'network: ' + String(em).slice(0,120), duration_ms: Date.now() - __genT0 });
      showLoadingError('Network error: ' + em);
      ctaBtn.disabled = false;
    });
  });

  notifyForm.addEventListener('submit', function(e){
    e.preventDefault();
    var em = (notifyEmail.value || '').trim(); if (!em) return;
    fetch('/api/magic.php?notify_email=' + encodeURIComponent(em), { method: 'POST' }).catch(function(){});
    notifyForm.style.display = 'none'; notifySaid.style.display = 'block';
  });

  // ---------- chat / edits ----------
  function appendMsg(kind, text){
    var div = document.createElement('div'); div.className = 'msg msg-' + kind;
    if (kind === 'wiz') {
      var img = document.createElement('img'); img.className='tinywiz'; img.src='/preview/wizzy-wave.gif'; img.alt='Wizzy';
      div.appendChild(img);
      var span = document.createElement('span'); span.textContent = text; div.appendChild(span);
    } else { div.textContent = text; }
    chatHistory.appendChild(div); chatHistory.scrollTop = chatHistory.scrollHeight;
    return div;
  }
  function appendTyping(){
    var div = document.createElement('div'); div.className = 'msg msg-wiz typing';
    var img = document.createElement('img'); img.className='tinywiz'; img.src='/preview/wizzy-wave.gif'; img.alt='Wizzy';
    div.appendChild(img);
    var span = document.createElement('span'); span.textContent = 'Updating now…'; div.appendChild(span);
    chatHistory.appendChild(div); chatHistory.scrollTop = chatHistory.scrollHeight;
    return div;
  }

  function updateEditsChip(n){
    state.editsRemaining = n;
    editsChip.textContent = n === 1 ? '1 edit remaining' : (n + ' edits remaining');
    editsChip.classList.toggle('zero', n === 0);
    if ((EDIT_CAP - n) >= 3) addUploadChip();
    if (n === 0) {
      body.setAttribute('data-cap', 'hit');
      chatInput.setAttribute('readonly', 'true'); chatSend.disabled = true;
    }
  }

  function addUploadChip(){
    if (suggestedRow.querySelector('.sugchip.upload')) return;
    var s = document.createElement('span'); s.className = 'sugchip upload pulse';
    s.innerHTML = '📎 Upload your logo or photos';
    s.addEventListener('click', openUploadModal);
    suggestedRow.appendChild(s);
    setTimeout(function(){ s.classList.remove('pulse'); }, 1100);
  }

  suggestedRow.addEventListener('click', function(e){
    var c = e.target.closest('.sugchip'); if (!c) return;
    if (c.classList.contains('upload')) { openUploadModal(); return; }
    var fill = c.getAttribute('data-fill') || c.textContent;
    chatInput.value = fill; chatInput.focus();
  });

  function sendEdit(message){
    if (!state.token || state.sending || state.editsRemaining <= 0) return;
    state.sending = true; chatSend.disabled = true;
    appendMsg('user', message); chatInput.value = '';
    var typing = appendTyping();
    track('edit_used', { edit_number: (EDIT_CAP - state.editsRemaining) + 1, edit_type: 'text', message: message.slice(0, 140) });

    fetch('/api/edit.php', {
      method: 'POST',
      headers: { 'Content-Type':'application/json', 'Accept':'application/json' },
      body: JSON.stringify({ token: state.token, message: message })
    })
    .then(function(r){ return r.json().then(function(j){ return { ok:r.ok, body:j }; }); })
    .then(function(res){
      typing.remove();
      var b = res.body || {};
      if (b.ok) {
        var src = b.preview_url || (('/preview/' + state.token + '/v1/index.html?e=' + Date.now()));
        previewFrame.src = src;
        appendMsg('wiz', b.reply || "Done. How's that?");
        updateEditsChip(typeof b.edits_remaining === 'number' ? b.edits_remaining : (state.editsRemaining - 1));
        if (b.cap_hit) onCapHit();
      } else if (b.cap_hit) {
        updateEditsChip(0); onCapHit(b.reply);
      } else {
        appendMsg('wiz', 'Hmm, that one didn\'t take. ' + (b.error || 'Try wording it a different way?'));
      }
    })
    .catch(function(e){ typing.remove(); appendMsg('wiz', 'Network hiccup — try again? (' + (e && e.message || 'unknown') + ')'); })
    .finally(function(){
      state.sending = false;
      if (state.editsRemaining > 0) chatSend.disabled = false;
    });
  }

  function onCapHit(customMsg){
    var msg = customMsg || "That's all the tweaks I can do here. If you love where it's at, let's make it real. If it still needs work, my human teammates can take it from here once you launch it.";
    appendMsg('wiz', msg);
    track('edit_cap_hit');
    setTimeout(showConvCard, 700);
  }

  function showConvCard(){
    body.setAttribute('data-conv', 'on');
    convErr.classList.remove('on');
    setTimeout(function(){ convCard.scrollIntoView({ behavior:'smooth', block:'start' }); }, 50);
  }
  function hideConvCard(){
    body.removeAttribute('data-conv');
  }
  convBack.addEventListener('click', hideConvCard);

  convCta.addEventListener('click', function(){
    if (!state.token) { convErr.textContent = 'Lost track of your preview — refresh and try again.'; convErr.classList.add('on'); return; }
    track('make_it_real_clicked');
    convCta.disabled = true; convCta.textContent = 'Spinning up checkout…';
    convErr.classList.remove('on');
    fetch('/api/try_checkout.php', {
      method:'POST',
      headers: { 'Content-Type':'application/json','Accept':'application/json' },
      body: JSON.stringify({ token: state.token })
    })
    .then(function(r){ return r.json().then(function(j){ return { ok:r.ok, body:j }; }); })
    .then(function(res){
      var b = res.body || {};
      if (b.ok && b.checkout_url) { track('checkout_started', { session_id: b.session_id || null }); window.location.href = b.checkout_url; return; }
      convErr.textContent = b.error || 'Could not start checkout. Try again?';
      convErr.classList.add('on');
      convCta.disabled = false; convCta.textContent = 'Make it real →';
    })
    .catch(function(e){
      convErr.textContent = 'Network error: ' + (e && e.message || 'unknown');
      convErr.classList.add('on');
      convCta.disabled = false; convCta.textContent = 'Make it real →';
    });
  });

  chatSend.addEventListener('click', function(){
    var m = (chatInput.value || '').trim();
    if (m.length < 3) { chatInput.focus(); return; }
    sendEdit(m);
  });
  chatInput.addEventListener('keydown', function(e){
    if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); chatSend.click(); }
  });

  iLoveIt.addEventListener('click', function(){
    appendMsg('user', 'I love it.');
    appendMsg('wiz', 'Heck yes. Let\'s make it real.');
    setTimeout(showConvCard, 500);
  });

  // ---------- upload modal ----------
  var pickedLogo = null;
  var pickedPhotos = [];

  function openUploadModal(){
    if (state.editsRemaining <= 0) return;
    umBackdrop.classList.add('on'); uploadErr.classList.remove('on');
    document.body.style.overflow = 'hidden';
    track('asset_upload_opened');
  }
  function closeUploadModal(){
    umBackdrop.classList.remove('on'); document.body.style.overflow = '';
    pickedLogo = null; pickedPhotos = [];
    logoInput.value = ''; photosInput.value = '';
    resetDz(dzLogo, 'Drop your logo', 'PNG or SVG, up to 2 MB');
    resetDz(dzPhotos, 'Drop photos', 'JPG or PNG, up to 5 MB each, max 3');
  }
  function resetDz(dz, big, small){
    var inner = dz.querySelector('.dz-inner');
    if (inner) inner.innerHTML = '<strong>' + big + '</strong><br><small>' + small + '</small>';
    dz.classList.remove('drag');
  }
  function showPickedLogo(file){
    var inner = dzLogo.querySelector('.dz-inner');
    inner.innerHTML = '<span class="picked">' + escapeHtml(file.name) + '</span><br><small>' + Math.round(file.size/1024) + ' KB · ready to send</small>';
  }
  function showPickedPhotos(files){
    var inner = dzPhotos.querySelector('.dz-inner');
    var names = Array.prototype.map.call(files, function(f){ return escapeHtml(f.name); }).join(', ');
    inner.innerHTML = '<span class="picked">' + files.length + ' photo' + (files.length===1?'':'s') + ' picked</span><br><small>' + names + '</small>';
  }

  uploadCancel.addEventListener('click', closeUploadModal);
  umBackdrop.addEventListener('click', function(e){ if (e.target === umBackdrop) closeUploadModal(); });
  dzLogo.addEventListener('click', function(e){ if (e.target.tagName !== 'INPUT') logoInput.click(); });
  dzPhotos.addEventListener('click', function(e){ if (e.target.tagName !== 'INPUT') photosInput.click(); });
  logoInput.addEventListener('change', function(){
    if (!logoInput.files || !logoInput.files[0]) return;
    pickedLogo = logoInput.files[0]; showPickedLogo(pickedLogo);
  });
  photosInput.addEventListener('change', function(){
    if (!photosInput.files) return;
    pickedPhotos = Array.prototype.slice.call(photosInput.files, 0, 3);
    showPickedPhotos(pickedPhotos);
  });

  function makeDroppable(dz, handler){
    ['dragenter','dragover'].forEach(function(ev){ dz.addEventListener(ev, function(e){ e.preventDefault(); dz.classList.add('drag'); }); });
    ['dragleave','drop'].forEach(function(ev){ dz.addEventListener(ev, function(e){ e.preventDefault(); dz.classList.remove('drag'); }); });
    dz.addEventListener('drop', function(e){ var files = e.dataTransfer && e.dataTransfer.files; if (!files || !files.length) return; handler(files); });
  }
  makeDroppable(dzLogo, function(files){ pickedLogo = files[0]; showPickedLogo(pickedLogo); });
  makeDroppable(dzPhotos, function(files){ pickedPhotos = Array.prototype.slice.call(files, 0, 3); showPickedPhotos(pickedPhotos); });

  uploadSend.addEventListener('click', function(){
    uploadErr.classList.remove('on');
    if (!pickedLogo && (!pickedPhotos || !pickedPhotos.length)) { uploadErr.textContent = 'Pick a logo or at least one photo first.'; uploadErr.classList.add('on'); return; }
    if (!state.token) { uploadErr.textContent = 'No preview to attach to.'; uploadErr.classList.add('on'); return; }

    uploadSend.disabled = true; uploadSend.textContent = 'Sending...';
    var fd = new FormData();
    fd.append('token', state.token);
    if (pickedLogo) fd.append('logo', pickedLogo, pickedLogo.name);
    if (pickedPhotos) pickedPhotos.forEach(function(p){ fd.append('photos[]', p, p.name); });
    track('edit_used', { edit_number: (EDIT_CAP - state.editsRemaining) + 1, edit_type: 'asset_upload', logo: !!pickedLogo, photo_count: pickedPhotos.length });
    appendMsg('user', '📎 Sent ' + (pickedLogo ? 'logo' : '') + (pickedLogo && pickedPhotos.length ? ' + ' : '') + (pickedPhotos.length ? (pickedPhotos.length + ' photo' + (pickedPhotos.length===1?'':'s')) : ''));
    var typing = appendTyping();
    closeUploadModal();

    fetch('/api/upload.php', { method:'POST', body: fd })
      .then(function(r){ return r.json().then(function(j){ return { ok:r.ok, body:j }; }); })
      .then(function(res){
        typing.remove(); var b = res.body || {};
        if (b.ok) {
          var src = b.preview_url || (('/preview/' + state.token + '/v1/index.html?e=' + Date.now()));
          previewFrame.src = src;
          appendMsg('wiz', b.reply || "Got it. I'll work these in.");
          updateEditsChip(typeof b.edits_remaining === 'number' ? b.edits_remaining : (state.editsRemaining - 1));
          track('asset_upload_completed', { logo: !!pickedLogo, photo_count: pickedPhotos.length });
          if (b.cap_hit) onCapHit();
        } else { appendMsg('wiz', 'Upload failed: ' + (b.error || 'something went wrong')); }
      })
      .catch(function(e){ typing.remove(); appendMsg('wiz', 'Network hiccup on the upload: ' + (e && e.message || 'unknown')); })
      .finally(function(){ uploadSend.disabled = false; uploadSend.textContent = 'Send to Wizzy →'; });
  });

  // ---------- on-load hydration ----------
  // If PHP gave us an initial token (Stripe cancel recovery), the upload chip
  // should be visible if we're already 3+ edits in.
  if (state.token && state.editsRemaining <= 2) addUploadChip();

  // Initial analytics on page load
  if (INIT.view === 'form')    track('hero_view', { from: document.referrer || null });
  if (INIT.view === 'reveal')  track('reveal_viewed', { recovered: true });
  if (INIT.view === 'success') track('checkout_completed_view');


  // ---------- Wizzy video pause-then-loop ----------
  // Removed `loop` from the <video> tags so we can wait 5s between plays
  // (per Omar). Some Wizzy videos are short loops (~3-5s); without this
  // pause they feel frantic. Re-plays muted so autoplay policies hold.
  function wireWizzyPause(){
    var vids = document.querySelectorAll('video.wizzy-vid');
    vids.forEach(function(v){
      v.removeAttribute('loop');
      v.addEventListener('ended', function(){
        // 1s pause between loops — feels alive without being frantic
        setTimeout(function(){ try { v.currentTime = 0; v.play().catch(function(){}); } catch(e){} }, 1000);
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', wireWizzyPause);
  else wireWizzyPause();

  // ---------- helpers ----------
  function escapeHtml(s){ return String(s).replace(/[&<>"']/g, function(c){
    return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
  });}
})();
</script>
</body></html>
