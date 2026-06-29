<?php
// /api/_email_templates.php — Bold editorial email templates for WebWiz.
// All 6 transactional templates (3 customer-facing + 3 admin alerts).
// Library file; direct HTTP requests do nothing.

declare(strict_types=1);

/** Shared chrome: star marquee header, drop-shadow card, brand row, footer. */
function ww_email_shell(string $eyebrow, string $title, string $body_html): string {
    $eyebrow = htmlspecialchars($eyebrow);
    $title   = htmlspecialchars($title);
    return <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>$title</title></head>
<body style="margin:0;padding:0;background:#FFF8E7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#12184A;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#FFF8E7;">
<tr><td align="center" style="padding:32px 16px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background:#fff;border:4px solid #12184A;border-radius:28px;box-shadow:10px 10px 0 #F7C84A;">
<tr><td style="background:#12184A;color:#FFF8E7;border-radius:24px 24px 0 0;padding:14px 26px;font-family:'Nunito',-apple-system,sans-serif;font-weight:900;font-size:11px;letter-spacing:0.28em;text-transform:uppercase;text-align:center;">
&#9733; $eyebrow &#9733;
</td></tr>
<tr><td style="padding:32px 36px 0;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
<td style="padding-right:14px;vertical-align:middle;">
<img src="https://i.imgur.com/7OdNLrM.png" alt="Wizzy" width="48" height="48" style="display:block;height:48px;width:48px;border:2px solid #12184A;border-radius:50%;background:#F8EFD3;">
</td>
<td style="vertical-align:middle;">
<div style="font-family:'Nunito',-apple-system,sans-serif;font-weight:900;font-size:30px;color:#12184A;letter-spacing:-0.02em;line-height:1;">WebWiz<span style="color:#F7C84A;">.</span></div>
</td>
</tr></table>
</td></tr>
$body_html
<tr><td style="padding:20px 36px;background:#F8EFD3;border-radius:0 0 24px 24px;border-top:3px solid #12184A;font-family:'Nunito',sans-serif;font-weight:900;font-size:10px;letter-spacing:0.18em;text-transform:uppercase;text-align:center;color:#12184A;">
WEBWIZ STUDIO &nbsp;&middot;&nbsp;
<a href="https://trywebwiz.com" style="color:#12184A;text-decoration:none;">trywebwiz.com</a> &nbsp;&middot;&nbsp;
<a href="mailto:hello@trywebwiz.com" style="color:#12184A;text-decoration:none;">hello@trywebwiz.com</a>
</td></tr>
</table>
<p style="font-family:Inter,sans-serif;font-size:11px;color:#12184A;opacity:0.5;margin:20px 0 0;text-align:center;">
You received this because of activity on your WebWiz account.
</p>
</td></tr></table>
</body></html>
HTML;
}

/** Big display headline with one word emphasized as a navy chip. */
function ww_email_hero(string $line_before, string $emphasized, string $line_after = ''): string {
    return '<tr><td style="padding:24px 36px 4px;">'
        . '<h1 style="font-family:\'Nunito\',-apple-system,sans-serif;font-weight:900;font-size:50px;line-height:0.96;letter-spacing:-0.03em;margin:0;color:#12184A;">'
        . htmlspecialchars($line_before) . ' '
        . '<span style="background:#12184A;color:#F7C84A;padding:0 14px;border-radius:14px;display:inline-block;">' . htmlspecialchars($emphasized) . '</span>'
        . ($line_after !== '' ? ' ' . htmlspecialchars($line_after) : '')
        . '</h1></td></tr>';
}

/** Paragraph row (HTML allowed in $html). */
function ww_email_para(string $html, int $top = 14): string {
    return '<tr><td style="padding:' . $top . 'px 36px 4px;">'
        . '<p style="font-family:Inter,-apple-system,sans-serif;font-size:18px;line-height:1.55;margin:0;color:#12184A;">'
        . $html . '</p></td></tr>';
}

/** Yellow-tinted summary card with key/value rows. */
function ww_email_card(string $title, array $rows): string {
    $row_html = '';
    $i = 0;
    foreach ($rows as $k => $v) {
        $is_total = (strpos((string)$k, 'Charged') === 0 || strpos((string)$k, 'Total') === 0);
        $border = $i === 0 ? '' : 'border-top:1px solid #00000022;';
        $row_html .= '<tr>'
            . '<td style="padding:6px 0;color:#12184A;opacity:0.75;' . $border . '">' . htmlspecialchars((string)$k) . '</td>'
            . '<td style="padding:6px 0;text-align:right;color:#12184A;font-weight:' . ($is_total ? '900;font-size:18px' : '700') . ';' . $border . '">' . $v . '</td>'
            . '</tr>';
        $i++;
    }
    return '<tr><td style="padding:28px 36px 4px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:3px solid #12184A;border-radius:16px;background:#F8EFD3;">'
        . '<tr><td style="padding:14px 18px;font-family:\'Nunito\',-apple-system,sans-serif;font-weight:900;font-size:11px;letter-spacing:0.22em;text-transform:uppercase;color:#12184A;border-bottom:2px solid #12184A;">'
        . '&#9733; ' . htmlspecialchars($title) . '</td></tr>'
        . '<tr><td style="padding:14px 18px;font-family:Inter,sans-serif;font-size:15px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $row_html . '</table>'
        . '</td></tr></table></td></tr>';
}

/** Numbered steps with colored circle bubbles. $steps = [['title' => str, 'body' => str], ...] */
function ww_email_steps(string $heading, array $steps): string {
    $colors = ['#F7C84A', '#3FCFA8', '#FFF8E7'];
    $rows = '';
    foreach ($steps as $i => $s) {
        $color = $colors[$i % 3];
        $n = $i + 1;
        $last = ($i === count($steps) - 1);
        $rows .= '<tr>'
            . '<td style="padding:0 0 ' . ($last ? '0' : '14px') . ';vertical-align:top;width:44px;">'
            . '<div style="width:34px;height:34px;background:' . $color . ';border:2px solid #12184A;border-radius:50%;font-family:\'Nunito\',sans-serif;font-weight:900;font-size:16px;text-align:center;line-height:30px;color:#12184A;">' . $n . '</div>'
            . '</td>'
            . '<td style="padding:0 0 ' . ($last ? '0' : '14px') . ';vertical-align:top;font-family:Inter,sans-serif;font-size:15px;line-height:1.55;color:#12184A;">'
            . '<strong>' . htmlspecialchars($s['title']) . '</strong> ' . $s['body']
            . '</td></tr>';
    }
    return '<tr><td style="padding:32px 36px 12px;">'
        . '<h2 style="font-family:\'Nunito\',-apple-system,sans-serif;font-weight:900;font-size:24px;letter-spacing:-0.02em;margin:0 0 14px;color:#12184A;">' . htmlspecialchars($heading) . '</h2>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $rows . '</table></td></tr>';
}

/** Bulleted list with checkmark prefix. */
function ww_email_bullets(array $items): string {
    $rows = '';
    foreach ($items as $item) {
        $rows .= '<tr>'
            . '<td style="vertical-align:top;width:20px;padding:6px 0;font-family:\'Nunito\',sans-serif;font-weight:900;font-size:16px;color:#3FCFA8;">&#10003;</td>'
            . '<td style="padding:6px 0 6px 8px;font-family:Inter,sans-serif;font-size:15px;line-height:1.55;color:#12184A;">' . $item . '</td>'
            . '</tr>';
    }
    return '<tr><td style="padding:14px 36px 4px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $rows . '</table>'
        . '</td></tr>';
}

/** Big pill CTA with yellow drop-shadow. */
function ww_email_cta(string $label, string $href): string {
    return '<tr><td align="center" style="padding:28px 36px 12px;">'
        . '<a href="' . htmlspecialchars($href) . '" style="display:inline-block;background:#12184A;color:#FFF8E7;font-family:\'Nunito\',sans-serif;font-weight:900;font-size:16px;letter-spacing:0.02em;text-decoration:none;padding:16px 32px;border-radius:999px;box-shadow:5px 5px 0 #F7C84A;">'
        . htmlspecialchars($label) . ' &rarr;</a>'
        . '</td></tr>';
}

/** Small centered subtext after CTA. */
function ww_email_subtext(string $html): string {
    return '<tr><td style="padding:8px 36px 28px;font-family:Inter,sans-serif;font-size:13px;line-height:1.55;text-align:center;color:#12184A;opacity:0.7;">' . $html . '</td></tr>';
}

// ============================================================
// EMAIL TEMPLATES — each returns ['subject' => str, 'html' => str]
// ============================================================

/** 1. Order received (customer). */
function ww_email_order_received(array $v): array {
    $first = htmlspecialchars($v['first_name'] ?? 'friend');
    $biz   = htmlspecialchars($v['business_name'] ?? '');
    $plan  = htmlspecialchars($v['plan_label'] ?? '');
    $amt   = htmlspecialchars($v['amount'] ?? '$0');
    $body  = ww_email_hero("You're", "in.")
           . ww_email_para("Thanks <strong>$first</strong>. Payment cleared and your build is officially in the queue. We&rsquo;ll reach out shortly to get the details we need from you.")
           . ww_email_card('Order summary', [
                'Business'      => $biz,
                'Plan'          => $plan,
                'Charged today' => $amt,
            ])
           . ww_email_steps('What happens next.', [
                ['title' => "We'll reach out.",   'body' => 'Keep an eye on your inbox.'],
                ['title' => 'We design and build.', 'body' => 'Hand-built site, two rounds of revisions, real human notes.'],
                ['title' => 'Your site goes live.', 'body' => 'A handful of days, start to finish.'],
            ])
           . ww_email_cta('Reply with any questions', 'mailto:hello@trywebwiz.com')
           . ww_email_subtext('Questions? Hit reply. A real human reads every one.');
    return [
        'subject' => "You're in. Welcome to WebWiz.",
        'html'    => ww_email_shell("PAYMENT RECEIVED \xE2\x98\x85 ORDER CONFIRMED \xE2\x98\x85 BUILD STARTING", "You're in. Welcome to WebWiz.", $body),
    ];
}

/** 2. Payment failed (customer). */
function ww_email_payment_failed(array $v): array {
    $first    = htmlspecialchars($v['first_name'] ?? 'there');
    $amt      = htmlspecialchars($v['amount'] ?? '');
    $invoice  = (string)($v['hosted_invoice_url'] ?? '');
    $body     = ww_email_hero('Heads', 'up.')
              . ww_email_para("Hey <strong>$first</strong>. Your card got declined for the $amt charge on your WebWiz care plan. No big deal, things happen.")
              . ww_email_para('Stripe will retry automatically over the next few days. To skip the wait, update your card now.');
    if ($invoice !== '') {
        $body .= ww_email_cta('Update payment method', $invoice);
    }
    $body .= ww_email_subtext('If your card got replaced or the bank flagged it, this fixes in a minute. Reply if you want a hand.');
    return [
        'subject' => 'Quick thing. Your WebWiz card got declined.',
        'html'    => ww_email_shell("PAYMENT DECLINED \xE2\x98\x85 ACTION NEEDED", 'Payment declined', $body),
    ];
}

/** 3. Subscription cancelled (customer). */
function ww_email_sub_cancelled(array $v): array {
    $first = htmlspecialchars($v['first_name'] ?? 'there');
    $body  = ww_email_hero('Sorry to see you', 'go.')
           . ww_email_para("Hey <strong>$first</strong>. Your WebWiz care plan has been cancelled. You won&rsquo;t be charged again.")
           . ww_email_para('A couple of things to know:', 24)
           . ww_email_bullets([
                'Your site stays live through the end of the billing period.',
                'After that, hosting goes offline. We can hand off the files for you to host elsewhere. Just reply.',
                'If you change your mind, signing up again is one click.',
            ])
           . ww_email_para('Anything we could have done better? Hit reply and tell us. We read every one.', 24)
           . ww_email_cta('Reply with feedback', 'mailto:hello@trywebwiz.com')
           . ww_email_subtext('Thanks for trusting us with your site.');
    return [
        'subject' => 'Your WebWiz plan has been cancelled',
        'html'    => ww_email_shell("PLAN CANCELLED \xE2\x98\x85 SITE STAYS LIVE THIS PERIOD", 'Plan cancelled', $body),
    ];
}

// ----- ADMIN ALERTS -----

/** 4. New order alert (admin). */
function ww_email_admin_new_order(array $v): array {
    $biz = htmlspecialchars($v['business_name'] ?? '(no business name)');
    $plan = htmlspecialchars($v['plan_label'] ?? '');
    $amt = htmlspecialchars($v['amount'] ?? '');
    $rows = [];
    foreach ([
        'Contact'      => $v['contact_name']  ?? '',
        'Email'        => $v['customer_email'] ?? '',
        'Phone'        => $v['phone']         ?? '',
        'Current site' => $v['current_site']  ?? '',
        'What they do' => $v['what_you_do']   ?? '',
        'Audience'     => $v['audience']      ?? '',
        'Inspiration'  => $v['inspiration']   ?? '',
        'Notes'        => $v['notes']         ?? '',
    ] as $k => $val) {
        if ((string)$val === '') continue;
        $rows[$k] = nl2br(htmlspecialchars((string)$val));
    }
    $body = ww_email_hero('New', 'order.', $biz)
          . ww_email_para("<strong>$plan</strong> &nbsp;&middot;&nbsp; $amt")
          . ww_email_card('Customer details', $rows);
    $stripe = !empty($v['payment_intent']) ? 'https://dashboard.stripe.com/payments/' . urlencode((string)$v['payment_intent']) : 'https://dashboard.stripe.com/';
    $body .= ww_email_cta('View in Stripe', $stripe)
           . ww_email_subtext('<a href="https://trywebwiz.com/admin/" style="color:#12184A;">Open WebWiz admin</a>');
    return [
        'subject' => "[WebWiz] New order: $biz",
        'html'    => ww_email_shell("NEW ORDER \xE2\x98\x85 " . $amt, "New order: $biz", $body),
    ];
}

/** 5. Payment failed alert (admin). */
function ww_email_admin_payment_failed(array $v): array {
    $email = htmlspecialchars($v['customer_email'] ?? 'unknown');
    $amt   = htmlspecialchars($v['amount'] ?? '');
    $invoice = (string)($v['hosted_invoice_url'] ?? '');
    $body  = ww_email_hero('Card', 'declined.')
           . ww_email_para("Invoice failed for <strong>$email</strong> &middot; $amt.")
           . ww_email_para('Stripe will retry automatically over the next few days.', 8);
    if ($invoice !== '') $body .= ww_email_cta('Hosted invoice', $invoice);
    $body .= ww_email_subtext('Customer was also notified.');
    return [
        'subject' => "[WebWiz] Payment failed: $email",
        'html'    => ww_email_shell("PAYMENT FAILED \xE2\x98\x85 STRIPE RETRYING", 'Payment failed', $body),
    ];
}

/** 7. /try preview-ready notification (sent when user submitted notify-form). */
function ww_email_preview_ready(array $v): array {
    $biz = htmlspecialchars($v['business_name'] ?? 'your business');
    $url = htmlspecialchars($v['preview_url'] ?? 'https://trywebwiz.com/try/');
    $body = ww_email_hero("It's", 'ready.')
          . ww_email_para("Hey there. Wizzy just finished designing your <strong>$biz</strong> website. It&rsquo;s waiting for you.")
          . ww_email_cta('See your website', $url)
          . ww_email_subtext('Love what you see? Hit the Buy button on the preview to make it yours.');
    return [
        'subject' => 'Your website is ready. Come take a look.',
        'html'    => ww_email_shell("YOUR WEBSITE IS READY \xE2\x98\x85 CLICK TO SEE IT", "$biz is ready", $body),
    ];
}

/** 6. Subscription cancelled alert (admin). */
function ww_email_admin_sub_cancelled(array $v): array {
    $email = htmlspecialchars($v['customer_email'] ?? 'unknown');
    $body  = ww_email_hero('Sub', 'cancelled.')
           . ww_email_para("<strong>$email</strong> just cancelled their care plan. Site goes offline at end of period.")
           . ww_email_subtext('Customer was also notified.');
    return [
        'subject' => "[WebWiz] Subscription cancelled: $email",
        'html'    => ww_email_shell("SUB CANCELLED \xE2\x98\x85 SITE GOES OFFLINE EOP", 'Sub cancelled', $body),
    ];
}
