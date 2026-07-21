#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

JOB_ID="${1:-}"
PREVIEW_FILE="exports/youtube-preview/current/preview.mp4"

echo "YouTube preview file:"
echo "  ${PWD}/${PREVIEW_FILE}"
echo

if [[ ! -f "$PREVIEW_FILE" ]]; then
  echo "No preview found. Create one first from the YouTube analyzer Preview video button."
  exit 1
fi

echo "When this preview is approved, make sure the matching analyzer job has been clicked Run in the admin UI."
echo "This script renders the queued job locally and uploads/completes it automatically."
echo

if [[ -n "$JOB_ID" ]]; then
  echo "Running YouTube worker for job #${JOB_ID}..."
  node scripts/youtube-render-worker.mjs "--job-id=${JOB_ID}"
else
  echo "Running YouTube worker for the next queued job..."
  node scripts/youtube-render-worker.mjs --once
fi
