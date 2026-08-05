#!/bin/bash
# Regenerate the /o/ showcase thumbnails from the live preview pages.
# See private/qa-tools/showcase-thumbs.sh (installed copy).
set -u
OUT=/var/www/sites/trywebwiz/public/preview/showcase
mkdir -p "$OUT"
TOKENS="d1fa58b89d4ce070c8700954 e2f229e6240312fb9b6a3c8c 96e77503e68e48db16f7e9cf"
for t in $TOKENS; do
  ud=$(mktemp -d /tmp/wwshot-XXXX)
  png=$ud/shot.png
  HOME=$ud /bin/google-chrome-stable --headless=new --no-sandbox --disable-gpu \
    --disable-dev-shm-usage --disable-breakpad --no-crash-upload \
    --disable-features=Crashpad --hide-scrollbars --force-color-profile=srgb \
    --user-data-dir=$ud --crash-dumps-dir=$ud \
    --window-size=1280,900 --virtual-time-budget=12000 \
    --screenshot=$png "https://trywebwiz.com/preview/$t/v1/index.html" >/dev/null 2>&1
  if [ ! -s "$png" ]; then echo "FAIL $t (no png)"; rm -rf $ud; continue; fi
  # 760px wide = ~2.2x the 350px mobile tile. WebP + JPEG fallback.
  convert "$png" -resize 760x534^ -gravity north -extent 760x534 -strip -quality 74 "$OUT/$t.webp"
  convert "$png" -resize 760x534^ -gravity north -extent 760x534 -strip -quality 72 "$OUT/$t.jpg"
  echo "OK $t  png=$(stat -c%s $png)  webp=$(stat -c%s $OUT/$t.webp)  jpg=$(stat -c%s $OUT/$t.jpg)"
  rm -rf $ud
done
chown -R www-data:www-data "$OUT"
chmod 644 "$OUT"/*
