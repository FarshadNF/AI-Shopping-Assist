from rest_framework import serializers


class ChatRequestSerializer(serializers.Serializer):
    message = serializers.CharField(allow_blank=False, trim_whitespace=True)
    conversation_id = serializers.UUIDField(required=False, allow_null=True)
