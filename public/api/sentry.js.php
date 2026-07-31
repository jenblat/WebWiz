<?php
// /api/sentry.js.php - browser Sentry bootstrap for WebWiz.
// The browser DSN is public by nature (it ships to every visitor) but it is still
// served from the SeedSite secrets manager so it never lands in the git repo.
// This endpoint must never break a page: on any failure it emits an inert comment.
declare(strict_types=1);

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');

$dsn = '';
$release = 'unknown';
$env = 'production';
try {
    require_once '/var/www/sites/trywebwiz/private/webwiz_lib.php';
    $s = function_exists('ww_secrets') ? ww_secrets() : array();
    $dsn = (string) ($s['SENTRY_BROWSER_DSN'] ?? '');
    if (defined('WW_RELEASE')) { $release = WW_RELEASE; }
    elseif (defined('WW_VERSION')) { $release = WW_VERSION; }
    if (isset($s['SENTRY_ENVIRONMENT']) && $s['SENTRY_ENVIRONMENT'] !== '') { $env = (string) $s['SENTRY_ENVIRONMENT']; }
} catch (\Throwable $t) {
    $dsn = '';
}

if ($dsn === '' || !preg_match('#^https://([0-9a-f]+)@#i', $dsn, $m)) {
    echo "/* webwiz sentry: SENTRY_BROWSER_DSN missing or malformed */\n";
    exit;
}
$publicKey = $m[1];

$cfg = json_encode(array(
    'release'          => 'webwiz@' . $release,
    'environment'      => $env,
    'tracesSampleRate' => 0.1,
    'sendDefaultPii'   => false,
    'ignoreErrors'     => array(
        'ResizeObserver loop limit exceeded',
        'ResizeObserver loop completed with undelivered notifications',
        'Non-Error promise rejection captured',
        'Load failed',
        'Failed to fetch',
        'NetworkError',
        'AbortError',
        'top.GLOBALS',
        // ---- in-app browser native-bridge noise (Sentry WEBWIZ-FRONTEND-3/4) ----
        // These are NOT our code and NOT a script we load. Instagram/Facebook and
        // Android WebView hosts INJECT their own JavaScript into the page, so when
        // that injected code throws it is attributed to /try/ and looks like ours.
        // Evidence: WEBWIZ-FRONTEND-3 events carry browser "Instagram 440.0.0" on
        // iOS with frames sendDataToNative / sendPageHideMessage - the host app's
        // own bridge, firing on pagehide as the user leaves, when the webview has
        // already begun tearing the bridge down. Users Impacted: 0. Our funnel
        // JavaScript is unaffected; the only real damage was burying genuine
        // errors in noise.
        //
        // Deliberately NOT fixed by shimming window.webkit.messageHandlers: faking
        // a native bridge would make the host's injected code take its "bridge
        // present" path and post to a handler that does not exist, which is a
        // worse failure than the one being suppressed.
        //
        // Substring matches, same as denyUrls above - not regexes.
        'window.webkit.messageHandlers',
        'Java object is gone',
        'Error invoking postMessage',
        'sendDataToNative',
        'sendPageHideMessage',
    ),
    // denyUrls entries coming from JSON can only be strings, and the SDK treats a
    // string as a substring match - not a regex. Regex-looking strings would never
    // match, so these are plain substrings on purpose.
    'denyUrls'         => array(
        'chrome-extension://',
        'moz-extension://',
        'safari-extension://',
        'safari-web-extension://',
        'chrome://',
        '/extensions/',
        'googletagmanager.com',
        'google-analytics.com',
        'connect.facebook.net',
    ),
), JSON_UNESCAPED_SLASHES);
$loader = 'https://js.sentry-cdn.com/' . $publicKey . '.min.js';
?>
(function () {
  if (window.__wwSentryBooted) { return; }
  window.__wwSentryBooted = true;

  var CFG = <?php echo $cfg; ?>;
  var early = [];

  function keepError(e) { if (e && e.error) { early.push(e.error); } }
  function keepRejection(e) { if (e && typeof e.reason !== 'undefined') { early.push(e.reason); } }
  window.addEventListener('error', keepError);
  window.addEventListener('unhandledrejection', keepRejection);

  window.sentryOnLoad = function () {
    try {
      if (!window.Sentry || !window.Sentry.init) { return; }
      window.Sentry.init(CFG);
      window.removeEventListener('error', keepError);
      window.removeEventListener('unhandledrejection', keepRejection);
      for (var i = 0; i < early.length; i++) {
        try { window.Sentry.captureException(early[i]); } catch (ignored) {}
      }
      early.length = 0;
      window.wwReportIssue = function (message, extra) {
        try {
          window.Sentry.withScope(function (scope) {
            scope.setLevel('error');
            scope.setTag('reported_by', 'wwReportIssue');
            if (extra) { scope.setExtras(extra); }
            window.Sentry.captureMessage(String(message));
          });
        } catch (ignored) {}
      };
      // Verification hook: loading any instrumented page with ?sentrytest=1 throws a
      // deliberate error so we can prove browser errors reach Sentry. Harmless otherwise.
      if (window.location.search.indexOf('sentrytest=1') !== -1) {
        setTimeout(function webwizBrowserProbe() {
          throw new Error('WebWiz browser probe from ' + window.location.pathname);
        }, 0);
      }
    } catch (ignored) {}
  };

  var s = document.createElement('script');
  s.src = <?php echo json_encode($loader, JSON_UNESCAPED_SLASHES); ?>;
  s.crossOrigin = 'anonymous';
  s.onerror = function () { /* CDN blocked or offline: the page keeps working */ };
  (document.head || document.documentElement).appendChild(s);
})();
