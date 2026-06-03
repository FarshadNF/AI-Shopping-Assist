import os

from django.core.asgi import get_asgi_application


os.environ.setdefault("DJANGO_SETTINGS_MODULE", "shopping_assist.settings")

# Compatibility entrypoint for ASGI servers that used to import core_api:app.
app = get_asgi_application()
