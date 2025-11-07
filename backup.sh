#!/bin/bash

echo "📦 Backing up entire colorfix-site folder..."

# Config
FOLDER_NAME="colorfix-site"
BACKUP_NAME="${FOLDER_NAME}-$(date +%F-%H%M%S).zip"
REMOTE_USER="shortgal"
REMOTE_HOST="terrymarr.com"
REMOTE_PATH="public_html/colorfix_backups"

# Save current directory and move up one level
START_DIR=$(pwd)
cd ..

# 🗜️ Create zip of the entire folder
echo "🗜️  Zipping $FOLDER_NAME to $BACKUP_NAME..."
zip -r "$BACKUP_NAME" "$FOLDER_NAME"

# 📤 Upload to remote
echo "📤 Uploading $BACKUP_NAME to $REMOTE_HOST:$REMOTE_PATH ..."
scp "$BACKUP_NAME" "$REMOTE_USER@$REMOTE_HOST:$REMOTE_PATH"

# 🧹 Delete local zip
rm "$BACKUP_NAME"
echo "🧹 Local zip removed"

# Restore original directory
cd "$START_DIR"

# ✅ Auto-delete old backups on remote (keep last 5)
echo "🧹 Cleaning old backups on remote (keep last 5)..."
ssh "$REMOTE_USER@$REMOTE_HOST" <<EOF
  cd "$REMOTE_PATH" || exit
  ls -tp | grep '.zip$' | tail -n +6 | xargs -r rm --
EOF

echo "✅ Backup complete."
