#!/bin/bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/deploy-env.sh"

echo "🚀 Deploying content files via SSH..."

# Configuration
LOCAL_CONTENT_DIR="content/"
REMOTE_PATH="public_html/colorfix/content"

# Ensure remote folder exists
deploy_ssh "mkdir -p '$REMOTE_PATH'"

# Deploy using rsync over SSH
deploy_rsync -avz --delete \
  -e "$RSYNC_SSH" \
  --exclude=".DS_Store" \
  "$LOCAL_CONTENT_DIR" "$REMOTE_TARGET:$REMOTE_PATH"

echo "✅ Content deployment complete."
