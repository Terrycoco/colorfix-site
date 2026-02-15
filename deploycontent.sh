#!/bin/bash
set -euo pipefail

echo "🚀 Deploying content files via SSH..."

# Configuration
LOCAL_CONTENT_DIR="content/"
REMOTE_USER="shortgal"
REMOTE_HOST="terrymarr.com"
REMOTE_PATH="public_html/colorfix/content"

# Ensure remote folder exists
ssh -o StrictHostKeyChecking=no "$REMOTE_USER@$REMOTE_HOST" "mkdir -p '$REMOTE_PATH'"

# Deploy using rsync over SSH
/opt/homebrew/bin/rsync -avz --delete \
  -e "ssh -o StrictHostKeyChecking=no" \
  --exclude=".DS_Store" \
  "$LOCAL_CONTENT_DIR" "$REMOTE_USER@$REMOTE_HOST:$REMOTE_PATH"

echo "✅ Content deployment complete."
