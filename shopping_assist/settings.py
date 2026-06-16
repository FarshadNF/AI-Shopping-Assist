import os
from pathlib import Path
from urllib.parse import unquote, urlparse
from django.core.exceptions import ImproperlyConfigured
from corsheaders.defaults import default_headers
from dotenv import load_dotenv

BASE_DIR = Path(__file__).resolve().parent.parent

# بارگذاری متغیرهای محیطی از فایل .env
load_dotenv(BASE_DIR / ".env")

SECRET_KEY = os.getenv("DJANGO_SECRET_KEY", "dev-only-secret-key-change-me")
DEBUG = os.getenv("DJANGO_DEBUG", "1").lower() in {"1", "true", "yes", "on"}

ALLOWED_HOSTS = [
    host.strip()
    for host in os.getenv("DJANGO_ALLOWED_HOSTS", "localhost,127.0.0.1,[::1]").split(",")
    if host.strip()
]


def env_bool(name, default=False):
    fallback = "1" if default else "0"
    return os.getenv(name, fallback).strip().lower() in {"1", "true", "yes", "on"}


def env_csv(name, default=""):
    return [
        item.strip().rstrip("/")
        for item in os.getenv(name, default).split(",")
        if item.strip()
    ]

INSTALLED_APPS = [
    "django.contrib.admin",
    "django.contrib.auth",
    "django.contrib.contenttypes",
    "django.contrib.sessions",
    "django.contrib.messages",
    "django.contrib.staticfiles",
    "rest_framework",
    "corsheaders",
    "assistant_app",
]

MIDDLEWARE = [
    "corsheaders.middleware.CorsMiddleware", 
    "django.middleware.security.SecurityMiddleware",
    "whitenoise.middleware.WhiteNoiseMiddleware",
    "django.contrib.sessions.middleware.SessionMiddleware",
    "django.middleware.common.CommonMiddleware",
    "django.middleware.csrf.CsrfViewMiddleware",
    "django.contrib.auth.middleware.AuthenticationMiddleware",
    "django.contrib.messages.middleware.MessageMiddleware",
    "django.middleware.clickjacking.XFrameOptionsMiddleware",
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
        if not DEBUG:
            raise ImproperlyConfigured(
                "CRITICAL ERROR: DATABASE_URL is missing in Production! "
                "The system cannot fallback to SQLite when DEBUG=False to prevent data loss."
            )
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

# داینامیک‌سازی زبان و منطقه زمانی برای پشتیبانی از پروژه‌های بین‌المللی (مانند قطر)
LANGUAGE_CODE = os.getenv("DJANGO_LANGUAGE_CODE", "en-us")
TIME_ZONE = os.getenv("DJANGO_TIME_ZONE", "Asia/Qatar")
USE_I18N = True
USE_TZ = True

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

# ----------------- تنظیمات اختصاصی هوش مصنوعی و اپن‌کارت -----------------
GEMINI_MODEL = os.getenv("GEMINI_MODEL", "gemini-2.5-flash")
CHAT_MEMORY_LIMIT = int(os.getenv("CHAT_MEMORY_LIMIT", "20"))
AI_ASSISTANT_SYNC_TOKEN = os.getenv("AI_ASSISTANT_SYNC_TOKEN", "")
AI_ASSISTANT_CHAT_TOKEN = os.getenv(
    "AI_ASSISTANT_CHAT_TOKEN",
    AI_ASSISTANT_SYNC_TOKEN,
)
AI_ASSISTANT_MAX_CATALOG_ITEMS = int(os.getenv("AI_ASSISTANT_MAX_CATALOG_ITEMS", "5000"))
AI_ASSISTANT_ASYNC_CATALOG_IMPORT = env_bool("AI_ASSISTANT_ASYNC_CATALOG_IMPORT", True)
AI_ASSISTANT_VECTOR_ENABLED = env_bool("AI_ASSISTANT_VECTOR_ENABLED", True)
AI_ASSISTANT_VECTOR_DB_PATH = Path(
    os.getenv("AI_ASSISTANT_VECTOR_DB_PATH", BASE_DIR / "rockford_vector_db")
)
AI_ASSISTANT_VECTOR_COLLECTION = os.getenv(
    "AI_ASSISTANT_VECTOR_COLLECTION",
    "rockford_knowledge",
)
AI_ASSISTANT_VECTOR_RESULTS = int(os.getenv("AI_ASSISTANT_VECTOR_RESULTS", "4"))
AI_ASSISTANT_VECTOR_CHUNK_SIZE = int(
    os.getenv("AI_ASSISTANT_VECTOR_CHUNK_SIZE", "1200")
)
AI_ASSISTANT_VECTOR_CHUNK_OVERLAP = int(
    os.getenv("AI_ASSISTANT_VECTOR_CHUNK_OVERLAP", "150")
)
AI_ASSISTANT_CRAWLER_DELAY = float(os.getenv("AI_ASSISTANT_CRAWLER_DELAY", "0.5"))
AI_ASSISTANT_CRAWLER_MAX_PDF_BYTES = int(
    os.getenv("AI_ASSISTANT_CRAWLER_MAX_PDF_BYTES", str(15 * 1024 * 1024))
)
AI_ASSISTANT_CRAWLER_MAX_PDF_PAGES = int(
    os.getenv("AI_ASSISTANT_CRAWLER_MAX_PDF_PAGES", "80")
)

OPENCART_BASE_URL = os.getenv("OPENCART_BASE_URL", "")
OPENCART_CATALOG_ROUTE = os.getenv(
    "OPENCART_CATALOG_ROUTE",
    "index.php?route=extension/ai_shopping_assist/module/ai_shopping_assist.getCatalog",
)
OPENCART_CATALOG_URL = os.getenv("OPENCART_CATALOG_URL", "")
OPENCART_SYNC_TOKEN = os.getenv("OPENCART_SYNC_TOKEN", AI_ASSISTANT_SYNC_TOKEN)
OPENCART_TIMEOUT = float(os.getenv("OPENCART_TIMEOUT", "15"))

# ----------------- تنظیمات هویتی و بیزینسی چت‌بات (پروژه‌محور) -----------------
STORE_BRAND = os.getenv("STORE_BRAND", "Rockford Qatar")
AI_ASSISTANT_NAME = os.getenv("AI_ASSISTANT_NAME", "MANU")
BUSINESS_MODEL = os.getenv("BUSINESS_MODEL", "B2B_INQUIRY") # می‌تواند B2B_INQUIRY یا B2C_CART باشد
TARGET_MARKET = os.getenv("TARGET_MARKET", "Qatar and Middle East")

# تعریف مقادیر پیش‌فرض قدرتمند برای سایت‌های مهندسی
_DEFAULT_BRANDS = "MOXA, WESTERMO, BEIJER, PROXIM, COHU, VIDEOTEC, SIEMENS RUGGEDCOM, SIEMENS SCALANCE, BRIDGEWAVE"
COMPANY_BRANDS = env_csv("COMPANY_BRANDS", _DEFAULT_BRANDS)

_DEFAULT_INDUSTRIES = "Oil & Gas, Power/Utility, Transportation Rail/Road, Infrastructure, Water/Wastewater, Automation, IP Surveillance, Manufacturing, Marine"
COMPANY_INDUSTRIES = env_csv("COMPANY_INDUSTRIES", _DEFAULT_INDUSTRIES)

_DEFAULT_SERVICES = "Advisory & Consulting, Pre-Sales Support, Network Design, Training"
COMPANY_SERVICES = env_csv("COMPANY_SERVICES", _DEFAULT_SERVICES)

# ----------------- تنظیمات مارکتینگ و سیستم ترکینگ (GA4 & GTM) -----------------
GA4_MEASUREMENT_ID = os.getenv("GA4_MEASUREMENT_ID", "")
GTM_CONTAINER_ID = os.getenv("GTM_CONTAINER_ID", "")

# ----------------- تنظیمات CORS (ایمن و استاندارد) -----------------
CORS_ALLOW_ALL_ORIGINS = env_bool("AI_ASSISTANT_CORS_ALLOW_ALL", False)
CORS_ALLOWED_ORIGINS = env_csv(
    "AI_ASSISTANT_ALLOWED_ORIGINS",
    "http://localhost,http://127.0.0.1,http://localhost:3000,http://127.0.0.1:3000,https://rockford-qatar.com",
)

CORS_ALLOW_HEADERS = list(default_headers) + [
    "x-ai-assistant-token",
]
CORS_MAX_AGE = 86400
