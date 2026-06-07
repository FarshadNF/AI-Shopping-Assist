import html
import json
import re
from functools import lru_cache
from numbers import Number
from urllib.parse import urljoin

import httpx
import requests
from django.conf import settings
from django.utils import timezone
from asgiref.sync import sync_to_async

from .models import ChatMessage, Conversation, OpenCartConnectionStatus

# ------------------------------------------------------------------------
# 1. AI & NLP Regex Configurations
# ------------------------------------------------------------------------
ACTION_RE = re.compile(
    r"\[ACTION:\s*ADD_TO_CART:\s*(?P<name>[^|\]]+)(?:\|\s*QTY:\s*(?P<qty>\d+))?\s*\]", 
    re.IGNORECASE
)

# ------------------------------------------------------------------------
# Helper برای نرمال‌سازی پارت‌نامبرهای صنعتی (Moxa)
# ------------------------------------------------------------------------
def normalize_part_number(text):
    """
    حذف تمام کاراکترهای غیر الفبایی-عددی برای جفت‌وجور کردن دقیق پارت‌نامبرها.
    مثال: "EDS-205-M-SC" و "eds 205 m sc" و "Eds205MSc" همگی تبدیل می‌شوند به "eds205msc"
    """
    if not text:
        return ""
    return re.sub(r'[^a-zA-Z0-9]', '', text).lower().strip()

# ------------------------------------------------------------------------
# 2. Catalog & Data Management
# ------------------------------------------------------------------------
@lru_cache(maxsize=1)
def load_catalog():
    catalog_paths = (
        settings.BASE_DIR / "products_catalog.json",
        settings.BASE_DIR / "data" / "products_catalog.json",
    )
    for catalog_path in catalog_paths:
        try:
            with catalog_path.open("r", encoding="utf-8") as catalog_file:
                return json.load(catalog_file)
        except (FileNotFoundError, json.JSONDecodeError):
            continue
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

def get_or_create_conversation(conversation_id=None, session_key=None):
    if conversation_id:
        conversation, _ = Conversation.objects.get_or_create(public_id=conversation_id)
        return conversation

    if session_key:
        conversation, _ = Conversation.objects.get_or_create(session_key=session_key)
        return conversation

    return Conversation.objects.create()

def get_relevant_catalog(user_message, top_k=5):
    """
    RAG هوشمند: سیستم امتیازدهی پیشرفته بر اساس کلمات کلیدی فنی
    """
    full_catalog = load_catalog()
    if len(user_message) < 3 or not full_catalog:
        return full_catalog[:top_k]
    
    stop_words = {"دارید", "سلام", "میشه", "من", "یک", "از", "با", "به", "در", "برای", "این", "است", "و", "یا", "خرید"}
    search_terms = set(word.lower() for word in user_message.split() if word.lower() not in stop_words)
    
    if not search_terms:
        return full_catalog[:top_k]

    def score_product(product):
        score = 0
        p_name = product.get('name', '')
        sales_angle = product.get('sales_angle', '')
        category = product.get('category', '')
        attributes = json.dumps(product.get('attributes', {}))
        
        text_corpus = f"{p_name} {sales_angle} {category} {attributes}".lower()
        
        # بررسی پارت‌نامبر نرمال شده در متن پیام کاربر
        normalized_name = normalize_part_number(p_name)
        normalized_message = normalize_part_number(user_message)
        if normalized_name and normalized_name in normalized_message:
            score += 10  # بالاترین وزن برای تطابق پارت‌نامبر موکسا

        for term in search_terms:
            if term in text_corpus:
                score += 1
                if term in p_name.lower():
                    score += 3  # وزن بیشتر برای وجود کلمه در نام اصلی
        return score

    scored_products = [(p, score_product(p)) for p in full_catalog]
    relevant = [p for p, score in scored_products if score > 0]
    relevant.sort(key=lambda x: score_product(x), reverse=True)
    
    # اگر چیزی پیدا نشد، ۵ محصول اول کاتالوگ را به عنوان پیش‌فرض برگردان
    return relevant[:top_k] if relevant else full_catalog[:top_k]

# ------------------------------------------------------------------------
# 3. AI Connector & Memory (Asynchronous)
# ------------------------------------------------------------------------
def build_system_instruction(user_message):
    relevant_products = get_relevant_catalog(user_message)
    catalog_string = json.dumps(relevant_products, ensure_ascii=False, indent=2) if relevant_products else "محصولی یافت نشد."

    return f"""
تو یک مشاور فروش ارشد، مهندس زیرساخت و متخصص "حل مسئله" در حوزه اتوماسیون صنعتی و تجهیزات برند Moxa هستی.
وظیفه تو راهنمایی دقیق فنی خریداران و صادر کردن دستورات خودکار سیستم است.

کاتالوگ محصولات مرتبط موجود در دیتابیس فروشگاه:
{catalog_string}

پروتکل‌های عملکردی (Strict Rules):
۱. تطبیق پویا با صنف: کاملاً مهندسی، مستدل و بر اساس استانداردهای شبکه و اتوماسیون (مانند پورت‌های سریال، ریل مینیاتوری، ایزولاسیون) صحبت کن.
۲. مدیریت تعداد و موجودی (Stock & QTY): همیشه قبل از تایید خرید، میدان `stock` را چک کن. اگر موجودی صفر بود، به هیچ وجه تگ خرید صادر نکن و مدل‌های جایگزین کاتالوگ را پیشنهاد بده.
۳. ممیزی دیتای غلط (Data Audit): اگر در کاتالوگ ارائه‌شده تناقض فنی عجیبی دیدی، تگ خطا صادر کن: [NOTIFICATION: DATA_ERROR: نام محصول - شرح ایراد]
۴. فرمان اکشن خرید: فقط و فقط زمانی که کاربر صراحتاً درخواست خرید یا افزودن به سبد خرید را تایید کرد، تگ اکشن را دقیقاً به این فرمت در انتهای پاسخ خود بگذار:
[ACTION: ADD_TO_CART: Exact_Product_Name | QTY: 1]

نمونه رفتار صحیح (Few-Shot Examples):
کاربر: "من یه سوئیچ ۵ پورت مدیریتی موکسا می‌خوام برای داخل تابلو برق."
پاسخ: "برای این کاربرد سوئیچ EDS-205 موکسا با قابلیت نصب روی ریل مینیاتوری و ولتاژ ورودی ۱۲ تا ۴۸ ولت عالی است. موجودی انبار تایید شد. آیا مایلید به سبد خرید شما اضافه کنم؟"
کاربر: "بله حتماً ۲ عدد برام ثبت کن."
پاسخ: "سوئیچ ۵ پورت صنعتی EDS-205 با موفقیت به تعداد ۲ عدد برای شما ثبت شد. 
[ACTION: ADD_TO_CART: EDS-205 | QTY: 2]"

گاردریل متنی: پاسخ‌ها کاملاً به زبان فارسی، بدون تکلف بیجا، خلاصه و کاملاً مهندسی باشد.
""".strip()

# تبدیل توابع همگام سنگین ساخت پرامپت به ناهمگام جهت جلوگیری از قفل شدن سرور
build_system_instruction_async = sync_to_async(build_system_instruction)
load_catalog_async = sync_to_async(load_catalog)

@sync_to_async
def get_memory_messages_async(conversation):
    if conversation is None:
        return []
    limit = max(getattr(settings, 'CHAT_MEMORY_LIMIT', 10), 0)
    messages = list(conversation.messages.order_by("-created_at", "-id")[:limit])
    messages.reverse()
    return [{"role": message.role, "content": message.content} for message in messages]

@sync_to_async
def save_chat_turn_async(conversation, user_message, assistant_reply):
    if conversation is None:
        return
    ChatMessage.objects.bulk_create([
        ChatMessage(conversation=conversation, role=ChatMessage.ROLE_USER, content=user_message),
        ChatMessage(conversation=conversation, role=ChatMessage.ROLE_ASSISTANT, content=assistant_reply),
    ])
    Conversation.objects.filter(pk=conversation.pk).update(updated_at=timezone.now())

def get_memory_messages(conversation):
    if conversation is None:
        return []
    limit = max(getattr(settings, 'CHAT_MEMORY_LIMIT', 10), 0)
    messages = list(conversation.messages.order_by("-created_at", "-id")[:limit])
    messages.reverse()
    return [{"role": message.role, "content": message.content} for message in messages]

def save_chat_turn(conversation, user_message, assistant_reply):
    if conversation is None:
        return
    ChatMessage.objects.bulk_create([
        ChatMessage(conversation=conversation, role=ChatMessage.ROLE_USER, content=user_message),
        ChatMessage(conversation=conversation, role=ChatMessage.ROLE_ASSISTANT, content=assistant_reply),
    ])
    Conversation.objects.filter(pk=conversation.pk).update(updated_at=timezone.now())

def ask_ai(message, conversation=None):
    memory = get_memory_messages(conversation)
    payload = {
        "model": getattr(settings, 'OLLAMA_MODEL', 'qwen2.5:7b'),
        "messages": [
            {"role": "system", "content": build_system_instruction(message)},
            *memory,
            {"role": "user", "content": message},
        ],
        "stream": False,
        "options": {
            "temperature": 0.1,
            "num_ctx": 4096,
        }
    }

    response = requests.post(
        getattr(settings, 'OLLAMA_CHAT_URL', 'http://localhost:11434/api/chat'),
        json=payload,
        timeout=getattr(settings, 'OLLAMA_TIMEOUT', 180.0),
    )
    response.raise_for_status()
    data = response.json()
    reply = data.get("message", {}).get("content", "")
    if not isinstance(reply, str):
        raise ValueError("Unexpected response from Ollama.")

    save_chat_turn(conversation, message, reply)
    return reply

async def ask_ai_async(message, conversation=None):
    """
    ارتباط کاملاً ناهمگام (Async) بدون بلوکه کردن Event Loop جنگو
    """
    memory = await get_memory_messages_async(conversation)
    
    # اصلاح حیاتی: اجرای ناهمگام فرآیند سنگین RAG و ساخت پرامپت سیستم
    system_instruction = await build_system_instruction_async(message)
    
    payload = {
        "model": getattr(settings, 'OLLAMA_MODEL', 'qwen2.5:7b'),
        "messages": [
            {"role": "system", "content": system_instruction},
            *memory,
            {"role": "user", "content": message},
        ],
        "stream": False,
        "options": {
            "temperature": 0.1,  # کاهش دما برای جلوگیری از توهم در پارت‌نامبرها
            "num_ctx": 4096 
        }
    }

    async with httpx.AsyncClient(timeout=getattr(settings, 'OLLAMA_TIMEOUT', 180.0)) as client:
        try:
            response = await client.post(
                getattr(settings, 'OLLAMA_CHAT_URL', 'http://localhost:11434/api/chat'),
                json=payload
            )
            response.raise_for_status()
            data = response.json()
            reply = data.get("message", {}).get("content", "")
            
            await save_chat_turn_async(conversation, message, reply)
            return reply
            
        except httpx.ReadTimeout:
            return "سیستم در حال پردازش دیتای سنگینی است و پاسخ طولانی شد. لطفا لحظاتی بعد مجدد تلاش کنید."
        except Exception as e:
            return f"خطا در ارتباط با مغز متفکر: {str(e)}"

# ------------------------------------------------------------------------
# 4. Action Parsers
# ------------------------------------------------------------------------
def extract_cart_action(reply):
    match = ACTION_RE.search(reply or "")
    if not match:
        return None

    requested_name = html.unescape(match.group("name").strip())
    requested_qty = int(match.group("qty")) if match.group("qty") else 1
    
    normalized_requested = normalize_part_number(requested_name)
    
    for product in load_catalog():
        if normalize_part_number(product.get("name", "")) == normalized_requested:
            return {
                "product_name": product.get("name"),
                "product_id": product.get("product_id"),
                "price": product.get("price"),
                "stock": product.get("stock", 0),
                "requested_qty": requested_qty,
                "image": product.get("image", "")
            }
            
    return {
        "product_name": requested_name, 
        "requested_qty": requested_qty, 
        "error": "Product metadata not found"
    }

async def extract_cart_action_async(reply):
    """
    نسخه ارتقا یافته ناهمگام با سیستم انطباق پارت‌نامبرهای صنعتی ضد خطا
    """
    match = ACTION_RE.search(reply or "")
    if not match:
        return None

    requested_name = html.unescape(match.group("name").strip())
    requested_qty = int(match.group("qty")) if match.group("qty") else 1
    
    normalized_requested = normalize_part_number(requested_name)
    catalog = await load_catalog_async()
    
    for product in catalog:
        # انطباق هوشمند پارت‌نامبر به جای تساوی سخت‌گیرانه متنی
        if normalize_part_number(product.get("name", "")) == normalized_requested:
            return {
                "product_name": product.get("name"),
                "product_id": product.get("product_id"),
                "price": product.get("price"),
                "stock": product.get("stock", 0),
                "requested_qty": requested_qty,
                "image": product.get("image", "")
            }
            
    return {
        "product_name": requested_name, 
        "requested_qty": requested_qty, 
        "error": "Product metadata not found"
    }
