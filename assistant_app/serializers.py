from rest_framework import serializers


def _first_present(source, *keys, default=None):
    for key in keys:
        value = source.get(key)
        if value not in (None, ""):
            return value
    return default


def _clean_text(value):
    if value is None:
        return ""
    return str(value).strip()


class ChatRequestSerializer(serializers.Serializer):
    conversation_id = serializers.CharField(
        required=False,
        allow_null=True,
        allow_blank=False,
        max_length=80,
    )
    message = serializers.CharField(required=True, allow_blank=False, max_length=2000)
    page_context = serializers.DictField(required=False, allow_empty=True)


class ProductSerializer(serializers.Serializer):
    def to_internal_value(self, data):
        if not isinstance(data, dict):
            raise serializers.ValidationError("Each product must be an object.")

        name = _clean_text(_first_present(data, "name", "product_name", "title"))
        if not name:
            raise serializers.ValidationError({"name": "Product name is required."})

        product_id = _clean_text(_first_present(data, "product_id", "id", "productId"))
        if product_id and len(product_id) > 100:
            raise serializers.ValidationError(
                {"product_id": "Ensure this field has no more than 100 characters."}
            )

        if len(name) > 1000:
            raise serializers.ValidationError(
                {"name": "Ensure this field has no more than 1000 characters."}
            )

        return dict(data)


class CatalogImportSerializer(serializers.Serializer):
    source = serializers.CharField(required=False, max_length=255, default="opencart")
    products = serializers.ListField(
        child=ProductSerializer(),
        required=True,
        allow_empty=False,
        max_length=5000,
    )
