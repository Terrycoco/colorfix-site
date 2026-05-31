#!/bin/bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/deploy-env.sh"

echo "🚀 Deploying site build (dist/) via SSH..."

# Configuration
LOCAL_DIR="dist/"
REMOTE_PATH="public_html/colorfix"

# Check that build output exists
if [ ! -f "${LOCAL_DIR}index.html" ]; then
  echo "❌ Error: ${LOCAL_DIR}index.html not found. Did you run 'npm run build'?"
  exit 1
fi

echo "🧱 Injecting cached homepage HTML. Run ./deployfrontpage.sh when front-page content changes."
npm run prebuild-homepage-html

# Show what's being deployed
echo "📂 Contents of ${LOCAL_DIR}"
ls -l "$LOCAL_DIR"

# 🧹 Clean old hashed files only in assets/
echo "🧹 Cleaning old hashed files in assets/ on remote..."
deploy_ssh "
  cd "$REMOTE_PATH/assets" || exit
  rm -f *.js *.css
"

# 🚀 Upload everything from dist/ but DO NOT delete remote files outside of dist/assets
echo "🚀 Uploading files via rsync..."
deploy_rsync -avz \
  -e "$RSYNC_SSH" \
  "$LOCAL_DIR" "$REMOTE_TARGET:$REMOTE_PATH"

echo "🚀 Uploading rewrite rules..."
deploy_rsync -avz \
  -e "$RSYNC_SSH" \
  "$SCRIPT_DIR/.htaccess" "$REMOTE_TARGET:$REMOTE_PATH/.htaccess"

echo "✅ Site deployment complete."
