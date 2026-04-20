#!/bin/bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/deploy-env.sh"

echo "🚀 Deploying share files via SSH..."

# Configuration
LOCAL_SHARE_DIR="share/"
REMOTE_PATH="public_html/colorfix/share"

# Deploy using rsync over SSH
deploy_rsync -avz --delete \
  -e "$RSYNC_SSH" \
  "$LOCAL_SHARE_DIR" "$REMOTE_TARGET:$REMOTE_PATH"

echo "✅ Share deployment complete."
