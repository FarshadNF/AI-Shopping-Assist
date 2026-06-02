import html
import json
import re
from functools import lru_cache
from numbers import Number

import requests
from django.conf import settings
from django.utils import timezone

from .models import ChatMessage, Conversation

ACTION_RE = re.compile(r"\[ACTION:\s*ADD_TO_CART:\s*(?P<name>[^\]]+)\]", re.IGNORECASE)

@lru_cache(maxsize=1)
def load_catalog():
    catalog_path = settings.BASE_DIR / "products_catalog.json"
    try:
        with catalog_path.open("r", encoding="utf-8") as catalog_file:
            return json.load(catalog_file)
    except FileNotFoundError:
        return []

def _first_present(source, *keys, default=None):
    if not isinstance(source, dict):
        return default

    for key in keys:
        value = source.get(key)
        if value not in (None, ""):
            return value
    return default

def _clean_text(value, default=""):
    if value is None:
        return default
    return html.unescape(str(value)).strip()

def _to_int(value, default=0):
    try:
        if isinstance(value, Number):
            return int(value)
        return int(float(str(value).replace(",", "").strip()))
    except (TypeError, ValueError):
        return default

def _normalize_attributes(raw_attributes):
    if isinstance(raw_attributes, dict):
        return {
            _clean_text(key): _clean_text(value)
            for key, value in raw_attributes.items()
            if _clean_text(key) and _clean_text(value)
        }

    normalized = {}
    if isinstance(raw_attributes, list):
        for entry in raw_attributes:
            if not isinstance(entry, dict):
                continue

            nested_attributes = _first_present(
                entry,
                "attribute",
                "attributes",
                "items",
                default=None,
            )
            if isinstance(nested_attributes, list):
                normalized.update(_normalize_attributes(nested_attributes))
                continue

            name = _clean_text(
                _first_present(entry, "name", "title", "attribute_name")
            )
            value = _clean_text(
                _first_present(entry, "text", "value", "attribute_value")
            )
            if name and value:
                normalized[name] = value

    return normalized

def normalize_catalog_product(item):
    name = _clean_text(_first_present(item, "name", "product_name", "title"))
    product_id = _clean_text(
        _first_present(item, "product_id", "id", "productId")
    )
    stock = _to_int(_first_present(item, "stock", "quantity", "qty", default=0))
    category = _clean_text(
        _first_present(item, "category", "category_name", "manufacturer"),
        default="Industrial Automation & Networking",
    )
    attributes = _normalize_attributes(
        _first_present(item, "attributes", "attribute_groups", "specifications")
    )

    if not attributes:
        attributes = {
            "Interface": "مشخصات پورت یافت نشد",
            "Protection": "استاندارد بدنه نامشخص",
        }

    sales_angle = _clean_text(_first_present(item, "sales_angle", "description"))
    if not sales_angle:
        sales_angle = (
            f"تجهیزات باکیفیت مدل {name}. گزینه‌ای مناسب برای انتخاب دقیق‌تر "
            "بر اساس موجودی و مشخصات فروشگاه."
        )

    return {
        "product_id": product_id,
        "name": name,
        "price": _clean_text(_first_present(item, "price", "special", default="0")),
        "stock": stock,
        "category": category,
        "attributes": attributes,
        "sales_angle": sales_angle,
        "alternatives": item.get("alternatives", []) if isinstance(item, dict) else [],
    }

def replace_catalog(raw_products):
    products = [
        normalize_catalog_product(item)
        for item in raw_products
        if isinstance(item, dict)
        and _clean_text(_first_present(item, "name", "product_name", "title"))
    ]

    catalog_path = settings.BASE_DIR / "products_catalog.json"
    temp_path = catalog_path.with_suffix(".json.tmp")
    with temp_path.open("w", encoding="utf-8") as catalog_file:
        json.dump(products, catalog_file, ensure_ascii=False, indent=4)
    temp_path.replace(catalog_path)
    load_catalog.cache_clear()
    return products

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

def get_or_create_conversation(conversation_id=None, session_key=None):
    if conversation_id:
        conversation, _ = Conversation.objects.get_or_create(public_id=conversation_id)
        return conversation

    if session_key:
        conversation, _ = Conversation.objects.get_or_create(session_key=session_key)
        return conversation

    return Conversation.objects.create()

def get_memory_messages(conversation):
    if conversation is None:
        return []

    limit = max(settings.CHAT_MEMORY_LIMIT, 0)
    if limit == 0:
        return []

    messages = list(
        conversation.messages.order_by("-created_at", "-id")[:limit]
    )
    messages.reverse()
    return [
        {"role": message.role, "content": message.content}
        for message in messages
    ]

def save_chat_turn(conversation, user_message, assistant_reply):
    if conversation is None:
        return

    ChatMessage.objects.bulk_create(
        [
            ChatMessage(
                conversation=conversation,
                role=ChatMessage.ROLE_USER,
                content=user_message,
            ),
            ChatMessage(
                conversation=conversation,
                role=ChatMessage.ROLE_ASSISTANT,
                content=assistant_reply,
            ),
        ]
    )
    Conversation.objects.filter(pk=conversation.pk).update(updated_at=timezone.now())

def ask_ai(message, conversation=None):
    payload = {
        "model": settings.OLLAMA_MODEL,
        "messages": [
            {"role": "system", "content": build_system_instruction()},
            *get_memory_messages(conversation),
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
    save_chat_turn(conversation, message, reply)
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
