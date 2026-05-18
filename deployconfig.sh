#!/bin/bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/deploy-env.sh"

echo "🚀 Deploying config files via SSH..."

LOCAL_CONFIG_DIR="config/"
REMOTE_PATH="public_html/colorfix/config"

deploy_ssh "mkdir -p '$REMOTE_PATH'"

deploy_rsync -avz \
  -e "$RSYNC_SSH" \
  --exclude="mail.local.php" \
  --exclude=".DS_Store" \
  "$LOCAL_CONFIG_DIR" "$REMOTE_TARGET:$REMOTE_PATH"

echo "✅ Config deployment complete."
