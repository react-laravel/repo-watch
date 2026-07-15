#!/usr/bin/env bash
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
  echo "run this script as root" >&2
  exit 1
fi

SOURCE_ROOT="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)}"
DB_PASSWORD_FILE=/root/.repo-watch-db-password
SECRET_DIR=/root/.repo-watch-secrets
API_ROOT=/var/www/repo-watch-api
WEB_ROOT=/var/www/repo-watch

install -d -m 700 "$SECRET_DIR"

generate_secret() {
  local file="$1"
  local bytes="$2"
  if [ ! -s "$file" ]; then
    openssl rand -hex "$bytes" > "$file"
    chmod 600 "$file"
  fi
}

generate_secret "$DB_PASSWORD_FILE" 32
generate_secret "$SECRET_DIR/sso-client-secret" 32
if [ ! -s "$SECRET_DIR/app-key" ]; then
  openssl rand 32 > "$SECRET_DIR/app-key"
  chmod 600 "$SECRET_DIR/app-key"
fi

db_password="$(cat "$DB_PASSWORD_FILE")"
app_key="base64:$(openssl base64 -A < "$SECRET_DIR/app-key")"
sso_secret="$(cat "$SECRET_DIR/sso-client-secret")"

install -d -o www-data -g www-data -m 775 \
  "$API_ROOT/shared" \
  "$API_ROOT/shared/storage/app/public" \
  "$API_ROOT/shared/storage/framework/cache/data" \
  "$API_ROOT/shared/storage/framework/sessions" \
  "$API_ROOT/shared/storage/framework/views" \
  "$API_ROOT/shared/storage/logs" \
  "$API_ROOT/releases" \
  "$WEB_ROOT/shared" \
  "$WEB_ROOT/releases"

cat > "$API_ROOT/shared/.env" <<EOF
APP_NAME="DogeOW Repo Watch API"
APP_ENV=production
APP_KEY=$app_key
APP_DEBUG=false
APP_URL=https://repo-watch.dogeow.com
FRONTEND_URL=https://repo-watch.dogeow.com
APP_LOCALE=zh_CN
APP_FALLBACK_LOCALE=zh_CN
LOG_CHANNEL=stack
LOG_LEVEL=info

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=repo_watch
DB_USERNAME=repo_watch
DB_PASSWORD=$db_password

SESSION_DRIVER=redis
SESSION_CONNECTION=session
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_COOKIE=repo_watch_session
SESSION_PATH=/
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax

CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_QUEUE=repo-watch
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_DB=14
REDIS_CACHE_DB=15
REDIS_SESSION_DB=16
REDIS_PREFIX=repo-watch:

IDENTITY_API_URL=https://next-api.dogeow.com
IDENTITY_SSO_CLIENT_SECRET=$sso_secret
GITHUB_TOKEN=
GITHUB_WEBHOOK_SECRET=
GITHUB_REPO_WATCH_REFRESH_HOURS=6
EOF

cat > "$WEB_ROOT/.env.production" <<EOF
REPO_WATCH_API_ORIGIN=http://127.0.0.1:8012
NEXT_PUBLIC_ACCOUNT_URL=https://next.dogeow.com
EOF

chown www-data:www-data "$API_ROOT/shared/.env" "$WEB_ROOT/.env.production"
chmod 640 "$API_ROOT/shared/.env" "$WEB_ROOT/.env.production"

set_env() {
  local file="$1" key="$2" value="$3"
  if grep -q "^${key}=" "$file"; then
    sed -i "s|^${key}=.*|${key}=${value}|" "$file"
  else
    printf '\n%s=%s\n' "$key" "$value" >> "$file"
  fi
}

central_env=""
for candidate in "${CENTRAL_ENV:-}" /var/www/dogeow-api/shared/.env /var/www/dogeow-api/.env /var/www/dogeow-api/current/.env; do
  if [ -n "$candidate" ] && [ -f "$candidate" ]; then
    central_env="$(readlink -f "$candidate")"
    break
  fi
done

if [ -z "$central_env" ]; then
  echo "cannot find central dogeow-api .env" >&2
  exit 1
fi

set_env "$central_env" REPO_WATCH_SSO_CLIENT_SECRET "$sso_secret"
set_env "$central_env" REPO_WATCH_SSO_CALLBACK_URL "https://repo-watch.dogeow.com/auth/callback"
set_env "$central_env" REPO_WATCH_SSO_RETURN_ORIGINS "https://repo-watch.dogeow.com,http://localhost:3012,http://127.0.0.1:3012"

central_artisan=/var/www/dogeow-api/current/artisan
if [ -f "$central_artisan" ]; then
  php "$central_artisan" config:clear
  systemctl restart dogeow-octane.service
else
  echo "warning: central dogeow-api current release not found; deploy it before testing SSO" >&2
fi

install -o root -g root -m 644 "$SOURCE_ROOT/deploy/nginx-repo-watch.conf" /etc/nginx/conf.d/repo-watch.dogeow.com.conf
install -o root -g root -m 644 "$SOURCE_ROOT/deploy/supervisor-repo-watch-api.conf" /etc/supervisor/conf.d/repo-watch-api.conf
install -o root -g root -m 644 "$SOURCE_ROOT/deploy/repo-watch-api.cron" /etc/cron.d/repo-watch-api
touch /var/log/repo-watch-api-scheduler.log
chown www-data:www-data /var/log/repo-watch-api-scheduler.log

nginx -t
systemctl reload nginx
supervisorctl reread
supervisorctl update

echo "Repo Watch infrastructure prepared"
