#!/bin/bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/deploy-env.sh"

echo "🚀 Deploying scripts via SSH..."

# Configuration
LOCAL_SCRIPTS_DIR="scripts/"
REMOTE_PATH="public_html/colorfix/scripts"
REMOTE_ROOT="public_html/colorfix"

# Ensure remote folder exists
deploy_ssh "mkdir -p '$REMOTE_PATH'"

# Deploy using rsync over SSH
deploy_rsync -avz --delete \
  -e "$RSYNC_SSH" \
  --exclude=".DS_Store" \
  "$LOCAL_SCRIPTS_DIR" "$REMOTE_TARGET:$REMOTE_PATH"

echo "🚀 Deploying root workflow files..."
deploy_rsync -avz \
  -e "$RSYNC_SSH" \
  package.json \
  deploy.sh \
  deployapi.sh \
  deployfrontpage.sh \
  deployscripts.sh \
  "$REMOTE_TARGET:$REMOTE_ROOT/"

echo "✅ Scripts deployment complete."
