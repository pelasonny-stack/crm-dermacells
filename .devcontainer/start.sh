#!/usr/bin/env bash
# Start all services in background. Runs on every codespace start.
set -u

REPO_ROOT="/workspaces/crm-dermacells"
API_DIR="${REPO_ROOT}/apps/api"
LOG_DIR="${REPO_ROOT}/.devcontainer/logs"
mkdir -p "${LOG_DIR}"

# Refresh CODESPACE_NAME-derived env on each start (codespace name stays stable but defensive)
if [[ -n "${CODESPACE_NAME:-}" ]]; then
    ENV_FILE="${API_DIR}/.env"
    sed -i "s|^APP_URL=.*|APP_URL=https://${CODESPACE_NAME}-8000.app.github.dev|" "${ENV_FILE}" || true
    sed -i "s|^REVERB_HOST=.*|REVERB_HOST=${CODESPACE_NAME}-8080.app.github.dev|" "${ENV_FILE}" || true
    sed -i "s|^VITE_REVERB_HOST=.*|VITE_REVERB_HOST=${CODESPACE_NAME}-8080.app.github.dev|" "${ENV_FILE}" || true
fi

is_running() {
    pgrep -f "$1" >/dev/null 2>&1
}

bg() {
    local name="$1"; shift
    if is_running "$1"; then
        echo "[start] ${name} already running"
        return 0
    fi
    echo "[start] ${name}..."
    nohup "$@" > "${LOG_DIR}/${name}.log" 2>&1 &
    disown || true
}

cd "${API_DIR}"

bg api          php artisan serve --host=0.0.0.0 --port=8000
bg horizon      php artisan horizon
bg reverb       php artisan reverb:start --host=0.0.0.0 --port=8080

cd "${REPO_ROOT}"
# Vite — pnpm filter, force host bind for public port
if ! is_running "vite"; then
    echo "[start] vite..."
    nohup pnpm --filter web exec vite --host 0.0.0.0 --port 5173 > "${LOG_DIR}/vite.log" 2>&1 &
    disown || true
fi

echo ""
echo "[start] Services launching. Tail logs:"
echo "    tail -f .devcontainer/logs/{api,horizon,reverb,vite}.log"
echo ""
if [[ -n "${CODESPACE_NAME:-}" ]]; then
    echo "  Web:    https://${CODESPACE_NAME}-5173.app.github.dev"
    echo "  API:    https://${CODESPACE_NAME}-8000.app.github.dev"
    echo "  Admin:  https://${CODESPACE_NAME}-8000.app.github.dev/admin"
    echo "  Reverb: wss://${CODESPACE_NAME}-8080.app.github.dev"
fi
