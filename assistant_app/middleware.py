from django.conf import settings
from django.http import HttpResponse


class SimpleCorsMiddleware:
    def __init__(self, get_response):
        self.get_response = get_response

    def __call__(self, request):
        if request.method == "OPTIONS" and self._is_allowed(request):
            response = HttpResponse()
        else:
            response = self.get_response(request)

        self._add_headers(request, response)
        return response

    def _is_allowed(self, request):
        origin = request.headers.get("Origin")
        if not origin:
            return False
        return (
            settings.AI_ASSISTANT_CORS_ALLOW_ALL
            or origin in settings.AI_ASSISTANT_ALLOWED_ORIGINS
        )

    def _add_headers(self, request, response):
        origin = request.headers.get("Origin")
        if not origin or not self._is_allowed(request):
            return

        response["Access-Control-Allow-Origin"] = (
            "*" if settings.AI_ASSISTANT_CORS_ALLOW_ALL else origin
        )
        response["Access-Control-Allow-Methods"] = "GET, POST, OPTIONS"
        response["Access-Control-Allow-Headers"] = (
            "Content-Type, X-AI-Assistant-Token"
        )
        response["Access-Control-Max-Age"] = "86400"
