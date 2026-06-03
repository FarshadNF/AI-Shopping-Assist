from django.conf import settings
from rest_framework import serializers


class ChatRequestSerializer(serializers.Serializer):
    message = serializers.CharField(allow_blank=False, trim_whitespace=True)
    conversation_id = serializers.UUIDField(required=False, allow_null=True)


class CatalogImportSerializer(serializers.Serializer):
    source = serializers.CharField(required=False, allow_blank=True, max_length=255)
    products = serializers.ListField(
        child=serializers.DictField(),
        allow_empty=False,
    )

    def validate_products(self, products):
        max_items = settings.AI_ASSISTANT_MAX_CATALOG_ITEMS
        if len(products) > max_items:
            raise serializers.ValidationError(
                f"Catalog import is limited to {max_items} products."
            )
        return products
