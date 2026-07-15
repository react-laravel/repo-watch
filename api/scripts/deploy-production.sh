#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="${APP_ROOT:-/var/www/repo-watch-api}"
WORKSPACE="${GITHUB_WORKSPACE:-$(pwd -P)}"
RELEASES="$APP_ROOT/releases"
SHARED="$APP_ROOT/shared"
CURRENT="$APP_ROOT/current"
STAMP="$(date +%Y%m%d%H%M%S)"
STAGING="$RELEASES/.staging-$STAMP-$$"
RELEASE="$RELEASES/$STAMP"
PREVIOUS="$(readlink -f "$CURRENT" 2>/dev/null || true)"

activate_release() {
  local target="$1"
  local next_link="$APP_ROOT/.current-$STAMP-$$"

  ln -s "$target" "$next_link"
  mv -Tf "$next_link" "$CURRENT"
}

mkdir -p "$RELEASES" "$SHARED/storage/framework/cache/data" \
  "$SHARED/storage/framework/sessions" "$SHARED/storage/framework/views" \
  "$SHARED/storage/logs" "$SHARED/storage/app/public"
test -s "$SHARED/.env"

cleanup() { rm -rf "$STAGING"; }
trap cleanup EXIT

rsync -a --delete \
  --exclude='.git' --exclude='.env' --exclude='vendor' --exclude='storage' \
  "$WORKSPACE/" "$STAGING/"

cd "$STAGING"
composer install --prefer-dist --no-interaction --optimize-autoloader
rm -rf storage
ln -s "$SHARED/storage" storage
ln -s "$SHARED/.env" .env
mkdir -p bootstrap/cache
chmod -R ug+rwX bootstrap/cache "$SHARED/storage"

vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
php artisan test --no-coverage
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

php artisan config:cache
php artisan view:cache
php artisan migrate --force

mv "$STAGING" "$RELEASE"
activate_release "$RELEASE"

php "$CURRENT/artisan" queue:restart || true
sudo -n supervisorctl reread
sudo -n supervisorctl update
sudo -n supervisorctl restart repo-watch-api:*
sudo -n supervisorctl restart repo-watch-api-worker || sudo -n supervisorctl start repo-watch-api-worker

rollback_release() {
  if [ -n "$PREVIOUS" ] && [ -d "$PREVIOUS" ]; then
    activate_release "$PREVIOUS"
    sudo -n supervisorctl restart repo-watch-api:*
    sudo -n supervisorctl restart repo-watch-api-worker || sudo -n supervisorctl start repo-watch-api-worker
  fi
}

healthy=false
for _ in {1..15}; do
  user_status="$(curl -sS --max-time 2 --output /dev/null --write-out '%{http_code}' http://127.0.0.1:8012/api/user || true)"
  if curl -fsS --max-time 2 http://127.0.0.1:8012/up >/dev/null && [ "$user_status" = "401" ]; then
    healthy=true
    break
  fi
  sleep 1
done

if [ "$healthy" != true ]; then
  rollback_release
  exit 1
fi

find "$RELEASES" -mindepth 1 -maxdepth 1 -type d -name '20*' -printf '%T@ %p\n' \
  | sort -nr | tail -n +4 | cut -d' ' -f2- | xargs -r rm -rf
