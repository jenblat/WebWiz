<?php
// Public magic-link landing: shows a WebWiz loading screen, calls /api/magic.php, then opens the preview.
$website = trim((string)($_GET['website'] ?? $_GET['url'] ?? ''));
$name    = trim((string)($_GET['name'] ?? ''));
$host    = $website ? (parse_url(preg_match('~^https?://~i', $website) ? $website : 'https://' . $website, PHP_URL_HOST) ?: $website) : '';
$host    = preg_replace('~^www\.~', '', (string)$host);
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Building your website&hellip; · WebWiz</title>
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
    <h1 id="head">Wizzy is building <?= htmlspecialchars($host ?: 'your', ENT_QUOTES) ?><?= $host ? "&rsquo;s" : '' ?> website&hellip;</h1>
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
  var steps=['Looking up the website&hellip;','Reading the brand, colors &amp; content&hellip;','Designing a fresh layout&hellip;','Placing images &amp; copy&hellip;','Polishing the final touches&hellip;'];
  var si=0, pct=6, statusEl=document.getElementById('status'), progEl=document.getElementById('prog');
  progEl.style.width=pct+'%';
  var tick=setInterval(function(){ si=Math.min(si+1,steps.length-1); statusEl.innerHTML=steps[si]; pct=Math.min(pct+16,88); progEl.style.width=pct+'%'; }, 6000);
  statusEl.innerHTML=steps[0];
  fetch('/api/magic.php?'+qs.toString(),{headers:{'Accept':'application/json'}})
    .then(function(r){ return r.json().then(function(j){ return {ok:r.ok,j:j}; }); })
    .then(function(res){
      clearInterval(tick);
      if(res.j && res.j.ok && res.j.url){ progEl.style.width='100%'; statusEl.innerHTML='Done! Opening your site&hellip;'; setTimeout(function(){ location.href=res.j.url; }, 600); }
      else { statusEl.textContent=''; var m=(res.j && res.j.error) ? res.j.error : 'Something went wrong generating the site.'; document.getElementById('errbox').innerHTML='<div class="err">'+m.replace(/</g,'&lt;')+'</div>'; }
    })
    .catch(function(e){ clearInterval(tick); statusEl.textContent=''; document.getElementById('errbox').innerHTML='<div class="err">Network error: '+String(e.message).replace(/</g,'&lt;')+'</div>'; });
})();
</script>
</body></html>
