<?php
// Builder entry for the TRUNCATED REVEAL cell.
// Sets the offer, then runs the normal /try page. Default /try is untouched.
// The truncation itself lives in try/index.php, gated on this offer key.
$WW_OFFER = 'u';
require '/var/www/sites/trywebwiz/public/try/index.php';
