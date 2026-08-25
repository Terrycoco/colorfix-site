#!/bin/zsh
set -euo pipefail

CONFIG_DIR="$HOME/.config/colorfix"
SECRET_FILE="$CONFIG_DIR/pub-video-worker.secret"

mkdir -p "$CONFIG_DIR"
chmod 700 "$CONFIG_DIR"

if [ -n "${COLORFIX_RENDER_WORKER_SECRET:-}" ]; then
  SECRET="$COLORFIX_RENDER_WORKER_SECRET"
  echo "Using COLORFIX_RENDER_WORKER_SECRET from this Terminal session."
else
  printf "Paste the existing server render_worker_secret: "
  IFS= read -r -s SECRET
  printf "\n"
fi

if [ -z "$SECRET" ]; then
  echo "No secret supplied. Nothing was saved." >&2
  exit 1
fi

umask 077
printf '%s\n' "$SECRET" > "$SECRET_FILE"
chmod 600 "$SECRET_FILE"

unset SECRET

echo "Saved video worker secret to:"
echo "$SECRET_FILE"
echo "You only need to run this setup again if the server secret changes."
