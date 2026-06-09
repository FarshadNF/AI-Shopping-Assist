import html
import json
import re
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
                        description="The exact part number or name of the product"
                    ),
                    "qty": types.Schema(
                        type=types.Type.INTEGER,
                        description="Quantity to add to the cart"
                    )
                },
                required=["product_name", "qty"]
            )
        ) # <--- این خط اصلاح شد (تبدیل آکولاد به پرانتز)
    ]
)

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
        default="General Category",
    )
    attributes = _normalize_attributes(_first_present(item, "attributes", "attribute_groups", "specifications"))
    sales_angle = _clean_text(_first_present(item, "sales_angle", "description"))
    if not sales_angle:
        sales_angle = f"گزینه‌ای مناسب برای انتخاب دقیق‌تر بر اساس موجودی و مشخصات."
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
    cache.set("products_catalog", products, timeout=None)
    return products

def get_or_create_conversation(conversation_id=None, session_key=None, api_key=None):
    if conversation_id:
        conversation, created = Conversation.objects.get_or_create(public_id=conversation_id)
        if api_key and conversation.api_key != api_key:
             conversation.api_key = api_key
             conversation.save(update_fields=['api_key'])
        return conversation
    if session_key:
        conversation, created = Conversation.objects.get_or_create(session_key=session_key)
        if api_key and conversation.api_key != api_key:
             conversation.api_key = api_key
             conversation.save(update_fields=['api_key'])
        return conversation
    return Conversation.objects.create(api_key=api_key)

def get_relevant_catalog(user_message, top_k=5):
    full_catalog = load_catalog()
    if len(user_message) < 3 or not full_catalog:
        return full_catalog[:top_k]

    scored_products = []
    normalized_message = normalize_part_number(user_message)
    
    for product in full_catalog:
        p_name = product.get('name', '')
        score = fuzz.token_set_ratio(user_message, p_name)
        
        normalized_name = normalize_part_number(p_name)
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
    
    assistant_name = getattr(settings, 'AI_ASSISTANT_NAME', 'پشتیبان هوشمند')
    store_brand = getattr(settings, 'STORE_BRAND', 'فروشگاه ما')
    
    return f"""
شما یک انسان واقعی و پشتیبان فروش آنلاین در {store_brand} هستید. 
نام شما {assistant_name} است. اگر مشتری اسم شما را پرسید، کوتاه و طبیعی بگویید «من {assistant_name} هستم» و مثل یک انسان گرم، صمیمی و حرفه‌ای رفتار کنید.
هرگز از عباراتی مثل "من یک هوش مصنوعی هستم" استفاده نکنید! مکالمه را طبیعی پیش ببرید.
 
اطلاعات محصولاتی که الان در سیستم فروشگاه ما موجود است و به درخواست کاربر ربط دارد:
{catalog_string}
 
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
    
    tools = [ADD_TO_CART_TOOL]
    user_api_key = conversation.api_key if conversation else None
    
    agent_output = await ai_agent.ask_async(
        system_instruction=system_instruction, 
        chat_history=memory, 
        user_message=message,
        tools=tools,
        api_key=user_api_key
    )
    
    reply_text = agent_output.text or "در حال پردازش..."
    await save_chat_turn_async(conversation, message, reply_text)
    
    return agent_output


# =====================================================================
# [حل چالش باگ عدم تطابق نام کالا]: بازنویسی منطق استخراج اکشن‌ها به همراه Fuzzy Fallback
# =====================================================================
async def extract_cart_action_async(agent_output):
    if hasattr(agent_output, 'function_calls') and agent_output.function_calls:
        for call in agent_output.function_calls:
            if call.name == "add_to_cart":
                args = call.args
                requested_name = args.get("product_name", "")
                requested_qty = int(args.get("qty", 1))
                
                normalized_requested = normalize_part_number(requested_name)
                catalog = load_catalog()
                
                # گام اول: تلاش برای یافتن انطباق دقیق آلفانومریکال
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
                
                # گام دوم (Fuzzy Fallback): اگر مدل در نوشتن نام کالا اشتباه تایپی یا ساختاری جزیی داشت
                best_match = None
                best_score = 0
                for product in catalog:
                    score = fuzz.token_set_ratio(requested_name, product.get("name", ""))
                    if score > best_score and score > 85: # آستانه اطمینان بالای ۸۵ درصد
                        best_score = score
                        best_match = product
                
                if best_match:
                    return {
                        "product_name": best_match.get("name"),
                        "product_id": best_match.get("product_id"),
                        "price": best_match.get("price"),
                        "stock": best_match.get("stock", 0),
                        "requested_qty": requested_qty,
                        "image": best_match.get("image", "")
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