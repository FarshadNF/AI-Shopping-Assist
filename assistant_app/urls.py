from django.urls import path

from . import views


urlpatterns = [
    path("", views.chat_page, name="chat-page"),
    path("api/chat", views.chat_api, name="chat-api-no-slash"),
    path("api/chat/", views.chat_api, name="chat-api"),
    path("api/health/", views.health_check, name="health-check"),
]
