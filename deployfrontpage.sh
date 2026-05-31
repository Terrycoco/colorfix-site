#!/bin/bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/deploy-env.sh"

echo "🧱 Regenerating front-page payload..."
npm run prebuild-front-page

echo "🏗️  Building site..."
npm run build

echo "🧱 Injecting prebuilt homepage HTML..."
npm run prebuild-homepage-html

echo "🚀 Uploading front-page cache payloads..."
deploy_ssh "mkdir -p public_html/colorfix/api/cache"
deploy_rsync -avz \
  -e "$RSYNC_SSH" \
  "$SCRIPT_DIR/api/cache/front-page-public.json" \
  "$SCRIPT_DIR/api/cache/front-page-admin.json" \
  "$REMOTE_TARGET:public_html/colorfix/api/cache/"

echo "🚀 Deploying rebuilt front page..."
bash "$SCRIPT_DIR/deploy.sh"

echo "✅ Front-page prebuild and deployment complete."
