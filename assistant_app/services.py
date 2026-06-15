import html
import hashlib
import json
import logging
import re
import uuid
from functools import lru_cache
from numbers import Number
from urllib.parse import urljoin
import requests
from django.conf import settings
from django.utils import timezone
from django.core.cache import cache
from asgiref.sync import async_to_sync
from google.genai import types
from thefuzz import fuzz

from .models import ChatMessage, Conversation, OpenCartConnectionStatus
from .utils.ai_agent import ai_agent

logger = logging.getLogger(__name__)

ASSISTANT_ACTION_TOOLS = types.Tool(
    function_declarations=[
        types.FunctionDeclaration(
            name="add_to_cart",
            description="Adds a specified product to the user's shopping cart. Call this ONLY when the user explicitly confirms they want to buy or add an item to the cart.",
            parameters=types.Schema(
                type=types.Type.OBJECT,
                properties={
                    "product_name": types.Schema(
                        type=types.Type.STRING,
                        description="The exact part number or name of the product"
                    ),
                    "qty": types.Schema(
                        type=types.Type.INTEGER,
                        description="Quantity to add to the cart"
                    )
                },
                required=["product_name", "qty"]
            )
        ),
        types.FunctionDeclaration(
            name="redirect_to_checkout",
            description="Redirects the shopper to the checkout/payment page. Call this when the user explicitly asks to checkout, pay, complete the order, or go to payment.",
            parameters=types.Schema(
                type=types.Type.OBJECT,
                properties={
                    "reason": types.Schema(
                        type=types.Type.STRING,
                        description="Short reason for the redirect, in the user's language"
                    )
                },
                required=[]
            )
        ),
        types.FunctionDeclaration(
            name="show_cart",
            description="Shows the shopper what is currently in their basket/cart. Call this when the user asks what is in the basket, cart contents, cart total, or basket summary.",
            parameters=types.Schema(
                type=types.Type.OBJECT,
                properties={
                    "reason": types.Schema(
                        type=types.Type.STRING,
                        description="Short reason for showing the cart, in the user's language"
                    )
                },
                required=[]
            )
        ),
        types.FunctionDeclaration(
            name="redirect_to_cart",
            description="Redirects the shopper to the shopping basket/cart page. Call this when the user asks to open, view, or go to their basket/cart page.",
            parameters=types.Schema(
                type=types.Type.OBJECT,
                properties={
                    "reason": types.Schema(
                        type=types.Type.STRING,
                        description="Short reason for opening the cart, in the user's language"
                    )
                },
                required=[]
            )
        ),
        types.FunctionDeclaration(
            name="redirect_to_product",
            description="Redirects the shopper to a product detail page. Call this when the user asks to open, view, go to, or navigate to a product page.",
            parameters=types.Schema(
                type=types.Type.OBJECT,
                properties={
                    "product_name": types.Schema(
                        type=types.Type.STRING,
                        description="The product name or part number the user wants to open"
                    ),
                    "product_id": types.Schema(
                        type=types.Type.STRING,
                        description="The product id if known"
                    )
                },
                required=["product_name"]
            )
        ),
        types.FunctionDeclaration(
            name="update_cart_item",
            description="Updates the quantity of an item already in the cart. Call this when the user asks to change an item's basket quantity.",
            parameters=types.Schema(
                type=types.Type.OBJECT,
                properties={
                    "product_name": types.Schema(
                        type=types.Type.STRING,
                        description="The product name or part number the user wants to update"
                    ),
                    "product_id": types.Schema(
                        type=types.Type.STRING,
                        description="The product id if known"
                    ),
                    "qty": types.Schema(
                        type=types.Type.INTEGER,
                        description="The final desired quantity in the cart"
                    )
                },
                required=["product_name", "qty"]
            )
        ),
        types.FunctionDeclaration(
            name="remove_from_cart",
            description="Removes an item from the cart. Call this when the user asks to remove, delete, or take an item out of the basket.",
            parameters=types.Schema(
                type=types.Type.OBJECT,
                properties={
                    "product_name": types.Schema(
                        type=types.Type.STRING,
                        description="The product name or part number the user wants removed"
                    ),
                    "product_id": types.Schema(
                        type=types.Type.STRING,
                        description="The product id if known"
                    )
                },
                required=["product_name"]
            )
        ),
        types.FunctionDeclaration(
            name="clear_cart",
            description="Clears all items from the cart. Call this when the user asks to empty or clear the basket/cart.",
            parameters=types.Schema(
                type=types.Type.OBJECT,
                properties={
                    "reason": types.Schema(
                        type=types.Type.STRING,
                        description="Short reason for clearing the cart, in the user's language"
                    )
                },
                required=[]
            )
        ),
        types.FunctionDeclaration(
            name="apply_coupon",
            description="Applies a coupon, promo, or voucher code to the cart. Call this when the user provides a discount code or asks to apply a coupon.",
            parameters=types.Schema(
                type=types.Type.OBJECT,
                properties={
                    "code": types.Schema(
                        type=types.Type.STRING,
                        description="Coupon or promo code"
                    )
                },
                required=["code"]
            )
        ),
        types.FunctionDeclaration(
            name="send_invoice",
            description="Requests sending an invoice, proforma invoice, or quote to the shopper. Call this when the user asks for an invoice, proforma, quote, or billing document.",
            parameters=types.Schema(
                type=types.Type.OBJECT,
                properties={
                    "email": types.Schema(
                        type=types.Type.STRING,
                        description="Customer email address if the user provided one, otherwise leave empty"
                    ),
                    "invoice_type": types.Schema(
                        type=types.Type.STRING,
                        description="invoice, proforma, quote, or receipt"
                    ),
                    "note": types.Schema(
                        type=types.Type.STRING,
                        description="Any customer note for the invoice request"
                    )
                },
                required=[]
            )
        )
    ]
)

ADD_TO_CART_TOOL = ASSISTANT_ACTION_TOOLS

def normalize_part_number(text):
    if not text:
        return ""
    return re.sub(r'[^a-zA-Z0-9]', '', text).lower().strip()

def load_catalog():
    cached_catalog = cache.get("products_catalog")
    if cached_catalog is not None:
        return cached_catalog

    catalog_paths = (
        settings.BASE_DIR / "products_catalog.json",
        settings.BASE_DIR / "data" / "products_catalog.json",
    )
    for catalog_path in catalog_paths:
        try:
            with catalog_path.open("r", encoding="utf-8") as catalog_file:
                data = json.load(catalog_file)
                cache.set("products_catalog", data, timeout=None)
                return data
        except (FileNotFoundError, json.JSONDecodeError):
            continue
    return []

def _clear_load_catalog_cache():
    cache.delete("products_catalog")

load_catalog.cache_clear = _clear_load_catalog_cache

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
    catalog_url = getattr(settings, 'OPENCART_CATALOG_URL', '').strip()
    if catalog_url:
        return catalog_url
    base_url = getattr(settings, 'OPENCART_BASE_URL', '').strip()
    if not base_url:
        return ""
    return urljoin(base_url.rstrip("/") + "/", getattr(settings, 'OPENCART_CATALOG_ROUTE', ''))

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


# =====================================================================
# [ارتقای معماری فاز ۵]: موتور همگام‌سازی امن کاتالوگ به صورت سرور به سرور (Server-to-Server)
# =====================================================================
def sync_opencart_catalog_live():
    """
    این متد جایگزین کدهای ناامن جاوااسکریپت فرانت‌اند شده است. 
    جنگو مستقیماً کاتالوگ را به صورت ایمن لود کرده و دیتابیس هوش مصنوعی را بروزرسانی می‌کند.
    """
    catalog_url = get_opencart_catalog_url()
    if not catalog_url:
        raise ValueError("آدرس کاتالوگ اپن‌کارت در تنظیمات پروژه (OPENCART_BASE_URL) تعریف نشده است.")

    status = get_or_create_opencart_status()
    status.status = OpenCartConnectionStatus.STATUS_WAITING
    status.message = "همگام‌سازی کاتالوگ از سمت سرور آغاز شد..."
    status.save()

    collected_raw_products = []
    seen_keys = set()
    page = 1
    page_limit = 100
    max_pages = 100  # لایه محافظتی برای جلوگیری از لوپ بی‌نهایت در کانال‌های بزرگ

    session = requests.Session()
    # استفاده از توکن امنیتی در هدر درخواست‌های داخلی بک‌اند به بک‌اند
    sync_token = getattr(settings, 'OPENCART_SYNC_TOKEN', '')
    headers = {'Content-Type': 'application/json'}
    if sync_token:
        headers['X-AI-Assistant-Token'] = sync_token

    while page <= max_pages:
        try:
            response = session.get(
                catalog_url,
                params={"page": page, "limit": page_limit},
                headers=headers,
                timeout=getattr(settings, 'OPENCART_TIMEOUT', 15),
            )
            response.raise_for_status()
            payload = response.json()

            if isinstance(payload, dict) and payload.get("success") is False:
                raise ValueError(payload.get("error") or "خطا در خروجی API اپن‌کارت")

            rows = _extract_catalog_rows(payload)
            if not rows:
                break

            for item in rows:
                if not isinstance(item, dict):
                    continue
                name = _clean_text(_first_present(item, "name", "product_name", "title"))
                p_id = _clean_text(_first_present(item, "product_id", "id", "productId"))
                
                if not name:
                    continue
                
                key = p_id or name
                if key in seen_keys:
                    continue
                seen_keys.add(key)
                collected_raw_products.append(item)

            total = _extract_catalog_total(payload, rows)
            if total and len(collected_raw_products) >= total:
                break
            if len(rows) < page_limit:
                break

            page += 1
        except Exception as exc:
            status.status = OpenCartConnectionStatus.STATUS_DISCONNECTED
            status.message = f"خطا در صفحه {page}: {str(exc)}"
            status.save()
            raise exc

    # بهینه‌سازی، ساختاردهی و بازنویسی کاتالوگ محلی دیتابیس/وکتورها
    final_catalog = replace_catalog(collected_raw_products)

    # ثبت موفقیت وضعیت نهایی
    now = timezone.now()
    status.status = OpenCartConnectionStatus.STATUS_CONNECTED
    status.source = catalog_url
    status.catalog_items = len(final_catalog)
    status.message = f"همگام‌سازی سرور به سرور با موفقیت انجام شد. تعداد محصولات چت‌بات: {len(final_catalog)}"
    status.last_sync_at = now
    status.last_checked_at = now
    status.save()
    
    return final_catalog


def record_opencart_catalog_sync(source, catalog_items):
    """جهت حفظ سازگاری با متدهای قدیمی لایه View"""
    now = timezone.now()
    status = get_or_create_opencart_status()
    status.status = OpenCartConnectionStatus.STATUS_CONNECTED
    status.source = source or status.source
    status.catalog_items = catalog_items
    status.message = "داده‌ها با موفقیت بازنویسی شدند."
    status.last_sync_at = now
    status.last_checked_at = now
    status.save()
    return status

def check_opencart_connection():
    """تست سریع وضعیت اتصال بدون دانلود کل کاتالوگ"""
    status = get_or_create_opencart_status()
    catalog_url = get_opencart_catalog_url()
    now = timezone.now()
    if not catalog_url:
        if status.last_sync_at:
            status.status = OpenCartConnectionStatus.STATUS_CONNECTED
            status.message = "No live OpenCart URL is configured. Using cached local storage."
        else:
            status.status = OpenCartConnectionStatus.STATUS_WAITING
            status.message = "Set OPENCART_BASE_URL to enable live connection checks."
        status.last_checked_at = now
        status.save()
        return status
    try:
        response = requests.get(
            catalog_url,
            params={"page": 1, "limit": 1},
            timeout=getattr(settings, 'OPENCART_TIMEOUT', 10),
        )
        response.raise_for_status()
        payload = response.json()
        if isinstance(payload, dict) and payload.get("success") is False:
            raise ValueError(payload.get("error") or "OpenCart API validation failed.")
        rows = _extract_catalog_rows(payload)
        status.status = OpenCartConnectionStatus.STATUS_CONNECTED
        status.source = catalog_url
        status.catalog_items = _extract_catalog_total(payload, rows)
        status.message = "Live OpenCart catalog link is stable."
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

def _prefers_rtl_language(text):
    rtl_count = len(re.findall(r"[\u0590-\u08ff]", text or ""))
    latin_count = len(re.findall(r"[A-Za-z]", text or ""))
    return rtl_count > latin_count

def _dominant_script(text):
    rtl_count = len(re.findall(r"[\u0590-\u08ff]", text or ""))
    latin_count = len(re.findall(r"[A-Za-z]", text or ""))
    if not rtl_count and not latin_count:
        return ""
    return "rtl" if rtl_count > latin_count else "latin"

def localized_text(user_message, english, persian):
    return persian if _prefers_rtl_language(user_message) else english

def response_language_mismatch(user_message, reply_text):
    user_script = _dominant_script(user_message)
    reply_script = _dominant_script(reply_text)
    return bool(user_script and reply_script and user_script != reply_script)

def localized_processing_reply(user_message, is_cart_action=False):
    if is_cart_action:
        return localized_text(
            user_message,
            "Adding this to your cart...",
            "در حال افزودن به سبد خرید شما...",
        )
    return localized_text(
        user_message,
        "I'm processing your request...",
        "در حال پردازش درخواست شما...",
    )

def _has_add_to_cart_call(agent_output):
    return any(
        getattr(call, "name", "") == "add_to_cart"
        for call in getattr(agent_output, "function_calls", []) or []
    )

def _requested_quantity(args):
    return max(_to_int(args.get("qty", 1), default=1), 1)

_PRODUCT_NAME_ALIASES = (
    ("\u0622\u06cc\u200c\u0645\u06a9", "imac"),
    ("\u0622\u06cc \u0645\u06a9", "imac"),
    ("\u0622\u06cc\u0645\u06a9", "imac"),
    ("\u0627\u06cc \u0645\u06a9", "imac"),
    ("\u0627\u06cc\u0645\u06a9", "imac"),
    ("\u0622\u06cc\u0641\u0648\u0646", "iphone"),
    ("\u0627\u06cc\u0641\u0648\u0646", "iphone"),
    ("\u0622\u06cc \u067e\u062f", "ipad"),
    ("\u0622\u06cc\u067e\u062f", "ipad"),
    ("\u0645\u06a9 \u0628\u0648\u06a9", "macbook"),
    ("\u0645\u06a9\u200c\u0628\u0648\u06a9", "macbook"),
)

_PRODUCT_NAVIGATION_ENGLISH_VERBS = (
    "open",
    "view",
    "go to",
    "navigate",
    "take me to",
    "show me",
)
_PRODUCT_NAVIGATION_ENGLISH_TARGETS = ("product", "page")
_PRODUCT_NAVIGATION_PERSIAN_VERBS = (
    "\u0628\u0631\u0648",
    "\u0628\u0628\u0631",
    "\u0628\u0627\u0632",
    "\u0646\u0645\u0627\u06cc\u0634",
    "\u0646\u0634\u0627\u0646",
    "\u0647\u062f\u0627\u06cc\u062a",
)
_PRODUCT_NAVIGATION_PERSIAN_TARGETS = (
    "\u0635\u0641\u062d\u0647",
    "\u0645\u062d\u0635\u0648\u0644",
    "\u06a9\u0627\u0644\u0627",
)

def _call_args(call):
    args = getattr(call, "args", {}) or {}
    if hasattr(args, "items"):
        return dict(args.items())
    return args if isinstance(args, dict) else {}

def _checkout_action(args=None):
    args = args or {}
    return {
        "type": "redirect_to_checkout",
        "target": "checkout",
        "reason": _clean_text(args.get("reason", "")),
    }

def _cart_view_action(args=None):
    args = args or {}
    return {
        "type": "show_cart",
        "reason": _clean_text(args.get("reason", "")),
    }

def _cart_redirect_action(args=None):
    args = args or {}
    return {
        "type": "redirect_to_cart",
        "target": "cart",
        "reason": _clean_text(args.get("reason", "")),
    }

def _product_url(product):
    return _clean_text(
        _first_present(product, "url", "href", "link", "product_url", "productUrl")
    )

def _human_text(value):
    return (
        _clean_text(value)
        .lower()
        .replace("\u064a", "\u06cc")
        .replace("\u0643", "\u06a9")
    )

def _canonical_product_text(value):
    text = _human_text(value)
    for alias, canonical in _PRODUCT_NAME_ALIASES:
        text = text.replace(alias, canonical)
    return normalize_part_number(text)

def _find_catalog_product(requested_name="", product_id=""):
    requested_name = _clean_text(requested_name)
    product_id = _clean_text(product_id)
    normalized_requested = _canonical_product_text(requested_name)
    catalog = load_catalog()

    for product in catalog:
        if product_id and _clean_text(product.get("product_id")) == product_id:
            return product
        normalized_name = _canonical_product_text(product.get("name", ""))
        if normalized_requested and (
            normalized_name == normalized_requested
            or normalized_name in normalized_requested
            or normalized_requested in normalized_name
        ):
            return product

    best_match = None
    best_score = 0
    for product in catalog:
        score = fuzz.token_set_ratio(requested_name, product.get("name", ""))
        if score > best_score and score > 85:
            best_score = score
            best_match = product
    return best_match

def _product_redirect_action(args=None):
    args = args or {}
    requested_name = _clean_text(args.get("product_name", ""))
    requested_id = _clean_text(args.get("product_id", ""))
    product = _find_catalog_product(requested_name, requested_id)
    if product:
        return {
            "type": "redirect_to_product",
            "target": "product",
            "product_name": product.get("name"),
            "product_id": product.get("product_id"),
            "product_url": _product_url(product),
        }
    return {
        "type": "redirect_to_product",
        "target": "product",
        "product_name": requested_name,
        "product_id": requested_id,
        "product_url": "",
    }

def _is_product_navigation_request(message):
    text = _human_text(message)
    has_english_intent = any(term in text for term in _PRODUCT_NAVIGATION_ENGLISH_VERBS)
    has_english_target = any(term in text for term in _PRODUCT_NAVIGATION_ENGLISH_TARGETS)
    has_persian_intent = any(term in text for term in _PRODUCT_NAVIGATION_PERSIAN_VERBS)
    has_persian_target = any(term in text for term in _PRODUCT_NAVIGATION_PERSIAN_TARGETS)
    return (has_english_intent and has_english_target) or (has_persian_intent and has_persian_target)

def _product_navigation_query(message):
    query = _human_text(message)
    removable_patterns = (
        r"\b(?:open|view|go to|navigate to|take me to|show me)\b",
        r"\b(?:the|a|an|product|page|for|please)\b",
        r"(?<!\S)\u0628\u0631\u0648(?!\S)",
        r"(?<!\S)\u0628\u0628\u0631(?!\S)",
        r"(?<!\S)\u0628\u0627\u0632(?!\S)",
        r"(?<!\S)\u06a9\u0646(?!\S)",
        r"(?<!\S)\u0628\u0647(?!\S)",
        r"(?<!\S)\u0635\u0641\u062d\u0647(?!\S)",
        r"(?<!\S)\u0645\u062d\u0635\u0648\u0644(?!\S)",
        r"(?<!\S)\u06a9\u0627\u0644\u0627(?!\S)",
        r"(?<!\S)\u0646\u0645\u0627\u06cc\u0634(?!\S)",
        r"(?<!\S)\u0646\u0634\u0627\u0646(?!\S)",
        r"(?<!\S)\u0628\u062f\u0647(?!\S)",
        r"(?<!\S)\u0647\u062f\u0627\u06cc\u062a(?!\S)",
        r"(?<!\S)\u0631\u0627(?!\S)",
    )
    for pattern in removable_patterns:
        query = re.sub(pattern, " ", query, flags=re.IGNORECASE)
    return re.sub(r"\s+", " ", query).strip(" .,:;?!\u061f\u060c") or _clean_text(message)

def _fallback_product_navigation_action(user_message, actions):
    if actions or not _is_product_navigation_request(user_message):
        return None
    return _product_redirect_action({"product_name": _product_navigation_query(user_message)})

def _update_cart_action(args=None):
    args = args or {}
    return {
        "type": "update_cart_item",
        "product_name": _clean_text(args.get("product_name", "")),
        "product_id": _clean_text(args.get("product_id", "")),
        "requested_qty": _requested_quantity(args),
    }

def _remove_cart_action(args=None):
    args = args or {}
    return {
        "type": "remove_from_cart",
        "product_name": _clean_text(args.get("product_name", "")),
        "product_id": _clean_text(args.get("product_id", "")),
    }

def _clear_cart_action(args=None):
    args = args or {}
    return {
        "type": "clear_cart",
        "reason": _clean_text(args.get("reason", "")),
    }

def _coupon_action(args=None):
    args = args or {}
    return {
        "type": "apply_coupon",
        "code": _clean_text(args.get("code", "")),
    }

def _invoice_action(args=None):
    args = args or {}
    return {
        "type": "send_invoice",
        "email": _clean_text(args.get("email", "")),
        "invoice_type": _clean_text(args.get("invoice_type", "invoice")) or "invoice",
        "note": _clean_text(args.get("note", "")),
    }

def localized_cart_reply(user_message, action):
    quantity = _to_int((action or {}).get("requested_qty", 1), default=1)
    quantity = max(quantity, 1)
    product_name = _clean_text((action or {}).get("product_name")) or localized_text(
        user_message,
        "this item",
        "این محصول",
    )
    return localized_text(
        user_message,
        f"Adding {quantity} {product_name} to your cart...",
        f"در حال اضافه کردن {quantity} عدد {product_name} به سبد خرید شما هستم.",
    )

def localized_action_reply(user_message, action):
    action_type = (action or {}).get("type")
    if action_type == "add_to_cart":
        return localized_cart_reply(user_message, action)
    if action_type == "redirect_to_checkout":
        return localized_text(
            user_message,
            "Taking you to checkout...",
            "در حال انتقال شما به صفحه پرداخت...",
        )
    if action_type == "show_cart":
        return localized_text(
            user_message,
            "Checking your basket...",
            "در حال بررسی سبد خرید شما...",
        )
    if action_type == "redirect_to_cart":
        return localized_text(
            user_message,
            "Opening your basket...",
            "در حال باز کردن سبد خرید شما...",
        )
    if action_type == "redirect_to_product":
        product_name = _clean_text((action or {}).get("product_name")) or localized_text(
            user_message,
            "the product",
            "صفحه محصول",
        )
        return localized_text(
            user_message,
            f"Opening {product_name}...",
            f"در حال باز کردن صفحه {product_name}...",
        )
    if action_type == "update_cart_item":
        quantity = _to_int((action or {}).get("requested_qty", 1), default=1)
        product_name = _clean_text((action or {}).get("product_name")) or localized_text(
            user_message,
            "that item",
            "این محصول",
        )
        return localized_text(
            user_message,
            f"Updating {product_name} to quantity {quantity}...",
            f"در حال تغییر تعداد {product_name} به {quantity}...",
        )
    if action_type == "remove_from_cart":
        product_name = _clean_text((action or {}).get("product_name")) or localized_text(
            user_message,
            "that item",
            "این محصول",
        )
        return localized_text(
            user_message,
            f"Removing {product_name} from your basket...",
            f"در حال حذف {product_name} از سبد خرید شما...",
        )
    if action_type == "clear_cart":
        return localized_text(
            user_message,
            "Clearing your basket...",
            "در حال خالی کردن سبد خرید شما...",
        )
    if action_type == "apply_coupon":
        return localized_text(
            user_message,
            "Applying your coupon...",
            "در حال اعمال کد تخفیف شما...",
        )
    if action_type == "send_invoice":
        return localized_text(
            user_message,
            "I'll request the invoice for you now.",
            "الان درخواست صدور فاکتور را برای شما ثبت می‌کنم.",
        )
    return localized_processing_reply(user_message, is_cart_action=False)

def response_refuses_action(action, reply_text):
    if (action or {}).get("type") != "redirect_to_product":
        return False
    text = _human_text(reply_text)
    refusal_terms = (
        "cannot",
        "can't",
        "unable",
        "not able",
        "\u0646\u0645\u06cc\u200c\u062a\u0648\u0627\u0646\u0645",
        "\u0646\u0645\u06cc\u062a\u0648\u0627\u0646\u0645",
        "\u0646\u0645\u06cc \u062a\u0648\u0627\u0646\u0645",
        "\u0642\u0627\u062f\u0631 \u0646\u06cc\u0633\u062a\u0645",
    )
    return any(term in text for term in refusal_terms)

def normalized_reply_text(user_message, agent_output, action=None):
    reply_text = agent_output.text.strip() if agent_output.text else ""
    if action and (
        not reply_text
        or response_language_mismatch(user_message, reply_text)
        or response_refuses_action(action, reply_text)
    ):
        return localized_action_reply(user_message, action)
    if not reply_text:
        return localized_processing_reply(
            user_message,
            is_cart_action=bool(action) or _has_add_to_cart_call(agent_output),
        )
    return reply_text

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
                entry, "attribute", "attributes", "items", default=None,
            )
            if isinstance(nested_attributes, list):
                normalized.update(_normalize_attributes(nested_attributes))
                continue
            name = _clean_text(_first_present(entry, "name", "title", "attribute_name"))
            value = _clean_text(_first_present(entry, "text", "value", "attribute_value"))
            if name and value:
                normalized[name] = value
    return normalized

def normalize_catalog_product(item):
    name = _clean_text(_first_present(item, "name", "product_name", "title"))
    product_id = _clean_text(_first_present(item, "product_id", "id", "productId"))
    product_url = _clean_text(_first_present(item, "url", "href", "link", "product_url", "productUrl"))
    stock = _to_int(_first_present(item, "stock", "quantity", "qty", default=0))
    category = _clean_text(
        _first_present(item, "category", "category_name", "manufacturer"),
        default="General Category",
    )
    attributes = _normalize_attributes(_first_present(item, "attributes", "attribute_groups", "specifications"))
    sales_angle = _clean_text(_first_present(item, "sales_angle", "description"))
    if not sales_angle:
        sales_angle = f"گزینه‌ای مناسب برای انتخاب دقیق‌تر بر اساس موجودی و مشخصات."
    return {
        "product_id": product_id,
        "name": name,
        "product_url": product_url,
        "price": _clean_text(_first_present(item, "price", "special", default="0")),
        "stock": stock,
        "category": category,
        "brand": _clean_text(_first_present(item, "brand", "manufacturer")),
        "image": _clean_text(_first_present(item, "image", "image_url")),
        "full_description": _clean_text(
            _first_present(item, "full_description", "description")
        ),
        "attributes": attributes,
        "sales_angle": sales_angle,
        "datasheet_content": _clean_text(
            _first_present(item, "datasheet_content", "datasheet_text")
        ),
        "alternatives": item.get("alternatives", []) if isinstance(item, dict) else [],
    }


@lru_cache(maxsize=1)
def get_vector_store():
    if not getattr(settings, "AI_ASSISTANT_VECTOR_ENABLED", True):
        return None

    try:
        from .utils.vector_handler import RockfordVectorStore

        return RockfordVectorStore()
    except Exception as exc:
        logger.warning("Vector Brain is unavailable: %s", exc)
        return None


def get_vector_knowledge(user_message):
    vector_store = get_vector_store()
    if vector_store is None:
        return ""

    return vector_store.query_relevant_knowledge(
        user_message,
        n_results=getattr(settings, "AI_ASSISTANT_VECTOR_RESULTS", 4),
    )


def index_catalog_knowledge(products):
    vector_store = get_vector_store()
    if vector_store is None:
        return 0

    payload = []
    for product in products:
        attributes = " | ".join(
            f"{key}: {value}"
            for key, value in product.get("attributes", {}).items()
        )
        content = "\n".join(
            [
                f"Product Name: {product.get('name', '')}",
                f"Brand: {product.get('brand', '')}",
                f"Product ID: {product.get('product_id', '')}",
                f"Category: {product.get('category', '')}",
                f"Price: {product.get('price', '')}",
                f"Stock: {product.get('stock', 0)}",
                f"Sales Position: {product.get('sales_angle', '')}",
                f"Description: {product.get('full_description', '')}",
                f"Technical Specifications: {attributes}",
            ]
        )
        payload.append(
            {
                "source": product.get("product_url", ""),
                "type": "opencart_product",
                "content": content,
            }
        )
        if product.get("datasheet_content"):
            payload.append(
                {
                    "source": product.get("product_url", "") + " (Datasheet)",
                    "type": "datasheet_pdf",
                    "content": product["datasheet_content"],
                }
            )

    return vector_store.inject_knowledge_base(
        payload,
        namespace="opencart_catalog",
        replace_namespace=True,
    )


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
    cache.set("products_catalog", products, timeout=None)
    try:
        index_catalog_knowledge(products)
    except Exception:
        logger.exception("Could not synchronize the OpenCart catalog to Vector Brain.")
    return products

def get_or_create_conversation(conversation_id=None, session_key=None, api_key=None):
    if conversation_id:
        normalized_id = str(conversation_id).strip()
        try:
            public_id = uuid.UUID(normalized_id)
        except (TypeError, ValueError, AttributeError):
            external_key = f"client:{normalized_id}"
            if len(external_key) > 80:
                external_key = "client:" + hashlib.sha256(
                    normalized_id.encode("utf-8")
                ).hexdigest()
            conversation, _ = Conversation.objects.get_or_create(
                session_key=external_key
            )
            return conversation
        conversation, _ = Conversation.objects.get_or_create(public_id=public_id)
        return conversation
    if session_key:
        conversation, _ = Conversation.objects.get_or_create(session_key=session_key)
        return conversation
    return Conversation.objects.create()

def get_relevant_catalog(user_message, top_k=5):
    full_catalog = load_catalog()
    if len(user_message) < 3 or not full_catalog:
        return full_catalog[:top_k]

    scored_products = []
    normalized_message = normalize_part_number(user_message)
    
    for product in full_catalog:
        p_name = product.get('name', '')
        normalized_name = normalize_part_number(p_name)
        # فیلتر سریع: اگر حداقل یکی از کلمات کلیدی پیام کاربر در اسم محصول یا برعکس نبود، از پردازش Fuzzy رد شو
        user_words = set(normalized_message.split())
        product_words = set(normalized_name.split())
        if not user_words.intersection(product_words) and len(normalized_message) > 3:
            continue # پرش از پردازش سنگین فازی
        score = fuzz.token_set_ratio(user_message, p_name)
        
        if normalized_name and normalized_name in normalized_message:
            score += 50
            
        if score > 35:
            scored_products.append((product, score))

    scored_products.sort(key=lambda x: x[1], reverse=True)
    relevant = [p[0] for p in scored_products]
    
    return relevant[:top_k] if relevant else full_catalog[:top_k]

def build_system_instruction(user_message):
    relevant_products = get_relevant_catalog(user_message)
    catalog_string = json.dumps(relevant_products, ensure_ascii=False, indent=2) if relevant_products else "درحال حاضر محصول مرتبطی پیدا نشد."
    vector_knowledge = get_vector_knowledge(user_message)
    vector_context = vector_knowledge or "No additional Vector Brain context was found."
    
    assistant_name = getattr(settings, 'AI_ASSISTANT_NAME', 'پشتیبان هوشمند')
    store_brand = getattr(settings, 'STORE_BRAND', 'فروشگاه ما')
    
    return f"""
LANGUAGE RULE:
Detect the language of the user's latest message and continue the conversation in that exact language.
If the user writes in Persian, answer in Persian. If the user writes in English, Arabic, Turkish, Russian, or any other language, answer in that same language.
If the user switches languages, switch with them immediately. Do not default to the store language unless it matches the user's current message.

TOOL RULE:
When calling add_to_cart, pass qty as the exact quantity requested in the latest user message.
For example, "add 3 imac" means qty=3, never qty=1.
Even when you call add_to_cart, include a short visible sentence in the latest user's language confirming what you are doing.
When the user asks what is in the basket/cart or asks for cart total, call show_cart. Do not say you cannot inspect the basket.
When the user asks to open the basket/cart page, call redirect_to_cart.
When the user asks to open, view, go to, or navigate to a product page, call redirect_to_product. Do not say you cannot navigate to the product page.
When the user asks to change item quantity, call update_cart_item with the final desired qty.
When the user asks to remove one item, call remove_from_cart.
When the user asks to empty the whole basket/cart, call clear_cart.
When the user gives a coupon, discount, promo, or voucher code, call apply_coupon.
When the user asks to pay, checkout, finalize, or complete the order, call redirect_to_checkout.
When the user asks for an invoice, proforma invoice, quote, or receipt, call send_invoice.
If the user asks to add products and pay in one message, call add_to_cart first, then redirect_to_checkout.

شما یک انسان واقعی و پشتیبان فروش آنلاین در {store_brand} هستید. 
نام شما {assistant_name} است. اگر مشتری اسم شما را پرسید، کوتاه و طبیعی بگویید «من {assistant_name} هستم» و مثل یک انسان گرم، صمیمی و حرفه‌ای رفتار کنید.
هرگز از عباراتی مثل "من یک هوش مصنوعی هستم" استفاده نکنید! مکالمه را طبیعی پیش ببرید.
 
اطلاعات محصولاتی که الان در سیستم فروشگاه ما موجود است و به درخواست کاربر ربط دارد:
{catalog_string}

VECTOR BRAIN CONTEXT:
Use this retrieved technical context for specifications, descriptions, and datasheet details.
Treat the live catalog above as the authority for current price and stock.
{vector_context}
 
قوانین رفتار انسانی و فروشگاهی تو:
۱. پاسخ‌ها باید کوتاه، روان و متقاعدکننده باشند.
۲. اول نتیجه را بگو، بعد دلیل خرید را خیلی کوتاه توضیح بده.
۳. برای متقاعد کردن، روی نیاز مشتری، موجودی واقعی، مزیت عملی محصول و اطمینان خرید تمرکز کن.
۴. اگر چند گزینه مرتبط وجود داشت، فقط بهترین پیشنهاد را برجسته کن.
۵. هر پاسخ را با یک دعوت به اقدام کوتاه تمام کن.
۶. آگاهی از محیط فروشگاه: شما داخل سایت فروشگاه ما با مشتری چت می‌کنید.
۷. چک کردن موجودی (Stock): همیشه فیلد موجودی (stock) محصولات را چک کنید. اگر صفر بود، اصلاً ثبت سفارش نکنید.
۸. ابزار ثبت سفارش: فقط زمانی که مشتری قطعی گفت "همینو می‌خوام" یا تایید نهایی کرد، ابزار `add_to_cart` را فراخوانی کنید.
""".strip()

async def get_memory_messages_async(conversation):
    if conversation is None:
        return []
    limit = max(getattr(settings, 'CHAT_MEMORY_LIMIT', 10), 0)
    messages = []
    async for message in conversation.messages.order_by("-created_at", "-id")[:limit]:
        messages.append(message)
    messages.reverse()
    return [{"role": m.role, "content": m.content} for m in messages]

async def save_chat_turn_async(conversation, user_message, assistant_reply):
    if conversation is None or not assistant_reply:
        return
    await ChatMessage.objects.abulk_create([
        ChatMessage(conversation=conversation, role=ChatMessage.ROLE_USER, content=user_message),
        ChatMessage(conversation=conversation, role=ChatMessage.ROLE_ASSISTANT, content=assistant_reply),
    ])
    await Conversation.objects.filter(pk=conversation.pk).aupdate(updated_at=timezone.now())

async def ask_ai_async(message, conversation=None):
    memory = await get_memory_messages_async(conversation)
    system_instruction = build_system_instruction(message)
    
    tools = [ASSISTANT_ACTION_TOOLS]
    
    agent_output = await ai_agent.ask_async(
        system_instruction=system_instruction, 
        chat_history=memory, 
        user_message=message,
        tools=tools,
    )
    
    actions = await extract_assistant_actions_async(agent_output, message)
    reply_text = normalized_reply_text(
        message,
        agent_output,
        next(iter(actions), None),
    )
    await save_chat_turn_async(conversation, message, reply_text)
    
    return agent_output


# =====================================================================
# [حل چالش باگ عدم تطابق نام کالا]: بازنویسی منطق استخراج اکشن‌ها به همراه Fuzzy Fallback
# =====================================================================
async def extract_cart_action_async(agent_output, user_message=""):
    actions = await extract_assistant_actions_async(agent_output, user_message)
    return next((action for action in actions if action.get("type") == "add_to_cart"), None)

async def extract_assistant_actions_async(agent_output, user_message=""):
    actions = []
    if not getattr(agent_output, "function_calls", None):
        fallback_action = _fallback_product_navigation_action(user_message, actions)
        return [fallback_action] if fallback_action else actions

    for call in agent_output.function_calls:
        call_name = getattr(call, "name", "")
        args = _call_args(call)

        if call_name == "redirect_to_checkout":
            actions.append(_checkout_action(args))
            continue

        if call_name == "show_cart":
            actions.append(_cart_view_action(args))
            continue

        if call_name == "redirect_to_cart":
            actions.append(_cart_redirect_action(args))
            continue

        if call_name == "redirect_to_product":
            actions.append(_product_redirect_action(args))
            continue

        if call_name == "update_cart_item":
            actions.append(_update_cart_action(args))
            continue

        if call_name == "remove_from_cart":
            actions.append(_remove_cart_action(args))
            continue

        if call_name == "clear_cart":
            actions.append(_clear_cart_action(args))
            continue

        if call_name == "apply_coupon":
            actions.append(_coupon_action(args))
            continue

        if call_name == "send_invoice":
            actions.append(_invoice_action(args))
            continue

        if call_name != "add_to_cart":
            continue

        requested_name = args.get("product_name", "")
        requested_qty = _requested_quantity(args)
        product = _find_catalog_product(requested_name, args.get("product_id", ""))

        if product:
            actions.append({
                "type": "add_to_cart",
                "product_name": product.get("name"),
                "product_id": product.get("product_id"),
                "product_url": _product_url(product),
                "price": product.get("price"),
                "stock": product.get("stock", 0),
                "requested_qty": requested_qty,
                "image": product.get("image", ""),
            })
        else:
            actions.append({
                "type": "add_to_cart",
                "product_name": requested_name,
                "requested_qty": requested_qty,
                "error": "Product metadata not found",
            })

    fallback_action = _fallback_product_navigation_action(user_message, actions)
    if fallback_action:
        actions.append(fallback_action)

    return actions

def get_memory_messages(conversation):
    if conversation is None:
        return []
    limit = max(getattr(settings, 'CHAT_MEMORY_LIMIT', 10), 0)
    messages = list(conversation.messages.order_by("-created_at", "-id")[:limit])
    messages.reverse()
    return [{"role": message.role, "content": message.content} for message in messages]

def save_chat_turn(conversation, user_message, assistant_reply):
    if conversation is None or not assistant_reply:
        return
    ChatMessage.objects.bulk_create([
        ChatMessage(conversation=conversation, role=ChatMessage.ROLE_USER, content=user_message),
        ChatMessage(conversation=conversation, role=ChatMessage.ROLE_ASSISTANT, content=assistant_reply),
    ])
    Conversation.objects.filter(pk=conversation.pk).update(updated_at=timezone.now())

def ask_ai(message, conversation=None):
    memory = get_memory_messages(conversation)
    system_instruction = build_system_instruction(message)
    
    agent_output = async_to_sync(ai_agent.ask_async)(
        system_instruction, 
        memory, 
        message, 
        tools=[ASSISTANT_ACTION_TOOLS],
    )
    if not agent_output.is_error:
        actions = extract_assistant_actions(agent_output, message)
        reply_text = normalized_reply_text(
            message,
            agent_output,
            next(iter(actions), None),
        )
        save_chat_turn(conversation, message, reply_text)
    return agent_output

def extract_cart_action(agent_output, user_message=""):
    return async_to_sync(extract_cart_action_async)(agent_output, user_message)

def extract_assistant_actions(agent_output, user_message=""):
    return async_to_sync(extract_assistant_actions_async)(agent_output, user_message)
