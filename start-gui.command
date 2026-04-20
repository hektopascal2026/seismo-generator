#!/bin/bash
# macOS: double-click in Finder — opens Terminal and starts the local GUI.
cd "$(dirname "$0")" || exit 1
exec bash ./start-gui.sh
