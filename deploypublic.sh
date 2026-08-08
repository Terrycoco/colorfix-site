#!/bin/bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/deploy-env.sh"

echo "🚀 Deploying public root files via SSH..."

REMOTE_PATH="public_html/colorfix"

deploy_ssh "mkdir -p '$REMOTE_PATH/playlists'"

deploy_rsync -avz \
  -e "$RSYNC_SSH" \
  .htaccess sitemap.php playlist-share.php oauth-google.php qr.php card.php pv.php t.php "$REMOTE_TARGET:$REMOTE_PATH/"

deploy_rsync -avz --delete \
  -e "$RSYNC_SSH" \
  playlists/ "$REMOTE_TARGET:$REMOTE_PATH/playlists"

echo "✅ Public root deployment complete."
