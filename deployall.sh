#!/usr/bin/env bash
set -euo pipefail

run() {
  local f="$1"
  echo "==> $f"
  if [[ -x "./$f" ]]; then
    "./$f"
  else
    bash "./$f"
  fi
  echo "==> $f done"
  echo
}

run "deployapp.sh"
run "deployapi.sh"

echo "✅ all deploys finished"
