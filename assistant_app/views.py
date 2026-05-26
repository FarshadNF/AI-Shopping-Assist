from rest_framework.decorators import api_view
from rest_framework.response import Response

from .services import ask_ai, extract_cart_action, load_catalog
from .serializers import ChatRequestSerializer


@api_view(["GET"])
def api_index(request):
    return Response(
        {
            "status": "ok",
            "endpoints": {
                "chat": "/api/chat/",
                "health": "/api/health/",
            },
        }
    )


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
        reply = ask_ai(serializer.validated_data["message"])
    except Exception as exc:
        return Response({"status": "error", "reply": str(exc)}, status=502)

    response_data = {"status": "success", "reply": reply}
    action = extract_cart_action(reply)
    if action:
        response_data["action"] = action

    return Response(response_data)


@api_view(["GET"])
def health_check(request):
    return Response({"status": "ok", "catalog_items": len(load_catalog())})
