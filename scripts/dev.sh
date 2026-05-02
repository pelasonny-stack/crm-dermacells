#!/usr/bin/env bash
# dev.sh — Start all local development services for crm-dermacells
# Usage: ./scripts/dev.sh
# Alternatively: overmind start -f Procfile.dev

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
API_DIR="${REPO_ROOT}/apps/api"

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

info() { echo -e "${GREEN}[dev]${NC} $*"; }
warn() { echo -e "${YELLOW}[dev]${NC} $*"; }

# Accumulate background PIDs for cleanup
PIDS=()

cleanup() {
    echo ""
    info "Shutting down all services..."
    for pid in "${PIDS[@]:-}"; do
        if kill -0 "$pid" 2>/dev/null; then
            kill "$pid" 2>/dev/null || true
        fi
    done
    # Give processes a moment to exit cleanly
    sleep 1
    # Force-kill any survivors
    for pid in "${PIDS[@]:-}"; do
        if kill -0 "$pid" 2>/dev/null; then
            kill -9 "$pid" 2>/dev/null || true
        fi
    done
    info "All services stopped."
    exit 0
}

trap cleanup INT TERM EXIT

# ---------------------------------------------------------------------------
# 1. Start Postgres + Redis via brew services (idempotent)
# ---------------------------------------------------------------------------
info "Starting Postgres 17..."
brew services start postgresql@17 2>/dev/null || warn "postgresql@17 already running or not installed via brew"

info "Starting Redis..."
brew services start redis 2>/dev/null || warn "redis already running or not installed via brew"

# Brief pause to let services bind
sleep 1

# ---------------------------------------------------------------------------
# 2. Laravel web server
# ---------------------------------------------------------------------------
info "Starting Laravel API server on http://localhost:8000 ..."
(cd "${API_DIR}" && php artisan serve --host=127.0.0.1 --port=8000) &
PIDS+=($!)

# ---------------------------------------------------------------------------
# 3. Laravel Horizon (queue worker)
# ---------------------------------------------------------------------------
info "Starting Laravel Horizon..."
(cd "${API_DIR}" && php artisan horizon) &
PIDS+=($!)

# ---------------------------------------------------------------------------
# 4. Laravel Reverb (WebSockets)
# ---------------------------------------------------------------------------
info "Starting Laravel Reverb on ws://localhost:8080 ..."
(cd "${API_DIR}" && php artisan reverb:start) &
PIDS+=($!)

# ---------------------------------------------------------------------------
# 5. Laravel Pail (log tailing) — optional, comment out if noisy
# ---------------------------------------------------------------------------
info "Starting Laravel Pail (log tail)..."
(cd "${API_DIR}" && php artisan pail) &
PIDS+=($!)

echo ""
echo -e "${GREEN}All services running. Press Ctrl+C to stop.${NC}"
echo ""
echo "  API:      http://localhost:8000"
echo "  Horizon:  http://localhost:8000/horizon"
echo "  Reverb:   ws://localhost:8080"
echo "  Admin:    http://localhost:8000/admin"
echo ""

# Wait for any child to exit unexpectedly
wait "${PIDS[0]}"
