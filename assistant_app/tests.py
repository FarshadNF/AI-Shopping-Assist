import json
from io import BytesIO
from pathlib import Path
from types import SimpleNamespace
from tempfile import TemporaryDirectory
from unittest.mock import AsyncMock, Mock, patch
from zipfile import ZipFile

from asgiref.sync import async_to_sync
from django.contrib.admin.helpers import ACTION_CHECKBOX_NAME
from django.contrib.auth import get_user_model
from django.test import TestCase, override_settings
from django.urls import reverse

from . import services
from .models import ChatMessage, Conversation, OpenCartConnectionStatus
from .utils.ai_agent import AgentOutput, AIAgent


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

    @patch("assistant_app.services.ai_agent.ask_async", new_callable=AsyncMock)
    def test_chat_calls_gemini_and_extracts_cart_action(self, ask_async):
        ask_async.return_value = AgentOutput(
            text="Sure.",
            function_calls=[
                SimpleNamespace(
                    name="add_to_cart",
                    args={"product_name": "iPhone", "qty": 1},
                )
            ],
        )

        response = self.client.post(
            "/api/chat/",
            data=json.dumps({"message": "I want an iPhone"}),
            content_type="application/json",
        )

        self.assertEqual(response.status_code, 200)
        data = response.json()
        self.assertEqual(data["status"], "success")
        self.assertIn("conversation_id", data)
        self.assertEqual(data["action"]["product_id"], "40")
        ask_async.assert_awaited_once()

    @patch("assistant_app.services.ai_agent.ask_async", new_callable=AsyncMock)
    def test_chat_reuses_saved_memory_with_conversation_id(self, ask_async):
        ask_async.side_effect = [
            AgentOutput(text="first reply"),
            AgentOutput(text="second reply"),
        ]

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
        second_call = ask_async.call_args_list[1]
        second_messages = second_call.args[1]
        self.assertEqual(
            [message["role"] for message in second_messages],
            ["user", "assistant"],
        )
        self.assertEqual(second_messages[0]["content"], "first question")
        self.assertEqual(second_messages[1]["content"], "first reply")
        self.assertEqual(second_call.args[2], "second question")

    @patch("assistant_app.services.ai_agent.ask_async", new_callable=AsyncMock)
    def test_chat_reports_ai_failure_as_error_response(self, ask_async):
        ask_async.return_value = AgentOutput(
            text="ترافیک کاربران در حال حاضر بسیار بالاست. لطفاً یک دقیقه دیگر پیام خود را تکرار کنید.",
            error_code="ai_unavailable",
            status_code=503,
        )

        response = self.client.post(
            "/api/chat/",
            data=json.dumps({"message": "add this to cart"}),
            content_type="application/json",
        )

        self.assertEqual(response.status_code, 503)
        data = response.json()
        self.assertEqual(data["status"], "error")
        self.assertEqual(data["error_code"], "ai_unavailable")
        self.assertIn("conversation_id", data)
        self.assertEqual(ChatMessage.objects.count(), 0)

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


class AIAgentTests(TestCase):
    def test_optional_api_key_is_not_sent_to_generate_config(self):
        agent = AIAgent(model_name="test-model")
        agent.key_manager.keys = ["global-key"]
        agent.key_manager.current_key = "global-key"
        response = SimpleNamespace(text="ok", function_calls=[])
        client = SimpleNamespace(
            aio=SimpleNamespace(
                models=SimpleNamespace(
                    generate_content=AsyncMock(return_value=response)
                )
            )
        )

        with patch("assistant_app.utils.ai_agent.genai.Client", return_value=client) as client_factory:
            result = async_to_sync(agent.ask_async)(
                "system",
                [],
                "hello",
                api_key=None,
            )

        self.assertEqual(result.text, "ok")
        client_factory.assert_called_once_with(api_key="global-key")

    def test_conversation_api_key_can_be_used_without_global_keys(self):
        agent = AIAgent(model_name="test-model")
        agent.key_manager.keys = []
        agent.key_manager.current_key = None
        response = SimpleNamespace(text="ok", function_calls=[])
        client = SimpleNamespace(
            aio=SimpleNamespace(
                models=SimpleNamespace(
                    generate_content=AsyncMock(return_value=response)
                )
            )
        )

        with patch("assistant_app.utils.ai_agent.genai.Client", return_value=client) as client_factory:
            result = async_to_sync(agent.ask_async)(
                "system",
                [],
                "hello",
                api_key="conversation-key",
            )

        self.assertEqual(result.text, "ok")
        client_factory.assert_called_once_with(api_key="conversation-key")

    def test_retryable_gemini_unavailable_returns_error_output(self):
        agent = AIAgent(model_name="test-model")
        agent.key_manager.keys = ["key-1", "key-2"]
        agent.key_manager.current_key = "key-1"
        agent.key_manager.key_cycle = iter(["key-2"])
        client = SimpleNamespace(
            aio=SimpleNamespace(
                models=SimpleNamespace(
                    generate_content=AsyncMock(
                        side_effect=[
                            Exception("503 UNAVAILABLE high demand"),
                            Exception("503 UNAVAILABLE high demand"),
                        ]
                    )
                )
            )
        )

        with patch("assistant_app.utils.ai_agent.genai.Client", return_value=client) as client_factory:
            result = async_to_sync(agent.ask_async)("system", [], "hello")

        self.assertTrue(result.is_error)
        self.assertEqual(result.error_code, "ai_unavailable")
        self.assertEqual(result.status_code, 503)
        self.assertIn("ترافیک", result.text)
        self.assertEqual(client_factory.call_args_list[0].kwargs["api_key"], "key-1")
        self.assertEqual(client_factory.call_args_list[1].kwargs["api_key"], "key-2")


class ConversationAdminExportTests(TestCase):
    def setUp(self):
        user = get_user_model().objects.create_superuser(
            username="admin",
            email="admin@example.com",
            password="password",
        )
        self.client.force_login(user)

    def test_admin_exports_selected_conversations_to_xlsx(self):
        conversation = Conversation.objects.create(session_key="session-1")
        ChatMessage.objects.create(
            conversation=conversation,
            role=ChatMessage.ROLE_USER,
            content="Need a phone",
        )
        ChatMessage.objects.create(
            conversation=conversation,
            role=ChatMessage.ROLE_ASSISTANT,
            content="Here is a good option.",
        )

        other_conversation = Conversation.objects.create(session_key="session-2")
        ChatMessage.objects.create(
            conversation=other_conversation,
            role=ChatMessage.ROLE_USER,
            content="Do not export me",
        )

        response = self.client.post(
            reverse("admin:assistant_app_conversation_changelist"),
            {
                "action": "export_conversations_to_excel",
                ACTION_CHECKBOX_NAME: [str(conversation.pk)],
            },
        )

        self.assertEqual(response.status_code, 200)
        self.assertEqual(
            response["Content-Type"],
            "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        )
        self.assertIn("conversations-", response["Content-Disposition"])
        self.assertIn(".xlsx", response["Content-Disposition"])

        with ZipFile(BytesIO(response.content)) as workbook:
            sheet_xml = workbook.read("xl/worksheets/sheet1.xml").decode("utf-8")

        self.assertIn(str(conversation.public_id), sheet_xml)
        self.assertIn("session-1", sheet_xml)
        self.assertIn("Need a phone", sheet_xml)
        self.assertIn("Here is a good option.", sheet_xml)
        self.assertNotIn("session-2", sheet_xml)
        self.assertNotIn("Do not export me", sheet_xml)
