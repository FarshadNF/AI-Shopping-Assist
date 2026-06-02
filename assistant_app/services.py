import html
import json
import re
from functools import lru_cache
import requests
from django.conf import settings

# رجکس تقویت‌شده: استخراج نام محصول + استخراج اختیاری تعداد (مثال: [ACTION: ADD_TO_CART: Product_Name | QTY: 3])
ACTION_RE = re.compile(r"\[ACTION:\s*ADD_TO_CART:\s*(?P<name>[^|\]]+)(?:\s*\|\s*QTY:\s*(?P<qty>\d+))?\]", re.IGNORECASE)
NOTIFICATION_RE = re.compile(r"\[NOTIFICATION:\s*DATA_ERROR:\s*(?P<details>[^\]]+)\]", re.IGNORECASE)

# افزوده شد: اطلاعات ثابت و پایه‌ای کسب‌وکار برای پاسخگویی به سوالات لوجستیک بدون اشغال دیتابیس
BUSINESS_POLICIES = {
    "shipping_info": "ارسال برای تهران ظرف ۲۴ ساعت کاری (پیک/تیپاکس) و برای شهرستان‌ها ۲ تا ۴ روز کاری (پست پیشتاز/باربری) انجام می‌شود.",
    "bulk_orders": "برای سفارش‌های عمده، سازمانی یا تعداد بالاتر از موجودی کاتالوگ، مشتری تایید فنی می‌شود و برای صدور پیش‌فاکتور رسمی به واحد بازرگانی ارجاع داده می‌شود.",
    "working_hours": "ساعات کاری مجموعه شنبه تا چهارشنبه از ۹:۰۰ تا ۱۷:۰۰ و پنجشنبه‌ها از ۹:۰۰ تا ۱۳:۰۰ است."
}

@lru_cache(maxsize=1)
def load_catalog():
    catalog_path = settings.BASE_DIR / "products_catalog.json"
    try:
        with catalog_path.open("r", encoding="utf-8") as catalog_file:
            return json.load(catalog_file)
    except (FileNotFoundError, json.JSONDecodeError):
        # ارتقا: جلوگیری از کراش کل سیستم در صورت ناقص یا خراب بودن فایل JSON
        return []

def get_relevant_catalog(user_message):
    full_catalog = load_catalog()
    if not full_catalog:
        return []
        
    if len(user_message) < 10:
        return full_catalog[:8] # کاهش به ۸ محصول برای سبک‌سازی لود سرور
    
    # بهینه‌سازی فیلتر کلمات کلیدی برای سرورهای با پردازش محدودتر
    search_words = [w.lower() for w in user_message.split() if len(w) > 2]
    if not search_words:
        return full_catalog[:8]

    relevant = []
    for p in full_catalog:
        # ترکیب فیلدهای متنی برای جستجوی سریع‌تر
        search_zone = f"{p.get('name', '')} {p.get('brand', '')} {p.get('full_description', '')}".lower()
        if any(word in search_zone for word in search_words):
            relevant.append(p)
            
    return relevant if relevant else full_catalog[:8]

def build_system_instruction(user_message):
    relevant_products = get_relevant_catalog(user_message)
    
    # جراحی و سبک‌سازی کاتالوگ (Data Slimming) قبل از تزریق به کانتکست مدل
    light_catalog = []
    for p in relevant_products:
        light_catalog.append({
            "product_id": p.get("product_id"),
            "name": p.get("name"),
            "price": p.get("price"),
            "stock": p.get("stock", 0),
            "brand": p.get("brand"),
            "attributes": p.get("attributes"),
            # ارتقا: کاهش طول توضیحات به حداکثر ۳۵۰ کاراکتر برای صرفه‌جویی شدید در مصرف توکن و سرعت پاسخ دهی
            "short_desc": p.get("full_description", "")[:350].strip()
        })
        
    catalog_string = json.dumps(light_catalog, ensure_ascii=False, indent=2)

    return f"""
تو یک مشاور فروش ارشد، هوشمند و ممیز منصف اطلاعات فروشگاه هستی. وظیفه تو راهنمایی متنی دقیق، روان و بدون خطای کاربران است.

اطلاعات کلیدی رویه‌های کاری فروشگاه (لوجستیک و فروش):
- زمان و نحوه ارسال سفارشات: {BUSINESS_POLICIES['shipping_info']}
- سفارش‌های عمده و سازمانی: {BUSINESS_POLICIES['bulk_orders']}
- ساعات پاسخگویی رسمی: {BUSINESS_POLICIES['working_hours']}

کاتالوگ محصولات در دسترس تو (شامل موجودی `stock` و قیمت):
{catalog_string}

پروتکل عملکرد هوشمند (Strict Rules):
۱. تطبیق پویا با صنف: اگر محصولات کاتالوگ تجهیزات صنعتی هستند، کاملاً مهندسی و فنی صحبت کن. اگر محصولات عمومی یا دیجیتال هستند، روی کاربری روزمره و مزایای مصرف‌کننده تمرکز کن.
۲. مدیریت تعداد و موجودی (Stock & QTY): اگر کاربر تعداد خاصی از یک کالا را خواست، ابتدا `stock` آن را در کاتالوگ چک کن. اگر موجودی کافی بود، تایید کن. اگر `stock` صفر یا کمتر از نیاز کاربر بود، صراحتاً بگو موجودی فعلی این کالا محدود است و بلافاصله مدل‌های جایگزین موجود در کاتالوگ را پیشنهاد بده.
۳. ممیزی دیتای غلط (Data Audit): اگر متوجه تناقض شدید یا اطلاعات فنی اشتباه در کاتالوگ شدی (مثلاً برچسب برند اشتباه یا توضیحات نامربوط)، آن دیتای غلط را برای مشتری تایید نکن؛ اطلاعات درست عمومی را بده و حتماً در انتهای پاسخ خود این تگ گزارش را دقیقاً به این فرمت صادر کن:
[NOTIFICATION: DATA_ERROR: نام محصول - شرح ایراد]
۴. فرمان اکشن خرید با تعداد: فقط زمانی که کاربر تایید قطعی داد، تگ اکشن را صادر کن. اگر کاربر تعداد خواست، آن را در بخش QTY بگذار؛ در غیر این صورت QTY را ۱ قرار بده. فرمت الگو: [ACTION: ADD_TO_CART: Product_Name | QTY: 3]
۵. گاردریل متنی: پاسخ‌ها کاملاً به زبان فارسی، شسته روفته و بدون زیاده‌گویی باشد.
""".strip()

def ask_ai(message, history=None):
    if history is None:
        history = []

    system_message = {"role": "system", "content": build_system_instruction(message)}
    full_messages = [system_message] + history + [{"role": "user", "content": message}]

    payload = {
        "model": settings.OLLAMA_MODEL,
        "messages": full_messages,
        "stream": False,
        "options": {
            "temperature": 0.2, # دما روی 0.2 تنظیم شده تا مدل کاملاً متمرکز بر فکت‌ها باشد و خیالبافی نکند
            "num_ctx": 4096,     # تنظیم بهینه‌شده روی ۴۰۹۶ متناسب با توان سرور معمولی شرکت
            "top_p": 0.9,
            "stop": ["User:", "System:"] # جلوگیر‌ی از نشت کد یا اتمام خودسرانه مکالمه
        }
    }

    try:
        response = requests.post(
            settings.OLLAMA_CHAT_URL,
            json=payload,
            timeout=settings.OLLAMA_TIMEOUT,
        )
        response.raise_for_status()
        data = response.json()
        return data.get("message", {}).get("content", "")
    except Exception as e:
        return f"خطا در ارتباط با مغز متفکر: {str(e)}"

def extract_cart_action(reply):
    match = ACTION_RE.search(reply or "")
    if not match:
        return None

    requested_name = html.unescape(match.group("name").strip())
    # استخراج هوشمند تعداد (اگر کاربر تعداد نگفته بود پیش‌فرض ۱ لحاظ می‌شود)
    requested_qty = int(match.group("qty")) if match.group("qty") else 1
    
    for product in load_catalog():
        if product.get("name", "").strip().lower() == requested_name.lower():
            return {
                "product_name": product.get("name"),
                "product_id": product.get("product_id"),
                "price": product.get("price"),
                "stock": product.get("stock", 0),
                "image": product.get("image"),
                "quantity": requested_qty # تحویل تعداد به بک‌اند سایت جهت اعمال در سبد خرید
            }
    return {"product_name": requested_name, "quantity": requested_qty, "error": "Product metadata not found"}

def extract_support_notification(reply):
    match = NOTIFICATION_RE.search(reply or "")
    if not match:
        return None
        
    return {
        "error_details": match.group("details").strip()
    }