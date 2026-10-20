#!/usr/bin/env bash

#MISE description="Open a trace in the Playwright Trace Viewer - without a file, choose one from Tests/SystemUnderTest/e2e-results"
#USAGE arg "[file]" help="Trace zip - a file name in Tests/SystemUnderTest/e2e-results or a path"

source "$MISE_PROJECT_ROOT/mise/scripts/e2e-lib.sh"

file="${usage_file:-}"
if [ -z "$file" ]; then
    traces=$(cd "$E2E_RESULTS_DIR" 2> /dev/null && ls -t report_*.zip 2> /dev/null)
    [ -n "$traces" ] || { echo "No trace in $E2E_RESULTS_DIR - run e.g. mise run tests:e2e:runAll --trace"; exit 1; }
    # newest first; cancelled with Esc / Ctrl+C -> nothing opened
    file=$(echo "$traces" | gum choose --header "Which trace? (newest first)") || exit 1
fi
if [ ! -f "$file" ] && [ -f "$E2E_RESULTS_DIR/$file" ]; then
    file="$E2E_RESULTS_DIR/$file"
elif [ ! -f "$file" ] && [ -f "${MISE_ORIGINAL_CWD:-.}/$file" ]; then
    file="${MISE_ORIGINAL_CWD:-.}/$file"
fi
[ -f "$file" ] || { echo "Trace not found: $file"; exit 1; }
file=$(cd "$(dirname "$file")" && pwd)/$(basename "$file")

# the bridge runs in a container - the viewer needs Playwright on the host: the bridge's own version, installed once and
# again only when package-lock.json changed. The marker is touched ourselves: npm leaves its own files alone when nothing
# changed, they'd stay older than a checked out lock file
installed="$E2E_BRIDGE_DIR/node_modules/.trace-viewer-installed"
if [ ! "$installed" -nt "$E2E_BRIDGE_DIR/package-lock.json" ]; then
    echo "Installing Playwright for the trace viewer..."
    (cd "$E2E_BRIDGE_DIR" && npm ci && npx playwright install chromium && touch "$installed") || exit 1
fi
echo "Opening $file"
cd "$E2E_BRIDGE_DIR" && npx playwright show-trace "$file"
