from django.conf import settings
from rest_framework.decorators import api_view
from rest_framework.response import Response
from asgiref.sync import sync_to_async

from .services import (
    ask_ai_async,               
    extract_cart_action_async,  # اصلاح مهم: ایمپورت تابع ناهمگام جدید
    get_or_create_conversation,
    load_catalog,
    record_opencart_catalog_sync,
    replace_catalog,
)
from .serializers import CatalogImportSerializer, ChatRequestSerializer


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


@api_view(["POST"])
async def chat_api(request):
    """
    ارتقای معماری: این ویو حالا کاملاً Async است. 
    در زمان پردازش طولانی هوش مصنوعی، سرور جنگو آزاد می‌ماند تا به بقیه کاربران پاسخ دهد.
    """
    
    # ۱. پردازش اعتبارسنجی (عملیات Sync که در ویوی Async باید پوشش داده شود)
    @sync_to_async
    def validate_request():
        serializer = ChatRequestSerializer(data=request.data)
        if not serializer.is_valid():
            return False, serializer.errors, None
        return True, None, serializer.validated_data

    is_valid, errors, validated_data = await validate_request()
    
    if not is_valid:
        return Response(
            {
                "status": "error",
                "reply": "فیلد message الزامی است.",
                "errors": errors,
            },
            status=400,
        )

    # ۲. مدیریت نشست و دیتابیس (عملیات Sync با پایگاه داده)
    @sync_to_async
    def get_conversation_context():
        conversation_id = validated_data.get("conversation_id")
        session_key = None
        if not conversation_id:
            if not request.session.session_key:
                request.session.create()
            session_key = request.session.session_key

        return get_or_create_conversation(
            conversation_id=conversation_id,
            session_key=session_key,
        )

    try:
        conversation = await get_conversation_context()
        
        # ۳. فراخوانی هسته هوش مصنوعی به صورت غیرمسدودکننده (Async)
        reply = await ask_ai_async(validated_data["message"], conversation=conversation)
        
    except Exception as exc:
        return Response({"status": "error", "reply": str(exc)}, status=502)

    # ۴. ساختاردهی پاسخ نهایی
    response_data = {
        "status": "success",
        "reply": reply,
        "conversation_id": str(conversation.public_id),
    }
    
    # اصلاح حیاتی: استفاده از await برای اجرای صحیح پارسر ناهمگام سبد خرید
    action = await extract_cart_action_async(reply)
    if action:
        response_data["action"] = action

    return Response(response_data)


@api_view(["GET"])
def health_check(request):
    return Response({"status": "ok", "catalog_items": len(load_catalog())})


@api_view(["POST"])
def import_catalog(request):
    expected_token = settings.AI_ASSISTANT_SYNC_TOKEN
    if expected_token:
        received_token = request.headers.get("X-AI-Assistant-Token", "")
        if received_token != expected_token:
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

    products = replace_catalog(serializer.validated_data["products"])
    record_opencart_catalog_sync(
        serializer.validated_data.get("source", ""),
        len(products),
    )
    return Response(
        {
            "status": "success",
            "catalog_items": len(products),
            "source": serializer.validated_data.get("source", ""),
        }
    )