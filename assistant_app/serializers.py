from rest_framework import serializers

class ChatRequestSerializer(serializers.Serializer):
    """
    اعتبارسنجی درخواست‌های چت.
    فیلد api_key به دلایل امنیتی (جلوگیری از سرقت کلید در سمت کلاینت) حذف شده است.
    """
    conversation_id = serializers.UUIDField(required=False, allow_null=True)
    message = serializers.CharField(required=True, allow_blank=False, max_length=2000)


class ProductSerializer(serializers.Serializer):
    """
    اعتبارسنجی دقیق و ساختاریافته برای هر محصول در کاتالوگ.
    این کلاس جایگزین DictField کور شده است تا از ورود داده‌های نامعتبر یا مخرب جلوگیری کند.
    """
    # فیلدهای الزامی
    product_id = serializers.CharField(required=True, max_length=100)
    name = serializers.CharField(required=True, max_length=255)
    price = serializers.DecimalField(required=True, max_digits=12, decimal_places=2)
    
    # فیلدهای اختیاری با مقادیر پیش‌فرض امن
    sku = serializers.CharField(required=False, max_length=100, allow_blank=True, default="")
    stock = serializers.IntegerField(required=False, min_value=0, default=0)
    description = serializers.CharField(required=False, allow_blank=True, default="")
    category = serializers.CharField(required=False, max_length=100, allow_blank=True, default="")


class CatalogImportSerializer(serializers.Serializer):
    """
    اعتبارسنجی درخواست‌های همگام‌سازی کاتالوگ.
    """
    source = serializers.CharField(required=False, max_length=50, default="opencart")
    products = serializers.ListField(
        child=ProductSerializer(),  # استفاده از سریالایزر اختصاصی به جای دیکشنری آزاد
        required=True,
        allow_empty=False,          # جلوگیری از ارسال لیست خالی
        max_length=5000             # محدودیت منطقی برای جلوگیری از Overload شدن حافظه در یک درخواست
    )