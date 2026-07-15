#!/usr/bin/env bash
set -euo pipefail

ROOT="${GITHUB_WORKSPACE:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)}"

APP_ROOT=/var/www/repo-watch-api GITHUB_WORKSPACE="$ROOT/api" \
  "$ROOT/api/scripts/deploy-production.sh"

APP_ROOT=/var/www/repo-watch GITHUB_WORKSPACE="$ROOT/web" PM2_HOME="${PM2_HOME:-/var/www/.pm2}" \
  "$ROOT/web/scripts/deploy-production.sh"
