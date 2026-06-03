import uuid

from django.db import models


class Conversation(models.Model):
    public_id = models.UUIDField(default=uuid.uuid4, editable=False, unique=True)
    session_key = models.CharField(max_length=80, blank=True, null=True, unique=True)
    created_at = models.DateTimeField(auto_now_add=True)
    updated_at = models.DateTimeField(auto_now=True)

    class Meta:
        ordering = ["-updated_at"]

    def __str__(self):
        return str(self.public_id)


class ChatMessage(models.Model):
    ROLE_USER = "user"
    ROLE_ASSISTANT = "assistant"

    ROLE_CHOICES = [
        (ROLE_USER, "User"),
        (ROLE_ASSISTANT, "Assistant"),
    ]

    conversation = models.ForeignKey(
        Conversation,
        related_name="messages",
        on_delete=models.CASCADE,
    )
    role = models.CharField(max_length=20, choices=ROLE_CHOICES)
    content = models.TextField()
    created_at = models.DateTimeField(auto_now_add=True)

    class Meta:
        ordering = ["created_at", "id"]
        indexes = [
            models.Index(fields=["conversation", "created_at"]),
        ]

    def __str__(self):
        return f"{self.role}: {self.content[:60]}"


class OpenCartConnectionStatus(models.Model):
    STATUS_CONNECTED = "connected"
    STATUS_WAITING = "waiting"
    STATUS_DISCONNECTED = "disconnected"

    STATUS_CHOICES = [
        (STATUS_CONNECTED, "Connected"),
        (STATUS_WAITING, "Waiting"),
        (STATUS_DISCONNECTED, "Disconnected"),
    ]

    name = models.CharField(max_length=80, unique=True, default="opencart")
    status = models.CharField(
        max_length=20,
        choices=STATUS_CHOICES,
        default=STATUS_WAITING,
    )
    source = models.CharField(max_length=255, blank=True)
    message = models.TextField(blank=True)
    catalog_items = models.PositiveIntegerField(default=0)
    last_sync_at = models.DateTimeField(blank=True, null=True)
    last_checked_at = models.DateTimeField(blank=True, null=True)
    updated_at = models.DateTimeField(auto_now=True)

    class Meta:
        verbose_name = "OpenCart connection"
        verbose_name_plural = "OpenCart connection"

    def __str__(self):
        return "OpenCart connection"
