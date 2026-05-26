import html
import json

from django.http import JsonResponse
from django.shortcuts import render
from django.views.decorators.csrf import csrf_exempt
from django.views.decorators.http import require_GET, require_http_methods

from .services import ask_ai, extract_cart_action, load_catalog


@require_GET
def chat_page(request):
    catalog = load_catalog()
    products = [
        {
            **product,
            "name": html.unescape(str(product.get("name", ""))),
        }
        for product in catalog
    ]

    return render(
        request,
        "assistant_app/chat.html",
        {
            "products": products,
            "product_count": len(catalog),
        },
    )


def _request_data(request):
    content_type = request.headers.get("content-type", "")
    if "application/json" in content_type:
        if not request.body:
            return {}
        return json.loads(request.body.decode("utf-8"))
    return request.POST


@csrf_exempt
@require_http_methods(["POST"])
def chat_api(request):
    try:
        data = _request_data(request)
    except json.JSONDecodeError:
        return JsonResponse(
            {"status": "error", "reply": "درخواست JSON معتبر نیست."},
            status=400,
        )

    message = data.get("message", "")
    if not isinstance(message, str) or not message.strip():
        return JsonResponse(
            {"status": "error", "reply": "فیلد message الزامی است."},
            status=400,
        )

    try:
        reply = ask_ai(message.strip())
    except Exception as exc:
        return JsonResponse({"status": "error", "reply": str(exc)}, status=502)

    response_data = {"status": "success", "reply": reply}
    action = extract_cart_action(reply)
    if action:
        response_data["action"] = action

    return JsonResponse(response_data)


@require_GET
def health_check(request):
    return JsonResponse({"status": "ok", "catalog_items": len(load_catalog())})
