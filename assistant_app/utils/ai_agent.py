import asyncio
import logging
import os
from itertools import cycle

from google import genai
from google.genai import types

logger = logging.getLogger(__name__)


def _csv_env(name, default=""):
    return [
        item.strip()
        for item in os.getenv(name, default).split(",")
        if item.strip()
    ]


def _int_env(name, default):
    try:
        return max(int(os.getenv(name, str(default))), 1)
    except (TypeError, ValueError):
        return default


def _float_env(name, default):
    try:
        return max(float(os.getenv(name, str(default))), 0)
    except (TypeError, ValueError):
        return default


class GeminiKeyManager:
    def __init__(self):
        self.keys = []
        seen = set()

        def add_key(key):
            if not key:
                return
            normalized = key.strip()
            if normalized and normalized not in seen:
                self.keys.append(normalized)
                seen.add(normalized)

        for i in range(1, 12):
            add_key(os.getenv(f"GEMINI_API_KEY_{i}"))
            add_key(os.getenv(f"GOOGLE_API_KEY_{i}"))

        if not self.keys:
            add_key(os.getenv("GEMINI_API_KEY"))
            add_key(os.getenv("GOOGLE_API_KEY"))

        # The SDK prints a warning and may prefer one env var when both singular
        # names are present. We pass keys explicitly, so clear auto-discovery.
        os.environ.pop("GEMINI_API_KEY", None)
        os.environ.pop("GOOGLE_API_KEY", None)

        if not self.keys:
            logger.error("No Gemini API keys found in environment variables.")

        if self.keys:
            self.key_cycle = cycle(self.keys)
            self.current_key = next(self.key_cycle)
        else:
            self.current_key = None

        self._lock = asyncio.Lock()

    async def rotate(self):
        async with self._lock:
            if self.keys:
                self.current_key = next(self.key_cycle)
                logger.warning("Switched to the next configured Gemini API key.")
            return self.current_key

    def get_client(self):
        if not self.current_key:
            raise ValueError("API key is missing or not configured.")
        return genai.Client(api_key=self.current_key)


def _error_text(error):
    return str(error).lower()


def _is_retryable_ai_error(error):
    error_msg = _error_text(error)
    return any(
        marker in error_msg
        for marker in (
            "429",
            "500",
            "502",
            "503",
            "504",
            "deadline",
            "exhausted",
            "high demand",
            "quota",
            "temporarily",
            "timeout",
            "try again later",
            "unavailable",
        )
    )


def _is_retryable_key_error(error):
    error_msg = _error_text(error)
    return any(
        marker in error_msg
        for marker in (
            "429",
            "500",
            "502",
            "503",
            "504",
            "deadline",
            "exhausted",
            "high demand",
            "quota",
            "temporarily",
            "timeout",
            "try again later",
            "unavailable",
        )
    )


def _is_high_demand_error(error):
    error_msg = _error_text(error)
    return any(
        marker in error_msg
        for marker in (
            "503",
            "high demand",
            "temporarily",
            "try again later",
            "unavailable",
        )
    )


def _is_permission_error(error):
    error_msg = _error_text(error)
    return any(
        marker in error_msg
        for marker in (
            "403",
            "api_key_invalid",
            "denied access",
            "forbidden",
            "permission_denied",
        )
    )


class AgentOutput:
    def __init__(
        self,
        text=None,
        function_calls=None,
        raw_response=None,
        error_code=None,
        status_code=None,
    ):
        self.text = text or ""
        self.function_calls = function_calls or []
        self.raw_response = raw_response
        self.error_code = error_code
        self.status_code = status_code

    @property
    def is_error(self):
        return bool(self.error_code)


def _agent_error(text, error_code, status_code=502):
    return AgentOutput(text=text, error_code=error_code, status_code=status_code)


def _response_text(response):
    try:
        return response.text or ""
    except ValueError:
        if getattr(response, "function_calls", []):
            return ""
        raise


def _ai_unavailable_message():
    return "در حال حاضر اتصال مدل کمی شلوغ شده است. لطفاً چند لحظه دیگر دوباره پیام بدهید."


def _permission_message():
    return (
        "خطای تنظیمات: کلید Gemini فعلی یا پروژه متصل به آن توسط Google رد شد. "
        "در Google AI Studio یک کلید فعال بسازید و مطمئن شوید کلید برای Gemini API مجاز است."
    )


class AIAgent:
    def __init__(self, model_name=None):
        self.key_manager = GeminiKeyManager()
        self.model_name = model_name or os.getenv("GEMINI_MODEL", "gemini-2.5-flash")
        fallback_models = _csv_env("GEMINI_FALLBACK_MODELS", "gemini-2.5-flash-lite")
        self.model_candidates = []
        for candidate in [self.model_name, *fallback_models]:
            if candidate and candidate not in self.model_candidates:
                self.model_candidates.append(candidate)
        self.retry_attempts = _int_env("GEMINI_RETRY_ATTEMPTS", 2)
        self.retry_base_delay = _float_env("GEMINI_RETRY_BASE_DELAY", 1.0)
        self.retry_max_delay = _float_env("GEMINI_RETRY_MAX_DELAY", 6.0)
        self.base_instruction = (
            "You are an advanced, multilingual AI shopping assistant. "
            "Language rule: detect the language of the user's latest message and write the entire reply "
            "in that same language, unless the user explicitly asks for another language. "
            "If the user switches languages during the conversation, switch with them. "
            "Do not default to Persian, English, or the store language unless that is the user's current language. "
            "Adapt your tone to be helpful and professional in that language.\n\n"
        )

    def _format_history(self, chat_history):
        formatted = []
        for msg in chat_history:
            role = "model" if msg["role"] in ["assistant", "model"] else "user"
            formatted.append({"role": role, "parts": [{"text": msg["content"]}]})
        return formatted

    def _extract_function_calls(self, response):
        calls = []
        if hasattr(response, "candidates") and response.candidates:
            for part in response.candidates[0].content.parts:
                if hasattr(part, "function_call") and part.function_call:
                    calls.append(part.function_call)
        return calls

    async def ask_async(
        self,
        system_instruction: str,
        chat_history: list,
        user_message: str,
        tools: list = None,
        temperature: float = 0.1,
        api_key: str = None,
        **kwargs,
    ) -> AgentOutput:
        request_key = api_key.strip() if api_key else None
        if not request_key and not self.key_manager.keys:
            return _agent_error(
                "خطای سیستم: کلید API گوگل تنظیم نشده است.",
                error_code="missing_api_key",
                status_code=503,
            )

        contents = self._format_history(chat_history)
        contents.append({"role": "user", "parts": [{"text": user_message}]})

        combined_instruction = self.base_instruction + (system_instruction or "")
        config = types.GenerateContentConfig(
            system_instruction=combined_instruction,
            temperature=temperature,
            tools=tools,
            **kwargs,
        )

        key_attempts = 1 if request_key else max(len(self.key_manager.keys), 1)
        last_error = None

        for model_index, model_name in enumerate(self.model_candidates):
            for retry_index in range(self.retry_attempts):
                for key_attempt in range(key_attempts):
                    client = (
                        genai.Client(api_key=request_key)
                        if request_key
                        else self.key_manager.get_client()
                    )
                    try:
                        response = await client.aio.models.generate_content(
                            model=model_name,
                            contents=contents,
                            config=config,
                        )

                        return AgentOutput(
                            text=_response_text(response),
                            function_calls=getattr(response, "function_calls", []),
                            raw_response=response,
                        )
                    except Exception as error:
                        last_error = error
                        if (
                            _is_retryable_key_error(error)
                            and not request_key
                            and key_attempt < key_attempts - 1
                        ):
                            logger.warning(
                                "Gemini API key failed or reached a limit. Trying the next configured key."
                            )
                            await self.key_manager.rotate()
                            await asyncio.sleep(0.5)
                            continue
                        break

                if (
                    last_error
                    and _is_high_demand_error(last_error)
                    and retry_index < self.retry_attempts - 1
                ):
                    delay = min(
                        self.retry_base_delay * (2**retry_index),
                        self.retry_max_delay,
                    )
                    logger.warning(
                        "Gemini model %s is temporarily unavailable. Retrying in %.1f seconds.",
                        model_name,
                        delay,
                    )
                    await asyncio.sleep(delay)
                    continue
                break

            if (
                last_error
                and _is_high_demand_error(last_error)
                and model_index < len(self.model_candidates) - 1
            ):
                logger.warning(
                    "Gemini model %s is under high demand. Falling back to %s.",
                    model_name,
                    self.model_candidates[model_index + 1],
                )
                continue

            if last_error:
                break

        if last_error:
            logger.error("AI API Error: %s", last_error)
            if _is_permission_error(last_error):
                return _agent_error(
                    _permission_message(),
                    error_code="gemini_permission_denied",
                    status_code=503,
                )
            if _is_retryable_ai_error(last_error):
                return _agent_error(
                    _ai_unavailable_message(),
                    error_code="ai_unavailable",
                    status_code=503,
                )
            return _agent_error(
                "متأسفانه در پردازش اطلاعات فنی مشکلی پیش آمد. لطفاً چند لحظه دیگر امتحان کنید.",
                error_code="ai_api_error",
                status_code=502,
            )

        return _agent_error(
            _ai_unavailable_message(),
            error_code="ai_unavailable",
            status_code=503,
        )


ai_agent = AIAgent()
