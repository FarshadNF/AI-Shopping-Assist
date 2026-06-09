import uuid

from django.db import models
from django.utils.translation import gettext_lazy as _


class Conversation(models.Model):
    public_id = models.UUIDField(default=uuid.uuid4, editable=False, unique=True)
    session_key = models.CharField(max_length=80, blank=True, null=True, unique=True)
    # فیلد api_key به دلایل امنیتی و انتقال کلیدها به متغیرهای محیطی حذف شد
    
    created_at = models.DateTimeField(auto_now_add=True)
    updated_at = models.DateTimeField(auto_now=True)

    class Meta:
        ordering = ["-updated_at"]

    def __str__(self):
        return str(self.public_id)


class ChatMessage(models.Model):
    # استفاده از استاندارد مدرن جنگو برای انتخاب‌ها (TextChoices)
    class Role(models.TextChoices):
        USER = "user", _("User")
        ASSISTANT = "assistant", _("Assistant")

    conversation = models.ForeignKey(
        Conversation,
        related_name="messages",
        on_delete=models.CASCADE,
    )
    role = models.CharField(max_length=20, choices=Role.choices)
    content = models.TextField()
    created_at = models.DateTimeField(auto_now_add=True)

    class Meta:
        ordering = ["created_at", "id"]
        # ایندکس‌گذاری عالی! به شدت در سرعت لود تاریخچه چت تاثیر مثبت دارد
        indexes = [
            models.Index(fields=["conversation", "created_at"]),
        ]

    def __str__(self):
        # استفاده از get_role_display برای نمایش نام تمیز نقش
        return f"{self.get_role_display()}: {self.content[:60]}..."


class OpenCartConnectionStatus(models.Model):
    class Status(models.TextChoices):
        CONNECTED = "connected", _("Connected")
        WAITING = "waiting", _("Waiting")
        DISCONNECTED = "disconnected", _("Disconnected")

    name = models.CharField(max_length=80, unique=True, default="opencart")
    status = models.CharField(
        max_length=20,
        choices=Status.choices,
        default=Status.WAITING,
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

    def save(self, *args, **kwargs):
        """
        الگوی Singleton: 
        تضمین می‌کند که سیستم همیشه فقط یک ردیف تنظیمات برای OpenCart داشته باشد.
        """
        self.pk = 1
        super().save(*args, **kwargs)

    @classmethod
    def load(cls):
        """
        یک متد کمکی برای دریافت سریع یا ساخت ردیف وضعیت.
        نحوه استفاده در کدهای دیگر: status = OpenCartConnectionStatus.load()
        """
        obj, created = cls.objects.get_or_create(pk=1)
        return obj

    def __str__(self):
        return f"OpenCart Status: {self.get_status_display()}"