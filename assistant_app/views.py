import hmac
import logging
import threading
from django.conf import settings
from rest_framework.decorators import api_view
from rest_framework.response import Response

from .services import (
    ask_ai,
    extract_assistant_actions,
    extract_cart_action,
    get_or_create_conversation,
    load_catalog,
    normalized_reply_text,
    record_opencart_catalog_sync,
    replace_catalog,
)
from .serializers import CatalogImportSerializer, ChatRequestSerializer

# پیکربندی لاگر برای ثبت ایمن خطاهای سرور (جلوگیری از Information Disclosure)
logger = logging.getLogger(__name__)

@api_view(["GET"])
def api_index(request):
    return Response(
        {
            "status": "ok",
            "endpoints": {
                "chat": "/api/chat/",
                "catalog_import": "/api/catalog/import/",
                "health": "/api/health/",
            },
        }
    )

def get_session_key(request):
    if not request.session.session_key:
        request.session.create()
    return request.session.session_key

@api_view(["POST"])
def chat_api(request):
    serializer = ChatRequestSerializer(data=request.data)
    
    if not serializer.is_valid():
        return Response(
            {
                "status": "error",
                "reply": "فیلد message الزامی است.",
                "errors": serializer.errors,
            },
            status=400,
        )

    try:
        conversation_id = serializer.validated_data.get("conversation_id")
        session_key = None if conversation_id else get_session_key(request)

        # فیلد api_key از ورودی کلاینت حذف شد تا امنیت حفظ شود. (Critical Vulnerability Fix)
        conversation = get_or_create_conversation(
            conversation_id=conversation_id,
            session_key=session_key,
        )
        
        user_message = serializer.validated_data["message"]
        agent_output = ask_ai(
            user_message,
            conversation=conversation
        )
        
    except Exception as exc:
        # ثبت لاگ در سرور بدون درز اطلاعات ساختاری به کلاینت (Information Disclosure Fix)
        logger.error("Chat API Error: %s", str(exc), exc_info=True)
        return Response(
            {
                "status": "error", 
                "reply": "خطای پردازش در سرور رخ داده است. لطفاً لحظاتی بعد تلاش کنید."
            }, 
            status=500
        )

    actions = extract_assistant_actions(agent_output, user_message)
    action = next(iter(actions), None)
    cart_action = extract_cart_action(agent_output, user_message)
    reply_text = normalized_reply_text(user_message, agent_output, action)

    if agent_output.is_error:
        return Response(
            {
                "status": "error",
                "reply": reply_text,
                "conversation_id": str(conversation.public_id),
                "error_code": agent_output.error_code,
            },
            status=agent_output.status_code or 502,
        )

    response_data = {
        "status": "success",
        "reply": reply_text,
        "conversation_id": str(conversation.public_id),
    }
    
    if actions:
        response_data["actions"] = actions
    if cart_action:
        response_data["action"] = cart_action
    elif action:
        response_data["action"] = action

    return Response(response_data)


@api_view(["GET"])
def health_check(request):
    return Response({"status": "ok", "catalog_items": len(load_catalog())})


def background_catalog_sync(products, source):
    """تابع کمکی برای اجرای همگام‌سازی در پس‌زمینه"""
    try:
        replaced_products = replace_catalog(products)
        record_opencart_catalog_sync(source, len(replaced_products))
    except Exception as e:
        logger.error("Background Catalog Sync Error: %s", str(e), exc_info=True)

@api_view(["POST"])
def import_catalog(request):
    expected_token = settings.AI_ASSISTANT_SYNC_TOKEN
    
    if expected_token:
        received_token = request.headers.get("X-AI-Assistant-Token", "")
        # استفاده از hmac برای جلوگیری از حملات زمانی (Timing Attack Fix)
        if not hmac.compare_digest(received_token, expected_token):
            return Response(
                {"status": "error", "reply": "Invalid catalog sync token."},
                status=403,
            )

    serializer = CatalogImportSerializer(data=request.data)
    if not serializer.is_valid():
        return Response(
            {
                "status": "error",
                "reply": "Invalid catalog payload.",
                "errors": serializer.errors,
            },
            status=400,
        )

    products = serializer.validated_data["products"]
    source = serializer.validated_data.get("source", "")
    
    if not getattr(settings, "AI_ASSISTANT_ASYNC_CATALOG_IMPORT", True):
        replaced_products = replace_catalog(products)
        record_opencart_catalog_sync(source, len(replaced_products))
        return Response(
            {
                "status": "success",
                "reply": "Catalog import completed.",
                "catalog_items": len(replaced_products),
                "catalog_items_received": len(products),
                "source": source,
            }
        )

    # انتقال پردازش سنگین به پس‌زمینه برای جلوگیری از تایم‌اوت شدن ریکوئست (Blocking IO Fix)
    thread = threading.Thread(target=background_catalog_sync, args=(products, source), daemon=True)
    thread.start()
    
    return Response(
        {
            "status": "success",
            "reply": "درخواست همگام‌سازی دریافت شد و در پس‌زمینه در حال پردازش است.",
            "catalog_items_received": len(products),
            "source": source,
        },
        status=202  # کد 202 نشان‌دهنده پذیرش درخواست و پردازش غیرهمزمان است
    )
