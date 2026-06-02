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
        self.assertIn("conversation_id", data)
        self.assertEqual(data["action"]["product_id"], "40")
        post.assert_called_once()

    @patch("assistant_app.services.requests.post")
    def test_chat_reuses_saved_memory_with_conversation_id(self, post):
        first_ollama_response = Mock()
        first_ollama_response.raise_for_status.return_value = None
        first_ollama_response.json.return_value = {
            "message": {"content": "first reply"}
        }

        second_ollama_response = Mock()
        second_ollama_response.raise_for_status.return_value = None
        second_ollama_response.json.return_value = {
            "message": {"content": "second reply"}
        }

        post.side_effect = [first_ollama_response, second_ollama_response]

        first_response = self.client.post(
            "/api/chat/",
            data=json.dumps({"message": "first question"}),
            content_type="application/json",
        )
        conversation_id = first_response.json()["conversation_id"]

        second_response = self.client.post(
            "/api/chat/",
            data=json.dumps(
                {
                    "message": "second question",
                    "conversation_id": conversation_id,
                }
            ),
            content_type="application/json",
        )

        self.assertEqual(second_response.status_code, 200)
        second_payload = post.call_args_list[1].kwargs["json"]
        second_messages = second_payload["messages"]
        self.assertEqual(
            [message["role"] for message in second_messages],
            ["system", "user", "assistant", "user"],
        )
        self.assertEqual(second_messages[1]["content"], "first question")
        self.assertEqual(second_messages[2]["content"], "first reply")
        self.assertEqual(second_messages[3]["content"], "second question")
