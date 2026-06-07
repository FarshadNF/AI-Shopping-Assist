import html
import json
import re
from functools import lru_cache
from numbers import Number
from urllib.parse import urljoin
import requests
from django.conf import settings
from django.utils import timezone
from asgiref.sync import sync_to_async, async_to_sync
from google.genai import types  # اضافه شدن برای تعریف ابزارها

from .models import ChatMessage, Conversation, OpenCartConnectionStatus
from .utils.ai_agent import ai_agent

# تعریف ساختار ابزار ثبت سفارش برای درک نیتیو توسط هوش مصنوعی
ADD_TO_CART_TOOL = types.Tool(
    function_declarations=[
        types.FunctionDeclaration(
            name="add_to_cart",
            description="Adds a specified product to the user's shopping cart. Call this ONLY when the user explicitly confirms they want to buy or add an item to the cart.",
            parameters=types.Schema(
                type=types.Type.OBJECT,
                properties={
                    "product_name": types.Schema(
                        type=types.Type.STRING,
                        description="The exact part number or name of the product (e.g. EDS-205)"
                    ),
                    "qty": types.Schema(
                        type=types.Type.INTEGER,
                        description="Quantity to add to the cart"
                    )
                },
                required=["product_name", "qty"]
            )
        )
    ]
)

def normalize_part_number(text):
    if not text:
        return ""
    return re.sub(r'[^a-zA-Z0-9]', '', text).lower().strip()

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
            status.message = "No live OpenCart URL is configured. The latest footer catalog sync was successful."
        else:
            status.status = OpenCartConnectionStatus.STATUS_WAITING
            status.message = "Set OPENCART_BASE_URL or OPENCART_CATALOG_URL to enable live OpenCart checks. Footer sync status is still tracked."
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
    stock = _to_int(_first_present(item, "stock", "quantity", "qty", default=0))
    category = _clean_text(
        _first_present(item, "category", "category_name", "manufacturer"),
        default="Industrial Automation & Networking",
    )
    attributes = _normalize_attributes(_first_present(item, "attributes", "attribute_groups", "specifications"))
    if not attributes:
        attributes = {
            "Interface": "مشخصات پورت یافت نشد",
            "Protection": "استاندارد بدنه نامشخص",
        }
    sales_angle = _clean_text(_first_present(item, "sales_angle", "description"))
    if not sales_angle:
        sales_angle = f"تجهیزات باکیفیت مدل {name}. گزینه‌ای مناسب برای انتخاب دقیق‌تر بر اساس موجودی و مشخصات فروشگاه."
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

def get_or_create_conversation(conversation_id=None, session_key=None, api_key=None):
    if conversation_id:
        conversation, created = Conversation.objects.get_or_create(public_id=conversation_id)
        # Update the api_key if one was provided and it differs
        if api_key and conversation.api_key != api_key:
             conversation.api_key = api_key
             conversation.save(update_fields=['api_key'])
        return conversation
    if session_key:
        conversation, created = Conversation.objects.get_or_create(session_key=session_key)
        # Update the api_key if one was provided and it differs
        if api_key and conversation.api_key != api_key:
             conversation.api_key = api_key
             conversation.save(update_fields=['api_key'])
        return conversation
    
    # If neither conversation_id nor session_key is provided
    return Conversation.objects.create(api_key=api_key)

def get_relevant_catalog(user_message, top_k=5):
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
        normalized_name = normalize_part_number(p_name)
        normalized_message = normalize_part_number(user_message)
        if normalized_name and normalized_name in normalized_message:
            score += 10
        for term in search_terms:
            if term in text_corpus:
                score += 1
                if term in p_name.lower():
                    score += 3
        return score
    scored_products = [(p, score_product(p)) for p in full_catalog]
    relevant = [p for p, score in scored_products if score > 0]
    relevant.sort(key=lambda x: score_product(x), reverse=True)
    return relevant[:top_k] if relevant else full_catalog[:top_k]

def build_system_instruction(user_message):
    relevant_products = get_relevant_catalog(user_message)
    catalog_string = json.dumps(relevant_products, ensure_ascii=False, indent=2) if relevant_products else "درحال حاضر محصول مرتبطی پیدا نشد."
    
    return f"""
شما یک انسان واقعی و پشتیبان فروش آنلاین در یک فروشگاه تخصصی تجهیزات اتوماسیون صنعتی و شبکه (برند Moxa) هستید. 
نام شما در سیستم پشتیبانی تعریف نشده است اما مانند یک انسان گرم، صمیمی و حرفه‌ای رفتار کنید. 
هرگز از عباراتی مثل "من یک هوش مصنوعی هستم" یا "من یک مشاور ارشد هستم" استفاده نکنید! مکالمه را طبیعی پیش ببرید. (مثال: "سلام! خیلی خوش اومدید، چطور می‌تونم راهنمایی‌تون کنم؟")

اطلاعات محصولاتی که الان در سیستم فروشگاه ما موجود است و به درخواست کاربر ربط دارد:
{catalog_string}

قوانین رفتار انسانی و فروشگاهی تو:
۱. لحن طبیعی: پاسخ‌ها را کوتاه، دوستانه و دقیق بدهید. نیازی نیست در هر پیام تمام مشخصات فنی را ردیف کنید، مگر اینکه کاربر پرسیده باشد.
۲. آگاهی از محیط فروشگاه: شما داخل سایت فروشگاه ما با مشتری چت می‌کنید. اگر محصولی در کاتالوگ بالا نبود، با احترام بگویید: "اجازه بدید چک کنم... متاسفانه این مدل رو الان موجود نداریم، اما مدل‌های مشابه..."
۳. چک کردن موجودی (Stock): همیشه فیلد موجودی (stock) محصولات را چک کنید. اگر صفر بود، اصلاً ثبت سفارش نکنید و جایگزین پیشنهاد دهید.
۴. ابزار ثبت سفارش: فقط و فقط زمانی که مشتری قطعی گفت "همینو می‌خوام"، "اضافه کن به سبد" یا تایید نهایی کرد، ابزار `add_to_cart` را فراخوانی کنید. نیازی به توضیح درباره فراخوانی ابزار به مشتری نیست.
""".strip()

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
    if conversation is None or not assistant_reply:
        return
    ChatMessage.objects.bulk_create([
        ChatMessage(conversation=conversation, role=ChatMessage.ROLE_USER, content=user_message),
        ChatMessage(conversation=conversation, role=ChatMessage.ROLE_ASSISTANT, content=assistant_reply),
    ])
    Conversation.objects.filter(pk=conversation.pk).update(updated_at=timezone.now())

async def ask_ai_async(message, conversation=None):
    memory = await get_memory_messages_async(conversation)
    system_instruction = await build_system_instruction_async(message)
    
    tools = [ADD_TO_CART_TOOL]
    
    # استخراج api_key در صورت وجود در آبجکت conversation
    user_api_key = conversation.api_key if conversation else None
    
    agent_output = await ai_agent.ask_async(
        system_instruction=system_instruction, 
        chat_history=memory, 
        user_message=message,
        tools=tools,
        api_key=user_api_key # ارسال api_key اختصاصی کاربر
    )
    
    reply_text = agent_output.text or "در حال پردازش سفارش شما..."
    await save_chat_turn_async(conversation, message, reply_text)
    
    return agent_output

async def extract_cart_action_async(agent_output):
    if hasattr(agent_output, 'function_calls') and agent_output.function_calls:
        for call in agent_output.function_calls:
            if call.name == "add_to_cart":
                args = call.args
                requested_name = args.get("product_name", "")
                requested_qty = int(args.get("qty", 1))
                
                normalized_requested = normalize_part_number(requested_name)
                catalog = await load_catalog_async()
                for product in catalog:
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
    return None

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
    
    user_api_key = conversation.api_key if conversation else None
    
    agent_output = async_to_sync(ai_agent.ask_async)(
        system_instruction, 
        memory, 
        message, 
        tools=[ADD_TO_CART_TOOL],
        api_key=user_api_key
    )
    reply_text = agent_output.text or "در حال پردازش..."
    save_chat_turn(conversation, message, reply_text)
    return agent_output

def extract_cart_action(agent_output):
    return async_to_sync(extract_cart_action_async)(agent_output)