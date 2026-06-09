from django.urls import path
from . import views

# نام‌گذاری و مسیریابی استاندارد لایه ارتباطی (The Nervous System)
urlpatterns = [
    # روت اصلی معرفی اندپوینت‌ها
    path("", views.api_index, name="api-index"),
    
    # اندپوینت چت هوش مصنوعی (مدیریت شده توسط Rate Limiter و کاملاً ایمن)
    path("api/chat/", views.chat_api, name="chat-api"),
    
    # اندپوینت همگام‌سازی کاتالوگ (پردازش Background غیرمسدودکننده و مجهز به تابع hmac)
    path("api/catalog/import/", views.import_catalog, name="catalog-import"),
    
    # اندپوینت بررسی سلامت ماژول
    path("api/health/", views.health_check, name="health-check"),
]