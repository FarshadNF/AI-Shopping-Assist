import json
from unittest.mock import Mock, patch

from django.test import TestCase


class ChatApiTests(TestCase):
    def test_api_index_lists_endpoints(self):
        response = self.client.get("/")

        self.assertEqual(response.status_code, 200)
        data = response.json()
        self.assertEqual(data["status"], "ok")
        self.assertEqual(data["endpoints"]["chat"], "/api/chat/")

    def test_health_check_reports_catalog_count(self):
        response = self.client.get("/api/health/")

        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.json()["status"], "ok")
        self.assertGreater(response.json()["catalog_items"], 0)

    def test_chat_requires_message(self):
        response = self.client.post(
            "/api/chat/",
            data=json.dumps({"message": ""}),
            content_type="application/json",
        )

        self.assertEqual(response.status_code, 400)
        self.assertEqual(response.json()["status"], "error")

    @patch("assistant_app.services.requests.post")
    def test_chat_calls_ollama_and_extracts_cart_action(self, post):
        ollama_response = Mock()
        ollama_response.raise_for_status.return_value = None
        ollama_response.json.return_value = {
            "message": {"content": "حتماً. [ACTION: ADD_TO_CART: iPhone]"}
        }
        post.return_value = ollama_response

        response = self.client.post(
            "/api/chat/",
            data=json.dumps({"message": "آیفون می‌خواهم"}),
            content_type="application/json",
        )

        self.assertEqual(response.status_code, 200)
        data = response.json()
        self.assertEqual(data["status"], "success")
        self.assertEqual(data["action"]["product_id"], "40")
        post.assert_called_once()
