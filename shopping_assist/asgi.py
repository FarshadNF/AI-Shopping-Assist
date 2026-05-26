import os

from django.core.asgi import get_asgi_application


os.environ.setdefault("DJANGO_SETTINGS_MODULE", "shopping_assist.settings")

application = get_asgi_application()
