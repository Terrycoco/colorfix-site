#!/bin/bash
set -euo pipefail

echo "🚀 Deploying share files via SSH..."

# Configuration
LOCAL_SHARE_DIR="share/"
REMOTE_USER="shortgal"
REMOTE_HOST="terrymarr.com"
REMOTE_PATH="public_html/colorfix/share"

# Deploy using rsync over SSH
/opt/homebrew/bin/rsync -avz --delete \
  -e "ssh -o StrictHostKeyChecking=no" \
  "$LOCAL_SHARE_DIR" "$REMOTE_USER@$REMOTE_HOST:$REMOTE_PATH"

echo "✅ Share deployment complete."
