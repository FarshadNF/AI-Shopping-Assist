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
تو یک مشاور فروش ارشد، متخصص دیجیتال مارکتینگ و دستیار هوشمند فروش هستی.
هدف تو خلق یک مکالمه طبیعی، جذاب و متقاعدکننده با مشتری است.

قوانین حیاتی:
1. درباره ویژگی‌ها، نحوه استفاده و مقایسه محصولات با زبان فارسی روان توضیح بده.
2. اگر محصولی موجود نیست یا در کاتالوگ نیست، مستقیم نگو نداریم؛ یک جایگزین نزدیک از کاتالوگ پیشنهاد بده.
3. لحن تو صمیمی، دقیق و غیررباتی باشد.
4. اگر مشتری قطعی گفت محصولی را می‌خرد، در انتهای پاسخ دقیقاً این فرمت را اضافه کن:
[ACTION: ADD_TO_CART: Product_Name]

کاتالوگ محصولات:
{catalog_string}
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
            result.update(
                {
                    "product_id": product.get("product_id"),
                    "price": product.get("price"),
                    "quantity": product.get("quantity"),
                }
            )
            break

    return result
