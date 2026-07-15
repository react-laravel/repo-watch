#!/usr/bin/env bash
set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-/var/backups/repo-watch-extraction}"
STAMP="$(date +%Y%m%d%H%M%S)"
ARCHIVE="$BACKUP_DIR/next-watched-packages-$STAMP.dump"
PASSWORD_FILE=/root/.repo-watch-db-password

sudo install -d -o postgres -g postgres -m 700 "$BACKUP_DIR"

exists="$(sudo -u postgres psql --dbname=next -Atqc "SELECT to_regclass('public.watched_packages') IS NOT NULL")"
if [ "$exists" != "t" ]; then
  echo "next.public.watched_packages does not exist" >&2
  exit 1
fi

if sudo -u postgres psql -Atqc "SELECT 1 FROM pg_database WHERE datname='repo_watch'" | grep -q 1; then
  target_exists="$(sudo -u postgres psql --dbname=repo_watch -Atqc "SELECT to_regclass('public.watched_packages') IS NOT NULL")"
  if [ "$target_exists" = "t" ]; then
    echo "repo_watch.watched_packages already exists; refusing to overwrite" >&2
    exit 1
  fi
fi

sudo -u postgres pg_dump --format=custom --no-owner --dbname=next \
  --table=public.watched_packages --file="$ARCHIVE"
sudo chmod 600 "$ARCHIVE"

if ! sudo test -s "$PASSWORD_FILE"; then
  sudo sh -c "umask 077; openssl rand -hex 32 > '$PASSWORD_FILE'"
fi

db_password="$(sudo cat "$PASSWORD_FILE")"
if ! sudo -u postgres psql -Atqc "SELECT 1 FROM pg_roles WHERE rolname='repo_watch'" | grep -q 1; then
  sudo -u postgres psql -v ON_ERROR_STOP=1 -c "CREATE ROLE repo_watch LOGIN PASSWORD '$db_password'"
else
  sudo -u postgres psql -v ON_ERROR_STOP=1 -c "ALTER ROLE repo_watch LOGIN PASSWORD '$db_password'"
fi

if ! sudo -u postgres psql -Atqc "SELECT 1 FROM pg_database WHERE datname='repo_watch'" | grep -q 1; then
  sudo -u postgres createdb --owner=repo_watch repo_watch
fi

sudo -u postgres pg_restore --exit-on-error --no-owner --role=repo_watch --dbname=repo_watch "$ARCHIVE"

sudo -u postgres psql -v ON_ERROR_STOP=1 --dbname=repo_watch <<'SQL'
CREATE TABLE IF NOT EXISTS migrations (
    id bigserial PRIMARY KEY,
    migration varchar(255) NOT NULL,
    batch integer NOT NULL
);
ALTER TABLE migrations OWNER TO repo_watch;
ALTER SEQUENCE migrations_id_seq OWNER TO repo_watch;
GRANT USAGE, CREATE ON SCHEMA public TO repo_watch;
INSERT INTO migrations (migration, batch)
SELECT '2026_03_09_000002_create_watched_packages_table', 1
WHERE NOT EXISTS (
    SELECT 1 FROM migrations WHERE migration = '2026_03_09_000002_create_watched_packages_table'
);
SQL

source_count="$(sudo -u postgres psql --dbname=next -Atqc 'SELECT count(*) FROM public.watched_packages')"
target_count="$(sudo -u postgres psql --dbname=repo_watch -Atqc 'SELECT count(*) FROM public.watched_packages')"
[ "$source_count" = "$target_count" ] || { echo "row count mismatch" >&2; exit 1; }

source_hash="$(sudo -u postgres psql --dbname=next -Atqc 'COPY (SELECT * FROM public.watched_packages ORDER BY id) TO STDOUT' | sha256sum | cut -d' ' -f1)"
target_hash="$(sudo -u postgres psql --dbname=repo_watch -Atqc 'COPY (SELECT * FROM public.watched_packages ORDER BY id) TO STDOUT' | sha256sum | cut -d' ' -f1)"
[ "$source_hash" = "$target_hash" ] || { echo "content hash mismatch" >&2; exit 1; }

echo "watched_packages rows=$target_count sha256=$target_hash"
echo "Snapshot retained at $ARCHIVE"
