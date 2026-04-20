#!/bin/bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/deploy-env.sh"

echo "🚀 Deploying backend app files via SSH..."

# Configuration
LOCAL_APP_DIR="app/"
REMOTE_PATH="public_html/colorfix/app"

# Ensure remote folder exists
deploy_ssh "mkdir -p '$REMOTE_PATH'"

# Deploy using rsync over SSH
deploy_rsync -avz --delete \
  -e "$RSYNC_SSH" \
  --exclude=".DS_Store" \
  "$LOCAL_APP_DIR" "$REMOTE_TARGET:$REMOTE_PATH"

echo "✅ App deployment complete."
