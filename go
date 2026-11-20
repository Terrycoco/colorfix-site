#!/usr/bin/env bash
set -euo pipefail

# Run from the project root, regardless of the current directory.
cd "$(dirname "$0")"

./deployall.sh
npm run build
./deploy.sh

echo 'All three steps completed.'
