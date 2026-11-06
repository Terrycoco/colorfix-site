#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
source "$PROJECT_DIR/deploy-env.sh"

ADMIN_EMAIL="${1:-terrymarr280@gmail.com}"
if [[ ! "$ADMIN_EMAIL" =~ ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]]; then
  echo "Provide a valid admin email address."
  exit 1
fi

echo "Uploading only SYSTEM/setup files. Live login APIs and frontend stay unchanged."
deploy_ssh "mkdir -p public_html/colorfix/app/SYSTEM public_html/colorfix/scripts"
deploy_rsync -az -e "$RSYNC_SSH" \
  "$PROJECT_DIR/app/SYSTEM/" "$REMOTE_TARGET:public_html/colorfix/app/SYSTEM/"
deploy_rsync -az -e "$RSYNC_SSH" \
  "$SCRIPT_DIR/system-admin-password.php" "$REMOTE_TARGET:public_html/colorfix/scripts/"

# Interactive account changes must not be automatically retried. The password is
# entered on the remote TTY, never passed in arguments or stored in a file.
"${SSH_BASE[@]}" -t "$REMOTE_TARGET" \
  "cd public_html/colorfix && php scripts/system-admin-password.php --email='$ADMIN_EMAIL'"
