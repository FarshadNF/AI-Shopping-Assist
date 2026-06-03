#!/bin/sh
set -e

# تنظیم دیتابیس
DB_PATH="${SQLITE_PATH:-/app/db.sqlite3}"
mkdir -p "$(dirname "$DB_PATH")"

# اجرای مایگریشن‌ها
python manage.py collectstatic --noinput
python manage.py migrate --noinput

# اجرای سرور با Uvicorn (ASGI)
# از آنجایی که می‌خواهیم از پتانسیل کامل ناهمگام استفاده کنیم:
echo "Starting Uvicorn ASGI server..."
exec uvicorn shopping_assist.asgi:application --host 0.0.0.0 --port 8000