#!/bin/sh
set -e

python manage.py collectstatic --noinput
python manage.py migrate --noinput

echo "Starting Uvicorn ASGI server..."
exec uvicorn shopping_assist.asgi:application --host 0.0.0.0 --port 8000
