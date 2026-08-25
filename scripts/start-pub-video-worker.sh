#!/bin/zsh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
SECRET_FILE="$HOME/.config/colorfix/pub-video-worker.secret"

if [ ! -r "$SECRET_FILE" ]; then
  echo "PUB video worker secret is not stored yet." >&2
  echo "Run this once:" >&2
  echo "  ./scripts/setup-pub-video-worker-secret.sh" >&2
  exit 1
fi

COLORFIX_RENDER_WORKER_SECRET="$(cat "$SECRET_FILE")"

if [ -z "$COLORFIX_RENDER_WORKER_SECRET" ]; then
  echo "Stored PUB video worker secret is empty." >&2
  echo "Run setup again:" >&2
  echo "  ./scripts/setup-pub-video-worker-secret.sh" >&2
  exit 1
fi

export COLORFIX_RENDER_WORKER_SECRET

cd "$PROJECT_ROOT"
exec node ./scripts/pub-video-worker.mjs
