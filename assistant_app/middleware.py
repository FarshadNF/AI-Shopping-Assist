import time
from django.core.cache import cache
from django.http import JsonResponse

class ChatRateLimitMiddleware:
    """
    مدیریت لایه امنیتی کنترل نرخ درخواست (Rate Limiting) برای اندپوینت‌های هوش مصنوعی.
    این میدل‌ور از کش جنگو برای محدود کردن تعداد درخواست‌ها در دقیقه استفاده می‌کند.
    """
    def __init__(self, get_response):
        self.get_response = get_response

    def __call__(self, request):
        # اعمال محدودیت صرفاً روی اندپوینت چت برای بهینه‌سازی پرفورمنس
        if request.path.startswith("/api/chat/"):
            # اولویت‌بندی شناسایی بر اساس کلید نشست و در غیر این صورت IP کلاینت
            identifier = request.session.session_key or request.META.get("REMOTE_ADDR")
            
            if identifier:
                cache_key = f"rate_limit_chat_{identifier}"
                request_count = cache.get(cache_key, 0)
                
                # سقف مجاز: حداکثر ۲۰ درخواست در دقیقه (قابل تنظیم بر اساس نیاز پروژه)
                if request_count >= 20:
                    return JsonResponse(
                        {
                            "status": "error",
                            "reply": "تعداد درخواست‌های شما بیش از حد مجاز است. لطفاً یک دقیقه دیگر تلاش کنید."
                        },
                        status=429  # HTTP 429 Too Many Requests
                    )
                
                # افزایش شمارنده و تمدید تا پایان بازه زمانی ۶۰ ثانیه‌ای
                cache.set(cache_key, request_count + 1, timeout=60)

        response = self.get_response(request)
        return response