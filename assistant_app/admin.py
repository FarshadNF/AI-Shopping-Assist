from django.contrib import admin, messages
from django.http import HttpResponse
from django.shortcuts import redirect
from django.utils import timezone
from django.utils.html import format_html

from .exporters import build_conversations_xlsx
from .models import ChatMessage, Conversation, OpenCartConnectionStatus
from .services import check_opencart_connection, get_or_create_opencart_status


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
    ordering = ("name",)
    search_fields = ("source", "message")

    def has_add_permission(self, request):
        return False

    def has_delete_permission(self, request, obj=None):
        return False

    def changelist_view(self, request, extra_context=None):
        if request.GET.get("check") == "1":
            status = check_opencart_connection()
            if status.status == OpenCartConnectionStatus.STATUS_CONNECTED:
                self.message_user(request, "OpenCart connection is healthy.")
            else:
                self.message_user(
                    request,
                    "OpenCart connection is not healthy yet.",
                    level=messages.WARNING,
                )
            return redirect(request.path)

        status = get_or_create_opencart_status()
        extra_context = {
            **(extra_context or {}),
            "opencart_status": status,
        }
        return super().changelist_view(request, extra_context=extra_context)

    @admin.display(description="Status")
    def status_badge(self, obj):
        colors = {
            OpenCartConnectionStatus.STATUS_CONNECTED: ("#ecfdf5", "#047857"),
            OpenCartConnectionStatus.STATUS_WAITING: ("#fffbeb", "#b45309"),
            OpenCartConnectionStatus.STATUS_DISCONNECTED: ("#fef2f2", "#b91c1c"),
        }
        background, color = colors.get(obj.status, ("#f8fafc", "#334155"))
        return format_html(
            (
                '<span style="display:inline-block;padding:3px 9px;border-radius:'
                '999px;background:{};color:{};font-weight:700;">{}</span>'
            ),
            background,
            color,
            obj.get_status_display(),
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
    actions = ("export_conversations_to_excel",)

    @admin.action(description="Export selected conversations to Excel")
    def export_conversations_to_excel(self, request, queryset):
        content = build_conversations_xlsx(queryset)
        timestamp = timezone.localtime(timezone.now()).strftime("%Y%m%d-%H%M%S")
        response = HttpResponse(
            content,
            content_type=(
                "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
            ),
        )
        response["Content-Disposition"] = (
            f'attachment; filename="conversations-{timestamp}.xlsx"'
        )
        return response


@admin.register(ChatMessage)
class ChatMessageAdmin(admin.ModelAdmin):
    list_display = ("conversation", "role", "created_at", "content_preview")
    list_filter = ("role", "created_at")
    search_fields = ("content", "conversation__public_id")
    readonly_fields = ("conversation", "role", "content", "created_at")

    @admin.display(description="Content")
    def content_preview(self, obj):
        if len(obj.content) <= 100:
            return obj.content
        return obj.content[:97] + "..."
