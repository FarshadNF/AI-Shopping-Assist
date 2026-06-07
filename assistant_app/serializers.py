from rest_framework import serializers

class ChatRequestSerializer(serializers.Serializer):
    conversation_id = serializers.UUIDField(required=False, allow_null=True)
    message = serializers.CharField(required=True, allow_blank=False)
    # اضافه شدن api_key به عنوان فیلد اختیاری
    api_key = serializers.CharField(max_length=255, required=False, allow_blank=True, allow_null=True)

class CatalogImportSerializer(serializers.Serializer):
    source = serializers.CharField(required=False, default="")
    products = serializers.ListField(
        child=serializers.DictField(),
        required=True
    )

class SetApiKeySerializer(serializers.Serializer):
    conversation_id = serializers.UUIDField(required=True)
    api_key = serializers.CharField(max_length=255, required=True, allow_blank=False)