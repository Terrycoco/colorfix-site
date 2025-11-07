#!/bin/bash

echo "🚀 Deploying PHP API files via SSH..."

# Configuration
LOCAL_API_DIR="api/"
REMOTE_USER="shortgal"
REMOTE_HOST="terrymarr.com"
REMOTE_PATH="public_html/colorfix/api"

# Deploy using rsync over SSH
/opt/homebrew/bin/rsync -avz --delete \
  -e "ssh -o StrictHostKeyChecking=no" \
  "$LOCAL_API_DIR" "$REMOTE_USER@$REMOTE_HOST:$REMOTE_PATH"

echo "✅ API deployment complete."
