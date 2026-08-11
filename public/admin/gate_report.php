<?php
// /admin/gate_report.php — the gate test, both cells side by side.
//
// Raw counts FIRST, percentages second, because with the volumes involved a
// percentage on its own is actively misleading: "100% conversion" off one visitor
// looks like a result and is not.
//
// ATTRIBUTION, and its one real limitation. Only the /o/ landing-page events
// (offer_view, offer_cta_clicked, ...) carry the cell in their try_events
// payload. The core funnel events (form_submit, gen_started, reveal_viewed,
// checkout_started) do NOT. Anything after generation is therefore attributed by
// joining try_events.token -> jobs.token -> jobs.offer_variant, which is exact
// where a token exists and invisible where it does not.
declare(strict_types=1);
require '/var/www/sites/trywebwiz/private/webwiz_lib.php';
require_once '/var/www/sites/trywebwiz/public/api/_session.php';
require_once '/var/www/sites/trywebwiz/public/api/_gatemetrics.php';
ww_session_start();

$me = !empty($_SESSION['uid']) ? ww_user_by_id((int)$_SESSION['uid']) : null;
if (!$me || ($me['role'] ?? '') !== 'admin') { header('Location: /admin/'); exit; }

$db = ww_db();
ww_gm_init_schema($db);

// The two cells under test. Price parity between them is asserted below: if it
// ever breaks, this stops being a gate test and becomes a price test.
$CELLS = ['b' => 'CONTROL (b)', 'u' => 'TEST (u)'];

$q1 = function (string $sql, array $a = []) use ($db) {
    $st = $db->prepare($sql); $st->execute($a); return (int)$st->fetchColumn();
};

// ---- per-cell counts -------------------------------------------------------
$M = [];
foreach (array_keys($CELLS) as $c) {
    $like = '%"variant":"' . $c . '"%';
    // Landing-page stage: variant lives in the payload.
    $M[$c]['offer_view']  = $q1("SELECT COUNT(*) FROM try_events WHERE event='offer_view' AND payload LIKE ?", [$like]);
    $M[$c]['cta_clicked'] = $q1("SELECT COUNT(*) FROM try_events WHERE event='offer_cta_clicked' AND payload LIKE ?", [$like]);
    // Everything from generation onward: attributed through the job row.
    $M[$c]['jobs']        = $q1("SELECT COUNT(*) FROM jobs WHERE offer_variant = ?", [$c]);
    $M[$c]['qa_passed']   = $q1("SELECT COUNT(*) FROM previews p JOIN jobs j ON j.id=p.job_id WHERE j.offer_variant = ? AND p.qa_pass = 1", [$c]);
    $M[$c]['reveal']      = $q1("SELECT COUNT(DISTINCT e.token) FROM try_events e JOIN jobs j ON j.token=e.token WHERE e.event='reveal_viewed' AND j.offer_variant = ?", [$c]);
    $M[$c]['unlock']      = $q1("SELECT COUNT(*) FROM try_events WHERE event='unlock_clicked' AND payload LIKE ?", [$like]);
    $M[$c]['ck_started']  = $q1("SELECT COUNT(DISTINCT e.token) FROM try_events e JOIN jobs j ON j.token=e.token WHERE e.event='checkout_started' AND j.offer_variant = ?", [$c]);
    $M[$c]['paid']        = $q1("SELECT COUNT(DISTINCT e.token) FROM try_events e JOIN jobs j ON j.token=e.token WHERE e.event='checkout_completed' AND j.offer_variant = ?", [$c]);
    $M[$c]['refunded']    = $q1("SELECT COUNT(*) FROM billing_events WHERE type='charge.refunded' AND offer_variant = ?", [$c]);
    $M[$c]['disputed']    = $q1("SELECT COUNT(*) FROM billing_events WHERE type LIKE 'charge.dispute%' AND offer_variant = ?", [$c]);
    // Month-two retention: a subscription is only measurable once its second
    // cycle could have been billed, i.e. 60 days after its first paid invoice.
    $M[$c]['subs_60d']    = $q1("SELECT COUNT(DISTINCT subscription_id) FROM billing_invoices WHERE offer_variant = ? AND billing_reason='subscription_create' AND paid_at <= datetime('now','-60 days')", [$c]);
    $M[$c]['m2_paid']     = $q1("SELECT COUNT(DISTINCT subscription_id) FROM billing_invoices WHERE offer_variant = ? AND billing_reason='subscription_cycle'", [$c]);
    $M[$c]['subs_any']    = $q1("SELECT COUNT(DISTINCT subscription_id) FROM billing_invoices WHERE offer_variant = ?", [$c]);
}

// ---- two-proportion z-test on reveal -> paid --------------------------------
// Answers Omar's stop condition ("run until the difference is meaningful")
// rather than leaving it to be eyeballed.
function ww_ztest(int $x1, int $n1, int $x2, int $n2): ?array {
    if ($n1 <= 0 || $n2 <= 0) return null;
    $p1 = $x1 / $n1; $p2 = $x2 / $n2;
    $p  = ($x1 + $x2) / ($n1 + $n2);
    $se = sqrt($p * (1 - $p) * (1 / $n1 + 1 / $n2));
    if ($se <= 0.0) return null;
    $z  = ($p2 - $p1) / $se;
    // Two-sided p from the normal CDF, Abramowitz & Stegun 7.1.26 erf approximation.
    // Verified against known values: z=1.96 -> p=0.0500, z=2.576 -> p=0.0100,
    // z=3 -> p=0.0027, all matching to 4dp.
    $erf = function (float $x): float {
        $s = $x < 0 ? -1 : 1; $x = abs($x);
        $t = 1 / (1 + 0.3275911 * $x);
        $y = 1 - ((((1.061405429 * $t - 1.453152027) * $t) + 1.421413741) * $t - 0.284496736) * $t * $t * exp(-$x * $x)
             - 0.254829592 * $t * exp(-$x * $x);
        return $s * $y;
    };
    $cdf = 0.5 * (1 + $erf($z / sqrt(2)));
    $pval = 2 * min($cdf, 1 - $cdf);
    $se_d = sqrt(($p1 * (1 - $p1) / $n1) + ($p2 * (1 - $p2) / $n2));
    return ['z'=>$z, 'p'=>$pval, 'diff'=>$p2-$p1, 'lo'=>($p2-$p1)-1.96*$se_d, 'hi'=>($p2-$p1)+1.96*$se_d];
}
$zt = ww_ztest($M['b']['paid'], $M['b']['reveal'], $M['u']['paid'], $M['u']['reveal']);

// ---- price parity assertion -------------------------------------------------
// If the two cells ever diverge on price the experiment silently stops measuring
// gate position and starts measuring price. Fail loudly rather than quietly.
$parity_ok = true; $parity_msg = '';
try {
    $oc = file_get_contents('/var/www/sites/trywebwiz/public/api/offer_checkout.php');
    preg_match_all("~'([bu])'\s*=>\s*\['build'\s*=>\s*(\d+),\s*'monthly'\s*=>\s*(\d+),\s*'trial'\s*=>\s*(\d+)~", $oc, $mm, PREG_SET_ORDER);
    $seen = [];
    foreach ($mm as $m) $seen[$m[1]] = [$m[2], $m[3], $m[4]];
    if (count($seen) < 2)            { $parity_ok = false; $parity_msg = 'could not read both cells from offer_checkout.php'; }
    elseif ($seen['b'] !== $seen['u']) { $parity_ok = false; $parity_msg = 'b=' . implode('/', $seen['b']) . ' vs u=' . implode('/', $seen['u']); }
    else                              { $parity_msg = 'build ' . $seen['b'][0] . 'c, monthly ' . $seen['b'][1] . 'c, trial ' . $seen['b'][2] . 'd, identical'; }
} catch (Throwable $e) { $parity_ok = false; $parity_msg = $e->getMessage(); }

$refund_flag = ($M['b']['refunded'] + $M['b']['disputed'] + $M['u']['refunded'] + $M['u']['disputed']) > 0;
$pc = fn(int $x, int $n) => $n > 0 ? round($x * 100 / $n, 1) . '%' : '&mdash;';
?><!DOCTYPE html><html lang="en"><head><meta charset="utf-8">
<title>Gate report &middot; WebWiz admin</title><meta name="robots" content="noindex">
<style>
 :root{--cream:#FFF8E7;--navy:#12184A;--yellow:#F7C84A;}
 *{box-sizing:border-box;margin:0;padding:0}
 body{font-family:Inter,system-ui,sans-serif;background:var(--cream);color:var(--navy);padding:32px;font-size:15px}
 h1{font-size:30px;font-weight:900;letter-spacing:-.02em;margin-bottom:4px}
 p.sub{opacity:.65;margin-bottom:20px;font-size:14px}
 table{border-collapse:collapse;background:#fff;border:2px solid var(--navy);border-radius:10px;overflow:hidden;min-width:640px}
 th,td{padding:9px 14px;border-top:1px solid #e8e8e8;text-align:right}
 th:first-child,td:first-child{text-align:left}
 thead th{background:var(--navy);color:var(--cream);border:0;font-size:11px;letter-spacing:.1em;text-transform:uppercase}
 .n{font-weight:800;font-size:16px}.p{opacity:.55;font-size:12px}
 .primary td{background:#FFF3CE;font-weight:800}
 .banner{border:3px solid #9b1c1c;background:#ffecec;color:#9b1c1c;padding:14px 18px;border-radius:12px;margin-bottom:18px;font-weight:800}
 .ok{border:2px solid #137333;background:#eaf7f1;color:#137333;padding:10px 14px;border-radius:10px;margin-bottom:18px;font-size:13px}
 .warn{border:2px solid #8a6100;background:#fff6e5;color:#8a6100;padding:12px 16px;border-radius:10px;margin:18px 0;font-size:13px}
 .note{font-size:13px;opacity:.75;margin-top:16px;max-width:760px;line-height:1.5}
</style></head><body>
<h1>Gate test</h1>
<p class="sub">Control (b) = full free reveal &middot; Test (u) = hero and first section live, the rest locked. Same price in both.</p>

<?php if ($refund_flag): ?>
  <div class="banner">REFUNDS OR DISPUTES PRESENT. That is the early-warning signal that the generated sites are not good enough to charge for. Investigate before spending more.</div>
<?php endif; ?>

<?php if ($parity_ok): ?>
  <div class="ok"><strong>Price parity holds:</strong> <?= htmlspecialchars($parity_msg) ?>. The cells differ only in where the ask sits.</div>
<?php else: ?>
  <div class="banner">PRICE PARITY BROKEN: <?= htmlspecialchars($parity_msg) ?>. This is no longer a gate test, it is a price test. Fix before reading anything below.</div>
<?php endif; ?>

<table>
 <thead><tr><th>Stage</th><th>Control (b)</th><th>Test (u)</th></tr></thead>
 <tbody>
 <?php
 $rows = [
   ['offer page viewed',            'offer_view',  null],
   ['landing CTA clicked',          'cta_clicked', 'offer_view'],
   ['generation started (jobs)',    'jobs',        'cta_clicked'],
   ['QA passed',                    'qa_passed',   'jobs'],
   ['reveal viewed',                'reveal',      'jobs'],
   ['unlock clicked',               'unlock',      'reveal'],
   ['checkout started',             'ck_started',  'reveal'],
   ['paid',                         'paid',        'ck_started'],
 ];
 foreach ($rows as [$label, $key, $den]):
 ?>
 <tr>
   <td><?= htmlspecialchars($label) ?></td>
   <?php foreach (array_keys($CELLS) as $c): ?>
     <td><span class="n"><?= $M[$c][$key] ?></span>
       <?php if ($den): ?><br><span class="p">of <?= $M[$c][$den] ?> &middot; <?= $pc($M[$c][$key], $M[$c][$den]) ?></span><?php endif; ?></td>
   <?php endforeach; ?>
 </tr>
 <?php endforeach; ?>
 <tr class="primary">
   <td>REVEAL &rarr; PAID (primary)</td>
   <?php foreach (array_keys($CELLS) as $c): ?>
     <td><span class="n"><?= $M[$c]['paid'] ?> / <?= $M[$c]['reveal'] ?></span><br><span class="p"><?= $pc($M[$c]['paid'], $M[$c]['reveal']) ?></span></td>
   <?php endforeach; ?>
 </tr>
 <tr><td>refunded</td><?php foreach (array_keys($CELLS) as $c): ?><td class="n"><?= $M[$c]['refunded'] ?></td><?php endforeach; ?></tr>
 <tr><td>disputed / chargeback</td><?php foreach (array_keys($CELLS) as $c): ?><td class="n"><?= $M[$c]['disputed'] ?></td><?php endforeach; ?></tr>
 <tr><td>subscriptions aged 60d+</td><?php foreach (array_keys($CELLS) as $c): ?><td class="n"><?= $M[$c]['subs_60d'] ?></td><?php endforeach; ?></tr>
 <tr><td>of those, month-two paid</td><?php foreach (array_keys($CELLS) as $c): ?><td class="n"><?= $M[$c]['m2_paid'] ?></td><?php endforeach; ?></tr>
 </tbody>
</table>

<?php
// Month-two retention must never render as 0% before it is measurable: a 0
// reads as "everybody churned" when it means "nobody is old enough yet".
$m2_measurable = ($M['b']['subs_60d'] + $M['u']['subs_60d']) > 0;
$subs_any = $M['b']['subs_any'] + $M['u']['subs_any'];
?>
<div class="warn">
<strong>Month-two retention:</strong>
<?php if ($m2_measurable): ?>
  control <?= $M['b']['m2_paid'] ?> of <?= $M['b']['subs_60d'] ?>, test <?= $M['u']['m2_paid'] ?> of <?= $M['u']['subs_60d'] ?>.
<?php else: ?>
  not yet measurable. <?= $subs_any ?> subscription<?= $subs_any === 1 ? '' : 's' ?> attributed to either cell, none yet 60 days old,
  so a second billing cycle could not have occurred. This is deliberately not shown as 0%.
<?php endif; ?>
</div>

<div class="warn">
<strong>Is the difference real yet?</strong>
<?php if (!$zt): ?>
  No. Neither cell has a reveal yet, so there is nothing to compare.
<?php else: ?>
  z = <?= number_format($zt['z'], 2) ?>, p = <?= number_format($zt['p'], 3) ?>.
  95% CI on the difference: <?= number_format($zt['lo'] * 100, 1) ?>% to <?= number_format($zt['hi'] * 100, 1) ?>%.
  <br>
  <?php if ($zt['p'] < 0.05): ?>
    <strong>Distinguishable from noise.</strong> The test cell differs from control at p &lt; 0.05.
  <?php else: ?>
    <strong>Not distinguishable from noise.</strong> The confidence interval spans zero, so on this data the two cells
    cannot be told apart. Keep running.
  <?php endif; ?>
<?php endif; ?>
</div>

<p class="note">
<strong>Read the integers, not the percentages.</strong> At these volumes a single conversion moves a rate by tens of points.
<br><br>
<strong>Attribution caveat:</strong> only the <code>/o/</code> landing events carry the cell in their payload. Everything from
generation onward is attributed by joining <code>try_events.token &rarr; jobs.offer_variant</code>, so a session that never
minted a job row is invisible to the lower half of this table.
<br><br>
<strong>Cell b is shared.</strong> The existing PT-B price-test ad set and this test's control both write
<code>offer_variant = 'b'</code>, so the control column mixes both sources of traffic. Until that is separated, treat the
control's absolute numbers as an upper bound rather than a clean measurement of this test.
</p>
</body></html>
