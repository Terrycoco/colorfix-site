#!/bin/bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/deploy-env.sh"

# Simple helper to SSH into the server and run the migration script
REMOTE_PATH="public_html/colorfix"

echo "🔐 Connecting to ${REMOTE_TARGET}..."
if ! deploy_ssh "cd '$REMOTE_PATH' && php api/tools/run-migrations.php"; then
  echo "❌ Remote migration failed."
  exit 1
fi
echo "✅ Migrations executed on remote server."
