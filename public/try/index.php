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
$dl_description = trim((string)($_GET['description'] ?? ''));
$dl_company     = trim((string)($_GET['company'] ?? ''));
$is_describe_link = ($website === '' && $dl_description !== '');
$is_magic = ($website !== '') || $is_describe_link;

if ($is_magic) {
    $host    = $website ? (parse_url(preg_match('~^https?://~i', $website) ? $website : 'https://' . $website, PHP_URL_HOST) ?: $website) : '';
    $host    = preg_replace('~^www\.~', '', (string)$host);
    $magic_label = $host !== '' ? $host : $dl_company;
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
  .sub{font-size:16px;color:#12184A;margin:0 0 26px;}
  /* /try landing form helpers */
  .opt-tag{display:inline-block;background:transparent;color:#12184A;opacity:0.55;font-family:var(--body);font-weight:500;font-size:12px;letter-spacing:0.04em;margin-left:6px;text-transform:none;}
  .field-helper{font-family:var(--body);font-size:13px;color:#12184A;opacity:0.7;margin-top:6px;line-height:1.4;}
  .form-card .field.invalid .field-helper{display:none;}
  .cta-microcopy{font-family:var(--body);font-size:13px;color:#12184A;opacity:0.65;text-align:center;margin:12px 0 0;font-weight:500;}
  .bar{height:10px;background:#e8e0c8;border-radius:99px;overflow:hidden;max-width:360px;margin:0 auto 16px;}
  .bar>span{display:block;height:100%;width:0;background:linear-gradient(90deg,var(--teal),var(--navy));border-radius:99px;transition:width .6s ease;}
  #status{font-size:15px;font-weight:700;min-height:22px;}
  .err{background:#fde8e8;border:2px solid #c0392b;color:#7a1f17;padding:14px 16px;border-radius:12px;margin-top:18px;font-size:14px;}
  .err a{color:var(--navy);font-weight:700;}
  .brand{margin-top:30px;font-size:12px;letter-spacing:.16em;text-transform:uppercase;opacity:.55;font-weight:800;}

  </style><!-- Meta Pixel -->
<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','1974530180093513');fbq('track','PageView');window.wwMetaTrack=function(name,params,userParams){var eid='ww_'+Date.now().toString(36)+Math.random().toString(36).slice(2,14);try{if(window.fbq)fbq('track',name,params||{},{eventID:eid});}catch(e){}try{var body=Object.assign({event_name:name,event_id:eid,event_source_url:location.href},params||{},userParams||{});if(navigator.sendBeacon){var blob=new Blob([JSON.stringify(body)],{type:'application/json'});navigator.sendBeacon('/api/capi.php',blob);}else{fetch('/api/capi.php',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify(body),keepalive:true});}}catch(e){}return eid;};</script>
<noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=1974530180093513&ev=PageView&noscript=1"/></noscript>
<!-- End Meta Pixel -->
</head>
<body>
  <div class="card">
    <div class="wiz"><img src="/preview/wizzy-wave.gif" alt="Wizzy"></div>
    <h1 id="head">Getting <?= htmlspecialchars($magic_label ?: 'your', ENT_QUOTES) ?><?= $magic_label ? "&rsquo;s" : '' ?> website ready&hellip;</h1>
    <p class="sub">Hang tight<?= $name ? ', ' . htmlspecialchars(explode(' ', $name)[0], ENT_QUOTES) : '' ?>. Wizzy is designing your site now. This takes about two minutes.</p>
    <div class="bar"><span id="prog"></span></div>
    <div id="status">Starting&hellip;</div>
    <div id="errbox"></div>
    <div class="brand">Powered by WebWiz</div>
  </div>
<script>
(function(){
  var qs = new URLSearchParams(location.search);
  if(!qs.get('website') && !qs.get('url') && !qs.get('description')){ document.getElementById('status').textContent=''; document.getElementById('errbox').innerHTML='<div class="err">No website or description provided. The link needs a <strong>website</strong> or <strong>description</strong> parameter.</div>'; return; }
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
<script>
(function(){
  var box = document.getElementById('wizTesti');
  if (!box) return;
  var data = [
    {q: "Sent Wizzy our shop info on a Tuesday. By the weekend we had a site that actually looked like our place.",
     name: "Maria R.", role: "Bakery owner · Pawtucket, RI",
     face: "https://images.unsplash.com/photo-1494790108377-be9c29b29330?w=120&h=120&fit=crop&crop=face"},
    {q: "Filling out the form took five minutes. The site was up the same day. My business looks legit online for the first time ever.",
     name: "Jake M.", role: "Contractor · Tulsa, OK",
     face: "https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=120&h=120&fit=crop&crop=face"},
    {q: "I put off building a site for two years. Wizzy did it in an afternoon and handled the domain. Wish I'd done this sooner.",
     name: "Sarah K.", role: "Salon owner · Austin, TX",
     face: "https://images.unsplash.com/photo-1438761681033-6461ffad8d80?w=120&h=120&fit=crop&crop=face"}
  ];
  var q  = box.querySelector('.wt-quote');
  var nm = box.querySelector('.wt-name');
  var rl = box.querySelector('.wt-role');
  var fc = box.querySelector('.wt-face');
  var dots = box.querySelectorAll('.wt-dot');
  var i = 0, timer;

  function render(n) {
    q.classList.add('fade');
    setTimeout(function(){
      var d = data[n];
      q.textContent  = d.q;
      nm.textContent = d.name;
      rl.textContent = d.role;
      fc.src         = d.face;
      fc.alt         = d.name;
      dots.forEach(function(el, k){ el.classList.toggle('on', k === n); });
      q.classList.remove('fade');
    }, 220);
  }
  function next() { i = (i + 1) % data.length; render(i); }
  function start() { stop(); timer = setInterval(next, 5400); }
  function stop()  { if (timer) clearInterval(timer); }

  dots.forEach(function(el, k){
    el.addEventListener('click', function(){ i = k; render(i); start(); });
  });
  box.addEventListener('mouseenter', stop);
  box.addEventListener('mouseleave', start);
  start();
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
        } elseif (is_file('/var/www/sites/trywebwiz/public/preview/' . $tparam . '/v1/index.html')) {
            // No DB row yet (persist still pending on disk), but preview files exist.
            // Hydrate from filesystem so the user can come back to their gen even
            // before the metadata has caught up.
            $biz_from_pending = '';
            $pending_file = '/var/www/sites/trywebwiz/data/pending_magic/' . $tparam . '.json';
            if (is_file($pending_file)) {
                $p = json_decode((string)@file_get_contents($pending_file), true);
                if (is_array($p)) $biz_from_pending = (string)($p['biz'] ?? '');
            }
            $initial_token = $tparam;
            $initial_biz   = $biz_from_pending ?: 'Your site';
            $initial_edits = 5;
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
<title>See your new website free. Launch it for $500 · WebWiz</title>
<meta name="description" content="See your new website free in minutes. A custom design runs $5,000; yours launches for a flat $500, so you save about $4,500. Then $50/month hosting. Free to preview, no card to try.">
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

  header.topbar{padding:10px 16px;display:flex;align-items:center;justify-content:space-between;gap:16px;}
  /* Brand sizing applies on ALL views (form/loading/reveal) — without this the
     wizzy-face.png renders at its native size on the form/loading views. */
  header.topbar .brand{display:flex;align-items:center;gap:10px;text-decoration:none;}
  header.topbar .brand-icon{width:36px;height:36px;border-radius:50%;background:var(--wizbg);border:2px solid var(--navy);overflow:hidden;flex-shrink:0;display:inline-block;}
  header.topbar .brand-icon img{width:100%;height:100%;object-fit:cover;display:block;}
  header.topbar .brand-stack{display:flex;flex-direction:column;line-height:1;}
  header.topbar .brand-stack small{font-family:ui-monospace,monospace;font-size:10px;letter-spacing:0.18em;text-transform:uppercase;opacity:0.6;margin-top:2px;font-weight:600;}
  /* Center pills + Customize + Buy + device toggle are reveal-only. */
  .hdr-center,.topbar-customize{display:none!important;}
  body[data-view="reveal"] .hdr-center{display:flex!important;}
  body[data-view="reveal"] .topbar-customize{display:inline-flex!important;}
  /* Reveal view: classic preview-page layout (header bar + full-height iframe).
     Use dvh (dynamic viewport height) so iOS Safari address bar showing/hiding
     doesn't cause the layout to jump. Fall back to vh for browsers that don't
     support dvh. */
  body[data-view="reveal"]{display:flex;flex-direction:column;height:100vh;height:100dvh;overflow:hidden;background:var(--cream);}
  body[data-view="reveal"] header.topbar{position:relative;flex-shrink:0;background:var(--cream);border-bottom:3px solid var(--navy);padding:10px 20px;display:grid;grid-template-columns:auto 1fr auto;gap:16px;align-items:center;border-radius:0;box-shadow:none;}
  body[data-view="reveal"] header.topbar .brand{display:flex;align-items:center;gap:10px;}
  body[data-view="reveal"] header.topbar .brand .brand-stack{display:flex;flex-direction:column;line-height:1;}
  body[data-view="reveal"] header.topbar .brand small{font-family:ui-monospace,monospace;font-size:10px;letter-spacing:0.18em;text-transform:uppercase;opacity:0.6;margin-top:2px;font-weight:600;}
  body[data-view="reveal"] header.topbar .brand-icon{width:32px;height:32px;border-radius:50%;background:var(--wizbg);border:2px solid var(--navy);overflow:hidden;flex-shrink:0;}
  body[data-view="reveal"] header.topbar .brand-icon img{width:100%;height:100%;object-fit:cover;display:block;}
  .topbar-actions{display:flex;align-items:center;gap:10px;}
  /* Center pills (variant + current site) */
  .hdr-center{display:flex;gap:10px;justify-content:center;align-items:center;flex-wrap:wrap;}
  .variant-pill{font-family:var(--display);background:var(--navy);color:var(--cream);border:2px solid var(--navy);border-radius:999px;padding:8px 16px;font-weight:800;font-size:12px;letter-spacing:0.04em;display:flex;flex-direction:column;align-items:center;gap:1px;line-height:1.1;}
  .variant-pill .v-sub{font-size:9px;opacity:0.7;letter-spacing:0.12em;text-transform:uppercase;}
  .current-site-pill{font-family:var(--display);background:transparent;color:var(--navy);border:2px dashed var(--navy);border-radius:999px;padding:8px 14px;font-weight:800;font-size:12px;display:flex;flex-direction:column;align-items:center;gap:1px;line-height:1.1;}
  .current-site-pill .cs-sub{font-size:9px;opacity:0.7;letter-spacing:0.06em;text-transform:lowercase;font-family:ui-monospace,monospace;font-weight:600;}
  /* Right side: Customize ghost + Buy now primary */
  body[data-view="reveal"] .topbar-actions .topbar-customize{font-family:var(--display);background:transparent;color:var(--navy);border:2px solid var(--navy);border-radius:999px;padding:9px 16px;font-weight:800;font-size:13px;cursor:pointer;}
  body[data-view="reveal"] .topbar-actions .topbar-customize:hover{background:var(--navy);color:var(--cream);}
  body[data-view="reveal"] .topbar-buy{background:var(--navy);color:var(--cream);border:2px solid var(--navy);box-shadow:3px 3px 0 var(--yellow);padding:9px 16px;font-size:13px;}
  body[data-view="reveal"] .topbar-buy:hover{box-shadow:4px 4px 0 var(--yellow);}
  /* Mobile: stack header */
  @media (max-width:880px){
    body[data-view="reveal"] header.topbar{grid-template-columns:1fr;text-align:center;padding:8px 12px;gap:6px;}
    .hdr-center{justify-content:center;flex-wrap:wrap;}
    .topbar-actions{justify-content:center;flex-wrap:wrap;}
    body[data-view="reveal"] header.topbar .brand{justify-content:center;}
  }
  /* Hard mobile breakpoint for the reveal view: chat becomes a bottom sheet
     covering ~60% of screen so iframe stays visible above. Topbar slims to a
     single line. Device toggle hidden (no value on a phone). */
  @media (max-width:640px){
    body[data-view="reveal"] header.topbar{padding:6px 10px;gap:4px;}
    body[data-view="reveal"] header.topbar .brand-stack{font-size:14px;}
    body[data-view="reveal"] header.topbar .brand-icon{width:28px;height:28px;}
    body[data-view="reveal"] .hdr-center{display:none!important;}
    body[data-view="reveal"] .device-toggle-floating{display:none!important;}
    body[data-view="reveal"] .topbar-customize{padding:6px 10px;font-size:11px;}
    body[data-view="reveal"] .topbar-buy{padding:6px 10px;font-size:11px;}
    /* Chat: bottom-sheet that takes the full width and bottom half of screen. */
    body[data-view="reveal"] .edit-panel{right:0!important;bottom:0!important;left:0!important;width:100vw!important;max-width:100vw!important;height:62vh!important;max-height:62vh!important;border-radius:18px 18px 0 0!important;border-bottom:none!important;box-shadow:0 -6px 0 var(--navy)!important;}
    .chat-fab{right:14px!important;bottom:14px!important;width:60px!important;height:60px!important;}
    /* Iframe occupies the top portion; user can dismiss chat to see more */
    body[data-view="reveal"][data-chat="open"] .reveal-frame-wrap{padding-bottom:0;}
    /* Edit overlay sized for small screen */
    .edit-overlay-avatar{width:96px!important;height:96px!important;}
    .edit-overlay-text{font-size:18px!important;}
  }
  header.topbar .brand{font-family:var(--display);font-weight:900;font-size:22px;letter-spacing:-0.03em;color:var(--navy);text-decoration:none;}
  header.topbar .brand .dot{color:var(--yellow);}
  /* "Buy the site" CTA in the topbar, only visible on the reveal view. */
  .topbar-buy{display:none;background:var(--yellow);color:var(--navy);border:2px solid var(--navy);border-radius:999px;padding:10px 18px;font-family:var(--body);font-weight:800;font-size:14px;cursor:pointer;box-shadow:3px 3px 0 var(--navy);transition:transform .12s ease,box-shadow .12s ease;}
  .topbar-buy:hover{transform:translate(-1px,-1px);box-shadow:4px 4px 0 var(--navy);}
  .topbar-buy:active{transform:translate(1px,1px);box-shadow:1px 1px 0 var(--navy);}
  body[data-view="reveal"] .topbar-buy{display:inline-flex;align-items:center;gap:6px;}

  /* ----------- Hero ----------- */
  main{padding:24px 32px 48px;max-width:1280px;margin:0 auto;}
  .hero{display:grid;grid-template-columns:1.4fr 1fr;gap:48px;align-items:start;margin-top:24px;}
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
  .trust{display:flex;flex-wrap:wrap;justify-content:center;gap:10px;margin-top:20px;}
  .chip{display:inline-flex;align-items:center;gap:6px;background:var(--cream);border:2px solid var(--navy);border-radius:999px;padding:8px 16px;font-family:var(--body);font-weight:600;font-size:14px;color:var(--navy);}
  .chip .tick{color:var(--teal);font-weight:900;}
  .hero-mascot{position:relative;display:flex;flex-direction:column;justify-content:flex-start;align-items:center;min-height:0;min-width:0;}
  .wiz-circle{width:min(420px,100%);aspect-ratio:1;height:auto;flex-shrink:0;border-radius:50%;background:var(--wizbg);border:3px solid var(--navy);display:flex;align-items:center;justify-content:center;overflow:hidden;position:relative;box-shadow:8px 8px 0 var(--yellow);}
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
  .wq-card{max-width:440px;margin:26px auto 0;background:#fffdf8;border:2px solid var(--navy);border-radius:18px;box-shadow:0 10px 30px rgba(18,24,74,.12);padding:20px 20px 16px;text-align:left;}
  .wq-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;}
  .wq-kicker{font-family:var(--body);font-weight:700;font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:var(--teal);}
  .wq-count{font-family:var(--body);font-weight:600;font-size:12px;color:rgba(18,24,74,.5);}
  .wq-q{font-family:var(--body);font-weight:800;font-size:18px;line-height:1.32;color:var(--navy);margin:0 0 14px;}
  .wq-opts{display:flex;flex-direction:column;gap:8px;}
  .wq-opt{width:100%;text-align:left;font-family:var(--body);font-weight:600;font-size:14.5px;color:var(--navy);background:#fff;border:2px solid #e7dfce;border-radius:11px;padding:12px 14px;cursor:pointer;transition:border-color .15s ease,transform .05s ease,background .15s ease;}
  .wq-opt:hover{border-color:var(--teal);background:#f4fbf9;}
  .wq-opt:active{transform:translateY(1px);}
  .wq-skip{margin:12px 0 0;text-align:center;}
  .wq-skip button{background:none;border:none;color:rgba(18,24,74,.45);font-family:var(--body);font-size:12.5px;cursor:pointer;text-decoration:underline;}
  .wq-done{text-align:center;padding:6px 0 2px;}
  .wq-done p{font-family:var(--body);font-size:15px;color:var(--navy);margin:0;line-height:1.5;}
  .powered-chip{display:inline-block;margin-top:32px;background:var(--navy);color:var(--cream);font-family:var(--body);font-weight:600;font-size:12px;letter-spacing:0.14em;text-transform:uppercase;padding:6px 14px;border-radius:999px;}
  .late-fallback{display:none;max-width:520px;margin:32px auto 0;background:var(--cream);border:2px solid var(--navy);border-radius:14px;padding:20px 22px;text-align:left;box-shadow:4px 4px 0 var(--navy);}
  .late-fallback.on{display:block;}
  .late-fallback p{margin:0 0 12px;font-size:15px;color:var(--navy);}
  .late-fallback .row{display:flex;gap:10px;flex-wrap:wrap;}
  .late-fallback input{flex:1;min-width:200px;height:46px;background:var(--cream);border:2px solid var(--navy);border-radius:8px;padding:0 14px;font-family:var(--body);font-size:16px;color:var(--navy);outline:none;}
  .late-fallback button{height:46px;padding:0 18px;background:var(--yellow);color:var(--navy);border:2px solid var(--navy);border-radius:8px;font-family:var(--display);font-weight:900;font-size:14px;cursor:pointer;}
  .loading-err{display:none;max-width:520px;margin:24px auto 0;background:#fde2e2;border:2px solid #8a0e0e;color:#5a0808;border-radius:12px;padding:14px 16px;font-size:14px;text-align:left;}
  .loading-err.on{display:block;}
  .loading-err .back-btn{display:inline-block;margin-top:10px;background:var(--navy);color:var(--cream);border:none;border-radius:8px;padding:8px 14px;font-family:var(--display);font-weight:900;font-size:13px;cursor:pointer;}

  /* ----------- Reveal + Edit Chat ----------- */
  /* ---- REVEAL VIEW: header on top + iframe fills the rest ---- */
  /* Kill main's default padding/max-width so the reveal iframe is true full-width.
     CRITICAL: only target the visible reveal main, not all 4 main elements
     (form/loading/reveal/success), otherwise flex:1 splits space between them. */
  body[data-view="reveal"] main.view-reveal{padding:0!important;max-width:none!important;margin:0!important;flex:1;display:flex;flex-direction:column;min-height:0;width:100%;}
  body[data-view="reveal"] main:not(.view-reveal){display:none!important;}
  /* Hide the /try page's own footer on reveal — the iframe is the page now */
  body[data-view="reveal"] footer.tryfoot,body[data-view="reveal"] footer{display:none!important;}
  .view-reveal{padding:0;flex:1;display:flex;flex-direction:column;min-height:0;width:100%;}
  .reveal-layout{flex:1;display:flex;flex-direction:column;width:100%;min-height:0;}
  .reveal-frame-wrap{flex:1;position:relative;background:var(--cream);min-height:0;overflow:hidden;}
  .reveal-frame-wrap.device-mobile{padding:16px;display:flex;align-items:stretch;justify-content:center;background:var(--cream);background-image:radial-gradient(rgba(18,24,74,0.07) 1.5px, transparent 1.5px);background-size:24px 24px;}
  /* Edit-in-progress overlay (shown over iframe while Wizzy is updating the site) */
  .edit-overlay{position:absolute;inset:0;background:rgba(18,24,74,0.55);backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px);display:none;flex-direction:column;align-items:center;justify-content:center;gap:18px;z-index:40;color:var(--cream);font-family:var(--display);pointer-events:auto;}
  .edit-overlay.on{display:flex;animation:overlayIn .25s ease;}
  @keyframes overlayIn{from{opacity:0;}to{opacity:1;}}
  .edit-overlay-avatar{width:140px;height:140px;border-radius:50%;background:var(--wizbg);border:4px solid var(--cream);overflow:hidden;box-shadow:0 12px 40px rgba(0,0,0,0.35);position:relative;}
  .edit-overlay-avatar video{width:100%;height:100%;object-fit:cover;display:block;}
  .edit-overlay-avatar img{width:100%;height:100%;object-fit:cover;display:block;}
  .edit-overlay-text{font-weight:900;font-size:22px;letter-spacing:-0.01em;text-align:center;text-shadow:0 2px 8px rgba(0,0,0,0.3);}
  .edit-overlay-sub{font-family:var(--body);font-weight:600;font-size:14px;opacity:0.85;letter-spacing:0.02em;}
  .edit-overlay-dots{display:inline-flex;gap:6px;margin-top:4px;}
  .edit-overlay-dots span{width:9px;height:9px;background:var(--yellow);border-radius:50%;animation:editDot 1.1s infinite ease-in-out;}
  .edit-overlay-dots span:nth-child(2){animation-delay:.15s;}
  .edit-overlay-dots span:nth-child(3){animation-delay:.3s;}
  @keyframes editDot{0%,80%,100%{transform:scale(0.6);opacity:0.5;}40%{transform:scale(1);opacity:1;}}
  /* Device toggle pill inside the floating topbar */
  .device-toggle-floating{display:inline-flex;background:var(--cream);border:2px solid var(--navy);border-radius:999px;padding:3px;gap:2px;}
  .device-toggle-floating button{background:transparent;border:0;border-radius:999px;padding:6px 12px;font-family:var(--body);font-weight:700;font-size:12px;letter-spacing:0.04em;color:var(--navy);cursor:pointer;display:inline-flex;align-items:center;gap:6px;}
  .device-toggle-floating button.active{background:var(--navy);color:var(--cream);}
  .device-toggle-floating svg{width:14px;height:14px;}
  .device-toggle{display:inline-flex;background:var(--cream);border:2px solid var(--navy);border-radius:999px;padding:3px;gap:2px;}
  .device-toggle button{background:transparent;border:0;border-radius:999px;padding:6px 14px;font-family:var(--body);font-weight:700;font-size:12px;letter-spacing:0.04em;color:var(--navy);cursor:pointer;display:inline-flex;align-items:center;gap:6px;}
  .device-toggle button.active{background:var(--navy);color:var(--cream);}
  .device-toggle svg{width:14px;height:14px;}
  .reveal-frame-wrap.device-mobile .reveal-frame{max-width:420px;height:100%;border:2px solid var(--navy);border-radius:32px;overflow:hidden;box-shadow:8px 8px 0 var(--navy);}
  .reveal-frame-wrap.device-mobile .reveal-frame iframe{border-radius:30px;}
  .reveal-frame-wrap{position:relative;}
  .reveal-frame{width:100%;height:100%;border:0;border-radius:0;background:var(--cream);box-shadow:8px 8px 0 var(--yellow);overflow:hidden;}
  .reveal-frame iframe{width:100%;height:100%;border:0;display:block;background:var(--cream);}
  .wizzy-badge-wrap{position:absolute;top:-20px;left:-20px;display:flex;align-items:flex-start;gap:12px;z-index:5;}
  .wizzy-badge{width:80px;height:80px;border-radius:50%;background:var(--wizbg);border:3px solid var(--navy);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;box-shadow:4px 4px 0 var(--yellow);}
  .wizzy-badge img{width:78%;height:78%;object-fit:contain;}
  .speech-bubble{margin-top:16px;background:var(--cream);border:2px solid var(--navy);border-radius:12px;padding:10px 14px;font-family:var(--body);font-weight:600;font-size:14px;color:var(--navy);box-shadow:3px 3px 0 var(--navy);max-width:280px;position:relative;}
  .speech-bubble::before{content:'';position:absolute;left:-12px;top:14px;width:0;height:0;border-top:8px solid transparent;border-bottom:8px solid transparent;border-right:12px solid var(--navy);}
  .speech-bubble::after{content:'';position:absolute;left:-9px;top:16px;width:0;height:0;border-top:6px solid transparent;border-bottom:6px solid transparent;border-right:10px solid var(--cream);}

  /* Floating Intercom-style chat widget on the reveal view */
  .edit-panel{display:none;}
  body[data-view="reveal"] .edit-panel{background:var(--cream);border:2px solid var(--navy);border-radius:20px;box-shadow:6px 6px 0 var(--navy);display:flex;flex-direction:column;overflow:hidden;position:fixed;right:20px;bottom:20px;width:400px;max-width:calc(100vw - 40px);height:560px;max-height:calc(100vh - 100px);z-index:60;transform-origin:bottom right;transition:transform .2s ease,opacity .2s ease;}
  body[data-view="reveal"][data-chat="closed"] .edit-panel{transform:scale(.7);opacity:0;pointer-events:none;}
  body[data-view="reveal"][data-chat="open"] .edit-panel{transform:scale(1);opacity:1;}
  /* Floating Wizzy avatar bubble (toggles the panel) */
  .chat-fab{display:none;position:fixed;right:24px;bottom:24px;z-index:55;width:72px;height:72px;border-radius:50%;background:var(--wizbg);border:3px solid var(--navy);box-shadow:5px 5px 0 var(--yellow);cursor:pointer;padding:0;overflow:hidden;transition:transform .15s ease,box-shadow .15s ease;}
  .chat-fab:hover{transform:translate(-2px,-2px);box-shadow:7px 7px 0 var(--yellow);}
  .chat-fab img{width:100%;height:100%;object-fit:cover;display:block;}
  .chat-fab .fab-dot{position:absolute;top:-4px;right:-4px;background:var(--teal);color:var(--cream);border:2px solid var(--navy);border-radius:50%;width:22px;height:22px;font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center;}
  body[data-view="reveal"] .chat-fab{display:block;}
  body[data-chat="open"] .chat-fab{display:none;}
  /* Close X button inside the chat panel header */
  .chat-close{position:absolute;top:10px;right:10px;background:rgba(18,24,74,0.08);border:0;width:30px;height:30px;border-radius:50%;color:var(--navy);font-size:20px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center;z-index:10;font-weight:700;}
  .chat-close:hover{background:rgba(18,24,74,0.18);}
  body[data-view="reveal"] .edit-panel{position:fixed;}
  .edit-header{padding:14px 16px 14px 16px;padding-right:52px;border-bottom:2px solid var(--navy);display:flex;align-items:center;gap:8px;flex-wrap:wrap;background:var(--cream);position:relative;}
  .edit-header h3{margin-right:auto;font-size:15px;white-space:nowrap;}
  .edit-header-wiz{width:40px;height:40px;border-radius:50%;background:var(--wizbg);border:2px solid var(--navy);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;}
  .edit-header-wiz img{width:100%;height:100%;object-fit:cover;}
  .edit-header h3{flex:1;font-family:var(--display);font-weight:900;font-size:18px;color:var(--navy);margin:0;letter-spacing:-0.01em;}
  .edits-chip{background:var(--yellow);color:var(--navy);border:2px solid var(--navy);border-radius:999px;padding:6px 12px;font-family:var(--body);font-weight:700;font-size:12px;letter-spacing:0.04em;white-space:nowrap;}
  .edits-chip.zero{background:rgba(255,190,0,0.25);}
  .copy-link-btn{background:transparent;color:var(--navy);border:2px solid var(--navy);border-radius:999px;padding:6px 12px;font-family:var(--body);font-weight:700;font-size:12px;letter-spacing:0.04em;cursor:pointer;transition:background .15s ease;white-space:nowrap;}
  .copy-link-btn:hover{background:var(--navy);color:var(--cream);}
  .copy-link-btn.copied{background:#1f9d55;color:#fff;border-color:#1f9d55;}

  .chat-history{flex:1;overflow-y:auto;padding:16px 18px;display:flex;flex-direction:column;gap:10px;}
  .chat-history:empty::before{content:'Tip: click a chip below or type your own tweak.';display:block;color:rgba(18,24,74,0.5);font-size:13px;font-style:italic;}
  .msg{font-family:var(--body);font-weight:400;font-size:14px;line-height:1.45;padding:10px 12px;border-radius:12px;max-width:75%;word-wrap:break-word;}
  .msg-user{background:var(--navy);color:var(--cream);align-self:flex-end;}
  .msg-wiz{background:var(--cream);border:2px solid var(--navy);color:var(--navy);align-self:flex-start;display:flex;gap:8px;align-items:flex-start;}
  .msg-wiz img.tinywiz{width:28px;height:28px;border-radius:50%;flex-shrink:0;object-fit:cover;background:var(--wizbg);border:1.5px solid var(--navy);}
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
  .conv-card{display:none;padding:24px 22px;overflow-y:auto;max-height:100%;flex:1;min-height:0;-webkit-overflow-scrolling:touch;}
  body[data-conv="on"] .conv-card{display:block;}
  /* When conversion card is showing, the chat input/history is hidden underneath. */
  body[data-conv="on"] .chat-history,
  body[data-conv="on"] .suggested-row,
  body[data-conv="on"] .chat-input-row,
  body[data-conv="on"] .edit-header{display:none;}
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
    .reveal-frame{height:100%;}
  }
  @media (max-width:768px){
    main{padding:16px 20px 60px;} header.topbar{padding:16px 20px;}
    .hero{grid-template-columns:1fr;gap:18px;}
    /* Form-first on mobile: hero-copy/form above, Wizzy illustration below.
       Was order:-1 which pushed mascot above; flipped so CTA lands in first viewport. */
    .hero-copy{order:1;}
    .hero-mascot{order:2;min-height:0;margin-top:8px;}
    .wiz-circle{width:200px;height:200px;}
    .sticker{transform:scale(0.85);}
    body[data-view="form"] main{padding:12px 16px 40px;}
    .sticker{font-size:12px;padding:8px 12px;top:0;right:0;}
    h1{font-size:42px;} .lead{font-size:18px;}
    .form-card{padding:24px;box-shadow:4px 4px 0 var(--navy);}
    .cta{font-size:16px;height:52px;}
    footer{padding:18px 20px;} footer .row{flex-direction:column;text-align:center;}
    .loading-h2{font-size:32px;} .loading-mascot{width:200px;height:200px;}
    .reveal-frame{height:100%;} .wizzy-badge{width:64px;height:64px;}
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
<!-- Meta Pixel -->
<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','1974530180093513');fbq('track','PageView');window.wwMetaTrack=function(name,params,userParams){var eid='ww_'+Date.now().toString(36)+Math.random().toString(36).slice(2,14);try{if(window.fbq)fbq('track',name,params||{},{eventID:eid});}catch(e){}try{var body=Object.assign({event_name:name,event_id:eid,event_source_url:location.href},params||{},userParams||{});if(navigator.sendBeacon){var blob=new Blob([JSON.stringify(body)],{type:'application/json'});navigator.sendBeacon('/api/capi.php',blob);}else{fetch('/api/capi.php',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify(body),keepalive:true});}}catch(e){}return eid;};</script>
<noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=1974530180093513&ev=PageView&noscript=1"/></noscript>
<!-- End Meta Pixel -->
<style id="wiz-testi-css">
  .wiz-testi{position:relative;background:#fff;border:2px solid var(--navy);border-radius:18px;box-shadow:5px 5px 0 var(--yellow);padding:20px 24px 16px;max-width:460px;width:100%;margin:22px auto 0;text-align:left;font-family:var(--body);}
  .wiz-testi .wt-stars{color:#F7C84A;font-size:18px;letter-spacing:3px;line-height:1;margin-bottom:10px;}
  .wiz-testi .wt-quote{font-family:var(--body);font-size:15px;line-height:1.5;color:var(--navy);font-weight:500;margin:0 0 14px;min-height:66px;transition:opacity 0.25s;}
  .wiz-testi .wt-quote.fade{opacity:0;}
  .wiz-testi .wt-who{display:flex;align-items:center;gap:10px;}
  .wiz-testi .wt-face{width:44px;height:44px;border-radius:50%;border:2px solid var(--navy);background:var(--paper);object-fit:cover;flex-shrink:0;transition:opacity 0.25s;}
  .wiz-testi .wt-face.fade{opacity:0;}
  .wiz-testi .wt-meta{min-width:0;}
  .wiz-testi .wt-name{font-family:var(--body);font-weight:700;font-size:14px;color:var(--navy);line-height:1.1;}
  .wiz-testi .wt-role{font-family:var(--body);font-size:12px;color:var(--navy);opacity:0.65;margin-top:2px;}
  .wiz-testi .wt-dots{display:flex;justify-content:center;gap:6px;margin-top:14px;}
  .wiz-testi .wt-dot{width:8px;height:8px;border-radius:50%;background:rgba(18,24,74,0.2);border:0;padding:0;cursor:pointer;transition:background 0.2s, transform 0.2s;}
  .wiz-testi .wt-dot.on{background:var(--navy);transform:scale(1.25);}
  @media(max-width:900px){.wiz-testi{max-width:320px;margin:16px auto 0;}}
</style>
</head>
<body data-view="<?= htmlspecialchars($initial_view, ENT_QUOTES) ?>" data-cap="<?= $initial_edits === 0 ? 'hit' : 'ok' ?>">

<div class="net-banner" id="netBanner" role="alert">You appear to be offline. Reconnect and we’ll keep going.</div>

<?php
  // Reveal-view header data: business name + current URL (for the dashed pill).
  $reveal_biz = $initial_biz ?: 'Your site';
  $reveal_host = '';
  if (!empty($current_url ?? '')) {
      $reveal_host = preg_replace('~^www\.~', '', (string)(parse_url($current_url, PHP_URL_HOST) ?: $current_url));
  } else if (!empty($website)) {
      $reveal_host = preg_replace('~^www\.~', '', (string)(parse_url($website, PHP_URL_HOST) ?: $website));
  }
?>
<header class="topbar">
  <a href="/try" class="brand">
    <span class="brand-icon"><img src="/preview/wizzy-face.png" alt=""></span>
    <span class="brand-stack">
      <span style="font-family:var(--display);font-weight:900;font-size:20px;letter-spacing:-0.03em;">WebWiz<span class="dot" style="color:var(--yellow);">.</span></span>
      <?php if ($initial_view === 'reveal' && $reveal_biz && $reveal_biz !== 'Your site'): ?><small>for <?= htmlspecialchars($reveal_biz, ENT_QUOTES) ?></small><?php endif; ?>
    </span>
  </a>
  <div class="hdr-center">
    <?php if ($reveal_host): ?>
    <span class="current-site-pill">Your current site<span class="cs-sub"><?= htmlspecialchars($reveal_host, ENT_QUOTES) ?></span></span>
    <?php endif; ?>
  </div>
  <div class="topbar-actions">
    <div class="device-toggle-floating" id="deviceToggleTop" role="tablist" aria-label="Preview viewport" style="display:none;">
      <button type="button" id="devDesktop" class="active" data-mode="desktop" role="tab" aria-selected="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="13" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
        Desktop
      </button>
      <button type="button" id="devMobile" data-mode="mobile" role="tab" aria-selected="false">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M11 18h2"/></svg>
        Mobile
      </button>
    </div>
    <button type="button" class="topbar-customize" id="topbarCustomize">Customize</button>
    <button type="button" class="topbar-buy" id="topbarBuy">Buy now $500</button>
  </div>
</header>
<!-- Floating chat FAB (only on reveal view, shown when panel is closed) -->
<button type="button" class="chat-fab" id="chatFab" aria-label="Open chat with Wizzy">
  <img src="/preview/wizzy-face.png" alt="Wizzy">
</button>

<!-- ===================== FORM VIEW ===================== -->
<main class="view view-form">
  <section class="hero">
    <div class="twz-center">
    <style>
    .view-form .hero{display:block!important;padding:26px 20px 60px}
    .twz-center{max-width:600px;margin:0 auto;text-align:center;display:flex;flex-direction:column;align-items:center}
    .twz-wiz{width:150px;height:150px;border-radius:50%;overflow:hidden;border:3px solid #0e1e3c;background:#fffdf8;box-shadow:6px 6px 0 #ffc531;position:relative;margin-bottom:22px}
    .twz-wiz .wizzy-vid{width:100%;height:100%;object-fit:cover;display:block}
    .twz-wiz .sticker{position:absolute;right:-16px;top:-8px;background:#ffc531;border:2px solid #0e1e3c;border-radius:9px;font-size:11px;font-weight:800;color:#0e1e3c;padding:5px 9px;transform:rotate(6deg);line-height:1.1;text-align:center;box-shadow:2px 2px 0 #0e1e3c}
    .twz-wiz .sticker small{display:block;font-weight:600;font-size:9px;text-transform:uppercase;letter-spacing:.04em}
    .view-form .hero .eyebrow{display:inline-block;background:#ffc531;color:#0e1e3c;font-weight:800;font-size:12.5px;letter-spacing:.05em;text-transform:uppercase;padding:8px 16px;border-radius:999px;border:2px solid #0e1e3c;box-shadow:3px 3px 0 #0e1e3c;margin-bottom:20px}
    .twz-h1{font-size:clamp(38px,6.4vw,64px);line-height:1.03;color:#0e1e3c;letter-spacing:-.02em;margin:0;font-weight:900}
    .twz-h1 .twz-free{background:linear-gradient(transparent 58%,#ffc531 58%);padding:0 2px}
    .twz-anchor{display:inline-flex;align-items:center;gap:12px;flex-wrap:wrap;justify-content:center;margin:22px 0 0;font-weight:800}
    .twz-anchor .old{color:#8a93a3;text-decoration:line-through;font-size:20px}
    .twz-anchor .arrow{color:#1a9f6b;font-size:20px}
    .twz-anchor .new{font-size:26px;color:#0e1e3c}
    .twz-anchor .save{background:#37c9a6;color:#04241c;font-size:13px;padding:6px 12px;border-radius:999px}
    .view-form .hero .lead{color:#6b7789;font-size:17px;max-width:510px;margin:18px auto 0;line-height:1.55}
    .view-form .hero .form-card{max-width:560px;width:100%;margin:30px auto 0;text-align:left}
    .view-form .hero .trust{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin:22px auto 0}
    .view-form .hero .wiz-testi{max-width:560px;width:100%;margin:40px auto 0}
    @media(max-width:640px){.twz-wiz{width:120px;height:120px}}
    </style>
    <div class="twz-wiz"><video class="wizzy-vid" autoplay muted playsinline loop preload="metadata" poster="/preview/wizzy-waving-poster.jpg" aria-label="Wizzy waving"><source src="/preview/wizzy-waving.webm" type="video/webm"><source src="/preview/wizzy-waving.mp4" type="video/mp4"><img src="/preview/wizzy-wave.gif" alt="Wizzy waving"></video></div>
      <span class="eyebrow">&#9733; Free to preview &middot; no card to try</span>
      <h1 class="twz-h1">See your new website, <span class="twz-free">free.</span></h1>
      <div class="twz-anchor"><span class="old">$5,000</span><span class="arrow">&rarr;</span><span class="new">$500 to launch</span><span class="save">Save $4,500</span></div>
      <p class="lead">Tell Wizzy about your business and he&rsquo;ll design it in about two minutes.</p>

      <form class="form-card" id="tryForm" novalidate>
        <div class="field" data-field="website">
          <label for="website">What&rsquo;s your website? <span class="opt-tag">(optional)</span></label>
          <input type="text" id="website" name="website" inputmode="url" autocomplete="url" placeholder="yourbusiness.com">
          <div class="field-helper">Have a site? Paste the link and Wizzy will match it. No site yet? Leave this blank and tell Wizzy about your business below.</div>
          <div class="err-msg">Add your website, or just your business name.</div>
        </div>
        <div class="field" data-field="lead">
          <style>
            .lead-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
            @media (max-width: 520px) { .lead-row { grid-template-columns: 1fr; } }
            .lead-col label { display: block; }
            .field[data-field="lead"] input { width: 100%; }
            .field[data-field="lead"].invalid input[type="email"] { border-color: #b34; outline: 2px solid rgba(179,68,68,0.18); }
            .field[data-field="lead"] .err-msg { color: #b34; font-size: 13px; margin-top: 6px; display: none; }
            .field[data-field="lead"].invalid .err-msg { display: block; }
          </style>
          <div class="lead-row">
            <div class="lead-col">
              <label for="lead_name">Your name</label>
              <input type="text" id="lead_name" name="name" autocomplete="name" placeholder="Your first name" required>
              <div class="err-msg" id="errName" style="color:#b34;font-size:13px;margin-top:6px;display:none;">Please add your name.</div>
            </div>
            <div class="lead-col">
              <label for="lead_email">Your email</label>
              <input type="email" id="lead_email" name="email" autocomplete="email" placeholder="you@yourbusiness.com" required>
              <div class="err-msg">Please add an email so we can save your preview.</div>
            </div>
          </div>
          <label for="lead_company" style="margin-top:14px;display:block;">Business name</label>
          <input type="text" id="lead_company" name="company" autocomplete="organization" placeholder="What&rsquo;s your business called?" required>
            <div class="err-msg" id="errCompany">Tell Wizzy your business name.</div>
        </div>
        <div class="field" data-field="description">
          <label for="description">Tell Wizzy about your business <span class="opt-tag">(required)</span></label>
          <textarea id="description" name="description" rows="4" placeholder="We&rsquo;re a family bakery in Pawtucket. Custom cakes, weekend pastries, been here 15 years."></textarea>
          <div class="field-helper">No website yet? Give Wizzy a few sentences about what you do and he&rsquo;ll design from scratch.</div>
          <div class="err-msg">Give Wizzy a few sentences about your business (at least 20 characters) so he can design the right site.</div>
        </div>
        <button type="submit" class="cta" id="ctaBtn">Make my website &rarr;</button>
        <p class="cta-microcopy">Free to preview &middot; $500 to launch &middot; no card to try.</p>
      </form>

      <div class="wiz-testi" id="wizTesti" aria-live="polite">
        <div class="wt-stars" aria-hidden="true">&#9733;&#9733;&#9733;&#9733;&#9733;</div>
        <p class="wt-quote">Sent Wizzy our shop info on a Tuesday. By the weekend we had a site that actually looked like our place.</p>
        <div class="wt-who">
          <img class="wt-face" src="https://images.unsplash.com/photo-1494790108377-be9c29b29330?w=120&amp;h=120&amp;fit=crop&amp;crop=face" alt="Maria R." width="44" height="44">
          <div class="wt-meta">
            <div class="wt-name">Maria R.</div>
            <div class="wt-role">Bakery owner &middot; Pawtucket, RI</div>
          </div>
        </div>
        <div class="wt-dots" role="tablist">
          <button class="wt-dot on" type="button" aria-label="Show testimonial 1" data-i="0"></button>
          <button class="wt-dot"    type="button" aria-label="Show testimonial 2" data-i="1"></button>
          <button class="wt-dot"    type="button" aria-label="Show testimonial 3" data-i="2"></button>
        </div>
      </div>
      <div class="trust">
        <span class="chip"><span class="tick">&#10003;</span> Free to preview</span>
        <span class="chip"><span class="tick">&#10003;</span> $500 to launch</span>
        <span class="chip"><span class="tick">&#10003;</span> Save $4,500 vs a designer</span>
      </div>
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
    <?php
      $__wwqa_default = [
        ['q'=>"First, what's the #1 job for your new site?", 'a'=>["Bring in more leads & calls","Sell products online","Take bookings or appointments","Just look more credible"]],
        ['q'=>"How do you get most of your customers today?", 'a'=>["Word of mouth & referrals","Social media","Paid ads","Honestly, not sure"]],
        ['q'=>"When do you want to be live?", 'a'=>["ASAP, this week","Within a month","Just exploring for now"]],
        ['q'=>"A designer would charge \$3,000 to \$5,000 to build this. At \$500, how does that feel?", 'a'=>["Honestly, a steal","Sounds fair","Depends what I get","Still a lot for me"]],
        ['q'=>"What's been holding back a new website?", 'a'=>["No time to deal with it","Too pricey until now","Didn't know where to start","The one I have is outdated"]],
        ['q'=>"Who is this site mainly for?", 'a'=>["Brand-new customers","Repeat customers","Partners or investors","Hiring & recruiting"]],
        ['q'=>"What would make you say yes today?", 'a'=>["Loving the design","Seeing it go live","A quick call to talk it through","Honestly, I'm ready now"]],
        ['q'=>"Roughly how many new customers do you get each week?", 'a'=>["Just a few (0-5)","A handful (5-20)","Quite a lot (20-50)","50 or more"]],
      ];
      $__wwqa = $__wwqa_default;
      try { if (function_exists('ww_db')) { $__wr = ww_db()->query("SELECT value FROM settings WHERE key='try_qa_json'")->fetchColumn(); if ($__wr) { $__wd = json_decode($__wr, true); if (is_array($__wd) && count($__wd)) $__wwqa = $__wd; } } } catch (Throwable $e) {}
    ?>
    <script>window.__WW_QA = <?= json_encode(array_values($__wwqa), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;</script>
    <div class="wq-card" id="wqCard" style="display:none">
      <div class="wq-head"><span class="wq-kicker">While Wizzy works&hellip;</span><span class="wq-count" id="wqCount">1 / 4</span></div>
      <p class="wq-q" id="wqQ"></p>
      <div class="wq-opts" id="wqOpts"></div>
      <p class="wq-skip"><button type="button" id="wqSkip">Skip</button></p>
      <div class="wq-done" id="wqDone" style="display:none"><p><strong>Perfect.</strong> Wizzy&rsquo;s using this to tailor your pitch. Almost done&hellip;</p></div>
    </div>
    <script>
    (function(){
      var QA = (window.__WW_QA && window.__WW_QA.length) ? window.__WW_QA : [
        { k:'goal',     q:"First — what's the #1 job for your new site?", a:["Bring in more leads & calls","Sell products online","Take bookings or appointments","Just look more credible"] },
        { k:'channel',  q:"How do you get most of your customers today?",     a:["Word of mouth & referrals","Social media","Paid ads","Honestly, not sure"] },
        { k:'timeline', q:"When do you want to be live?",                      a:["ASAP — this week","Within a month","Just exploring for now"] },
        { k:'budget',   q:"Roughly, what's your monthly marketing budget?",    a:["Under $500","$500 – $2,000","$2,000 – $5,000","$5,000+"] }
      ];
      var idx=0, token=null, answers=[], started=false;
      var card,qEl,optsEl,countEl,doneEl,skipEl;
      function save(done){
        if(!token) return;
        try{ fetch('/api/qa.php',{method:'POST',headers:{'Content-Type':'application/json'},keepalive:true,
          body:JSON.stringify({token:token,answers:answers,complete:done?1:0})}).catch(function(){}); }catch(e){}
      }
      function render(){
        if(idx>=QA.length){ finish(); return; }
        var item=QA[idx];
        countEl.textContent=(idx+1)+' / '+QA.length;
        qEl.textContent=item.q;
        optsEl.innerHTML='';
        item.a.forEach(function(opt){
          var b=document.createElement('button');
          b.type='button'; b.className='wq-opt'; b.textContent=opt;
          b.addEventListener('click',function(){
            answers.push({ q:item.q, a:opt });
            try{ if(typeof track==='function') track('qa_answer',{q:String(item.q).slice(0,50),a:String(opt).slice(0,60),n:idx+1}); }catch(e){}
            save(false); idx++; render();
          });
          optsEl.appendChild(b);
        });
      }
      function finish(){
        qEl.style.display='none'; optsEl.style.display='none';
        if(skipEl&&skipEl.parentNode) skipEl.parentNode.style.display='none';
        countEl.textContent=QA.length+' / '+QA.length;
        doneEl.style.display='block';
        try{ if(typeof track==='function') track('qa_completed',{answered:answers.length}); }catch(e){}
        save(true);
      }
      window.__wwStartQA=function(t){
        if(started) return; started=true; token=t;
        card=document.getElementById('wqCard'); qEl=document.getElementById('wqQ');
        optsEl=document.getElementById('wqOpts'); countEl=document.getElementById('wqCount');
        doneEl=document.getElementById('wqDone'); skipEl=document.getElementById('wqSkip');
        if(!card) return;
        if(skipEl){ skipEl.addEventListener('click',function(){ idx++; render(); }); }
        card.style.display='block'; render();
      };
      window.__wwStopQA=function(){ if(card) card.style.display='none'; };
    })();
    </script>
    <div class="late-fallback" id="lateFallback">
      <p><strong>Building your site takes 2&ndash;5 minutes.</strong><br>Drop your email and Wizzy will ping you the second it&rsquo;s ready. No need to wait on this page.</p>
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
    <div class="reveal-frame-wrap" id="revealFrameWrap">
      <div class="edit-overlay" id="editOverlay" aria-hidden="true">
        <div class="edit-overlay-avatar"><video autoplay muted playsinline loop preload="auto" poster="/preview/wizzy-processing-poster.jpg"><source src="/preview/wizzy-processing.webm" type="video/webm"><source src="/preview/wizzy-processing.mp4" type="video/mp4"></video></div>
        <div class="edit-overlay-text" id="editOverlayText">Wizzy is updating your site</div>
        <div class="edit-overlay-sub">Wizzy is redesigning your page — this can take a minute or two…</div>
        <div class="edit-overlay-dots"><span></span><span></span><span></span></div>
      </div>
      <div class="reveal-frame">
        <iframe id="previewFrame" src="<?= htmlspecialchars($initial_preview_url ?: 'about:blank', ENT_QUOTES) ?>" loading="eager" title="Your new website preview"></iframe>
      </div>
    </div>

    <aside class="edit-panel">
      <div class="edit-header">
        <div class="edit-header-wiz"><img src="/preview/wizzy-face.png" alt="Wizzy"></div>
        <h3>Chat with Wizzy</h3>
        <span class="edits-chip <?= $initial_edits === 0 ? 'zero' : '' ?>" id="editsChip"><?= (int)$initial_edits ?> edit<?= $initial_edits === 1 ? '' : 's' ?> remaining</span>
        <button type="button" class="copy-link-btn" id="copyLinkBtn" title="Copy a link to come back to this preview later">Copy link</button>
        <button type="button" class="chat-close" id="chatClose" aria-label="Minimize chat">&times;</button>
      </div>

      <div class="chat-history" id="chatHistory" aria-live="polite"><div class="msg msg-wiz"><img class="tinywiz" src="/preview/wizzy-face.png" alt=""><span>Here&rsquo;s what I made you. What do you think?</span></div></div>

      <div class="suggested-row" id="suggestedRow">
        <span class="sugchip" data-fill="Change the colors">Change the colors</span>
        <span class="sugchip" data-fill="Make it feel more modern">Make it feel more modern</span>
        <span class="sugchip" data-fill="Add a section about our services">Add a section about our services</span>
        <span class="sugchip" data-fill="Update the contact info">Update the contact info</span>
      </div>

      <div class="chat-input-row">
        <textarea id="chatInput" placeholder="Tell Wizzy what to tweak..."<?= $initial_edits === 0 ? ' readonly' : '' ?>></textarea>
        <div class="attach-strip" id="attachStrip"></div>
        <input type="file" id="refImgInput" accept="image/png,image/jpeg,image/webp,image/gif" multiple style="display:none">
        <div class="row">
          <div style="display:flex;gap:8px;align-items:center;">
            <button type="button" class="attach-btn" id="attachBtn" title="Attach or paste a reference image"<?= $initial_edits === 0 ? ' disabled' : '' ?>>&#128206;</button>
            <button type="button" class="iloveit" id="iLoveIt">I love it &rarr;</button>
          </div>
          <button type="button" class="send-btn" id="chatSend"<?= $initial_edits === 0 ? ' disabled' : '' ?>>Send &rarr;</button>
        </div>
        <style>
          .attach-strip{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 8px;}
          .attach-strip:empty{display:none;}
          .attach-thumb{position:relative;width:46px;height:46px;border:2px solid var(--navy);border-radius:8px;overflow:hidden;background:#fff;}
          .attach-thumb img{width:100%;height:100%;object-fit:cover;display:block;}
          .attach-thumb .x{position:absolute;top:-6px;right:-6px;width:18px;height:18px;border-radius:50%;background:var(--navy);color:#fff;border:0;font-size:12px;line-height:16px;cursor:pointer;padding:0;}
          .attach-btn{height:40px;padding:0 12px;background:var(--cream);color:var(--navy);border:2px solid var(--navy);border-radius:8px;font-size:16px;cursor:pointer;line-height:1;}
          .attach-btn:hover{background:var(--navy);color:var(--cream);}
          .attach-btn[disabled]{opacity:0.55;cursor:not-allowed;}
        </style>
      </div>

      <!-- ============ Phase 4 conversion card (lives in same panel) ============ -->
      <div class="conv-card" id="convCard">
        <div class="conv-head">
          <h2>Want to make it real?</h2>
          <div class="wiz-mini"><img src="/preview/wizzy-face.png" alt="Wizzy"></div>
        </div>
        <p class="conv-lead">Previewing is free. A custom website design typically runs $5,000. Yours is a flat $500 to finalize and launch, so you save about $4,500. Here&rsquo;s what that covers:</p>
        <ul class="conv-checklist">
          <li><span class="ck">&#10003;</span> Polish the design by hand (real human designer review)</li>
          <li><span class="ck">&#10003;</span> Set up your domain and point it to your new site</li>
          <li><span class="ck">&#10003;</span> Host it on our servers and keep it running</li>
          <li><span class="ck">&#10003;</span> Set up your business email</li>
          <li><span class="ck">&#10003;</span> Be on call for small tweaks the first 30 days</li>
        </ul>
        <div class="conv-price">
          <div class="anchor" style="font-size:14px;color:#6a7a8a;margin-bottom:2px;">Typical website design <s>$5,000</s></div>
          <div class="big">$500 to launch</div>
          <div class="save" style="font-size:14px;font-weight:800;color:#1a7f4b;margin-top:2px;">You save $4,500</div>
          <div class="sub">+ $50/month for hosting &amp; care</div>
          <div class="note">Cancel hosting anytime. The site stays yours either way.</div>
        </div>
        <button type="button" class="conv-cta" id="convCta">Launch my site for $500 &rarr;</button>
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
  // Seed the share URL when the page is hydrated with an existing token (?t=<token>).
  try { var __seed = <?= json_encode($initial_token ?: '') ?>; if (__seed) { window.__wwShareUrl = window.location.origin + '/try/?t=' + encodeURIComponent(__seed); } } catch(e){}
  // Topbar "Buy the site" button mirrors the convCta path (Stripe checkout).
  var topbarBuy = document.getElementById('topbarBuy');
  if (topbarBuy) {
    topbarBuy.addEventListener('click', function () {
      try { track('topbar_buy_click'); } catch (e) {}
      var cc = document.getElementById('convCta');
      if (cc) { cc.click(); return; }
      // Fallback if convCta isn't bound yet — set conv view directly.
      body.setAttribute('data-conv', 'open');
      var convCard = document.getElementById('convCard');
      if (convCard) convCard.scrollIntoView({behavior: 'smooth', block: 'center'});
    });
  }
  // Desktop/Mobile viewport toggle on the iframe wrapper.
  var devDesktop = document.getElementById('devDesktop');
  var devMobile = document.getElementById('devMobile');
  var revealFrameWrap = document.getElementById('revealFrameWrap');
  function setDevice(mode) {
    if (!revealFrameWrap) return;
    if (mode === 'mobile') {
      revealFrameWrap.classList.add('device-mobile');
      if (devMobile) { devMobile.classList.add('active'); devMobile.setAttribute('aria-selected','true'); }
      if (devDesktop) { devDesktop.classList.remove('active'); devDesktop.setAttribute('aria-selected','false'); }
    } else {
      revealFrameWrap.classList.remove('device-mobile');
      if (devDesktop) { devDesktop.classList.add('active'); devDesktop.setAttribute('aria-selected','true'); }
      if (devMobile) { devMobile.classList.remove('active'); devMobile.setAttribute('aria-selected','false'); }
    }
    try { track('device_toggle', { mode: mode }); } catch (e) {}
  }
  if (devDesktop) devDesktop.addEventListener('click', function () { setDevice('desktop'); });
  if (devMobile)  devMobile.addEventListener('click',  function () { setDevice('mobile'); });

  // Floating chat widget: FAB <-> panel toggle
  var chatFab = document.getElementById('chatFab');
  var chatClose = document.getElementById('chatClose');
  function setChat(state) {
    body.setAttribute('data-chat', state === 'open' ? 'open' : 'closed');
    try { track('chat_' + state); } catch (e) {}
  }
  if (chatFab) chatFab.addEventListener('click', function () { setChat('open'); chatInput.focus(); });
  if (chatClose) chatClose.addEventListener('click', function () { setChat('closed'); });
  // Topbar "Customize" button opens the chat panel
  var topbarCustomize = document.getElementById('topbarCustomize');
  if (topbarCustomize) {
    topbarCustomize.addEventListener('click', function () { setChat('open'); if (chatInput) chatInput.focus(); try { track('topbar_customize_click'); } catch(e){} });
  }

  // If page is already hydrated to reveal (via /try/?t=<token>), init chrome open.
  if (body.getAttribute('data-view') === 'reveal') {
    var dtNow = document.getElementById('deviceToggleTop');
    if (dtNow) dtNow.style.display = 'inline-flex';
    body.setAttribute('data-chat-init', '1');
    setChat('open');
  }

  // Copy link button: copies the resumable /try/?t=<token> URL to the clipboard.
  var copyLinkBtn = document.getElementById('copyLinkBtn');
  if (copyLinkBtn) {
    copyLinkBtn.addEventListener('click', function () {
      var url = window.__wwShareUrl || window.location.href;
      var done = function () {
        copyLinkBtn.textContent = 'Copied!';
        copyLinkBtn.classList.add('copied');
        setTimeout(function () { copyLinkBtn.textContent = 'Copy link'; copyLinkBtn.classList.remove('copied'); }, 2000);
        try { track('share_link_copied'); } catch (e) {}
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(done).catch(function () {
          window.prompt('Copy this link to come back later:', url);
        });
      } else {
        window.prompt('Copy this link to come back later:', url);
      }
    });
  }
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

  var statusMessages = ['Reading your business…','Sketching your layout…','Picking your colors…','Choosing fonts that fit you…','Writing your hero copy…','Wizzy is working fast…','Adding the finishing touches…'];
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

  function setView(v){
    body.setAttribute('data-view', v);
    window.scrollTo({top:0, behavior:'smooth'});
    // On reveal: show device toggle in topbar; chat starts OPEN.
    var dt = document.getElementById('deviceToggleTop');
    if (dt) dt.style.display = (v === 'reveal' ? 'inline-flex' : 'none');
    if (v === 'reveal' && !body.hasAttribute('data-chat-init')) {
      body.setAttribute('data-chat-init', '1');
      body.setAttribute('data-chat', 'open');
    } else if (v !== 'reveal') {
      body.removeAttribute('data-chat');
    }
  }

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
    lateTimer = setTimeout(function(){ lateFallback.classList.add('on'); }, 5000);
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
    // Description is REQUIRED (>= MIN_DESC) so Wizzy always has real content to design from,
    // even when the website scrape fails. Website stays optional.
    var webVal  = (web.value || '').trim();
    var descVal = (desc.value || '').trim();
    var hasWeb  = webVal !== '';
    var hasDesc = descVal.length >= MIN_DESC;
    if (!hasDesc) {
      descField.classList.add('invalid');
      desc.focus(); return;
    }
    if (hasWeb) {
      var webOk = (/\./.test(webVal) ? validateWebsite() : true);
      websiteField.classList.toggle('invalid', !webOk);
      if (!webOk) { web.focus(); return; }
    } else {
      websiteField.classList.remove('invalid');
    }
    descField.classList.remove('invalid');
    // Email is required for the nurture sequence.
    var emailEl = document.getElementById('lead_email');
    var emailVal = ((emailEl && emailEl.value) || '').trim();
    var emailOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal);
    var leadField = emailEl ? emailEl.closest('.field') : null;
    if (leadField) leadField.classList.toggle('invalid', !emailOk);
    if (!emailOk) { if (emailEl) emailEl.focus(); return; }

    var nameEl = document.getElementById('lead_name');
    var nameVal = ((nameEl && nameEl.value) || '').trim();
    var errName = document.getElementById('errName');
    if (!nameVal) { if (nameEl) nameEl.focus(); if (errName) errName.style.display = 'block'; return; }
    if (errName) errName.style.display = 'none';

    track('form_submit', { has_website: !!web.value.trim(), description_length: desc.value.trim().length });
    track('gen_started');
    var __genT0 = Date.now();
    generating = true; ctaBtn.disabled = true;
    var hostGuess = '';
    try { var raw = web.value.trim().replace(/^https?:\/\//i,'').replace(/^www\./i,''); hostGuess = raw.split('/')[0].split('?')[0]; } catch(e){}
    loadingHead.innerHTML = 'Wizzy is designing ' + (hostGuess ? escapeHtml(hostGuess) : 'your site') + '…';
    setView('loading'); startLoadingTickers();

    var leadName    = (document.getElementById('lead_name')    || {}).value || '';
    var leadEmail   = (document.getElementById('lead_email')   || {}).value || '';
    var leadCompany = (document.getElementById('lead_company') || {}).value || '';
    if (!leadCompany.trim()) {
      var compEl = document.getElementById('lead_company');
      if (compEl) {
        compEl.focus();
        compEl.style.borderColor = '#8a0e0e';
      }
      var errC = document.getElementById('errCompany'); if (errC) errC.style.display = 'block';
      return;
    }
    // ASYNC generation: get a token in ~1s, then poll a lightweight status endpoint until the
    // site is built. This eliminates the long held request that used to time out mid-build.
    var __openReveal = function(previewUrl, token){
      progFill.style.width = '100%'; loadingStatus.textContent = 'Ready! Opening your preview…';
      stopLoadingTickers();
      try { window.__wwStopQA && window.__wwStopQA(); } catch(e){}
      track('gen_completed', { duration_ms: Date.now() - __genT0 });
      setTimeout(function(){ previewFrame.src = previewUrl; setView('reveal'); chatInput.focus(); track('reveal_viewed');
        try { window.history.replaceState({t: token}, '', '/try/?t=' + encodeURIComponent(token)); window.__wwShareUrl = window.location.origin + '/try/?t=' + encodeURIComponent(token); } catch(e){}
      }, 500);
    };
    fetch('/api/magic.php?async=1', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({
        website: web.value.trim(), description: desc.value.trim(),
        name: leadName.trim(), email: leadEmail.trim(), company: leadCompany.trim(),
        describe: (web.value.trim() === '' ? 1 : 0)
      })
    })
    .then(function(r){ return r.json().then(function(j){ return { ok: r.ok, body: j }; }); })
    .then(function(res){
      var b = res.body || {};
      if (!(b.ok && b.token)) {
        var msg = b.error || 'Generation failed. Try again?';
        track('gen_failed', { reason: String(msg).slice(0,140), duration_ms: Date.now() - __genT0 });
        showLoadingError(msg); ctaBtn.disabled = false; return;
      }
      state.token = b.token;
      try { window.__wwStartQA && window.__wwStartQA(b.token); } catch(e){}
      state.businessName = leadCompany.trim() || 'your site';
      loadingHead.innerHTML = 'Wizzy is designing ' + escapeHtml(state.businessName) + '…';
      var polls = 0, maxPolls = 100; // ~5 min ceiling at 3s
      var poll = function(){
        polls++;
        if (polls > maxPolls) { track('gen_failed', { reason:'poll_timeout', duration_ms: Date.now()-__genT0 }); showLoadingError('This is taking longer than usual. Drop your email above and we&rsquo;ll send your site the moment it&rsquo;s ready.'); ctaBtn.disabled = false; return; }
        fetch('/api/gen_status.php?t=' + encodeURIComponent(b.token), { headers:{'Accept':'application/json'} })
          .then(function(r){ return r.json(); })
          .then(function(s){
            if (s.status === 'ready') { __openReveal(s.preview_url || ('/preview/' + b.token + '/v1/index.html'), b.token); }
            else if (s.status === 'failed') { track('gen_failed', { reason: String(s.error||'failed').slice(0,140), duration_ms: Date.now()-__genT0 }); showLoadingError(s.error || 'Wizzy hit a snag building that one. Please try again.'); ctaBtn.disabled = false; }
            else { setTimeout(poll, 3000); }
          })
          .catch(function(){ setTimeout(poll, 3500); });
      };
      setTimeout(poll, 4000);
    })
    .catch(function(e){
      var em = e && e.message ? e.message : 'unknown';
      track('gen_failed', { reason: 'network: ' + String(em).slice(0,120), duration_ms: Date.now() - __genT0 });
      showLoadingError('Network error: ' + em); ctaBtn.disabled = false;
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
      var img = document.createElement('img'); img.className='tinywiz'; img.src='/preview/wizzy-face.png'; img.alt='Wizzy';
      div.appendChild(img);
      var span = document.createElement('span'); span.textContent = text; div.appendChild(span);
    } else { div.textContent = text; }
    chatHistory.appendChild(div); chatHistory.scrollTop = chatHistory.scrollHeight;
    return div;
  }
  function appendTyping(label){
    var div = document.createElement('div'); div.className = 'msg msg-wiz typing';
    var img = document.createElement('img'); img.className='tinywiz'; img.src='/preview/wizzy-face.png'; img.alt='Wizzy';
    div.appendChild(img);
    var span = document.createElement('span'); span.textContent = label || 'On it…'; div.appendChild(span);
    chatHistory.appendChild(div); chatHistory.scrollTop = chatHistory.scrollHeight;
    return div;
  }
  // Edit-in-progress overlay over the iframe
  var editOverlay = document.getElementById('editOverlay');
  var editOverlayText = document.getElementById('editOverlayText');
  var editOverlayMessages = ['Wizzy is updating your site','Tweaking the design','Making it just right','Almost there'];
  var editOverlayTimer = null;
  function showEditOverlay(){
    if (!editOverlay) return;
    editOverlay.classList.add('on');
    editOverlay.setAttribute('aria-hidden','false');
    var i = 0;
    if (editOverlayText) editOverlayText.textContent = editOverlayMessages[0];
    if (editOverlayTimer) clearInterval(editOverlayTimer);
    editOverlayTimer = setInterval(function(){
      i = (i + 1) % editOverlayMessages.length;
      if (editOverlayText) editOverlayText.textContent = editOverlayMessages[i];
    }, 2200);
  }
  function hideEditOverlay(){
    if (!editOverlay) return;
    editOverlay.classList.remove('on');
    editOverlay.setAttribute('aria-hidden','true');
    if (editOverlayTimer) { clearInterval(editOverlayTimer); editOverlayTimer = null; }
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

  // Detect pure-conversational messages (compliments/thanks/etc.) — respond
  // in chat WITHOUT triggering a re-gen and WITHOUT spending an edit credit.
  function classifyMessage(m){
    var s = (m || '').toLowerCase().trim();
    if (!s) return 'empty';
    // Praise / acceptance ("looks great", "i love it", "perfect", "thanks", "nice")
    var praise = /^(thanks?|thank you|ty|amazing|awesome|love it|i love it|perfect|great|looks great|looks good|nice|sweet|beautiful|gorgeous|good job|nice job|well done|so good|wow|cool|sick|fire|dope|fantastic|excellent|brilliant|incredible)[\.\!\s]*$/;
    // Negative / confused but no specific change requested ("hmm", "not sure", "idk")
    var maybe = /^(hmm+|idk|not sure|maybe|ehh|meh)[\.\!\?\s]*$/;
    // Hello / banter
    var hello = /^(hi|hey|hello|yo|howdy|sup|what'?s up)[\.\!\,\?\s]*$/;
    // Direct question that doesn't ask for change ("what do you think", "any tips")
    var question = /^(what do you think|any tips|any ideas|what would you do|what should i do|is this good|does this look ok|does this look good)\??$/;
    if (praise.test(s))   return 'praise';
    if (maybe.test(s))    return 'maybe';
    if (hello.test(s))    return 'hello';
    if (question.test(s)) return 'question';
    // Heuristic: very short message (<3 words) with no edit verbs is probably chat
    var editVerbs = /\b(change|add|remove|delete|update|swap|make|use|try|set|put|move|fix|tweak|adjust|smaller|bigger|larger|lighter|darker|brighter|red|blue|green|yellow|black|white|hide|show|replace|insert|font|color|colour|button|hero|section|background|text|image|logo|header|footer|menu|nav|paragraph|line|words?)\b/i;
    var wordCount = s.split(/\s+/).length;
    if (wordCount <= 5 && !editVerbs.test(s)) return 'chat';
    return 'edit';
  }
  function conversationalReply(kind){
    var replies = {
      praise:   ['Glad you like it! ✨ Want me to tweak anything else?', 'Heyyy thank you! Anything else I can adjust?', "Appreciate it. Let me know if you want me to refine anything else."],
      maybe:    ["No worries — what feels off? Tell me what you'd want different.", "Want me to try a different vibe? Tell me what's not landing."],
      hello:    ['Hey! I built this site for you. Tell me anything you want changed — colors, copy, sections, anything.'],
      question: ["I think it's a solid start. Tell me one thing that feels off and I'll fix it."],
      chat:     ["I'm here! Tell me what you'd like me to change.", "Yep, listening — tell me what to tweak."]
    };
    var arr = replies[kind] || replies.chat;
    return arr[Math.floor(Math.random() * arr.length)];
  }

  function sendEdit(message){
    if (!state.token || state.sending || state.editsRemaining <= 0) return;
    var imgs = (typeof refImages !== 'undefined' ? refImages.slice() : []);

    // Conversational shortcut only when there are NO attached images.
    if (imgs.length === 0) {
      var kind = classifyMessage(message);
      if (kind === 'praise' || kind === 'maybe' || kind === 'hello' || kind === 'question' || kind === 'chat') {
        appendMsg('user', message); chatInput.value = '';
        setTimeout(function(){ appendMsg('wiz', conversationalReply(kind)); }, 350);
        try { track('chat_only', { kind: kind, len: message.length }); } catch(e){}
        return;
      }
    }
    if (!message && imgs.length) message = 'Use the attached reference image(s) to guide this edit.';

    state.sending = true; chatSend.disabled = true;
    appendMsg('user', message + (imgs.length ? ('  📎 ' + imgs.length + ' image' + (imgs.length > 1 ? 's' : '')) : '')); chatInput.value = '';
    if (typeof refImages !== 'undefined') { refImages.length = 0; renderRefThumbs(); }
    // Text-message style: drop a short "On it..." reply + show overlay over iframe
    var typing = appendTyping('On it…');
    showEditOverlay();
    track('edit_used', { edit_number: (EDIT_CAP - state.editsRemaining) + 1, edit_type: 'text', message: message.slice(0, 140) });

    var editCtl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    var editTimedOut = false;
    var editTimer = setTimeout(function(){ editTimedOut = true; if (editCtl) try { editCtl.abort(); } catch(_){} }, 345000);

    fetch('/api/edit.php', {
      method: 'POST',
      headers: { 'Content-Type':'application/json', 'Accept':'application/json' },
      body: JSON.stringify({ token: state.token, message: message, images: imgs }),
      signal: editCtl ? editCtl.signal : undefined
    })
    .then(function(r){ return r.json().then(function(j){ return { ok:r.ok, body:j }; }); })
    .then(function(res){
      typing.remove();
      hideEditOverlay();
      var b = res.body || {};
      if (b.ok) {
        var src = b.preview_url || (('/preview/' + state.token + '/v1/index.html?e=' + Date.now()));
        previewFrame.src = src;
        appendMsg('wiz', b.reply || "Done! Take a look 👀");
        updateEditsChip(typeof b.edits_remaining === 'number' ? b.edits_remaining : (state.editsRemaining - 1));
        if (b.cap_hit) onCapHit();
      } else if (b.cap_hit) {
        updateEditsChip(0); onCapHit(b.reply);
      } else if (b.needs_input) {
        appendMsg('wiz', b.reply || "I need a little more to do that — could you clarify, or upload the file with the 📎 button?");
      } else if (b.system_error) {
        appendMsg('wiz', b.error || 'Something broke on our end — we\'ve been alerted. Give it a sec and try again.');
      } else {
        appendMsg('wiz', 'Hmm, that one didn\'t take. ' + (b.error || 'Try wording it a different way?'));
      }
    })
    .catch(function(e){
      typing.remove(); hideEditOverlay();
      if (editTimedOut || (e && e.name === 'AbortError')) {
        appendMsg('wiz', "That was a big edit and it's taking too long to finish. Try it in smaller steps — e.g. change the style first, then add one feature at a time. (We've been alerted.)");
        try { track('edit_timeout', { message: (message||'').slice(0,140) }); } catch(_){}
      } else {
        appendMsg('wiz', 'Network hiccup — try again? (' + (e && e.message || 'unknown') + ')');
      }
    })
    .finally(function(){
      clearTimeout(editTimer);
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
    if(window.wwMetaTrack){try{window.wwMetaTrack('AddToCart',{content_name:'make_it_real',content_category:'website_build',value:500,currency:'USD'});}catch(e){}}
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
      if (b.ok && b.checkout_url) { track('checkout_started', { session_id: b.session_id || null }); if(window.wwMetaTrack){try{window.wwMetaTrack('InitiateCheckout',{content_name:'try_checkout',content_category:'website_build',value:500,currency:'USD'});}catch(e){}} window.location.href = b.checkout_url; return; }
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

  // ---------- reference image attach (paste + upload) ----------
  var refImages = [];
  var attachBtn = document.getElementById('attachBtn');
  var refImgInput = document.getElementById('refImgInput');
  var attachStrip = document.getElementById('attachStrip');
  var MAX_REF = 3;
  function renderRefThumbs(){
    if (!attachStrip) return;
    attachStrip.innerHTML = '';
    refImages.forEach(function(src, i){
      var d = document.createElement('div'); d.className = 'attach-thumb';
      var im = document.createElement('img'); im.src = src; d.appendChild(im);
      var x = document.createElement('button'); x.type = 'button'; x.className = 'x'; x.textContent = '×';
      x.addEventListener('click', function(){ refImages.splice(i, 1); renderRefThumbs(); });
      d.appendChild(x); attachStrip.appendChild(d);
    });
  }
  function addRefImageFile(file){
    if (!file || refImages.length >= MAX_REF || !/^image\//.test(file.type || '')) return;
    var reader = new FileReader();
    reader.onload = function(ev){
      var img = new Image();
      img.onload = function(){
        var max = 1280, w = img.width, h = img.height;
        if (w > max || h > max){ if (w >= h){ h = Math.round(h * max / w); w = max; } else { w = Math.round(w * max / h); h = max; } }
        var c = document.createElement('canvas'); c.width = w; c.height = h;
        c.getContext('2d').drawImage(img, 0, 0, w, h);
        var url; try { url = c.toDataURL('image/jpeg', 0.82); } catch(e){ url = ev.target.result; }
        if (refImages.length < MAX_REF){ refImages.push(url); renderRefThumbs(); }
      };
      img.src = ev.target.result;
    };
    reader.readAsDataURL(file);
  }
  if (attachBtn) attachBtn.addEventListener('click', function(){ if (state.editsRemaining > 0 && refImgInput) refImgInput.click(); });
  if (refImgInput) refImgInput.addEventListener('change', function(e){ var fs = e.target.files || []; for (var i = 0; i < fs.length; i++) addRefImageFile(fs[i]); refImgInput.value = ''; });
  if (chatInput) chatInput.addEventListener('paste', function(e){
    var items = (e.clipboardData && e.clipboardData.items) || [];
    for (var i = 0; i < items.length; i++){
      if (items[i].type && items[i].type.indexOf('image') === 0){ var f = items[i].getAsFile(); if (f){ addRefImageFile(f); e.preventDefault(); } }
    }
  });

  chatSend.addEventListener('click', function(){
    var m = (chatInput.value || '').trim();
    if (m.length < 3 && refImages.length === 0) { chatInput.focus(); return; }
    sendEdit(m);
  });
  chatInput.addEventListener('keydown', function(e){
    // Enter sends. Shift+Enter inserts a newline (standard chat UX).
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); chatSend.click(); }
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

  // ---------- Meta Pixel: ViewContent / Lead / CompleteRegistration ----------
  function wwMetaInit(){
    if(!window.wwMetaTrack) return;
    if(INIT.view === 'form'){
      window.wwMetaTrack('ViewContent', {content_name:'try_landing', content_category:'lead_gen'});
    } else if(INIT.view === 'reveal'){
      window.wwMetaTrack('ViewContent', {content_name:'try_reveal', content_category:'preview_view'});
    }
    var tryForm = document.getElementById('tryForm');
    if(tryForm){
      tryForm.addEventListener('submit', function(){
        var website = (document.getElementById('website')||{}).value || '';
        var desc    = (document.getElementById('description')||{}).value || '';
        window.wwMetaTrack('Lead',
          {content_name:'try_form_submit', content_category:'lead_gen', value:500, currency:'USD'},
          {}
        );
      }, {capture:true, once:true});
    }
    var notifyForm = document.getElementById('notifyForm');
    if(notifyForm){
      notifyForm.addEventListener('submit', function(){
        var em = (document.getElementById('notifyEmail')||{}).value || '';
        em = em.trim();
        if(!em) return;
        window.wwMetaTrack('CompleteRegistration',
          {content_name:'try_email_capture', content_category:'lead_gen', value:0, currency:'USD'},
          {email: em}
        );
      }, {capture:true, once:true});
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', wwMetaInit);
  else wwMetaInit();
})();
</script>
<script id="wiz-testi-js">
(function(){
  var box = document.getElementById("wizTesti");
  if (!box) return;
  var data = [
    {q: "Sent Wizzy our shop info on a Tuesday. By the weekend we had a site that actually looked like our place.",
     name: "Maria R.", role: "Bakery owner · Pawtucket, RI",
     face: "https://images.unsplash.com/photo-1494790108377-be9c29b29330?w=120&h=120&fit=crop&crop=face"},
    {q: "Filling out the form took five minutes. The site was up the same day. My business looks legit online for the first time ever.",
     name: "Jake M.", role: "Contractor · Tulsa, OK",
     face: "https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=120&h=120&fit=crop&crop=face"},
    {q: "I put off building a site for two years. Wizzy did it in an afternoon and handled the domain. Wish I had done this sooner.",
     name: "Sarah K.", role: "Salon owner · Austin, TX",
     face: "https://images.unsplash.com/photo-1438761681033-6461ffad8d80?w=120&h=120&fit=crop&crop=face"}
  ];
  var q  = box.querySelector(".wt-quote");
  var nm = box.querySelector(".wt-name");
  var rl = box.querySelector(".wt-role");
  var fc = box.querySelector(".wt-face");
  var dots = box.querySelectorAll(".wt-dot");
  var i = 0, timer = null;
  function render(n) {
    if (!q) return;
    q.classList.add("fade");
    if (fc) fc.classList.add("fade");
    setTimeout(function(){
      var d = data[n];
      q.textContent  = d.q;
      nm.textContent = d.name;
      rl.textContent = d.role;
      if (fc) { fc.src = d.face; fc.alt = d.name; }
      for (var k = 0; k < dots.length; k++) {
        if (k === n) dots[k].classList.add("on"); else dots[k].classList.remove("on");
      }
      q.classList.remove("fade");
      if (fc) fc.classList.remove("fade");
    }, 240);
  }
  function next() { i = (i + 1) % data.length; render(i); }
  function start() { stop(); timer = setInterval(next, 5400); }
  function stop()  { if (timer) { clearInterval(timer); timer = null; } }
  for (var k = 0; k < dots.length; k++) {
    (function(idx){
      dots[idx].addEventListener("click", function(){ i = idx; render(i); start(); });
    })(k);
  }
  box.addEventListener("mouseenter", stop);
  box.addEventListener("mouseleave", start);
  start();
})();
</script>
</body></html>
