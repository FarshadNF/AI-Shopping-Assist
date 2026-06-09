import os
from pathlib import Path
from urllib.parse import unquote, urlparse
from django.core.exceptions import ImproperlyConfigured
from dotenv import load_dotenv

BASE_DIR = Path(__file__).resolve().parent.parent

# [حل چالش ۶]: استفاده از پکیج استاندارد به جای اختراع چرخ (پارس دقیق و امن)
load_dotenv(BASE_DIR / ".env")

SECRET_KEY = os.getenv("DJANGO_SECRET_KEY", "dev-only-secret-key-change-me")
DEBUG = os.getenv("DJANGO_DEBUG", "1").lower() in {"1", "true", "yes", "on"}

ALLOWED_HOSTS = [
    host.strip()
    for host in os.getenv("DJANGO_ALLOWED_HOSTS", "localhost,127.0.0.1,[::1]").split(",")
    if host.strip()
]

INSTALLED_APPS = [
    "django.contrib.admin",
    "django.contrib.auth",
    "django.contrib.contenttypes",
    "django.contrib.sessions",
    "django.contrib.messages",
    "django.contrib.staticfiles",
    "rest_framework",
    "corsheaders",  # پکیج استاندارد جایگزین شد
    "assistant_app",
]

MIDDLEWARE = [
    "corsheaders.middleware.CorsMiddleware",  # باید بالاترین حد ممکن باشد
    "django.middleware.security.SecurityMiddleware",
    "whitenoise.middleware.WhiteNoiseMiddleware",
    "django.contrib.sessions.middleware.SessionMiddleware",
    "django.middleware.common.CommonMiddleware", # تکرار این مورد حذف شد
    "django.middleware.csrf.CsrfViewMiddleware",
    "django.contrib.auth.middleware.AuthenticationMiddleware",
    "django.contrib.messages.middleware.MessageMiddleware",
    "django.middleware.clickjacking.XFrameOptionsMiddleware",
    # میدل‌ور قدیمی SimpleCorsMiddleware که به صورت دستی نوشته شده بود حذف شد
]

ROOT_URLCONF = "shopping_assist.urls"

TEMPLATES = [
    {
        "BACKEND": "django.template.backends.django.DjangoTemplates",
        "DIRS": [],
        "APP_DIRS": True,
        "OPTIONS": {
            "context_processors": [
                "django.template.context_processors.request",
                "django.contrib.auth.context_processors.auth",
                "django.contrib.messages.context_processors.messages",
            ],
        },
    },
]

WSGI_APPLICATION = "shopping_assist.wsgi.application"

def database_from_env():
    database_url = os.getenv("DATABASE_URL")
    
    if not database_url:
        # [حل چالش ۸]: جلوگیری از ایجاد دیتابیس خام در پروداکشن
        if not DEBUG:
            raise ImproperlyConfigured(
                "CRITICAL ERROR: DATABASE_URL is missing in Production! "
                "The system cannot fallback to SQLite when DEBUG=False to prevent data loss."
            )
        # فال‌بک به SQLite فقط برای محیط توسعه (DEBUG=True) مجاز است
        return {
            "ENGINE": "django.db.backends.sqlite3",
            "NAME": os.getenv("SQLITE_PATH", BASE_DIR / "db.sqlite3"),
        }

    parsed = urlparse(database_url)
    if parsed.scheme not in {"postgres", "postgresql"}:
        raise ValueError("DATABASE_URL must use postgres:// or postgresql://")

    return {
        "ENGINE": "django.db.backends.postgresql",
        "NAME": unquote(parsed.path.lstrip("/")),
        "USER": unquote(parsed.username or ""),
        "PASSWORD": unquote(parsed.password or ""),
        "HOST": parsed.hostname or "",
        "PORT": str(parsed.port or ""),
    }


DATABASES = {"default": database_from_env()}

LANGUAGE_CODE = "fa-ir"
TIME_ZONE = "Asia/Tehran"
USE_I18N = True
USE_TZ = True

# [حل چالش ۷]: اسلش در ابتدای مسیر فایل‌های استاتیک برای جلوگیری از خطای ۴۰۴ در ساب‌دایرکتوری‌ها
STATIC_URL = "/static/"
STATIC_ROOT = BASE_DIR / "staticfiles"
DEFAULT_AUTO_FIELD = "django.db.models.BigAutoField"

REST_FRAMEWORK = {
    "DEFAULT_AUTHENTICATION_CLASSES": [],
    "DEFAULT_PERMISSION_CLASSES": [
        "rest_framework.permissions.AllowAny",
    ],
    "DEFAULT_RENDERER_CLASSES": [
        "rest_framework.renderers.JSONRenderer",
    ],
}

# ----------------- تنظیمات اختصاصی پروژه و هوش مصنوعی -----------------

GEMINI_MODEL = os.getenv("GEMINI_MODEL", "gemini-2.5-flash")
CHAT_MEMORY_LIMIT = int(os.getenv("CHAT_MEMORY_LIMIT", "20"))
AI_ASSISTANT_SYNC_TOKEN = os.getenv("AI_ASSISTANT_SYNC_TOKEN", "")
AI_ASSISTANT_MAX_CATALOG_ITEMS = int(os.getenv("AI_ASSISTANT_MAX_CATALOG_ITEMS", "5000"))

OPENCART_BASE_URL = os.getenv("OPENCART_BASE_URL", "")
OPENCART_CATALOG_ROUTE = os.getenv(
    "OPENCART_CATALOG_ROUTE",
    "index.php?route=extension/opencart/checkout/ai_assistant.getCatalog",
)
OPENCART_CATALOG_URL = os.getenv("OPENCART_CATALOG_URL", "")
OPENCART_TIMEOUT = float(os.getenv("OPENCART_TIMEOUT", "15"))

# ----------------- تنظیمات CORS (ایمن و استاندارد) -----------------
CORS_ALLOW_ALL_ORIGINS = False  

CORS_ALLOWED_ORIGINS = [
    "http://localhost:3000",
    "http://127.0.0.1:3000",
    # آدرس پروداکشن فرانت‌اند باید اینجا اضافه شود
]

CORS_ALLOW_HEADERS = [
    "content-type",
    "x-ai-assistant-token",
    "authorization",
]
CORS_MAX_AGE = 86400