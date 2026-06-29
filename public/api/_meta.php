<?php
// /api/_meta.php — Meta Pixel + Conversions API helpers.
// Library only. Direct HTTP access to this file returns nothing useful.

declare(strict_types=1);

define('WW_META_PIXEL_ID', '1974530180093513');

function ww_meta_secret(string $k): string {
    static $cache = null;
    if ($cache === null) {
        $p = __DIR__ . '/../../secrets.php';
        $cache = file_exists($p) ? (require $p) : [];
        if (!is_array($cache)) $cache = [];
    }
    return (string)($cache[$k] ?? '');
}

function ww_meta_token(): string {
    // Accept either key — SeedSite Secrets UI saved it as CAPI_TOKEN; the
    // original spec called it META_CAPI_ACCESS_TOKEN. Either works.
    $t = ww_meta_secret('META_CAPI_ACCESS_TOKEN');
    if ($t === '') $t = ww_meta_secret('CAPI_TOKEN');
    return $t;
}

function ww_meta_test_code(): string {
    // When set, all events route to Events Manager's Test Events tab instead of
    // production ad-optimization data. Leave empty for production traffic.
    return ww_meta_secret('META_TEST_EVENT_CODE');
}

// SHA256 hash a PII field per Meta CAPI requirements.
function ww_meta_hash(?string $v): ?string {
    if ($v === null) return null;
    $v = strtolower(trim($v));
    if ($v === '') return null;
    return hash('sha256', $v);
}

// Deterministic event_id derived from a seed (e.g. Stripe session ID) so the
// client-side Pixel call and the server-side CAPI call share an event_id and
// Meta dedups them. Pass empty seed for random IDs.
function ww_meta_event_id(string $seed = ''): string {
    if ($seed !== '') return 'ww_' . substr(hash('sha256', $seed), 0, 24);
    try {
        return 'ww_' . bin2hex(random_bytes(12));
    } catch (Throwable $e) {
        return 'ww_' . substr(hash('sha256', uniqid('', true) . mt_rand()), 0, 24);
    }
}

/**
 * Send one event to the Meta Conversions API.
 *
 * @return bool true on HTTP 2xx
 */
function ww_meta_send_event(
    string $event_name,
    string $event_id,
    array $user_data = [],
    array $custom_data = [],
    string $event_source_url = '',
    string $action_source = 'website',
    ?int $event_time = null
): bool {
    $token = ww_meta_token();
    if ($token === '') {
        error_log('[meta capi] skipped ' . $event_name . ': META_CAPI_ACCESS_TOKEN not set in secrets.php');
        return false;
    }

    $ud = [];
    foreach (['email' => 'em', 'phone' => 'ph', 'first_name' => 'fn', 'last_name' => 'ln'] as $src => $dst) {
        $h = ww_meta_hash($user_data[$src] ?? null);
        if ($h) $ud[$dst] = [$h];
    }
    if (!empty($user_data['fbp']))               $ud['fbp']               = (string)$user_data['fbp'];
    if (!empty($user_data['fbc']))               $ud['fbc']               = (string)$user_data['fbc'];
    if (!empty($user_data['client_ip_address'])) $ud['client_ip_address'] = (string)$user_data['client_ip_address'];
    if (!empty($user_data['client_user_agent'])) $ud['client_user_agent'] = (string)$user_data['client_user_agent'];

    $evt = [
        'event_name'    => $event_name,
        'event_time'    => $event_time ?? time(),
        'event_id'      => $event_id,
        'action_source' => $action_source,
    ];
    if ($event_source_url !== '') $evt['event_source_url'] = $event_source_url;
    if ($ud) $evt['user_data'] = $ud;
    if ($custom_data) $evt['custom_data'] = $custom_data;

    $body = ['data' => [$evt]];
    $test = ww_meta_test_code();
    if ($test !== '') $body['test_event_code'] = $test;

    $url = 'https://graph.facebook.com/v19.0/' . WW_META_PIXEL_ID . '/events?access_token=' . urlencode($token);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => ['content-type: application/json'],
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    @file_put_contents('/tmp/meta_capi.log',
        gmdate('c') . " {$event_name} eid={$event_id} http={$http} " . substr((string)$resp, 0, 300) . "\n",
        FILE_APPEND
    );

    if ($http >= 300 || $resp === false) {
        error_log('[meta capi] ' . $event_name . ' http=' . $http . ' resp=' . substr((string)$resp, 0, 400));
        return false;
    }
    return true;
}

function ww_meta_client_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $v = explode(',', (string)$_SERVER[$k])[0];
            return trim($v);
        }
    }
    return '';
}

function ww_meta_user_agent(): string {
    return (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
}

function ww_meta_cookies(): array {
    return [
        'fbp' => (string)($_COOKIE['_fbp'] ?? ''),
        'fbc' => (string)($_COOKIE['_fbc'] ?? ''),
    ];
}

// ----- Shared HTML snippet rendered on every page that wants the Pixel base.
// Echoes a <script> + <noscript> block; call once inside <head>.
function ww_meta_pixel_base_html(): string {
    $pid = WW_META_PIXEL_ID;
    return <<<HTML
<!-- Meta Pixel -->
<script>
!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
fbq('init','{$pid}');
fbq('track','PageView');
window.wwMetaTrack=function(name,params,userParams){
  var eid='ww_'+Date.now().toString(36)+Math.random().toString(36).slice(2,14);
  try{ if(window.fbq) fbq('track',name,params||{},{eventID:eid}); }catch(e){}
  try{
    var body=Object.assign({event_name:name,event_id:eid,event_source_url:location.href},params||{},userParams||{});
    if(navigator.sendBeacon){
      var blob=new Blob([JSON.stringify(body)],{type:'application/json'});
      navigator.sendBeacon('/api/capi.php',blob);
    }else{
      fetch('/api/capi.php',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify(body),keepalive:true});
    }
  }catch(e){}
  return eid;
};
</script>
<noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id={$pid}&ev=PageView&noscript=1"/></noscript>
<!-- End Meta Pixel -->
HTML;
}
