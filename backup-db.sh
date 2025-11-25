#!/bin/bash
set -euo pipefail

# === Config ===
REMOTE_USER="shortgal"
REMOTE_HOST="terrymarr.com"
REMOTE_PATH="public_html/colorfix_backups/db"
KEEP=10
DB_NAME="shortgal_colorfix"
STAMP=$(date +%F-%H%M%S)
DUMP_NAME="${DB_NAME}-${STAMP}.sql.gz"

echo "🗄️  Dumping $DB_NAME on $REMOTE_HOST ..."
ssh "$REMOTE_USER@$REMOTE_HOST" DUMP_NAME="$DUMP_NAME" KEEP="$KEEP" DB_NAME="$DB_NAME" bash <<'EOF_REMOTE'
set -euo pipefail
DB_HOST="localhost"
DB_USER="shortgal_colorfix_admin"
DB_PASS="M0thersh1p!!"
REMOTE_PATH="public_html/colorfix_backups/db"
DUMP_NAME="${DUMP_NAME}"
KEEP="${KEEP}"
DB_NAME="${DB_NAME}"

mkdir -p "$REMOTE_PATH"
mysqldump -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" \
  --single-transaction \
  --skip-lock-tables \
  --no-tablespaces \
  --ignore-table="$DB_NAME.generated_palette_color_view" \
  --ignore-table="$DB_NAME.twin_rules_overview" \
  "$DB_NAME" | gzip > "$REMOTE_PATH/$DUMP_NAME"
cd "$REMOTE_PATH"
ls -tp | grep '.sql.gz$' | tail -n +$((KEEP+1)) | xargs -r rm --
EOF_REMOTE

echo "✅ Database backup complete. (stored on $REMOTE_HOST:$REMOTE_PATH/$DUMP_NAME)"
