# WebWiz

Marketing site + AI website-preview generation platform for **trywebwiz.com**.
Hand-designed single-page websites for small businesses — flat $499 build, optional care plans.

Built by BusySeed. Runs on the SeedSite OpenLiteSpeed + PHP 8.3 stack.

## Layout

```
public/                 Web root (OpenLiteSpeed docroot)
  index.html            Marketing homepage
  start.html            Order form (/start) — plans, customize flow
  privacy.html terms.html cancel.html success.php
  legal_shared.css
  .htaccess             HTTPS + pretty-URL rewrites + LSCache rules
  admin/index.php       Admin dashboard (auth, stats, customers, sites,
                        jobs, prospects + Google Places quick-add, CSV import)
  api/
    checkout.php        Stripe Checkout session
    webhook.php         Stripe webhook handler
    wizzy.php           Wizzy chat backend (Sonnet)
    img.php             Image proxy w/ branded SVG fallback
    places_search.php   Google Places (New) text-search proxy
    prospect_add.php    Manual single-prospect add
  preview/index.php     Customer-facing preview gallery (/preview/{token}/)

private/                Outside web root
  webwiz_lib.php        DB (SQLite/PDO), helpers, migrations
  worker.php            Cron worker: scrape -> generate 3 variants -> QA
  lib/scrape.php        Homepage/multi-page scraper + image validation
  lib/anthropic.php     Anthropic Messages API helper + cost tracking
```

## Config
- `secrets.php` (gitignored) holds API keys — see `secrets.example.php`.
- App data in `data/webwiz.db` (SQLite, gitignored).
- Worker cron: `* * * * * sudo -u nobody php8.3 /var/www/sites/trywebwiz/private/worker.php`

## Generation pipeline
CSV import or Google quick-add → prospect + queued job → worker scrapes the
current site, validates images, asks Claude Sonnet for 3 design variants
(Bold Editorial / Modern Maximalist / Refined Minimal), runs a structural
quality gate, and publishes to `/preview/{token}/`. Optional visual QA pass
(off by default) screenshots + vision-checks each variant.
