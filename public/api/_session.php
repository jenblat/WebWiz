<?php
// Centralized session bootstrap.
// lsphp on this box does NOT honor .user.ini, and the default save_path
// (/var/lib/php/sessions) is reaped by the system phpsessionclean cron on the
// 24-min default gc_maxlifetime, which logged admins out constantly. Fix: a
// dedicated, non-web-readable save_path under private/ plus a 7-day cookie.
declare(strict_types=1);

if (!function_exists('ww_session_start')) {
    function ww_session_start(): void {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $dir = '/var/www/sites/trywebwiz/private/sessions';
        if (!is_dir($dir)) { @mkdir($dir, 0770, true); }
        if (is_dir($dir) && is_writable($dir)) { session_save_path($dir); }
        @ini_set('session.gc_maxlifetime', '604800'); // 7 days, server-side
        @ini_set('session.gc_probability', '1');
        @ini_set('session.gc_divisor', '100');
        @session_start([
            'cookie_lifetime' => 604800, // 7 days, so the cookie survives browser close
            'cookie_secure'   => true,
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
        ]);
    }
}
