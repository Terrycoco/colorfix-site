#!/bin/bash
set -euo pipefail

echo "🚀 Deploying database support files via SSH..."

LOCAL_DB_DIR="database/"
REMOTE_USER="shortgal"
REMOTE_HOST="terrymarr.com"
REMOTE_PATH="public_html/colorfix/database"

/opt/homebrew/bin/rsync -avz --delete \
  -e "ssh -o StrictHostKeyChecking=no" \
  "$LOCAL_DB_DIR" "$REMOTE_USER@$REMOTE_HOST:$REMOTE_PATH"

echo "✅ Database support deployment complete."
