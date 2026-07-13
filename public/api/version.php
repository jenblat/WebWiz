<?php
// /api/version.php — current WebWiz release version.
declare(strict_types=1);
require_once '/var/www/sites/trywebwiz/private/webwiz_lib.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode(['product' => 'WebWiz', 'version' => defined('WW_VERSION') ? WW_VERSION : 'unknown']);
