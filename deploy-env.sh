#!/usr/bin/env bash

# Shared SSH settings for Bluehost deploys. Use the server IP and key explicitly
# so deploy scripts do not depend on DNS or the local ~/.ssh/config state.
REMOTE_USER="${REMOTE_USER:-shortgal}"
REMOTE_HOST="${REMOTE_HOST:-162.241.219.134}"
REMOTE_KEY="${REMOTE_KEY:-$HOME/.ssh/colorfix_key}"
REMOTE_TARGET="${REMOTE_USER}@${REMOTE_HOST}"
SSH_CONTROL_PATH="${SSH_CONTROL_PATH:-$HOME/.ssh/colorfix-deploy-%r@%h:%p}"

SSH_BASE=(
  ssh
  -i "$REMOTE_KEY"
  -o IdentitiesOnly=yes
  -o StrictHostKeyChecking=no
  -o ControlMaster=auto
  -o ControlPersist=120s
  -o ControlPath="$SSH_CONTROL_PATH"
)
RSYNC_SSH="ssh -i ${REMOTE_KEY} -o IdentitiesOnly=yes -o StrictHostKeyChecking=no -o ControlMaster=auto -o ControlPersist=120s -o ControlPath=${SSH_CONTROL_PATH}"
DEPLOY_RETRIES="${DEPLOY_RETRIES:-5}"
DEPLOY_RETRY_SLEEP="${DEPLOY_RETRY_SLEEP:-8}"

deploy_retry() {
  local label="$1"
  shift
  local attempt=1

  while true; do
    if "$@"; then
      return 0
    fi

    if (( attempt >= DEPLOY_RETRIES )); then
      echo "❌ ${label} failed after ${attempt} attempts."
      return 1
    fi

    echo "⚠️ ${label} failed on attempt ${attempt}; retrying in ${DEPLOY_RETRY_SLEEP}s..."
    sleep "$DEPLOY_RETRY_SLEEP"
    attempt=$((attempt + 1))
  done
}

deploy_ssh() {
  deploy_retry "SSH command" "${SSH_BASE[@]}" "$REMOTE_TARGET" "$@"
}

deploy_rsync() {
  deploy_retry "rsync upload" /opt/homebrew/bin/rsync "$@"
}
