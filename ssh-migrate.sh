#!/bin/bash
set -euo pipefail

# Simple helper to SSH into the server and run the migration script
REMOTE_USER="shortgal"
REMOTE_HOST="terrymarr.com"

echo "🔐 Connecting to ${REMOTE_USER}@${REMOTE_HOST}..."
ssh -o StrictHostKeyChecking=no "${REMOTE_USER}@${REMOTE_HOST}" <<'EOF'
cd ~/public_html/colorfix || exit 1
php api/tools/run-migrations.php
EOF
echo "✅ Migrations executed on remote server."
