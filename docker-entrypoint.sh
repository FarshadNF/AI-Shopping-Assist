#!/bin/sh
set -e

DB_PATH="${SQLITE_PATH:-/app/db.sqlite3}"
mkdir -p "$(dirname "$DB_PATH")"

python manage.py migrate --noinput

exec "$@"
