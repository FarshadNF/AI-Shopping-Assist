import json
from pathlib import Path
from tempfile import TemporaryDirectory
from unittest.mock import Mock, patch

from django.test import TestCase, override_settings

from . import services
from .models import OpenCartConnectionStatus


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

    def test_catalog_import_replaces_catalog(self):
        with TemporaryDirectory() as temp_dir:
            services.load_catalog.cache_clear()
            with override_settings(
                BASE_DIR=Path(temp_dir),
                AI_ASSISTANT_SYNC_TOKEN="",
            ):
                response = self.client.post(
                    "/api/catalog/import/",
                    data=json.dumps(
                        {
                            "source": "test",
                            "products": [
                                {
                                    "product_id": 123,
                                    "name": "Test Switch",
                                    "price": "10.0000",
                                    "quantity": 7,
                                    "attributes": {"Ports": "8"},
                                }
                            ],
                        }
                    ),
                    content_type="application/json",
                )

                self.assertEqual(response.status_code, 200)
                self.assertEqual(response.json()["catalog_items"], 1)
                imported_catalog = services.load_catalog()
                self.assertEqual(imported_catalog[0]["product_id"], "123")
                self.assertEqual(imported_catalog[0]["stock"], 7)
                self.assertEqual(imported_catalog[0]["attributes"]["Ports"], "8")
                sync_status = OpenCartConnectionStatus.objects.get(name="opencart")
                self.assertEqual(
                    sync_status.status,
                    OpenCartConnectionStatus.STATUS_CONNECTED,
                )
                self.assertEqual(sync_status.source, "test")
                self.assertEqual(sync_status.catalog_items, 1)
            services.load_catalog.cache_clear()

    @override_settings(AI_ASSISTANT_SYNC_TOKEN="secret")
    def test_catalog_import_checks_token_when_configured(self):
        response = self.client.post(
            "/api/catalog/import/",
            data=json.dumps({"products": [{"name": "Blocked"}]}),
            content_type="application/json",
        )

        self.assertEqual(response.status_code, 403)

    @override_settings(
        OPENCART_CATALOG_URL="http://shop.test/index.php?route=catalog",
        OPENCART_TIMEOUT=1,
    )
    @patch("assistant_app.services.requests.get")
    def test_opencart_live_check_records_connected_status(self, get):
        opencart_response = Mock()
        opencart_response.raise_for_status.return_value = None
        opencart_response.json.return_value = {
            "success": True,
            "total": 12,
            "data": [{"product_id": "1", "name": "Switch"}],
        }
        get.return_value = opencart_response

        status = services.check_opencart_connection()

        self.assertEqual(status.status, OpenCartConnectionStatus.STATUS_CONNECTED)
        self.assertEqual(status.catalog_items, 12)
        self.assertEqual(status.source, "http://shop.test/index.php?route=catalog")
        get.assert_called_once()
