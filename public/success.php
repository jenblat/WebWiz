<?php
declare(strict_types=1);

$secrets_path = __DIR__ . '/../secrets.php';
$secrets = file_exists($secrets_path) ? require $secrets_path : [];
$STRIPE_SECRET = $secrets['STRIPE_SECRET_KEY'] ?? '';

$session = null;
$status  = 'unknown';
$amount  = null;
$plan    = null;
$email   = null;
$business_name = null;

$session_id = $_GET['session_id'] ?? '';
if (preg_match('/^cs_[A-Za-z0-9_]+$/', $session_id) && $STRIPE_SECRET) {
    $ch = curl_init('https://api.stripe.com/v1/checkout/sessions/' . urlencode($session_id) . '?expand[]=line_items');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERPWD        => $STRIPE_SECRET . ':',
        CURLOPT_HTTPHEADER     => ['Stripe-Version: 2024-06-20'],
    ]);
    $r = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($r !== false && $http < 400) {
        $session = json_decode($r, true);
        $status  = $session['payment_status'] ?? ($session['status'] ?? 'unknown');
        $amount  = ($session['amount_total'] ?? 0) / 100;
        $email   = $session['customer_email'] ?? ($session['customer_details']['email'] ?? null);
        $plan    = $session['metadata']['plan'] ?? null;
        $business_name = $session['metadata']['business_name'] ?? null;
    }
}

$plan_label = [
    'build_only'    => 'Build only',
    'build_plus_49' => 'Build + Hosting & Care',
    'build_plus_99' => 'Build + Hosting & Care + Edits',
][$plan ?? ''] ?? 'Your order';

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>You're in! · WebWiz</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<link rel="stylesheet" href="/legal_shared.css">
<style id="bs-credit-css">.foot-bottom .bs-credit{display:inline-flex;align-items:center;gap:10px;text-transform:none;letter-spacing:0.16em;}.foot-bottom .bs-credit a{display:inline-flex;align-items:center;line-height:0;}.foot-bottom .bs-credit img{height:22px;width:auto;display:block;vertical-align:middle;opacity:0.95;transition:opacity .15s;}.foot-bottom .bs-credit a:hover img{opacity:1;}</style>
<style>
  .ok-band{padding:80px 0;background:var(--paper);border-bottom:5px solid var(--navy);text-align:center;}
  .ok-band .eyebrow{display:inline-block;background:var(--teal);color:var(--navy);padding:8px 18px;border-radius:999px;font-family:var(--display);font-weight:900;font-size:12px;letter-spacing:0.22em;text-transform:uppercase;margin-bottom:18px;}
  .ok-band h1{font-family:var(--display);font-weight:900;font-size:80px;line-height:0.95;letter-spacing:-0.03em;margin:0 0 14px;}
  .ok-band h1 em{font-style:normal;color:var(--yellow);background:var(--navy);padding:0 14px;border-radius:14px;display:inline-block;}
  .ok-band p{font-family:var(--body);font-size:19px;color:var(--navy);max-width:560px;margin:0 auto;line-height:1.55;}
  .receipt{background:#fff;border:4px solid var(--navy);border-radius:22px;padding:26px;max-width:520px;margin:32px auto 0;box-shadow:8px 8px 0 var(--yellow);text-align:left;}
  .receipt h3{font-family:var(--display);font-weight:900;font-size:14px;letter-spacing:0.18em;text-transform:uppercase;color:var(--navy);margin:0 0 14px;}
  .receipt .row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #e5dfca;font-family:var(--body);font-size:15px;}
  .receipt .row:last-child{border-bottom:0;}
  .receipt .row b{font-weight:700;}
  .next{padding:64px 0;background:var(--cream);}
  .next h2{font-family:var(--display);font-weight:900;font-size:40px;letter-spacing:-0.02em;margin:0 0 24px;text-align:center;color:var(--navy);}
  .step-list{display:grid;grid-template-columns:repeat(3,1fr);gap:24px;max-width:980px;margin:0 auto;}
  @media (max-width:900px){.step-list{grid-template-columns:1fr;}}
  .step-list .step{background:#fff;border:4px solid var(--navy);border-radius:22px;padding:24px;}
  .step-list .step .num{font-family:var(--display);font-weight:900;font-size:14px;letter-spacing:0.18em;text-transform:uppercase;color:var(--yellow);background:var(--navy);padding:5px 12px;border-radius:999px;display:inline-block;margin-bottom:10px;}
  .step-list .step h3{font-family:var(--display);font-weight:900;font-size:22px;color:var(--navy);margin:0 0 8px;}
  .step-list .step p{font-family:var(--body);font-size:15px;line-height:1.5;color:var(--navy);}
</style>
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/favicon-16.png">
<link rel="icon" href="/favicon.ico">
<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
</head>
<body>

<nav class="nav">
  <div class="wrap nav-inner">
    <a href="/" class="brandmark">
      <span class="ring"><img src="/assets/aaaa1111-2222-3333-4444-555566667777.png" alt="Wizzy"></span>
      <span class="word">WebWiz<span class="dot">.</span></span>
    </a>
    <div class="nav-links">
      <a href="/#how">How it works</a>
      <a href="/#pricing">Pricing</a>
      <a href="/#care">Care plans</a>
    </div>
    <a href="mailto:hello@trywebwiz.com" class="nav-cta">Email us</a>
  </div>
</nav>

<section class="ok-band">
  <div class="wrap">
    <span class="eyebrow">&star; Payment received</span>
    <h1>You're <em>in.</em></h1>
    <?php if ($status === 'paid' || $status === 'complete'): ?>
      <p>Thanks <?= htmlspecialchars($business_name ?: 'friend') ?> — payment went through. Check <?= htmlspecialchars($email ?: 'your inbox') ?> for the Stripe receipt.</p>
    <?php else: ?>
      <p>We received your order. If your payment is still processing, you'll get a confirmation email shortly.</p>
    <?php endif; ?>

    <?php if ($session): ?>
    <div class="receipt">
      <h3>&star; Order summary</h3>
      <?php if ($business_name): ?><div class="row"><span>Business</span><b><?= htmlspecialchars($business_name) ?></b></div><?php endif; ?>
      <div class="row"><span>Plan</span><b><?= htmlspecialchars($plan_label) ?></b></div>
      <?php if ($amount !== null): ?><div class="row"><span>Charged today</span><b>$<?= number_format($amount, 2) ?></b></div><?php endif; ?>
      <?php if ($email): ?><div class="row"><span>Receipt sent to</span><b><?= htmlspecialchars($email) ?></b></div><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</section>

<section class="next">
  <div class="wrap">
    <h2>What happens now.</h2>
    <div class="step-list">
      <div class="step">
        <span class="num">Step 1</span>
        <h3>You hear from us.</h3>
        <p>Within one business day we'll email to schedule a 15-minute kickoff call. We'll cover your goals, brand, and content.</p>
      </div>
      <div class="step">
        <span class="num">Step 2</span>
        <h3>We design and build.</h3>
        <p>You get a designed site within ~10 business days. Two revision rounds included. We handle hosting, domain, email, the lot.</p>
      </div>
      <div class="step">
        <span class="num">Step 3</span>
        <h3>You go live.</h3>
        <p>We launch, set up analytics, and hand you a one-page guide. <?php if (in_array($plan, ['build_plus_49','build_plus_99'], true)): ?>Your care plan keeps the site safe and fast from there.<?php else: ?>You're free to take it from here.<?php endif; ?></p>
      </div>
    </div>
  </div>
</section>

<footer class="foot">
  <div class="wrap">
    <div class="foot-grid">
      <div>
        <div class="signoff">WebWiz<span class="dot">.</span></div>
        <p class="blurb">A tiny design studio making beautiful websites for small businesses. We're in your corner.</p>
      </div>
      <div><h4>The site</h4><a href="/#how">How it works</a><a href="/#pricing">Pricing</a><a href="/#care">Care plans</a></div>
      <div><h4>Talk</h4><a href="mailto:hello@trywebwiz.com">hello@trywebwiz.com</a><a href="/start">Start a site</a></div>
      <div><h4>Legal</h4><a href="/privacy">Privacy</a><a href="/terms">Terms</a></div>
    </div>
    <div class="foot-bottom">
      <span>&copy; 2026 WEBWIZ STUDIO &middot; MADE WITH &hearts; &amp; CSS</span>
      <span class="bs-credit">MADE BY <a href="https://www.busyseed.com/" target="_blank" rel="noopener" aria-label="BusySeed"><img src="https://i.imgur.com/iQr9LKx.png" alt="BusySeed" loading="lazy"></a></span>
    </div>
  </div>
</footer>

</body>
</html>
