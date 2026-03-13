#!/bin/bash
set -euo pipefail

SSH_HOST="shortgal@terrymarr.com"
REMOTE_ROOT="/home/shortgal/public_html/colorfix"
PHOTO_ROOT="${REMOTE_ROOT}/photos"
MIN_MB="${MIN_MB:-1}"
QUALITY="${QUALITY:-92}"
PNG_LEVEL="${PNG_LEVEL:-9}"
MAX_DIM="${MAX_DIM:-0}"
DRY_RUN="${DRY_RUN:-0}"
STRIP="${STRIP:-0}"

CMD="php scripts/compress_photos.php --root=\"$PHOTO_ROOT\" --min-mb=\"$MIN_MB\" --quality=\"$QUALITY\" --png-level=\"$PNG_LEVEL\""

if [ "$MAX_DIM" != "0" ]; then
  CMD="$CMD --max-dim=\"$MAX_DIM\""
fi

if [ "$DRY_RUN" = "1" ]; then
  CMD="$CMD --dry-run"
fi

if [ "$STRIP" = "1" ]; then
  CMD="$CMD --strip"
fi

ssh "$SSH_HOST" "cd \"$REMOTE_ROOT\" && $CMD"
