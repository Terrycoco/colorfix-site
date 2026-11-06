#!/bin/bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/deploy-env.sh"
cd "$SCRIPT_DIR"

echo "🏗️ Building the current frontend before deployment..."
npm run build

echo "🚀 Deploying the fresh site build (dist/) via SSH..."

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

# Keep fingerprinted assets so open tabs can finish loading their original build.
# New HTML points to the fresh build; old assets can be retired separately.
echo "🚀 Uploading fingerprinted assets before publishing their HTML..."
deploy_rsync -az --delay-updates \
  -e "$RSYNC_SSH" \
  "${LOCAL_DIR}assets/" "$REMOTE_TARGET:$REMOTE_PATH/assets/"

echo "🚀 Uploading files via rsync..."
deploy_rsync -avz --delay-updates --exclude='/assets/' \
  -e "$RSYNC_SSH" \
  "$LOCAL_DIR" "$REMOTE_TARGET:$REMOTE_PATH"

echo "🚀 Uploading rewrite rules..."
deploy_rsync -avz \
  -e "$RSYNC_SSH" \
  "$SCRIPT_DIR/.htaccess" "$REMOTE_TARGET:$REMOTE_PATH/.htaccess"

echo "✅ Site deployment complete."
