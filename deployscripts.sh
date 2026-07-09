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

echo "🚀 Deploying Remotion renderer source..."
deploy_ssh "mkdir -p '$REMOTE_ROOT/src/remotion' '$REMOTE_ROOT/src/assets/brand' '$REMOTE_ROOT/src/components/YoutubePlayer' '$REMOTE_ROOT/src/components/BrandBumperLogo' '$REMOTE_ROOT/src/components/AnimatedHueWheel' '$REMOTE_ROOT/src/components/ColorWheel' '$REMOTE_ROOT/src/helpers'"
deploy_rsync -avz --delete \
  -e "$RSYNC_SSH" \
  "$SCRIPT_DIR/src/remotion/" "$REMOTE_TARGET:$REMOTE_ROOT/src/remotion"
deploy_rsync -avz --delete \
  -e "$RSYNC_SSH" \
  "$SCRIPT_DIR/src/assets/brand/" "$REMOTE_TARGET:$REMOTE_ROOT/src/assets/brand"
deploy_rsync -avz --delete \
  -e "$RSYNC_SSH" \
  "$SCRIPT_DIR/src/components/YoutubePlayer/" "$REMOTE_TARGET:$REMOTE_ROOT/src/components/YoutubePlayer"
deploy_rsync -avz --delete \
  -e "$RSYNC_SSH" \
  "$SCRIPT_DIR/src/components/BrandBumperLogo/" "$REMOTE_TARGET:$REMOTE_ROOT/src/components/BrandBumperLogo"
deploy_rsync -avz --delete \
  -e "$RSYNC_SSH" \
  "$SCRIPT_DIR/src/components/AnimatedHueWheel/" "$REMOTE_TARGET:$REMOTE_ROOT/src/components/AnimatedHueWheel"
deploy_rsync -avz --delete \
  -e "$RSYNC_SSH" \
  "$SCRIPT_DIR/src/components/ColorWheel/" "$REMOTE_TARGET:$REMOTE_ROOT/src/components/ColorWheel"
deploy_rsync -avz \
  -e "$RSYNC_SSH" \
  "$SCRIPT_DIR/src/helpers/assetImage.js" \
  "$SCRIPT_DIR/src/helpers/config.js" \
  "$REMOTE_TARGET:$REMOTE_ROOT/src/helpers/"

echo "✅ Scripts deployment complete."
