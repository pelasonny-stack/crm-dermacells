#!/usr/bin/env bash
# setup-local.sh — One-time local dev bootstrap for crm-dermacells
# Run from the monorepo root: ./scripts/setup-local.sh

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
API_DIR="${REPO_ROOT}/apps/api"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

info()    { echo -e "${GREEN}[setup]${NC} $*"; }
warn()    { echo -e "${YELLOW}[setup]${NC} $*"; }
error()   { echo -e "${RED}[setup]${NC} $*" >&2; }
die()     { error "$*"; exit 1; }

# ---------------------------------------------------------------------------
# 1. Prerequisites
# ---------------------------------------------------------------------------
info "Checking prerequisites..."

check_php_version() {
    local required_major=8 required_minor=3
    if ! command -v php &>/dev/null; then
        die "php not found. Install PHP >= 8.3 (e.g. brew install php)"
    fi
    local ver
    ver=$(php -r 'echo PHP_VERSION;')
    local major minor
    major=$(echo "$ver" | cut -d. -f1)
    minor=$(echo "$ver" | cut -d. -f2)
    if [[ "$major" -lt "$required_major" ]] || { [[ "$major" -eq "$required_major" ]] && [[ "$minor" -lt "$required_minor" ]]; }; then
        die "PHP $ver found but >= 8.3 is required. Got $ver."
    fi
    info "PHP $ver — OK"
}

check_php_version

command -v composer &>/dev/null || die "composer not found. Install via: brew install composer"
info "Composer $(composer --version --no-ansi | head -1) — OK"

command -v psql &>/dev/null || die "psql not found. Install via: brew install postgresql@17"
info "psql $(psql --version | head -1) — OK"

command -v redis-cli &>/dev/null || die "redis-cli not found. Install via: brew install redis"
info "redis-cli $(redis-cli --version) — OK"

check_node_version() {
    if ! command -v node &>/dev/null; then
        die "node not found. Install Node >= 20 (e.g. brew install node or via nvm)"
    fi
    local ver
    ver=$(node -e 'process.stdout.write(process.versions.node)')
    local major
    major=$(echo "$ver" | cut -d. -f1)
    if [[ "$major" -lt 20 ]]; then
        die "Node $ver found but >= 20 is required."
    fi
    info "Node $ver — OK"
}

check_node_version

# ---------------------------------------------------------------------------
# 2. Environment file
# ---------------------------------------------------------------------------
info "Setting up .env..."
if [[ ! -f "${API_DIR}/.env" ]]; then
    cp "${API_DIR}/.env.example" "${API_DIR}/.env"
    info ".env created from .env.example"
else
    warn ".env already exists — skipping copy"
fi

# ---------------------------------------------------------------------------
# 3. Composer install
# ---------------------------------------------------------------------------
info "Installing Composer dependencies..."
(cd "${API_DIR}" && composer install --no-interaction --prefer-dist --optimize-autoloader)

# ---------------------------------------------------------------------------
# 4. npm install (monorepo root if package.json exists; also apps/api)
# ---------------------------------------------------------------------------
info "Installing npm dependencies..."
if [[ -f "${REPO_ROOT}/package.json" ]]; then
    (cd "${REPO_ROOT}" && npm install)
else
    warn "No root package.json found — skipping root npm install"
fi

if [[ -f "${API_DIR}/package.json" ]]; then
    (cd "${API_DIR}" && npm install)
fi

# ---------------------------------------------------------------------------
# 5. Ensure Postgres DBs and roles exist (idempotent)
# ---------------------------------------------------------------------------
info "Ensuring Postgres databases and roles..."

PG_SUPERUSER="${PG_SUPERUSER:-$(whoami)}"

psql_exec() {
    psql -U "${PG_SUPERUSER}" -d postgres -c "$1" 2>&1 || true
}

psql_exec_quiet() {
    # Returns exit code; suppresses output on success, prints on error
    psql -U "${PG_SUPERUSER}" -d postgres -c "$1" >/dev/null 2>&1
}

create_role_if_missing() {
    local role="$1" password="$2" extra_opts="${3:-}"
    if ! psql -U "${PG_SUPERUSER}" -d postgres -tAc "SELECT 1 FROM pg_roles WHERE rolname='${role}'" | grep -q 1; then
        psql -U "${PG_SUPERUSER}" -d postgres -c \
            "CREATE ROLE ${role} LOGIN PASSWORD '${password}' ${extra_opts};" >/dev/null
        info "Role '${role}' created"
    else
        warn "Role '${role}' already exists — skipping"
    fi
}

create_db_if_missing() {
    local db="$1" owner="$2"
    if ! psql -U "${PG_SUPERUSER}" -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname='${db}'" | grep -q 1; then
        psql -U "${PG_SUPERUSER}" -d postgres -c \
            "CREATE DATABASE ${db} OWNER ${owner};" >/dev/null
        info "Database '${db}' created"
    else
        warn "Database '${db}' already exists — skipping"
    fi
}

# Roles
create_role_if_missing "migration_role" "migration_dev_pass" "CREATEDB"
create_role_if_missing "app_role"       "app_dev_pass"
create_role_if_missing "worker_role"    "worker_dev_pass"
create_role_if_missing "report_role"    "report_dev_pass"

# Databases
create_db_if_missing "dermacells_dev"  "migration_role"
create_db_if_missing "dermacells_test" "migration_role"

# Grant app_role access to dermacells_dev
psql -U "${PG_SUPERUSER}" -d dermacells_dev -c "GRANT ALL ON SCHEMA public TO migration_role;" >/dev/null 2>&1 || true
psql -U "${PG_SUPERUSER}" -d dermacells_dev -c "GRANT USAGE, CREATE ON SCHEMA public TO app_role, worker_role, report_role;" >/dev/null 2>&1 || true

# Grant on test DB
psql -U "${PG_SUPERUSER}" -d dermacells_test -c "GRANT ALL ON SCHEMA public TO migration_role;" >/dev/null 2>&1 || true
psql -U "${PG_SUPERUSER}" -d dermacells_test -c "GRANT USAGE, CREATE ON SCHEMA public TO app_role, worker_role, report_role;" >/dev/null 2>&1 || true

info "Postgres setup complete"

# ---------------------------------------------------------------------------
# 6. Generate APP_KEY if missing
# ---------------------------------------------------------------------------
info "Checking APP_KEY..."
if grep -q '^APP_KEY=$' "${API_DIR}/.env" 2>/dev/null || ! grep -q '^APP_KEY=base64:' "${API_DIR}/.env" 2>/dev/null; then
    (cd "${API_DIR}" && php artisan key:generate)
    info "APP_KEY generated"
else
    warn "APP_KEY already set — skipping"
fi

# ---------------------------------------------------------------------------
# 7. Run migrations
# ---------------------------------------------------------------------------
info "Running migrations..."
(cd "${API_DIR}" && php artisan migrate --force)

# ---------------------------------------------------------------------------
# 8. Seed the database
# ---------------------------------------------------------------------------
info "Seeding database..."
(cd "${API_DIR}" && php artisan db:seed)

# ---------------------------------------------------------------------------
# Done
# ---------------------------------------------------------------------------
echo ""
echo -e "${GREEN}==========================================${NC}"
echo -e "${GREEN}  crm-dermacells local setup complete!   ${NC}"
echo -e "${GREEN}==========================================${NC}"
echo ""
echo "Next steps:"
echo "  1. Start all services:    ./scripts/dev.sh"
echo "     Or with overmind:      overmind start -f Procfile.dev"
echo ""
echo "  2. Individual services:"
echo "     API server:            cd apps/api && php artisan serve"
echo "     Queue worker:          cd apps/api && php artisan horizon"
echo "     WebSockets:            cd apps/api && php artisan reverb:start"
echo "     Log tailing:           cd apps/api && php artisan pail"
echo ""
echo "  3. Filament admin:        http://localhost:8000/admin"
echo "  4. Horizon dashboard:     http://localhost:8000/horizon"
echo ""
