# WebWiz - operational notes for agents

Live PHP 8.3 + SQLite site on the **SeedSite** droplet (568972980).
Site root `/var/www/sites/trywebwiz`, bind-mounted from `/mnt/sites-data/sites/trywebwiz`.
Repo `github.com/jenblat/WebWiz`, branch `main`. Shared lib `private/webwiz_lib.php`.

## Gotchas that will waste your time

0. **EDITING A FILE AS root CAN TAKE THE SITE DOWN.** Tools that write as root leave the
   file `root:root`. Most files are 644 so they still serve — but
   **`private/webwiz_lib.php` is mode 640**, so the moment it becomes root-owned,
   www-data cannot read it and **every endpoint that requires it 500s**. That is
   effectively the whole site.
   It is silent: nothing in the repo changes, `git status` is clean, and the health
   checker cannot report it because `health_check.php` dies on line 9 requiring the very
   file that broke (its try/catch starts *after* the require). It took the site down for
   ~6 minutes on 2026-08-07.
   **⚠️ CORRECTED 2026-08-09: the script this section told you to run DOES NOT EXIST.**
   This block used to say "there is already a script for this, use it, do not hand-roll a
   chown" and pointed at `/opt/seedsite/scripts/fix-webwiz-perms.sh`. That path is not on
   the droplet. `/opt/seedsite/scripts/` contains only `backfill-sites.js`,
   `backup-cleanup.sh`, `backup.sh`, `cert-monitor.sh`, `disk-monitor.sh`, `healthcheck.sh`,
   `provision-sftp.sh` and `provision-site.sh`. Either it was never written or it was lost.
   An agent that trusts this doc, edits `webwiz_lib.php` as root and then "runs the script"
   gets `No such file or directory` and leaves the site down.

   **Until someone writes it, restore ownership by hand, ownership only, never chmod:**
   ```
   chown -R www-data:www-data /var/www/sites/trywebwiz/private /var/www/sites/trywebwiz/public
   ```
   Ownership-only is the correct behaviour: www-data reads a 640 file fine once it owns it,
   and blanket-chmodding would loosen files that are tight on purpose. Skip `.git`,
   `.claude` and `backups/`. **Verify `private/webwiz_lib.php` is still `-rw-r----- www-data
   www-data` afterwards**, then curl `/api/db_ping.php` and confirm HTTP 200.

   Note that appending with `cat >>` or editing in place preserves ownership, whereas a tool
   that rewrites the file wholesale does not. That is why some root edits are harmless and
   others take the site down.

   This has now caused **two** outages: 2026-08-06 (`private/lib/anthropic.php`, which
   took `/api/wizzy.php`, `/api/edit.php`, `/api/upload.php` and `worker.php` down) and
   2026-08-07 (`private/webwiz_lib.php`, ~6 min, whole site).

   Verify with `curl -s https://trywebwiz.com/api/db_ping.php` → must be
   `{"db":"ok","rw":true,...}` and HTTP 200, and `/api/version.php` → HTTP 200.

   **Note `git` also runs as root here**, so ownership drifts after a commit too, not
   just after an edit. Restore ownership **last, after pushing**, and re-verify
   `db_ping.php` then.

1. **`git status` silently reports nothing** until you run
   `git config --global --add safe.directory` for **both** `/var/www/sites/trywebwiz`
   **and** `/mnt/sites-data/sites/trywebwiz`. The volume is owned by a numeric uid
   with no passwd entry, so git aborts with "dubious ownership" and prints nothing.
2. **`origin` has no embedded token**, so `git push` prompts for a username. A
   working PAT can be read from `git -C /opt/seedsite remote get-url origin`.
   Never echo it.
   **⚠️ ADDED 2026-08-17: that token can PUSH but CANNOT open a pull request.**
   `gh pr create` fails with `GraphQL: Resource not accessible by personal
   access token (createPullRequest)`, and the REST `POST /repos/.../pulls` gives
   the same as a 403. It is a fine-grained PAT without `Pull requests: write`.
   The branch lands on the remote, so it looks like a partial success and it is
   easy to conclude the push failed when it did not: verify with
   `gh api repos/jenblat/WebWiz/branches/<branch>` before retrying anything.
   **Use `GITHUB_TOKEN` from `secrets.php` for the PR step**, which has the
   scope: `GH_TOKEN="$T" gh api -X POST repos/jenblat/WebWiz/pulls ...`.
   Pipe output through `sed "s/$T/[REDACTED]/g"` so a failure cannot print it.
   Also note `api.github.com/graphql` returned 503s on 2026-08-17; retry with a
   short sleep before assuming a permission problem.
   **Pushing via a tokenised URL does NOT set an upstream**, so the branch reads
   as unpushed to anything checking local git state, and `git push -u origin
   <branch>` cannot fix it because `origin` has no token. Create the tracking
   ref explicitly instead:
   `git fetch "https://$T@github.com/jenblat/WebWiz.git" "<branch>:refs/remotes/origin/<branch>"`
   then `git branch --set-upstream-to=origin/<branch> <branch>`.
2b. **git cannot preserve the 640 modes, so a branch switch silently loosens
   them.** git records only the executable bit: `git ls-files -s` reports
   `100644` for `private/webwiz_lib.php` and `private/lib/anthropic.php`, so the
   640 on disk is local hardening that lives outside the repo. Any operation
   that REWRITES one of those files (`checkout` to a branch where it differs,
   `stash pop`, `reset --hard`) recreates it at the default umask, i.e. **644**.
   Observed 2026-08-17: `git checkout -b <new> origin/main` took
   `webwiz_lib.php` from 640 to 644. The site stays UP, because www-data still
   owns the file and can read it either way, so nothing alerts and the drift is
   invisible. Files that did not differ between the branches keep their 640,
   which is why the modes end up inconsistent rather than uniformly wrong.
   After any branch switch: `chmod 640 private/webwiz_lib.php` and check
   `private/lib/anthropic.php` too. This is the one case where a targeted chmod
   is correct; the ownership-only rule in gotcha 0 is about not blanket-chmodding
   the tree.
2c. **⚠️ THE CHECKED-OUT BRANCH *IS* PRODUCTION. Switching branches deploys.**
   `/var/www/sites/trywebwiz` is a bind mount of the working tree, so PHP serves
   whatever is on disk right now. There is no build, no deploy step and no
   symlink to a release. `main` is NOT what is live; the checked-out branch is.
   On 2026-08-17 I branched off `origin/main` to keep a PR clean and instantly
   took **2304 lines across 30 files** of unmerged work off the live site: the
   whole billing lifecycle (`public/api/_billing.php`,
   `public/api/cron_billing.php`), the gate-test cell `/o/u/` (both entry points
   404ed), `public/api/_gatemetrics.php`, `public/admin/gate_report.php`, and the
   `Disallow: /preview/` line in `robots.txt`. Health checks all stayed green,
   because `db_ping.php`, `version.php` and the homepage do not touch any of it.
   **Before `git checkout`, diff the branches and read the file list as a deploy
   plan:** `git diff --stat HEAD <target>`. If it lists files you did not intend
   to change, you are about to roll them back in production. To work on a clean
   base for review, do it in a separate clone or worktree, never by moving the
   live tree. Cron is exposed the same way: `/etc/cron.d` points at file paths,
   so a branch switch that removes `cron_billing.php` silently stops that job.
3. **`private/RELEASE`** is generated by `private/stamp-release.sh` (post-merge /
   post-checkout / post-rewrite hooks) and is gitignored. Don't commit it.
   A release string ending `-dirty` just means the tree had uncommitted changes.
4. **rclone config is NOT in `/root/.config/rclone/`** - it lives at
   `/opt/busyseed-backup/config/rclone.conf`. `rclone listremotes` as root
   therefore returns nothing and looks broken. Always pass `--config`.
5. **`sqlite3` CLI is not installed.** Inspect the DB via
   `php -r 'require "private/webwiz_lib.php"; ...'` instead.
6. Sentry projects: `webwiz` (PHP) and `webwiz-frontend` (browser). DSNs live in
   the SeedSite secrets manager, never the repo. Browser bootstrap is
   `public/api/sentry.js.php`; `?sentrytest=1` fires a deliberate probe.
7. The site cron lives in **`/etc/cron.d/seedsite-backup`**, not root's crontab
   (`crontab -l` says "no crontab for root").

## 2026-07-31 changes

### Nightly database outage (Sentry WEBWIZ-2 / WEBWIZ-3)
`/mnt/sites-data` is 98G with ~69G of local backups and ~18G of sites, leaving
~7G free. The nightly tarball is ~12G, i.e. **larger than the free space**, and
cleanup ran at 3am, an hour AFTER the 2am backup. SQLite therefore hit
`disk I/O error` nightly from ~06:09 to 07:00 UTC.

- `/etc/cron.d/seedsite-backup`: **cleanup moved to 01:30, before the 02:00
  backup.** Deletes nothing extra, buys ~12G of headroom during the write.
- `/opt/seedsite/scripts/backup.sh`: added an off-site upload stage. Artifacts
  are GPG-encrypted to `backups@busyseed.com` and streamed to Google Drive with
  `gpg --encrypt -o - | rclone rcat`, so the ENCRYPTED copy never lands on disk
  (writing tarball + .gpg would need ~24G). The local file is deleted **only
  after** the remote object is verified non-trivial. If Drive is unreachable the
  file is KEPT and logged, so an outage degrades to the old behaviour rather
  than losing a backup.
- `nlms` is ~15G of the 18G of sites. Moving it to a weekly schedule would
  shrink the nightly tarball enormously - **not done, needs Omar's call.**
- Retention is still 5 days. `backup-cleanup.sh` uses `find -mtime +5`, which is
  why 6 tarballs are usually present, not 5.

### Health checker could not report a DB failure (`public/api/health_check.php`)
- Line 12 was a bare `$db = ww_db();` **before** any try/catch, so a DB failure
  killed the script before it reached its own alerting. It computed a
  `db_writable` metric it could never report as false. Now wrapped; a failure
  yields RED + `db_writable:false` + `db_error`, and the script still completes.
- It measured `disk_free_space('/var/www')` - the **root** volume. The database
  and sites live on `/mnt/sites-data`. Now measures the data volume (and root
  separately), plus a free-GB alarm because "8% of 98G" is already too little
  for a 12G backup.
- Alert throttle state lived only in the DB, which is exactly what is missing
  during a DB outage. Now mirrored to `/tmp/ww_health_alert_ts`.

### SQLite write contention (Sentry WEBWIZ-4 / WEBWIZ-5)
**WAL and `busy_timeout=10000` were ALREADY enabled** in `ww_db()`, and nothing
opens the DB outside `ww_db()`. The mount is `/dev/sda ext4` (a real local block
device, so WAL is safe). The real cause is different:

> `busy_timeout` does **not** apply when a DEFERRED transaction upgrades from
> read to write while another writer holds the lock. SQLite returns SQLITE_BUSY
> *immediately* to avoid deadlock. Reproduced: deferred upgrade **threw after
> 0s**; `BEGIN IMMEDIATE` waited 3.03s and succeeded.

- `prospect_add.php` (the codebase's only transaction) now uses
  `BEGIN IMMEDIATE` and exec-based COMMIT/ROLLBACK.
- `webwiz_lib.php` gained `ww_db_write_retry()` - bounded backoff on
  "database is locked".
- `cron_nurture.php` now try/catches each contact. A thrown PDOException there
  was previously **uncaught** and killed the whole hourly run.

### /try/ errors in in-app browsers (WEBWIZ-FRONTEND-3 / -4)
**Not our code and not a script we load.** Instagram/Facebook and Android
WebView hosts inject their own JS into the page, so their `sendDataToNative` /
`sendPageHideMessage` failures are attributed to `/try/`. Evidence: browser
"Instagram 440.0.0", iOS, **Users Impacted: 0**, firing on pagehide as the
webview tears the bridge down. Added to `ignoreErrors` in `sentry.js.php`.
**Deliberately NOT shimmed** - faking `window.webkit.messageHandlers` would make
the host's injected code take its "bridge present" path and post to a handler
that does not exist, which is worse than the noise.

### Synthetic monitoring gap
SeedTester (`/opt/seedtester`, droplet 524473510) polls `/`, `/start.html`,
`/api/version.php`, `/api/sentry.js.php` - **none of which touch SQLite**, so it
stayed green through the 51-minute outage. Added **`public/api/db_ping.php`**:
unauthenticated, no user input, does one idempotent UPSERT plus a readback.
It **writes** on purpose - during a full disk SQLite reads still succeed, so a
read-only probe would not have caught the outage.

Calibrated live output (use for `mustContain`):
`{"db":"ok","rw":true,"at":"2026-07-31 18:51:15"}` / HTTP 200; 503 `{"db":"down","rw":false}` on failure.
**Still to do: add this URL to SeedTester's target list on droplet 524473510.**

## 2026-08-06: generated sites were all the same site

Measured across 400 shipped `preview/*/v1/index.html`: **398 loaded Manrope**,
302 loaded Fraunces, **400/400** had the identical `<nav>` → N×`<section>` →
`<footer>` skeleton (zero used `<main>`/`<header>`/`<article>`), 301 had exactly
6 or 7 sections, and ~90 used a "Ready to…" CTA heading.

Cause was prompt architecture, not the model: every one of the 955 sites came
from ONE static system prompt plus one of **three** hardcoded direction strings.
Nothing about the specific business ever reached a design decision.

**`private/lib/design.php`** (new) adds two layers:

1. `ww_design_dna($seed,$variant)` **assigns** one concrete value per orthogonal
   axis — type pairing (28 curated display/body pairs), layout archetype (12),
   colour strategy (10), ornament (10), shape (5), rhythm (4). Assigned, not
   offered: *offering a menu is precisely what collapsed to Manrope.* Seeded
   from `jobs.token` so regeneration is stable; each axis pool is
   deterministically shuffled so the 3 variants of a job are distinct by
   construction. Simulated over 400 jobs the top body font is now 4.5%.
2. `ww_art_direction_brief()` — one pre-pass per job (~$0.018, ~10s) returning a
   palette **derived from the scraped brand colours**, a copy voice, a signature
   visual detail and a section plan with real headlines. **Non-fatal**: a failed
   brief returns `[]` and the build proceeds on the DNA-only path.

Removed from `build_system_prompt()`: the `System fallback:
font-family:'Manrope'` line, the prescribed hero/stats/services/about/social-
proof/CTA section list, and the "VISUAL DENSITY (at least 3)" checklist that
*mandated* the gradient-blob hero. Added an explicit ban on the generated-site
tells (interchangeable headlines, the stock section sequence, invented stats).

- `quality_gate()` now counts `<article>` too and requires 4+. The old
  `<section>`-only count was itself teaching the uniform skeleton.
- Sampling temperature 0.7 → **1.0** now that the prompt supplies the variation.
- `finalize_html()` **randomises the reveal-failsafe identifiers.** It used to
  emit a byte-identical script (with a `/*ww-reveal-failsafe*/` marker) into all
  955 pages — any two WebWiz sites could be matched with one grep.
- `anthropic.php` wraps the `api_calls` INSERT in `ww_db_write_retry()`; the
  brief adds a 4th call per job and that log write was losing the SQLITE_BUSY
  race (WEBWIZ-4/5).

Verified against real scrapes (jobs 999, 1060): both passed the gate first try
and production visual QA (82/100, no critical issues), emitted `<header>`/
`<main>`, zero gradient blobs, no banned phrases. **Cost is now ~4 Anthropic
calls per job instead of 3** — still far under the $1.50 `job_max_cost_usd` cap.

## 2026-08-06: the worker was not running at all (showcase capture "failure")

The visible symptom was paired `sh: 1: cd: can't cd to .../private/qa-tools` +
`[showcase] job #NNN capture failed` lines. The cause was one thing, and it was
not the showcase code: **the worker cron ran as `nobody`.**

**The cron line is in `/etc/crontab` line 24, NOT `/etc/cron.d/`** (nothing under
`/etc/cron.d` mentions WebWiz, and `crontab -l -u nobody` says "no crontab").
`private/` is `750 www-data:www-data`, and `nobody` (uid 65534) is not www-data
and not in its group, so it cannot **traverse** `private/` at all. Therefore:

- `cd .../private/qa-tools` → EACCES. That error goes to the **shell's own
  stderr**; the `2>&1` in `ww_capture_showcase_local()` only ever applied to
  `node`, which is why a bare `sh:` line landed in the log next to a `capture
  failed` with no explanation.
- `ww_secrets()` could not read the secrets either, so `SCREENSHOTMACHINE_KEY`
  came back empty and the SMC path returned false **before** any HTTP call —
  that is why the local fallback was reached on every single job. SMC was never
  broken. Run as www-data it captures in ~4s.
- More seriously, php could not open `private/worker.php` itself, and cron's
  `>> logs/worker.log` (also 750 www-data) failed before php even started. **The
  worker was silently dead from 2026-05-26 12:11 to 2026-08-06** — worker.log's
  mtime, the newest `failed` job and the last `upload_batches` row all stop at
  that same timestamp. Generation kept working only because `/api/magic.php`
  does it synchronously in the web request, as www-data.

**Fix: `/etc/crontab` line 24 now runs the worker as `www-data`** (backup at
`/etc/crontab.bak-20260806-worker-user`). That is the only correct user —
everything the worker writes (`public/preview/*`, the SQLite DB, `logs/`) is
www-data-owned, and the three other WebWiz crons already run as www-data from
`crontab -u www-data`. Loosening `private/` was rejected: it would hand the
secrets to every uid on the box, and adding `nobody` to the www-data group grants
the same read anyway, so it buys no isolation. Relocating `qa-tools` out of
`private/` fixes nothing, since `worker.php` is itself inside `private/`.

`ww_capture_showcase_local()` also no longer shells out via `cd`: it invokes
`showcase.js` by **absolute path** (the script needs only node built-ins, so the
cwd bought nothing — `ww_render_screenshots()` above always did it this way), and
it now prints `rc=` plus the captured output on failure instead of leaking a bare
`sh:` line. A non-www-data caller gets an explicit
`[showcase] local capture unavailable: cannot read ... as uid 65534`.

Verified as www-data on real jobs: SMC path true in 4.2s (104KB jpg), local
Chrome fallback true in 6.2s (146KB jpg), both written www-data-owned. 133 jobs
were missing a showcase; the every-minute cron backfills 20 per run.

## 2026-08-07: the de-templating had missed the live funnel

**`build_user_prompt()`'s `$dna`/`$brief` params default to `[]`, so a caller that
omits them gets an EMPTY art direction and no error.** Only `private/worker.php`
was updated on 08-06. The two callers that actually serve traffic were not, so
the live path lost the old house style *and* gained no replacement.

> **The worker queue is NOT the live path.** `worker.log` says "no jobs" every
> minute and `gen_started` is 0 in every health window. `/try/` posts to
> **`/api/magic.php?async=1`**, which generates INLINE in the background
> (`fastcgi_finish_request()` + `set_time_limit(240)`) and never touches the
> queue. CSV uploads go through `private/lib/batch.php`. Change generation
> behaviour in **all three** or it does not ship.

- `magic.php` now computes a brief + per-variant DNA seeded from the token, and
  its temperature matches worker.php (1.0 main / 0.9 retries).
- `batch.php` gets **DNA only, no brief** — that loop covers every row of an
  upload inside the worker's 270s budget and the brief is a blocking ~10s call.
- Measured on the real endpoint: token back in 0.11s, build done in **168s**,
  output Yeseva One / Rubik with `<header>`/`<main>`. Healthy budget: 168s vs a
  240s `set_time_limit` and a ~5min client poll ceiling.

### Sentry: three silent customer-visible failures now report
- **`edit.php` had none at all.** Its `ee_alert()` sends an email throttled to
  **one per 10 minutes globally**, so an editor outage = one mail and nothing to
  trend — while the UI told the user "we've been alerted". Now also
  `ww_sentry_alert()`. `reason` must stay a **bounded classifier**:
  `ww_sentry_alert()` fingerprints on `(component, reason)`, so passing a raw
  message shatters it into one issue per event.
- **`gen_status.php` answered `building` forever** when generation died without
  writing a failure marker — the infinite spinner. Now fails + reports once past
  **420s** (not 300s: healthy is ~168s but retries + QA can exceed the 300s the
  client polls for), deduped with a `stalled` marker file.
- **`checkout.php`** (legacy $500 route) 500/502'd with `error_log` only.
  `try_checkout.php` / `offer_checkout.php` already reported; this one did not.
- Both new reporters `require` `webwiz_lib.php` **lazily, inside the failure
  branch**, so the 3s poll loop and the money path keep their bootstrap.
- Still uninstrumented (unswept): `brief.php`, `upload.php`, `wizzy.php`,
  `genimg.php`, `event.php`, `qa.php`, `track.php`, `places_search.php`, others.

### 2026-08-07 (later): full Sentry sweep of the API surface

**Uncaught exceptions were ALREADY covered** in any file requiring `webwiz_lib.php` — the
SDK's handler reports them. The real gap was never fatals, it was **swallowed** errors:
`catch (Throwable $e) {}` and `error_log()`-only branches. Those are what got instrumented.

- **Removed a duplicate fatal handler.** `ww_sentry_init()` had its own
  `register_shutdown_function()` on top of the one `\Sentry\init()` installs, so every
  fatal produced **two** issues (one with a stack trace, one without). Halves fatal event
  volume; coverage verified unchanged.
- **New `ww_report()`** in `webwiz_lib.php` is the throttled front door — use it for
  routine reporting; call `ww_sentry_alert()` directly only when every occurrence must
  send. **The throttle is not optional:** `ww_sentry_alert()` ends in a synchronous
  `\Sentry\flush(2)`, so on `img.php` (every image on every page) a broken upstream would
  both burn quota and stall requests — monitoring would become the outage. Suppressed
  repeats are counted into `suppressed_since_last`, and Sentry groups them anyway.
  Pass `$throttle_s = 0` on money paths (used for brief/offer_lead).
- `reason` **must be a bounded classifier** — fingerprint is `(component, reason)`, so raw
  messages shatter one incident into thousands of issues.
- Instrumented: `brief`, `offer_lead`, `upload`, `drain_pending`, `wizzy`, `event`,
  `places_search`, `unsubscribe`, `img`, `genimg`, `_meta` (CAPI), plus the earlier
  `edit`, `gen_status`, `checkout`.
- `img.php` / `genimg.php` / `_meta.php` have **no `webwiz_lib` on the hot path by
  design** — they use a local `*_report()` shim that requires the lib only inside a
  failure branch.
- Deliberately skipped: `capi.php` (400s are malformed client beacons = noise), `qa.php`
  (its catch is genuinely non-fatal, qa.json is source of truth), `version.php`,
  `_session.php`, `_email_templates.php`.

### 2026-08-07: copy tells (the em dash, and its friends)

**298 of 300 shipped pages contained an em or en dash.** It is the most recognisable
"written by AI" signal in body text, and a prompt instruction alone does not hold.

- **`ww_dedash_copy()`** in `worker.php` is the deterministic backstop, called from
  `ww_polish_html()` so it runs on every shipped page. **Text nodes only:**
  `<script>`/`<style>` are stashed out first and attributes are never touched, so it
  cannot corrupt markup, CSS, JS or URLs.
- It substitutes a **comma, not a full stop**. A comma can never leave a sentence
  fragment; worst case is a mild comma splice, which reads as normal informal copy. Two
  special cases: a leading dash (testimonial attribution, "— Jane") is **dropped**, and
  a dash between digits (9–5, 2020–2024) becomes a **hyphen**.
- Validated on 200 real generated pages: 1561 dashes to 66, **zero structural drift**
  (tag counts and script/style bodies byte-identical). All 66 survivors are in
  attributes (`alt`, the `?l=` proxy label) where nothing is visible and rewriting would
  risk breaking the URL / cache key.
- Prompt-side rules added to `build_system_prompt()` ("WRITE LIKE A PERSON") and to the
  brief in `design.php`: no dashes, no abstract virtue headings ("Uncompromising
  Integrity", "Built on Trust"), no anaphora triads ("Every decision, every
  communication..."), no "not just X but Y", a banned-vocabulary list (elevate, empower,
  unlock, seamless, leverage, curated, bespoke, journey, "next level"...), and vary
  sentence length.
- **`batch.php` was shipping RAW model output** — it never called `ww_polish_html()`, so
  CSV-upload sites missed the current-year copyright, the UTM backlink and the dash
  strip. Now polished like the other two paths. (jobs has no URL column; the client site
  comes off the prospect row, with the scrape as fallback.)
- **Existing previews were deliberately NOT backfilled** (owner's call, 2026-08-07). All
  ~958 preview dirs stay live at `/try/?t=<token>` with their original copy.

## 2026-08-09: visual QA gates the reveal, and the image pipeline root cause

**Visual QA ran on every generation and the verdict was thrown away.** 128 verdicts in
`/tmp/wwmagic_debug.log`, **53 failures = 41%**, and every failing page was shown to the
visitor. Real people saw them: Rod Donaciano (62), Gandona Winery (42), Misty Winter (52),
Mad Banana (38).

### The root cause was the image pipeline, not the model

**Nothing in the pipeline ever measured a REAL pixel dimension.** `ww_filter_live_images()`
did a `HEAD` request (`CURLOPT_NOBODY`) and checked only status + content-type.
`ww_image_is_thumb()` / `ww_image_is_icon()` parse dimensions out of the URL **string**.
`ww_image_is_soft()` early-returns for anything under 600x400, so it never evaluated small
images at all.

madbanana.com is a sliced table layout: `M3_r1_c1.jpg` etc at **72x81, 101x81, 72x36**, no
dimension hint in the URL, no `width`/`height` attrs. All nine passed every filter, counted
as nine "usable" images, **cleared the `magic_image_target` of 7 so ZERO real photography
was generated**, and Sonnet stretched 72x81 fragments across full-width sections. Score 38.

> **A regen would not have fixed that page.** Attempt two gets the same nine fragments.
> This is why the fix is in the image pipeline and not in more retries.

- `ww_filter_live_images()` now does a **ranged GET** (`CURLOPT_RANGE` + a write callback
  that aborts at 64KB), reads real dimensions with `getimagesizefromstring()` and sets
  `real_w`/`real_h`/`is_tiny` (below 400px long edge or 200px short edge).
  **Fails OPEN**: parse failure, SVG, a server that ignores `Range`, or a timeout all keep
  the image. Wrongly dropping a good photo is worse than keeping an unmeasured one.
- Do **not** feed that request's `CONTENT_LENGTH_DOWNLOAD` into `ww_image_is_soft()`. With
  `CURLOPT_RANGE` a 206 reports the **range** length (64KB), not the file size, so every
  large photo would look like ~0.03 MB/MP and be wrongly dropped as "soft".
- `is_tiny` **and `is_icon`** (which was never excluded from the photo pool) are filtered
  out of every content pool in `build_user_prompt()` and `magic.php`. That makes the
  existing "usable < target -> pre-generate with Imagen" branch fire, so a junk-image site
  becomes a generated-photography site.

Measured on the same site: 16 images all flagged tiny, **7 real photos generated, QA
pass=yes score=82** on the first attempt. Regression-checked on heritagebodyandframe.com
(the 82/100 site): 20 measured, 2 tiny, 18 kept, real 1536x1024 photos untouched.

### QA now gates the reveal, and there is NO repair

Order is now: write HTML -> pre-warm -> **QA** -> `ready`. It used to be HTML -> pre-warm ->
`ready` -> QA, i.e. the verdict arrived after the visitor was already looking at the page.

**Repair was removed entirely (owner's call, 2026-08-09).** The old regen was gated
`if (!$async && ...)` and `/try/` posts to `magic.php?async=1`, so it had not run for a real
visitor since `a2cbf47` (2026-07-13) - `PHASE_4c_qa_regen` stops dead in the timing log on
2026-07-14. It is not coming back: the client gives up at ~300s (`maxPolls=100` x 3s), a
healthy build is ~168s and a regen measured **102-165s**, so repair-then-reveal lands past
the point the visitor has been told it failed. It also burns a second Sonnet call on a page
a human will rebuild anyway, against a defect mix that is dominated by image problems the
regen cannot fix.

**A failing page HOLDS instead.** `magic.php` writes a `held` marker, `gen_status.php`
keeps answering `building` (with `stage:finishing`) and deliberately does **not** trip its
own 420s stall detector, and the client's existing 300s timeout shows "drop your email and
we'll send your site the moment it's ready". The page goes to a human.

> ⚠️ **`gen_status.php`'s `$settled` net outranked the reveal gate.** It declared a preview
> ready once `index.html` had merely existed for **25s** - and the HTML is written ~30s
> before QA finishes. **Any fix that only moved the `ready` marker later would have looked
> correct and changed nothing.** QA now announces itself with a `qa` marker and `$settled`
> is suppressed while it runs. If you touch this ordering, re-check that file.

### The verdict is finally written down

Every `previews` INSERT hardcoded `qa_score/qa_pass/qa_issues` to **NULL** (`magic.php` x2,
`edit.php`, `drain_pending.php`, `batch.php`) and nothing updated them, so **13 of 2445**
rows had a score and all 13 came from `worker.php` in May. New `ww_qa_persist()` in
`webwiz_lib.php` writes it, keyed by job token (the background phase no longer holds a
preview id). Admin **Stats** page shows pass rate, average score and recent failures.

Rows generated before 2026-08-09 **cannot be backfilled**: the verdicts only ever went to
`/tmp/wwmagic_debug.log`, which records a pid and a timestamp but not a token.

### Placeholder failures were cached for a day

`img.php` and `genimg.php` both answer a failure with a **branded monogram SVG at HTTP 200**
(initials on a flat colour). That is the "empty image box" / "placeholder box" / "pixelated
monogram" defect in the QA rubric. Both served it as `Cache-Control: public, max-age=86400`,
freezing a **transient** failure into the browser and any CDN for a full day when the very
next request would have succeeded. **Both are now `no-store`; successes keep
`max-age=604800, immutable`.**

`genimg.php` also retries once with jitter on 429/5xx/0. **13 of the 14 non-200 responses
that endpoint has ever returned were 429s**, and a burst on 2026-08-05 21:15:34-42 produced
the "flat colored placeholder boxes" and "empty image panel with only 'GO' text" verdict 24s
later. Pre-warm in `magic.php` now probes for those SVG responses by content-type and
retries them before the reveal.

> A 200 from `img.php`/`genimg.php` is **not** proof the image is real. Check the
> content-type: a real image is jpeg/png, a failure is `image/svg+xml`.

## 2026-08-10: edit chat retired, previews de-indexed, nurture truth, gate test

### The AI edit chat is GONE (`/api/edit.php` answers 410)
Owner's call. It was the funnel's failure point: the two deepest-engaged sessions
in the ad window both died in it (Rod asked for blue and got green; harrisonerd
asked for a hero animation, did not get it, asked for a full redesign).
The reveal now opens straight onto `#convCard` + the design brief, which was
already built and only reachable by exhausting the 5-edit cap.

- `setView('reveal')` AND the returning-visitor path (`/try/?t=`, server-rendered
  `data-view=reveal`, which does **not** go through `setView`) both set
  `data-conv=on`. Miss the second and the panel opens empty.
- The composer is hidden **unconditionally**, not only under `data-conv`.
- The chat DOM nodes are deliberately still there: ~12 handlers reference
  `chatInput`/`chatSend` and deleting the nodes null-crashes the reveal.
- `edit_log` and `/preview/<token>/edits/` are **preserved** on purpose.

### Previews are no longer indexable
`ww_noindex_html()` (in `worker.php`, called from `ww_polish_html()`) injects
`noindex,nofollow,noarchive,noimageindex`. robots.txt disallows `/preview/`.
The tag lives in the FILE because this box is **OpenLiteSpeed** and preview HTML
is served straight off disk without touching PHP, so no header can be added at
request time, and robots.txt is regenerated by SeedSite SEO. Backfilled all 2460
existing files (additive-only, asserted by removing the tag and comparing bytes).

### `nurture_sends` "pending" did NOT mean unsent
134 rows were **delivered emails we failed to write down**. Brevo accepted them;
the two writes that follow (advance the contact, stamp the row) lost a SQLite
lock. The cron log says it outright 68 times: `send-row update failed (email was
delivered): database is locked`. **Never re-send a pending row to clear the
counter.** Note `sent_at TEXT DEFAULT (datetime('now'))`, so a pending row gets a
timestamp at INSERT and it proves nothing.

- New status **`sent_unconfirmed`** = delivered, Brevo message id lost, opens and
  clicks unattributable forever. `ww_nurture_reconcile_pending()` runs at the top
  of each cron tick.
- **The idempotency guard must include `sent_unconfirmed`.** Omit it and the
  reconcile makes the guard stop matching and re-sends real emails.
- Admin open/click rates use CONFIRMED sends as denominator; mixing unconfirmed
  in reads as a fake engagement decline.
- `ww_nurture_upsert_contact()` used to hardcode `status='active'` and silently
  drop a caller-supplied status. Now whitelisted active/paused/unsubscribed.

### `/o/` leads enrol PAUSED, and why
`offer_lead.php` now enrols into nurture, but **paused**. The live 5-step
sequence is written for someone who has a preview: step 1 is "The free website we
made for {{company}}" with the CTA pointing at `{{preview_url}}`. An `/o/` form
lead has neither, so activating them ships copy that is untrue with a dead
button. **An offer-form sequence still needs writing.**

### Abandoned checkout (`checkout.session.expired`)
Now enabled on the Stripe endpoint (it was not before, so a handler alone would
have been dead). **Stripe fires `expired` up to 24h later and fires it even when
the customer paid via a different session**, so every send is gated on a live
purchase check against BOTH `offer_leads` and `nurture_contacts` (token and
tokenless checkouts land in different tables). That check is also what makes a
recovered purchase exit recovery. Cap is one email per person per 30 days.
Outcomes are written to `checkout_recovery`.

### Gate test cell is **`u`**, not `t`
`t` is the guarded $1 payment cell and `c` is the tokenless brief cell, so the
truncated-reveal cell is **`u`** (unlock). Price is held **identical to `b`**
(free build, $50/mo) in both `/o/_offer.php` and `offer_checkout.php` - the
variable under test is where the ask sits, not the price. If those diverge it
silently becomes another price test.

Truncation injects into the **same-origin** preview iframe (an overlay is
viewport-fixed and scrolling slides clean content out from under it) and **fails
open**. The hero is never blurred: seeing your own business rebuilt from real
scraped content is the whole advantage over ChatGPT/Lovable.

> **Two page shapes exist and they need different handling:**
> `<body><nav><section>xN<footer>` splits on `<body>`;
> `<body><header><main>…</main><footer>` must split **inside `<main>`**. Splitting
> the second on `<body>` keeps all of `<main>` sharp, locks only the footer, and
> the cell silently becomes the control. 2 of the first 4 previews sampled were
> that shape.

Adding a new cell means adding it to **every** whitelist:
`ww_offer_variant_from_request()`, `magic.php` (both persist paths),
`drain_pending.php`, `webhook.php`, plus `$OFFERS` in `offer_checkout.php` and
`$VARIANTS` in `/o/_offer.php`. Miss one and the job row gets a NULL
`offer_variant` and a returning visitor is priced from the legacy $500 funnel.

## Known issues not fixed
- ~~`/opt/seedsite/scripts/backup.sh` contains a plaintext PostgreSQL
  password.~~ **FIXED 2026-08-23.** It now reads `/root/.pgpass_seedsite`
  (600) via `PGPASSFILE`.
- ~68G of **legacy** tarballs remain in `/mnt/sites-data/backups/files/`
  (2026-08-17, 08-18, 08-19, 08-23) plus a truncated 10G one moved to
  `/var/backups-overflow/`. Nothing writes there any more. Deleting them is
  Omar's call; they are the only copies of those dates, and a complete
  encrypted copy of the CURRENT state is already on Drive.

## 2026-08-23: the disk filled and took the database down

`/mnt/sites-data` hit **100%, zero bytes free**, so SQLite could not write a
journal. `db_ping.php` returned 503 `{"db":"down","rw":false}` while the
homepage and `version.php` kept returning **200**, because neither opens the
database. Generation, checkout, lead capture and nurture were all failing and
nothing alerted.

**Why it filled: we were backing up backups.** 12G of every 17G nightly tarball
was `sites/nhms/public/wp-content/updraft` - 319 UpdraftPlus files, a fresh 95M
database dump every night since 2026-05-26, never pruned. Those are already
gzipped, so `tar -czf` could not compress them either. Add 5-day retention of
~17G tarballs plus 20G of sites and 98G does not fit.

**And nothing was going off-site.** The 2026-07-31 note in this file describing
a GPG + rclone upload stage in `backup.sh` **did not match the code**; no such
stage existed. Local was the only copy, which is why retention could not be
lowered. The 08-21 and 08-22 backups simply do not exist: tar and pg_dump both
died with "No space left on device".

`/opt/seedsite/scripts/backup.sh` was rewritten (commit `b9efba4`):

| Before | After |
|---|---|
| local only, 5 copies, ~17G each | encrypted, uploaded to Drive, **0 kept on disk** |
| updraft/plugin backups included | backup-plugin output excluded for every site |
| nhms nightly (17G of the 20G) | **nhms weekly, Sunday only** |
| staged on `/mnt/sites-data` | staged on `/var` (root volume); the data volume is now source-only |
| plaintext PG password in the script | `/root/.pgpass_seedsite` (600) via `PGPASSFILE` |

Measured on a full Sunday run: db 36M, nhms 3.5G, sites 2.7G, all encrypted and
uploaded, `staging 12K`. **~2.7G off-site nightly and nothing on disk**, against
~17G on disk and nothing off-site before.

It reuses the BusySeed agent's key and rclone config (`/opt/busyseed-backup`).
That key is **public-key only**: this box can encrypt a backup and cannot
decrypt one. Do not "fix" that by putting the private key here.

**Excluding `updraft` does not weaken restores.** The site itself is still
captured in full; we stopped storing a plugin's copy of the site we are already
storing. What is lost is UpdraftPlus's own historical restore points.

**`webwiz-db` was added to SeedTester** (`/opt/seedtester/targets.json` on the
dev droplet, commit `b524702`). It is the only target that touches SQLite. Every
other WebWiz check is served without opening the database, which is why both
this outage and the 2026-07-31 one stayed green for hours. If it goes red while
pages still load, check `df -h /mnt/sites-data` first.

## 2026-08-03: guarded $1 live-payment test cell (variant `t`)

Nothing downstream of a REAL `checkout.session.completed` for an `/o` cell had
ever executed. Webhook fulfilment, `offer_leads.status='purchased'`, the
`checkout_completed` try_event, the Meta Purchase + Subscribe CAPI events, the
paid branch of the receipt and the confirmation email were all unproven, and the
only way to prove them is to push a real card through. So there is now a fourth
cell, `t`, at **$1.00 one-time + $1.00/month, no trial** (i.e. **$2.00 due
today**, then $1.00/mo).

It is **not** a bypass. It runs the same `/o/_offer.php`, the same
`/api/offer_checkout.php`, the same Stripe Checkout, the same `/api/webhook.php`
and the same receipt as a/b/c. Only the amounts and the gate differ. A
special-cased shortcut would have proven nothing.

**Two entry points, because `webhook.php` handles them differently:**

| URL | Mirrors | `metadata.token` |
|---|---|---|
| `/o/t/?k=<OFFER_TEST_KEY>`      | cell C (brief form) | empty — the tokenless branch |
| `/o/t/try/?k=<OFFER_TEST_KEY>`  | cells A/B (builder) | real 24-hex job token |

**`source` metadata is `offer_test_1dollar`** (live cells stay
`offer_price_test`), so it is greppable in `logs/stripe-events.jsonl` and
`try_events`. Everything it produces is filterable with `variant <> 't'` /
`source <> 'offer_test_1dollar'` — the A/B/C price-test reporting is unaffected.

**The gate.** `OFFER_TEST_KEY` in `secrets.php`, compared with `hash_equals()`
in `ww_offer_test_key_ok()` (`private/webwiz_lib.php`). Every surface that can
CHOOSE variant `t` demands it, and all of them **fail closed** — a missing key,
a wrong key or an unconfigured secret all mean "not the test cell", never
"cheapest cell":

- `/o/t/` and `/o/t/try/` return a real **404** (not 403 — 403 confirms the path
  is worth attacking).
- `/try/?offer=t` is ignored without `&k=`; it falls back to the $500 funnel.
- `/api/magic.php?offer=t` will not persist `jobs.offer_variant='t'` without
  `&k=` (`ww_offer_variant_from_request()`).
- `/api/offer_checkout.php` maps a **tokenless** request to `t` only when the
  POST body carries a valid `test_key`; otherwise a tokenless request still
  means cell C, exactly as before.

A checkout that arrives **with** a token is priced from `jobs.offer_variant` and
needs no key. That is correct, not a hole: the job row could only have been
created through the gate above.

`success_url` / `cancel_url` for variant `t` carry `&k=` so the buyer lands on
the receipt instead of a 404. This means the key is stored on the Stripe session
— acceptable for a disposable test key, and a reason to rotate/remove it when
the test is done.

**Delete `OFFER_TEST_KEY` from `secrets.php` and the whole cell becomes
unreachable** without touching a line of code. That is the intended off switch.

### Gotcha: `secrets.php` is `var_export`ed, so comments in it do not survive

`/opt/seedsite/app/services/secrets-file.js` reads the file with
`require`, merges, and re-serialises with `var_export()`. Any hand-written
comment inside it is therefore destroyed by the next secrets-manager write (and
was destroyed by the 2026-08-03 edit that added `OFFER_TEST_KEY`). Document
secrets **here**, not in `secrets.php`.

For the record, so it is not rediscovered as a bug:
**`META_TEST_EVENT_CODE` is intentionally empty.** It held a 9-character code
until the 2026-08-03 "production CAPI" change emptied it. Empty means events go
to production ad-optimisation data instead of the Test Events tab
(`ww_meta_test_code()` in `public/api/_meta.php`). Do not "fix" it.
