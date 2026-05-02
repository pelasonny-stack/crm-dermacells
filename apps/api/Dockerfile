# syntax=docker/dockerfile:1.7
# =============================================================================
# crm-dermacells API — FrankenPHP (bundles PHP-FPM + Caddy, no nginx needed)
# https://frankenphp.dev/
# =============================================================================
# Stage 1 — Build: install Composer dependencies (no dev), optimize autoloader
# Stage 2 — Runtime: FrankenPHP production image with final app code
# =============================================================================

# ---------------------------------------------------------------------------
# Stage 1: composer-build
# ---------------------------------------------------------------------------
FROM composer:2 AS composer-build

WORKDIR /app

# Copy only dependency manifests first (layer-cache friendly)
COPY composer.json composer.lock ./

# Install production dependencies only; no scripts, no autoloader yet
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --no-interaction \
    --prefer-dist

# Copy the full application source
COPY . .

# Dump the optimized autoloader with the real source in place
RUN composer dump-autoload --optimize --no-dev --no-scripts

# ---------------------------------------------------------------------------
# Stage 2: runtime (FrankenPHP)
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:1-php8.3-alpine AS runtime

LABEL org.opencontainers.image.title="crm-dermacells API" \
      org.opencontainers.image.description="Laravel 12 headless API — FrankenPHP runtime" \
      org.opencontainers.image.source="https://github.com/your-org/crm-dermacells"

# Install system-level PHP extensions required by the application
# (FrankenPHP Alpine base already ships: pdo, pdo_mysql, opcache, mbstring, json, xml, tokenizer, ctype, fileinfo)
RUN install-php-extensions \
    pdo_pgsql \
    pgsql \
    redis \
    bcmath \
    intl \
    pcntl \
    zip \
    gd

# Non-root user for the PHP process (FrankenPHP runs Caddy as root for port binding,
# but PHP workers can be dropped to a dedicated user via FRANKENPHP_WORKER_USER)
RUN addgroup -g 1001 laravel && adduser -u 1001 -G laravel -s /bin/sh -D laravel

WORKDIR /app

# Copy application from build stage
COPY --from=composer-build --chown=laravel:laravel /app .

# Override the default Caddyfile for FrankenPHP worker mode
# (FrankenPHP serves the Laravel app from /app/public)
COPY --chown=laravel:laravel docker/Caddyfile /etc/caddy/Caddyfile

# Create writable directories Laravel needs at runtime
RUN mkdir -p storage/logs \
             storage/framework/cache/data \
             storage/framework/sessions \
             storage/framework/views \
             bootstrap/cache \
    && chown -R laravel:laravel storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Laravel optimisations baked into the image at build time
# (env vars must be injected at runtime via ECS task definition / Secrets Manager)
RUN php artisan config:cache \
    && php artisan route:cache \
    && php artisan view:cache \
    && php artisan event:cache

# FrankenPHP listens on 8000; Caddy handles TLS termination upstream (ALB/CloudFront)
EXPOSE 8000

# Health check — Laravel ships GET /up out of the box (Laravel 11+)
HEALTHCHECK --interval=15s --timeout=5s --start-period=30s --retries=3 \
    CMD wget -qO- http://127.0.0.1:8000/up || exit 1

# FrankenPHP worker mode: keeps the Laravel app booted in memory
# Set FRANKENPHP_CONFIG or override CMD in ECS task definition as needed
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
