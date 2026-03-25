#!/bin/bash
set -euo pipefail

echo "🚀 Deploying site build (dist/) via SSH..."

# Configuration
LOCAL_DIR="dist/"
REMOTE_USER="shortgal"
REMOTE_HOST="terrymarr.com"
REMOTE_PATH="public_html/colorfix"

# Check that build output exists
if [ ! -f "${LOCAL_DIR}index.html" ]; then
  echo "❌ Error: ${LOCAL_DIR}index.html not found. Did you run 'npm run build'?"
  exit 1
fi

# Show what's being deployed
echo "📂 Contents of ${LOCAL_DIR}"
ls -l "$LOCAL_DIR"

# 🧹 Clean old hashed files only in assets/
echo "🧹 Cleaning old hashed files in assets/ on remote..."
ssh "$REMOTE_USER@$REMOTE_HOST" <<EOF
  cd "$REMOTE_PATH/assets" || exit
  rm -f index-*.js index-*.css
EOF

# 🚀 Upload everything from dist/ but DO NOT delete remote files outside of dist/assets
echo "🚀 Uploading files via rsync..."
/opt/homebrew/bin/rsync -avz \
  -e "ssh -o StrictHostKeyChecking=no" \
  "$LOCAL_DIR" "$REMOTE_USER@$REMOTE_HOST:$REMOTE_PATH"

echo "✅ Site deployment complete."
