from django.conf import settings
from rest_framework.decorators import api_view
from rest_framework.response import Response
from asgiref.sync import sync_to_async

from .services import (
    ask_ai_async,
    extract_cart_action_async,
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


get_or_create_conversation_async = sync_to_async(get_or_create_conversation)


@sync_to_async
def handle_session(request):
    if not request.session.session_key:
        request.session.create()
    return request.session.session_key


@api_view(["POST"])
async def chat_api(request):
    serializer = ChatRequestSerializer(data=request.data)
    is_valid = await sync_to_async(serializer.is_valid)()
    
    if not is_valid:
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
        session_key = None
        
        if not conversation_id:
            session_key = await handle_session(request)

        conversation = await get_or_create_conversation_async(
            conversation_id=conversation_id,
            session_key=session_key,
        )
        
        # اینجا خروجی ما دیگر یک استرینگ ساده نیست، بلکه یک آبجکت چندبعدی است
        agent_output = await ask_ai_async(
            serializer.validated_data["message"], 
            conversation=conversation
        )
        
    except Exception as exc:
        return Response({"status": "error", "reply": str(exc)}, status=502)

    # اگر هوش مصنوعی متنی تولید نکرده باشد (فقط ابزار را صدا زده باشد)، یک پیام جایگزین نشان می‌دهیم
    reply_text = agent_output.text if agent_output.text else "در حال پردازش درخواست شما..."

    response_data = {
        "status": "success",
        "reply": reply_text,
        "conversation_id": str(conversation.public_id),
    }
    
    # کل آبجکت را به تابع استخراج می‌دهیم تا بتواند function_calls را بررسی کند
    action = await extract_cart_action_async(agent_output)
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