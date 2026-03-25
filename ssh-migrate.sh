#!/bin/bash
set -euo pipefail

# Simple helper to SSH into the server and run the migration script
REMOTE_USER="shortgal"
REMOTE_HOST="terrymarr.com"
REMOTE_PATH="public_html/colorfix"

echo "🔐 Connecting to ${REMOTE_USER}@${REMOTE_HOST}..."
if ! ssh -o StrictHostKeyChecking=no "${REMOTE_USER}@${REMOTE_HOST}" "cd '$REMOTE_PATH' && php api/tools/run-migrations.php"; then
  echo "❌ Remote migration failed."
  exit 1
fi
echo "✅ Migrations executed on remote server."
