#!/bin/bash
set -euo pipefail

SSH_HOST="shortgal@terrymarr.com"
REMOTE_ROOT="/home/shortgal/public_html/colorfix"
PHOTO_ROOT="${REMOTE_ROOT}/photos"
MIN_MB="${MIN_MB:-1}"
QUALITY="${QUALITY:-88}"

ssh "$SSH_HOST" "cd \"$REMOTE_ROOT\" && php scripts/compress_photos.php --root=\"$PHOTO_ROOT\" --min-mb=\"$MIN_MB\" --quality=\"$QUALITY\""
