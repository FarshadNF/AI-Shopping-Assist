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

def build_system_instruction():
    catalog = load_catalog()
    catalog_string = (
        json.dumps(catalog, ensure_ascii=False, indent=2)
        if catalog
        else "کاتالوگ محصولی پیدا نشد."
    )

    return f"""
تو یک مشاور فروش ارشد و متخصص تجهیزات شبکه و اتوماسیون صنعتی هستی.
وظیفه تو راهنمایی تخصصی مشتریان، مقایسه محصولات و نهایی کردن فروش است.

اطلاعات زنده انبار و محصولات ما (به فرمت JSON):
{catalog_string}

قوانین حیاتی تو (Strict Rules):
۱. لحن: کاملاً حرفه‌ای، مسلط به اصطلاحات مهندسی شبکه، اما روان و متقاعدکننده.
۲. استفاده از Sales Angle: هنگام معرفی هر محصول، حتماً از توضیحات بخش `sales_angle` برای برجسته کردن مزیت رقابتی آن استفاده کن.
۳. مقایسه فنی: اگر کاربر دو محصول را مقایسه کرد، دقیقاً از دیتاهای بخش `attributes` برای نشان دادن تفاوت‌ها استفاده کن.
۴. مدیریت ناموجودی: اگر فیلد `stock` برابر با 0 بود، به هیچ وجه نگو "موجود نداریم". بگو: "این مدل در حال حاضر ناموجود است، اما مدل‌های جایگزین زیر را با همان استانداردها پیشنهاد می‌کنم:" و از لیست `alternatives` پیشنهاد بده.
۵. فرمان خرید: اگر مشتری تصمیم قطعی برای خرید گرفت، فقط و فقط در انتهای پاسخ، دقیقاً کد زیر را تولید کن:
[ACTION: ADD_TO_CART: Product_Name]
""".strip()

def ask_ai(message):
    payload = {
        "model": settings.OLLAMA_MODEL,
        "messages": [
            {"role": "system", "content": build_system_instruction()},
            {"role": "user", "content": message},
        ],
        "stream": False,
    }

    response = requests.post(
        settings.OLLAMA_CHAT_URL,
        json=payload,
        timeout=settings.OLLAMA_TIMEOUT,
    )
    response.raise_for_status()
    data = response.json()
    reply = data.get("message", {}).get("content")
    if not isinstance(reply, str):
        raise ValueError("Unexpected response from Ollama.")
    return reply

def extract_cart_action(reply):
    match = ACTION_RE.search(reply or "")
    if not match:
        return None

    requested_name = html.unescape(match.group("name").strip())
    result = {"product_name": requested_name}

    for product in load_catalog():
        product_name = html.unescape(str(product.get("name", ""))).strip()
        if product_name.casefold() == requested_name.casefold():
            # تغییر quantity به stock برای هماهنگی با فایل JSON جدید
            result.update(
                {
                    "product_id": product.get("product_id"),
                    "price": product.get("price"),
                    "stock": product.get("stock", product.get("quantity", 0)), 
                }
            )
            break

    return result