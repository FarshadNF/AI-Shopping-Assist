import html
import json
import re
from functools import lru_cache
import requests
from django.conf import settings

ACTION_RE = re.compile(r"\[ACTION:\s*ADD_TO_CART:\s*(?P<name>[^\]]+)\]", re.IGNORECASE)

@lru_cache(maxsize=1)
def load_catalog():
    catalog_path = settings.BASE_DIR / "products_catalog.json"
    try:
        with catalog_path.open("r", encoding="utf-8") as catalog_file:
            return json.load(catalog_file)
    except FileNotFoundError:
        return []

def get_relevant_catalog(user_message):
    """
    ارتقای قدرت ذهنی: به جای ارسال کل کاتالوگ، محصولات مرتبط را بر اساس کلمات کلیدی پیام کاربر فیلتر می‌کند.
    این کار باعث افزایش دقت هوش مصنوعی و کاهش شلوغی ذهن مدل می‌شود.
    """
    full_catalog = load_catalog()
    # اگر پیام کوتاه بود یا بار اول بود، 10 محصول برتر را بفرست
    if len(user_message) < 10:
        return full_catalog[:10]
    
    # فیلتر ساده بر اساس نام یا برند (قابل ارتقا به جستجوی معنایی در آینده)
    relevant = [
        p for p in full_catalog 
        if any(word.lower() in p['name'].lower() or word.lower() in p.get('full_description', '').lower() 
               for word in user_message.split())
    ]
    
    return relevant if relevant else full_catalog[:10]

def build_system_instruction(user_message):
    # دریافت محصولات مرتبط به جای کل دیتابیس
    relevant_products = get_relevant_catalog(user_message)
    catalog_string = json.dumps(relevant_products, ensure_ascii=False, indent=2)

    return f"""
تو یک مشاور فروش ارشد و متخصص "حل مسئله" در حوزه اتوماسیون صنعتی هستی. 
هدف تو صرفاً فروختن نیست؛ هدف تو درک چالش فنی مشتری و ارائه بهترین راهکار از برند Moxa است.

کاتالوگ محصولات مرتبط با نیاز فعلی کاربر:
{catalog_string}

پروتکل فروش مشاوره‌ای (فلسفه ۱۲ ساله):
۱. کشف درد (Pain Point): قبل از پیشنهاد قطعی، اگر نیاز کاربر مبهم است، بپرس: "این تجهیزات قرار است در چه شرایط محیطی (نویز، دما، فاصله) کار کند؟"
۲. ارزش فنی: از بخش `attributes` استفاده کن تا بگویی فلان ویژگی "چرا" برای مشتری سودمند است (مثلاً: "چون این سوئیچ بدنه فلزی دارد، در برابر تداخلات الکترومغناطیسی خط تولید شما کاملاً مقاوم است").
۳. انتقال به تیم فروش: برای خریدهای عمده یا پروژه‌ای، ضمن تایید فنی، مشتری را به واحد بازرگانی ارجاع بده تا قرارداد نهایی شود.
۴. مدیریت موجودی: اگر `stock` صفر بود، با اطمینان مدل‌های مشابه در کاتالوگ را پیشنهاد بده.
۵. فرمان اکشن: فقط وقتی مشتری تایید نهایی داد، تگ [ACTION: ADD_TO_CART: Name] را بزن.
""".strip()

def ask_ai(message, history=None):
    """
    ارتقای حافظه: حالا این تابع تاریخچه چت را هم می‌پذیرد.
    history باید لیستی از دیکشنری‌های {'role': 'user/assistant', 'content': '...'} باشد.
    """
    if history is None:
        history = []

    # ساخت پیام سیستم بر اساس پیام فعلی کاربر
    system_message = {"role": "system", "content": build_system_instruction(message)}
    
    # ترکیب حافظه قبلی با پیام جدید
    full_messages = [system_message] + history + [{"role": "user", "content": message}]

    payload = {
        "model": settings.OLLAMA_MODEL,
        "messages": full_messages,
        "stream": False,
        "options": {
            "temperature": 0.3, # کاهش دما برای افزایش دقت فنی و جلوگیری از خیالبافی
            "num_ctx": 4096     # افزایش پهنای ذهن برای خواندن دیتای بیشتر
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
    
    # جستجوی دقیق در کاتالوگ برای استخراج متادیتا
    for product in load_catalog():
        if product.get("name", "").strip().lower() == requested_name.lower():
            return {
                "product_name": product.get("name"),
                "product_id": product.get("product_id"),
                "price": product.get("price"),
                "stock": product.get("stock", 0),
                "image": product.get("image") # اضافه شدن عکس به اکشن برای نمایش در سبد خرید
            }
    return {"product_name": requested_name, "error": "Product metadata not found"}