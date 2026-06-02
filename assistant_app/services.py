import html
import json
import re
from functools import lru_cache
from numbers import Number
from urllib.parse import urljoin

import requests
from django.conf import settings
from django.utils import timezone

from .models import ChatMessage, Conversation, OpenCartConnectionStatus

ACTION_RE = re.compile(r"\[ACTION:\s*ADD_TO_CART:\s*(?P<name>[^\]]+)\]", re.IGNORECASE)

@lru_cache(maxsize=1)
def load_catalog():
    catalog_path = settings.BASE_DIR / "products_catalog.json"
    try:
        with catalog_path.open("r", encoding="utf-8") as catalog_file:
            return json.load(catalog_file)
    except FileNotFoundError:
        return []

def get_or_create_opencart_status():
    status, _ = OpenCartConnectionStatus.objects.get_or_create(
        name="opencart",
        defaults={
            "status": OpenCartConnectionStatus.STATUS_WAITING,
            "message": "Waiting for the first OpenCart catalog sync.",
        },
    )
    return status

def get_opencart_catalog_url():
    catalog_url = settings.OPENCART_CATALOG_URL.strip()
    if catalog_url:
        return catalog_url

    base_url = settings.OPENCART_BASE_URL.strip()
    if not base_url:
        return ""

    return urljoin(base_url.rstrip("/") + "/", settings.OPENCART_CATALOG_ROUTE)

def _extract_catalog_rows(payload):
    if isinstance(payload, list):
        return payload
    if not isinstance(payload, dict):
        return []
    if isinstance(payload.get("data"), list):
        return payload["data"]
    if isinstance(payload.get("products"), list):
        return payload["products"]
    data = payload.get("data")
    if isinstance(data, dict) and isinstance(data.get("products"), list):
        return data["products"]
    return []

def _extract_catalog_total(payload, rows):
    if not isinstance(payload, dict):
        return len(rows)

    total = _first_present(payload, "total", default=None)
    if total is None and isinstance(payload.get("pagination"), dict):
        total = _first_present(payload["pagination"], "total", default=None)
    return _to_int(total, default=len(rows))

def record_opencart_catalog_sync(source, catalog_items):
    now = timezone.now()
    status = get_or_create_opencart_status()
    status.status = OpenCartConnectionStatus.STATUS_CONNECTED
    status.source = source or status.source
    status.catalog_items = catalog_items
    status.message = "Catalog sync completed from OpenCart footer widget."
    status.last_sync_at = now
    status.last_checked_at = now
    status.save()
    return status

def check_opencart_connection():
    status = get_or_create_opencart_status()
    catalog_url = get_opencart_catalog_url()
    now = timezone.now()

    if not catalog_url:
        if status.last_sync_at:
            status.status = OpenCartConnectionStatus.STATUS_CONNECTED
            status.message = (
                "No live OpenCart URL is configured. The latest footer catalog "
                "sync was successful."
            )
        else:
            status.status = OpenCartConnectionStatus.STATUS_WAITING
            status.message = (
                "Set OPENCART_BASE_URL or OPENCART_CATALOG_URL to enable live "
                "OpenCart checks. Footer sync status is still tracked."
            )
        status.last_checked_at = now
        status.save()
        return status

    try:
        response = requests.get(
            catalog_url,
            params={"page": 1, "limit": 1},
            timeout=settings.OPENCART_TIMEOUT,
        )
        response.raise_for_status()
        payload = response.json()
        if isinstance(payload, dict) and payload.get("success") is False:
            raise ValueError(payload.get("error") or "OpenCart catalog API failed.")

        rows = _extract_catalog_rows(payload)
        status.status = OpenCartConnectionStatus.STATUS_CONNECTED
        status.source = catalog_url
        status.catalog_items = _extract_catalog_total(payload, rows)
        status.message = "Live OpenCart catalog check succeeded."
    except Exception as exc:
        status.status = OpenCartConnectionStatus.STATUS_DISCONNECTED
        status.source = catalog_url
        status.message = str(exc)

    status.last_checked_at = now
    status.save()
    return status

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
