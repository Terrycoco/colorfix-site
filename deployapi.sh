#!/bin/bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/deploy-env.sh"

echo "🚀 Deploying PHP API files via SSH..."

# Configuration
LOCAL_API_DIR="api/"
REMOTE_PATH="public_html/colorfix/api"

# Deploy using rsync over SSH
deploy_rsync -avz --delete \
  -e "$RSYNC_SSH" \
  "$LOCAL_API_DIR" "$REMOTE_TARGET:$REMOTE_PATH"

echo "✅ API deployment complete."
