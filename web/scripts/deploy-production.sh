#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="${APP_ROOT:-/var/www/repo-watch}"
WORKSPACE="${GITHUB_WORKSPACE:-$(pwd -P)}"
RELEASES="$APP_ROOT/releases"
CURRENT="$APP_ROOT/current"
STAMP="$(date +%Y%m%d%H%M%S)"
STAGING="$RELEASES/.staging-$STAMP-$$"
RELEASE="$RELEASES/$STAMP"
PREVIOUS="$(readlink -f "$CURRENT" 2>/dev/null || true)"
PM2_HOME="${PM2_HOME:-/var/www/.pm2}"

activate_release() {
  local target="$1"
  local next_link="$APP_ROOT/.current-$STAMP-$$"

  ln -s "$target" "$next_link"
  mv -Tf "$next_link" "$CURRENT"
}

mkdir -p "$RELEASES" "$APP_ROOT/shared"
test -s "$APP_ROOT/.env.production"

cleanup() { rm -rf "$STAGING"; }
trap cleanup EXIT

rsync -a --delete --exclude='.git' --exclude='.next' --exclude='node_modules' \
  "$WORKSPACE/" "$STAGING/"
cp "$APP_ROOT/.env.production" "$STAGING/.env.production"

cd "$STAGING"
npm ci --ignore-scripts
npm run type-check
npm run lint
npm test
npm run build

mv "$STAGING" "$RELEASE"
activate_release "$RELEASE"

export PM2_HOME PM2_CWD="$CURRENT"
pm2 startOrReload "$CURRENT/ecosystem.config.cjs" --only repo-watch-nextjs --update-env

healthy=false
for _ in {1..15}; do
  status="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 2 http://127.0.0.1:3012/api/user || true)"
  if curl -fsS --max-time 2 http://127.0.0.1:3012/ >/dev/null && [ "$status" = "401" ]; then
    healthy=true
    break
  fi
  sleep 1
done

if [ "$healthy" != true ]; then
  if [ -n "$PREVIOUS" ] && [ -d "$PREVIOUS" ]; then
    activate_release "$PREVIOUS"
    export PM2_CWD="$CURRENT"
    pm2 startOrReload "$CURRENT/ecosystem.config.cjs" --only repo-watch-nextjs --update-env
  fi
  exit 1
fi

find "$RELEASES" -mindepth 1 -maxdepth 1 -type d -name '20*' -printf '%T@ %p\n' \
  | sort -nr | tail -n +4 | cut -d' ' -f2- | xargs -r rm -rf

pm2 save
