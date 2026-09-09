#!/usr/bin/env bash

set -euo pipefail


check_git_safety() {

  echo "==> Git safety check"

  if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then

    echo "❌ DEPLOY STOPPED"
    echo "This directory is not inside a Git working tree."
    exit 1

  fi


  local deletions

  deletions="$(
    git status --porcelain=v1 |
    awk '
      substr($0, 1, 1) == "D" ||
      substr($0, 2, 1) == "D"
    '
  )"


  if [[ -n "$deletions" ]]; then

    echo
    echo "🚨 DEPLOY STOPPED — GIT SHOWS TRACKED DELETIONS"
    echo
    echo "$deletions"
    echo
    echo "Review before deploying:"
    echo
    echo "  git status --short"
    echo
    echo "If the deletions are intentional, resolve/commit them first."
    echo
    exit 1

  fi


  echo "✅ no tracked deletions detected"

  echo
}


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


check_git_safety


run "deployapp.sh"

run "deployconfig.sh"

run "deployscripts.sh"

run "deployapi.sh"

run "deployshare.sh"

run "deploypublic.sh"

run "deploycontent.sh"

run "deploydb.sh"


echo "✅ all deploys finished"