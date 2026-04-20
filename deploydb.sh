#!/bin/bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/deploy-env.sh"

echo "🚀 Deploying database support files via SSH..."

LOCAL_DB_DIR="database/"
REMOTE_PATH="public_html/colorfix/database"

deploy_rsync -avz --delete \
  -e "$RSYNC_SSH" \
  "$LOCAL_DB_DIR" "$REMOTE_TARGET:$REMOTE_PATH"

echo "✅ Database support deployment complete."
