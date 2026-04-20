#!/usr/bin/env bash
# Double-click (macOS) or run: ./start-gui.sh
# Starts the local seismo-generator web UI on http://127.0.0.1:8765/

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

if ! command -v php >/dev/null 2>&1; then
  echo "php not found in PATH." >&2
  read -r _
  exit 1
fi

echo "seismo-generator GUI — http://127.0.0.1:8765/"
echo "Press Ctrl+C to stop."
echo ""

if [[ "$(uname -s)" == "Darwin" ]]; then
  (sleep 0.6 && open "http://127.0.0.1:8765/") &
fi

exec php -S 127.0.0.1:8765 -t "${SCRIPT_DIR}/gui"
