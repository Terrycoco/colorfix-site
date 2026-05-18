#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${1:-https://colorfix.terrymarr.com}"

check_url() {
  local label="$1"
  local url="$2"
  curl -m 15 -s -o /dev/null \
    -w "${label} status=%{http_code} dns=%{time_namelookup}s connect=%{time_connect}s tls=%{time_appconnect}s ttfb=%{time_starttransfer}s total=%{time_total}s size=%{size_download}\n" \
    "$url"
}

check_url "home" "${BASE_URL}/"
check_url "health" "${BASE_URL}/api/v2/health.php"
check_url "set_api" "${BASE_URL}/api/v2/playlist-instance-sets/get.php?id=3&aud=qr"
check_url "player_api" "${BASE_URL}/api/v2/player-playlist.php?playlist_instance_id=22&aud=qr"
