#!/usr/bin/env bash
# One-time setup for crm-dermacells codespace
set -euo pipefail

REPO_ROOT="/workspaces/crm-dermacells"
API_DIR="${REPO_ROOT}/apps/api"

echo "[setup] Installing system deps..."
# Drop stale third-party repos that can break apt-get (Yarn, etc.)
sudo rm -f /etc/apt/sources.list.d/yarn.list /etc/apt/sources.list.d/yarnpkg.list 2>/dev/null || true
sudo apt-get update -qq
sudo apt-get install -y --no-install-recommends \
    libpq-dev \
    libicu-dev \
    libzip-dev \
    postgresql-client \
    redis-tools \
    unzip

echo "[setup] Installing PHP extensions (one at a time to survive partial failures)..."
# Compile from /usr/src/php to avoid docker-php-ext-enable cwd bug
cd /
for ext in pdo_pgsql pgsql intl zip bcmath pcntl; do
    if ! php -m 2>/dev/null | grep -qi "^${ext}$"; then
        sudo docker-php-ext-install -j"$(nproc)" "${ext}" || echo "[setup] WARN: ${ext} compile failed"
    fi
done

# Workaround for docker-php-ext-enable bug: write .ini files manually
PHP_CONF_DIR=/usr/local/etc/php/conf.d
for ext in intl zip bcmath pcntl pdo_pgsql pgsql; do
    INI="${PHP_CONF_DIR}/docker-php-ext-${ext}.ini"
    [[ -f "${INI}" ]] || sudo bash -c "echo 'extension=${ext}.so' > '${INI}'"
done

# Redis via PECL
if ! php -m 2>/dev/null | grep -qi "^redis$"; then
    sudo pecl install redis </dev/null || true
    sudo bash -c "echo 'extension=redis.so' > '${PHP_CONF_DIR}/docker-php-ext-redis.ini'" || true
fi

php -m | grep -E "intl|zip|pcntl|pdo_pgsql|pgsql|bcmath|redis" || true

echo "[setup] Installing pnpm..."
sudo corepack enable || true
sudo corepack prepare pnpm@latest --activate || npm install -g pnpm

echo "[setup] Composer install..."
cd "${API_DIR}"
# --ignore-platform-req=php tolerates lockfile pinned to PHP 8.4 when image is 8.3
composer install --no-interaction --prefer-dist --optimize-autoloader \
    --ignore-platform-req=php --ignore-platform-req=php+ \
  || composer update --no-interaction --prefer-dist \
        --ignore-platform-req=php --ignore-platform-req=php+

echo "[setup] Setting up .env..."
if [[ ! -f "${API_DIR}/.env" ]]; then
    cp "${API_DIR}/.env.example" "${API_DIR}/.env"
fi

# Patch .env for codespace
ENV_FILE="${API_DIR}/.env"
patch_env() {
    local key="$1" val="$2"
    if grep -qE "^${key}=" "${ENV_FILE}"; then
        # Use | as sed delimiter to avoid escaping URLs
        sed -i "s|^${key}=.*|${key}=${val}|" "${ENV_FILE}"
    else
        echo "${key}=${val}" >> "${ENV_FILE}"
    fi
}

patch_env DB_HOST db
patch_env DB_PORT 5432
patch_env DB_USERNAME postgres
patch_env DB_PASSWORD postgres
patch_env DB_DATABASE dermacells_dev
patch_env DB_MIGRATION_USERNAME postgres
patch_env DB_MIGRATION_PASSWORD postgres
patch_env DB_WORKER_USERNAME postgres
patch_env DB_WORKER_PASSWORD postgres
patch_env DB_SSLMODE disable
patch_env REDIS_HOST redis
patch_env REDIS_PORT 6379
patch_env SESSION_DOMAIN ""
patch_env SESSION_SECURE_COOKIE true
patch_env TRUSTED_PROXIES "*"

if [[ -n "${CODESPACE_NAME:-}" ]]; then
    patch_env APP_URL "https://${CODESPACE_NAME}-8000.app.github.dev"
    patch_env REVERB_HOST "${CODESPACE_NAME}-8080.app.github.dev"
    patch_env REVERB_PORT 443
    patch_env REVERB_SCHEME https
    patch_env VITE_REVERB_HOST "${CODESPACE_NAME}-8080.app.github.dev"
    patch_env VITE_REVERB_PORT 443
    patch_env VITE_REVERB_SCHEME https
fi

echo "[setup] Waiting for Postgres..."
until pg_isready -h db -U postgres -q; do sleep 1; done

echo "[setup] Ensuring databases..."
psql -h db -U postgres -tAc "SELECT 1 FROM pg_database WHERE datname='dermacells_dev'" | grep -q 1 \
    || PGPASSWORD=postgres psql -h db -U postgres -c "CREATE DATABASE dermacells_dev"
psql -h db -U postgres -tAc "SELECT 1 FROM pg_database WHERE datname='dermacells_test'" | grep -q 1 \
    || PGPASSWORD=postgres psql -h db -U postgres -c "CREATE DATABASE dermacells_test"

echo "[setup] APP_KEY..."
if ! grep -qE '^APP_KEY=base64:' "${ENV_FILE}"; then
    php artisan key:generate --force
fi

echo "[setup] Running migrations..."
php artisan migrate --force || echo "[setup] migrations had errors — continuing"

echo "[setup] Seeding..."
php artisan db:seed --force || echo "[setup] seed had errors — continuing"

echo "[setup] Storage link..."
php artisan storage:link || true

echo "[setup] Workspace deps (pnpm)..."
cd "${REPO_ROOT}"
pnpm install --frozen-lockfile || pnpm install

echo "[setup] DONE"
