<?php
// /try/ — dual-mode landing.
// 1. /try/ (no params) → ad-funnel landing page (form → loading → reveal flow).
// 2. /try/?website=... → existing cold-email magic-link loading screen.
$website = trim((string)($_GET['website'] ?? $_GET['url'] ?? ''));
$name    = trim((string)($_GET['name'] ?? ''));
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

// ===== NEW AD-FUNNEL LANDING PAGE =====
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Your new website. Free. · WebWiz</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="image" href="/preview/wizzy-wave.gif">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Nunito:wght@700;800;900&display=swap" rel="stylesheet">
<style>
  /* ---- Brand tokens (strict palette) ---- */
  :root{
    --teal:#00C4A7;
    --navy:#12184A;
    --yellow:#FFBE00;
    --cream:#FFF8E7;
    --display:'Nunito',system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
    --body:'Inter',system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
  }
  *,*::before,*::after{box-sizing:border-box;}
  html,body{margin:0;padding:0;}
  body{
    font-family:var(--body);
    color:var(--navy);
    background:var(--cream);
    background-image:radial-gradient(rgba(18,24,74,0.07) 1.5px, transparent 1.5px);
    background-size:24px 24px;
    line-height:1.5;
    font-size:16px;
    -webkit-font-smoothing:antialiased;
    text-rendering:optimizeLegibility;
    min-height:100vh;
  }
  a{color:var(--navy);}

  /* ---- View state machine ---- */
  .view{opacity:0;visibility:hidden;height:0;overflow:hidden;transition:opacity 400ms ease;}
  body[data-view="form"]    .view-form    { opacity:1;visibility:visible;height:auto;overflow:visible; }
  body[data-view="loading"] .view-loading { opacity:1;visibility:visible;height:auto;overflow:visible; }
  body[data-view="reveal"]  .view-reveal  { opacity:1;visibility:visible;height:auto;overflow:visible; }

  /* ---- Header ---- */
  header.topbar{padding:20px 32px;display:flex;align-items:center;justify-content:space-between;}
  header.topbar .brand{font-family:var(--display);font-weight:900;font-size:22px;letter-spacing:-0.03em;color:var(--navy);text-decoration:none;}
  header.topbar .brand .dot{color:var(--yellow);}

  /* ---- Hero ---- */
  main{padding:24px 32px 80px;max-width:1280px;margin:0 auto;}
  .hero{display:grid;grid-template-columns:1.5fr 1fr;gap:48px;align-items:center;margin-top:24px;}
  .hero-copy{max-width:640px;}
  .eyebrow{display:inline-block;background:var(--yellow);color:var(--navy);border:2px solid var(--navy);border-radius:999px;padding:6px 14px;font-family:var(--body);font-weight:600;font-size:12px;letter-spacing:0.14em;text-transform:uppercase;margin-bottom:18px;}
  h1{font-family:var(--display);font-weight:900;font-size:72px;letter-spacing:-0.03em;line-height:1.02;color:var(--navy);margin:0 0 18px;}
  h1 .parens{display:block;font-size:0.55em;font-weight:800;letter-spacing:-0.02em;opacity:0.85;margin-top:6px;}
  .lead{font-size:22px;line-height:1.45;color:var(--navy);opacity:0.85;margin:0 0 28px;font-weight:400;}

  /* ---- Form card ---- */
  .form-card{background:var(--cream);border:2px solid var(--navy);border-radius:16px;padding:32px;box-shadow:6px 6px 0 var(--navy);max-width:560px;}
  .form-card label{display:block;font-family:var(--body);font-weight:600;font-size:14px;color:var(--navy);margin-bottom:8px;letter-spacing:0.01em;}
  .form-card label .opt{font-weight:400;font-size:12px;color:rgba(18,24,74,0.6);margin-left:6px;}
  .form-card input[type=text],.form-card textarea,.form-card input[type=email]{
    width:100%;background:var(--cream);border:2px solid var(--navy);border-radius:8px;
    padding:14px 16px;font-family:var(--body);font-size:16px;color:var(--navy);font-weight:400;
    outline:none;transition:box-shadow 120ms ease;
  }
  .form-card input[type=text],.form-card input[type=email]{height:48px;}
  .form-card textarea{min-height:108px;resize:vertical;line-height:1.45;}
  .form-card input:focus,.form-card textarea:focus{box-shadow:0 0 0 3px rgba(0,196,167,0.35);}
  .form-card .field+.field{margin-top:18px;}
  .form-card .err-msg{display:none;color:#8a0e0e;font-size:13px;font-weight:500;margin-top:6px;}
  .form-card .field.invalid input,.form-card .field.invalid textarea{border-color:#8a0e0e;}
  .form-card .field.invalid .err-msg{display:block;}

  .cta{
    display:flex;align-items:center;justify-content:center;
    width:100%;height:56px;margin-top:24px;
    background:var(--yellow);color:var(--navy);border:2px solid var(--navy);border-radius:12px;
    font-family:var(--display);font-weight:900;font-size:18px;letter-spacing:-0.01em;
    cursor:pointer;transition:transform 120ms ease,box-shadow 120ms ease;
    box-shadow:0 0 0 transparent;
  }
  .cta:hover{transform:translate(-2px,-2px);box-shadow:4px 4px 0 var(--navy);}
  .cta:active{transform:translate(0,0);box-shadow:0 0 0 var(--navy);}
  .cta[disabled]{opacity:0.55;cursor:not-allowed;transform:none;box-shadow:none;}

  /* ---- Trust chips ---- */
  .trust{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px;}
  .chip{display:inline-flex;align-items:center;gap:6px;background:var(--cream);border:2px solid var(--navy);border-radius:999px;padding:8px 16px;font-family:var(--body);font-weight:600;font-size:14px;color:var(--navy);}
  .chip .tick{color:var(--teal);font-weight:900;}

  /* ---- Wizzy column (hero) ---- */
  .hero-mascot{position:relative;display:flex;justify-content:center;align-items:center;min-height:520px;}
  .wiz-circle{width:480px;height:480px;border-radius:50%;background:var(--cream);border:3px solid var(--navy);display:flex;align-items:center;justify-content:center;overflow:hidden;position:relative;box-shadow:8px 8px 0 var(--yellow);}
  .wiz-circle img{width:78%;height:78%;object-fit:contain;}
  .sticker{
    position:absolute;top:8px;right:-4px;transform:rotate(12deg);
    background:var(--yellow);color:var(--navy);border:2px solid var(--navy);border-radius:14px;
    padding:10px 14px;font-family:var(--display);font-weight:900;font-size:14px;letter-spacing:-0.01em;
    box-shadow:4px 4px 0 var(--navy);line-height:1.1;text-align:center;
  }
  .sticker small{display:block;font-family:var(--body);font-weight:600;font-size:10px;letter-spacing:0.12em;text-transform:uppercase;margin-top:2px;opacity:0.85;}

  /* ============================================================ */
  /* ----                  LOADING VIEW                       ---- */
  /* ============================================================ */
  .view-loading{padding:40px 24px 60px;}
  .loading-wrap{max-width:600px;margin:0 auto;text-align:center;}
  .loading-mascot{
    width:280px;height:280px;margin:0 auto 8px;display:flex;align-items:center;justify-content:center;
    animation:bob 2s ease-in-out infinite;
  }
  .loading-mascot img{width:100%;height:100%;object-fit:contain;}
  @keyframes bob{
    0%,100%{transform:translateY(0);}
    50%{transform:translateY(-8px);}
  }
  .loading-h2{
    font-family:var(--display);font-weight:900;font-size:48px;letter-spacing:-0.02em;line-height:1.05;
    color:var(--navy);margin:24px 0 0;
  }
  .loading-sub{font-size:16px;color:rgba(18,24,74,0.7);margin:8px 0 0;}

  .progress-track{
    margin:24px auto 0;max-width:520px;height:12px;background:var(--cream);
    border:2px solid var(--navy);border-radius:999px;overflow:hidden;
  }
  .progress-fill{
    display:block;height:100%;width:5%;background:var(--teal);
    border-radius:999px;transition:width 600ms ease;
  }
  .loading-status{
    font-family:var(--body);font-weight:500;font-size:16px;color:var(--teal);
    margin:16px 0 0;min-height:22px;
    transition:opacity 250ms ease;
  }

  .powered-chip{
    display:inline-block;margin-top:32px;
    background:var(--navy);color:var(--cream);
    font-family:var(--body);font-weight:600;font-size:12px;letter-spacing:0.14em;text-transform:uppercase;
    padding:6px 14px;border-radius:999px;
  }

  .late-fallback{
    display:none;max-width:520px;margin:32px auto 0;
    background:var(--cream);border:2px solid var(--navy);border-radius:14px;padding:20px 22px;
    text-align:left;box-shadow:4px 4px 0 var(--navy);
  }
  .late-fallback.on{display:block;}
  .late-fallback p{margin:0 0 12px;font-size:15px;color:var(--navy);}
  .late-fallback .row{display:flex;gap:10px;flex-wrap:wrap;}
  .late-fallback input{
    flex:1;min-width:200px;height:46px;
    background:var(--cream);border:2px solid var(--navy);border-radius:8px;
    padding:0 14px;font-family:var(--body);font-size:15px;color:var(--navy);outline:none;
  }
  .late-fallback button{
    height:46px;padding:0 18px;
    background:var(--yellow);color:var(--navy);border:2px solid var(--navy);border-radius:8px;
    font-family:var(--display);font-weight:900;font-size:14px;cursor:pointer;
  }

  .loading-err{
    display:none;max-width:520px;margin:24px auto 0;
    background:#fde2e2;border:2px solid #8a0e0e;color:#5a0808;
    border-radius:12px;padding:14px 16px;font-size:14px;text-align:left;
  }
  .loading-err.on{display:block;}
  .loading-err .back-btn{
    display:inline-block;margin-top:10px;background:var(--navy);color:var(--cream);
    border:none;border-radius:8px;padding:8px 14px;font-family:var(--display);font-weight:900;font-size:13px;cursor:pointer;
  }

  /* ============================================================ */
  /* ----                  REVEAL VIEW                        ---- */
  /* ============================================================ */
  .view-reveal{padding:16px;}
  .reveal-frame-wrap{position:relative;max-width:1400px;margin:0 auto;}
  .reveal-frame{
    width:100%;height:80vh;border:2px solid var(--navy);border-radius:16px;
    background:var(--cream);box-shadow:8px 8px 0 var(--yellow);
    overflow:hidden;
  }
  .reveal-frame iframe{width:100%;height:100%;border:0;display:block;background:var(--cream);}

  .wizzy-badge-wrap{
    position:absolute;top:-20px;left:-20px;display:flex;align-items:flex-start;gap:12px;z-index:5;
  }
  .wizzy-badge{
    width:80px;height:80px;border-radius:50%;background:var(--cream);border:3px solid var(--navy);
    display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;
    box-shadow:4px 4px 0 var(--yellow);
  }
  .wizzy-badge img{width:78%;height:78%;object-fit:contain;}
  .speech-bubble{
    margin-top:16px;background:var(--cream);border:2px solid var(--navy);border-radius:12px;
    padding:10px 14px;font-family:var(--body);font-weight:600;font-size:14px;color:var(--navy);
    box-shadow:3px 3px 0 var(--navy);max-width:320px;
    position:relative;
  }
  .speech-bubble::before{
    content:'';position:absolute;left:-12px;top:14px;width:0;height:0;
    border-top:8px solid transparent;border-bottom:8px solid transparent;border-right:12px solid var(--navy);
  }
  .speech-bubble::after{
    content:'';position:absolute;left:-9px;top:16px;width:0;height:0;
    border-top:6px solid transparent;border-bottom:6px solid transparent;border-right:10px solid var(--cream);
  }

  .edit-placeholder{
    margin:28px auto 0;max-width:1400px;background:var(--cream);border:2px solid var(--navy);
    border-radius:16px;padding:24px;text-align:center;color:rgba(18,24,74,0.65);
    font-family:var(--body);font-weight:500;font-size:14px;
  }

  /* ---- Footer ---- */
  footer{border-top:1px solid var(--navy);padding:24px 32px;font-size:12px;color:rgba(18,24,74,0.7);}
  footer .row{max-width:1280px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}

  /* ---- Breakpoints ---- */
  @media (max-width:1100px){
    h1{font-size:60px;}
    .wiz-circle{width:380px;height:380px;}
    .hero{gap:32px;}
  }
  @media (max-width:768px){
    main{padding:16px 20px 60px;}
    header.topbar{padding:16px 20px;}
    .hero{grid-template-columns:1fr;gap:24px;}
    .hero-mascot{order:-1;min-height:0;margin-bottom:8px;}
    .wiz-circle{width:240px;height:240px;}
    .sticker{font-size:12px;padding:8px 12px;top:0;right:0;}
    h1{font-size:42px;}
    .lead{font-size:18px;}
    .form-card{padding:24px;box-shadow:4px 4px 0 var(--navy);}
    .cta{font-size:16px;height:52px;}
    footer{padding:18px 20px;}
    footer .row{flex-direction:column;text-align:center;}
    .loading-h2{font-size:32px;}
    .loading-mascot{width:200px;height:200px;}
    .reveal-frame{height:60vh;}
    .wizzy-badge{width:64px;height:64px;}
    .wizzy-badge-wrap{top:-14px;left:-10px;}
    .speech-bubble{font-size:13px;padding:8px 12px;max-width:200px;}
    .view-reveal{padding:12px;}
  }
  @media (max-width:375px){
    h1{font-size:36px;}
    .form-card{padding:20px;}
    .chip{font-size:13px;padding:7px 12px;}
    .loading-h2{font-size:28px;}
  }
</style>
</head>
<body data-view="form">

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
          <label for="website">What&rsquo;s your website? <span class="opt">(optional, but helps Wizzy a lot)</span></label>
          <input type="text" id="website" name="website" inputmode="url" autocomplete="url" placeholder="yourbusiness.com">
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
      <div class="wiz-circle">
        <img src="/preview/wizzy-wave.gif" alt="Wizzy the WebWiz spider holding a laptop">
      </div>
      <div class="sticker">Made with care<small>by Wizzy</small></div>
    </div>
  </section>
</main>

<!-- ===================== LOADING VIEW ===================== -->
<main class="view view-loading">
  <div class="loading-wrap">
    <div class="loading-mascot">
      <img src="/preview/wizzy-wave.gif" alt="Wizzy">
    </div>
    <h2 class="loading-h2" id="loadingHead">Wizzy is designing your site&hellip;</h2>
    <p class="loading-sub">Hang tight. He&rsquo;s working fast.</p>

    <div class="progress-track">
      <span class="progress-fill" id="progFill"></span>
    </div>
    <p class="loading-status" id="loadingStatus">Picking your colors&hellip;</p>

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

<!-- ===================== REVEAL VIEW ===================== -->
<main class="view view-reveal">
  <div class="reveal-frame-wrap">
    <div class="reveal-frame">
      <iframe id="previewFrame" src="about:blank" loading="eager" title="Your new website preview"></iframe>
    </div>
    <div class="wizzy-badge-wrap">
      <div class="wizzy-badge"><img src="/preview/wizzy-wave.gif" alt="Wizzy"></div>
      <div class="speech-bubble">Here&rsquo;s what I made you. What do you think?</div>
    </div>
  </div>
  <div class="edit-placeholder">Edit panel coming next.</div>
</main>

<footer>
  <div class="row">
    <span>Built by humans (and one spider) who care about your site.</span>
    <span>&copy; 2026 WebWiz &middot; <a href="mailto:hello@trywebwiz.com">hello@trywebwiz.com</a></span>
  </div>
</footer>

<script>
(function(){
  // ---- elements ----
  var body = document.body;
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

  var statusMessages = [
    'Picking your colors…',
    'Choosing fonts that fit you…',
    'Writing your hero copy…',
    'Laying out your homepage…',
    'Wizzy is working fast…',
    'Adding the finishing touches…'
  ];

  var MIN_DESC = 20;
  var WEBSITE_RX = /^([\w-]+\.)+[a-z]{2,}([\/?#].*)?$/i;

  // ---- form validation ----
  function validateWebsite(){
    var v = (web.value || '').trim();
    if (v === '') return false;
    // Accept "yourbusiness.com" OR "https://yourbusiness.com"
    var stripped = v.replace(/^https?:\/\//i,'').replace(/^www\./i,'');
    return WEBSITE_RX.test(stripped);
  }
  function validateDesc(){
    return ((desc.value || '').trim().length >= MIN_DESC);
  }
  desc.addEventListener('input', function(){
    if (descField.classList.contains('invalid') && validateDesc()) descField.classList.remove('invalid');
  });
  web.addEventListener('input', function(){
    if (websiteField.classList.contains('invalid') && (web.value.trim() === '' || validateWebsite())) websiteField.classList.remove('invalid');
  });

  // ---- view transitions ----
  function setView(v){ body.setAttribute('data-view', v); window.scrollTo({top:0, behavior:'smooth'}); }

  // ---- loading state machine ----
  var statusIdx = 0, statusTimer = null, progressTimer = null, lateTimer = null, generating = false;
  function startLoadingTickers(){
    var pct = 5;
    progFill.style.width = pct + '%';
    loadingStatus.textContent = statusMessages[0];
    statusIdx = 0;
    statusTimer = setInterval(function(){
      statusIdx = Math.min(statusIdx + 1, statusMessages.length - 1);
      loadingStatus.style.opacity = '0';
      setTimeout(function(){
        loadingStatus.textContent = statusMessages[statusIdx];
        loadingStatus.style.opacity = '1';
      }, 220);
    }, 4000);
    progressTimer = setInterval(function(){
      pct = Math.min(pct + 6, 88);
      progFill.style.width = pct + '%';
    }, 3000);
    lateTimer = setTimeout(function(){
      lateFallback.classList.add('on');
    }, 90000);
  }
  function stopLoadingTickers(){
    if (statusTimer)   { clearInterval(statusTimer);   statusTimer = null; }
    if (progressTimer) { clearInterval(progressTimer); progressTimer = null; }
    if (lateTimer)     { clearTimeout(lateTimer);      lateTimer = null; }
  }

  function showLoadingError(msg){
    stopLoadingTickers();
    loadingErrMsg.textContent = msg || 'Something went wrong on our end. Try again?';
    loadingErr.classList.add('on');
    generating = false;
  }
  backToForm.addEventListener('click', function(){
    loadingErr.classList.remove('on');
    lateFallback.classList.remove('on');
    progFill.style.width = '5%';
    setView('form');
  });

  // ---- submit ----
  form.addEventListener('submit', function(e){
    e.preventDefault();
    if (generating) return;

    var descOk = validateDesc();
    var webOk  = validateWebsite();

    descField.classList.toggle('invalid', !descOk);
    websiteField.classList.toggle('invalid', !webOk);

    if (!descOk) { desc.focus(); return; }
    if (!webOk)  { web.focus();  return; }

    generating = true;
    ctaBtn.disabled = true;

    // Tailor the H2 if possible. If website is fresh, take the host as best-guess
    // business name until backend returns the real one.
    var hostGuess = '';
    try {
      var raw = web.value.trim().replace(/^https?:\/\//i,'').replace(/^www\./i,'');
      hostGuess = raw.split('/')[0].split('?')[0];
    } catch(e){}
    loadingHead.innerHTML = 'Wizzy is designing ' + (hostGuess ? escapeHtml(hostGuess) : 'your site') + '…';

    setView('loading');
    startLoadingTickers();

    fetch('/api/magic.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({
        website:     web.value.trim(),
        description: desc.value.trim()
      })
    })
    .then(function(r){
      return r.json().then(function(j){ return { ok: r.ok, body: j }; });
    })
    .then(function(res){
      if (res.body && res.body.ok && (res.body.preview_url || res.body.url)) {
        // Bump bar to 100, swap to reveal after a beat
        progFill.style.width = '100%';
        loadingStatus.textContent = 'Ready! Opening your preview…';
        var biz = res.body.business_name || res.body.business || 'your site';
        loadingHead.innerHTML = 'Wizzy is designing ' + escapeHtml(biz) + '…';
        stopLoadingTickers();
        var previewUrl = res.body.preview_url || (res.body.url + 'v1/index.html');
        setTimeout(function(){
          previewFrame.src = previewUrl;
          setView('reveal');
        }, 600);
      } else {
        showLoadingError((res.body && res.body.error) ? res.body.error : 'Generation failed. Try again?');
        ctaBtn.disabled = false;
      }
    })
    .catch(function(e){
      showLoadingError('Network error: ' + (e && e.message ? e.message : 'unknown'));
      ctaBtn.disabled = false;
    });
  });

  // ---- 90s late fallback: capture notify email ----
  notifyForm.addEventListener('submit', function(e){
    e.preventDefault();
    var em = (notifyEmail.value || '').trim();
    if (!em) return;
    // Fire-and-forget log (no dedicated endpoint yet — drop into magic_hits via a header for now).
    fetch('/api/magic.php?notify_email=' + encodeURIComponent(em), { method: 'POST' }).catch(function(){});
    notifyForm.style.display = 'none';
    notifySaid.style.display = 'block';
  });

  // ---- helpers ----
  function escapeHtml(s){ return String(s).replace(/[&<>"']/g, function(c){
    return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
  });}
})();
</script>
</body></html>
