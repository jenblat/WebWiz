<?php
// Copy to /var/www/sites/trywebwiz/secrets.php and fill in real values.
// secrets.php is gitignored.
return [
    'STRIPE_SECRET_KEY'      => 'sk_live_xxx',
    'STRIPE_WEBHOOK_SECRET'  => 'whsec_xxx',
    'ANTHROPIC_API_KEY'      => 'sk-ant-xxx',
    'BREVO_API_KEY'          => 'xkeysib-xxx',
    'GOOGLE_PLACES_API_KEY'  => 'AIza-xxx',
    // Random secret that unlocks the guarded $1 live-payment test cell at
    // /o/t/ and /o/t/try/. Generate with: bin2hex(random_bytes(16)).
    // Leave this key ABSENT or EMPTY and the $1 cell is unreachable everywhere
    // (ww_offer_test_key_ok() fails closed), which is the correct default.
    'OFFER_TEST_KEY'         => '',
    // Postgres / SeedSite, etc.
];
