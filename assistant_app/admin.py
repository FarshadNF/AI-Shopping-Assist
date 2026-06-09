import csv
from django.contrib import admin, messages
from django.http import HttpResponse, StreamingHttpResponse, JsonResponse
from django.shortcuts import redirect
from django.urls import path
from django.utils import timezone
from django.utils.html import format_html

from .models import ChatMessage, Conversation, OpenCartConnectionStatus
from .services import check_opencart_connection


class EchoBuffer:
    """یک بافر کمکی برای نوشتن سطرها در خروجی StreamingHttpResponse بدون اشغال رم."""
    def write(self, value):
        return value


@admin.register(OpenCartConnectionStatus)
class OpenCartConnectionStatusAdmin(admin.ModelAdmin):
    change_list_template = (
        "admin/assistant_app/opencartconnectionstatus/change_list.html"
    )
    list_display = (
        "name",
        "status_badge",
        "source",
        "catalog_items",
        "last_sync_at",
        "last_checked_at",
        "short_message",
    )
    search_fields = ("source", "message")

    def has_add_permission(self, request):
        return False

    def has_delete_permission(self, request, obj=None):
        return False

    def get_urls(self):
        """افزودن یک اندپوینت اختصاصی برای چک کردن وضعیت به صورت سنکرون/آژاکس"""
        urls = super().get_urls()
        custom_urls = [
            path(
                "check-async/",
                self.admin_site.admin_view(self.check_connection_async),
                name="opencart_check_async",
            ),
        ]
        return custom_urls + urls

    def check_connection_async(self, request):
        """
        [حل چالش شماره ۴]: بررسی وضعیت کانکشن بدون قفل کردن کل پنل ادمین.
        این متد از طریق یک درخواست AJAX در فرانت‌انید ادمین فراخوانی می‌شود.
        """
        status_obj = check_opencart_connection()
        return JsonResponse({
            "status": status_obj.status,
            "message": status_obj.message or "وضعیت اتصال با موفقیت بررسی شد."
        })

    def changelist_view(self, request, extra_context=None):
        """استفاده از متد کمکی load که در مدل پیاده کردیم (الگوی سsingleton)."""
        status_obj = OpenCartConnectionStatus.load()
        extra_context = {
            **(extra_context or {}),
            "opencart_status": status_obj,
        }
        return super().changelist_view(request, extra_context=extra_context)

    @admin.display(description="Status")
    def status_badge(self, obj):
        # هماهنگ شده با TextChoices مدل جدید در گام قبلی
        colors = {
            OpenCartConnectionStatus.Status.CONNECTED: ("#ecfdf5", "#047857"),
            OpenCartConnectionStatus.Status.WAITING: ("#fffbeb", "#b45309"),
            OpenCartConnectionStatus.Status.DISCONNECTED: ("#fef2f2", "#b91c1c"),
        }
        background, color = colors.get(obj.status, ("#f8fafc", "#334155"))
        return format_html(
            '<span style="display:inline-block;padding:3px 9px;border-radius:999px;'
            'background:{};color:{};font-weight:700;">{}</span>',
            background, color, obj.get_status_display()
        )

    @admin.display(description="Message")
    def short_message(self, obj):
        if len(obj.message) <= 90:
            return obj.message
        return obj.message[:87] + "..."


@admin.register(Conversation)
class ConversationAdmin(admin.ModelAdmin):
    list_display = ("public_id", "session_key", "created_at", "updated_at")
    search_fields = ("public_id", "session_key")
    readonly_fields = ("public_id", "created_at", "updated_at")
    actions = ("export_conversations_streaming",)

    @admin.action(description="Export selected conversations (Streaming CSV)")
    def export_conversations_streaming(self, request, queryset):
        """
        [حل چالش شماره ۳]: استفاده از StreamingHttpResponse و iterator برای جلوگیری از خطای OOM.
        دیگر نیازی به لود کردن هزاران سطر در رم سرور نیست.
        """
        pseudo_buffer = EchoBuffer()
        writer = csv.writer(pseudo_buffer)

        def data_generator():
            # سطر عنوان جدول (Header)
            yield writer.writerow(["ID", "Public ID", "Session Key", "Created At", "Updated At"])
            
            # خواندن تکه‌تکه (Chunked) داده‌ها از دیتابیس با iterator
            for conversation in queryset.iterator(chunk_size=500):
                yield writer.writerow([
                    conversation.id,
                    str(conversation.public_id),
                    conversation.session_key or "N/A",
                    conversation.created_at.strftime("%Y-%m-%d %H:%M:%S"),
                    conversation.updated_at.strftime("%Y-%m-%d %H:%M:%S")
                ])

        timestamp = timezone.localtime(timezone.now()).strftime("%Y%m%d-%H%M%S")
        response = StreamingHttpResponse(data_generator(), content_type="text/csv")
        response["Content-Disposition"] = f'attachment; filename="conversations-{timestamp}.csv"'
        return response


@admin.register(ChatMessage)
class ChatMessageAdmin(admin.ModelAdmin):
    list_display = ("conversation", "role", "created_at", "content_preview")
    list_filter = ("role", "created_at")
    search_fields = ("content", "conversation__public_id")
    readonly_fields = ("conversation", "role", "content", "created_at")

    # [حل چالش شماره ۵]: استفاده از select_related برای حل بحران N+1 Queries
    list_select_related = ("conversation",)

    @admin.display(description="Content")
    def content_preview(self, obj):
        if len(obj.content) <= 100:
            return obj.content
        return obj.content[:97] + "..."