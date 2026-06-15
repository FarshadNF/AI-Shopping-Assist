import asyncio
import logging
import os
import time

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

        add_key(os.getenv("GEMINI_API_KEY"))
        add_key(os.getenv("GOOGLE_API_KEY"))

        # The SDK prints a warning and may prefer one env var when both singular
        # names are present. We pass keys explicitly, so clear auto-discovery.
        os.environ.pop("GEMINI_API_KEY", None)
        os.environ.pop("GOOGLE_API_KEY", None)

        if not self.keys:
            logger.error("No Gemini API keys found in environment variables.")

        self.current_index = 0
        self.current_key = self.keys[0] if self.keys else None
        self.key_cooldown_seconds = _float_env("GEMINI_KEY_COOLDOWN_SECONDS", 30.0)
        self.key_cooldown_max_seconds = _float_env("GEMINI_KEY_COOLDOWN_MAX_SECONDS", 300.0)
        self.permission_cooldown_seconds = _float_env(
            "GEMINI_KEY_PERMISSION_COOLDOWN_SECONDS",
            3600.0,
        )
        self._key_state = {}
        self._lock = asyncio.Lock()
        self._ensure_key_state()

    def _ensure_key_state(self):
        for key in self.keys:
            self._key_state.setdefault(
                key,
                {
                    "cooldown_until": 0.0,
                    "failures": 0,
                    "in_flight": 0,
                },
            )
        for key in list(self._key_state):
            if key not in self.keys:
                self._key_state.pop(key, None)
        if self.keys and self.current_index >= len(self.keys):
            self.current_index = 0
        self.current_key = self.keys[self.current_index] if self.keys else None

    def _round_robin_offset(self, index):
        if not self.keys:
            return 0
        return (index - self.current_index) % len(self.keys)

    async def acquire_key(self):
        async with self._lock:
            self._ensure_key_state()
            if not self.keys:
                return None

            now = time.monotonic()
            candidates = [
                (index, key)
                for index, key in enumerate(self.keys)
                if self._key_state[key]["cooldown_until"] <= now
            ]
            if not candidates:
                candidates = list(enumerate(self.keys))
                logger.warning("All Gemini API keys are cooling down; trying the earliest available key.")

            index, key = min(
                candidates,
                key=lambda item: (
                    self._key_state[item[1]]["cooldown_until"],
                    self._key_state[item[1]]["in_flight"],
                    self._round_robin_offset(item[0]),
                ),
            )
            self._key_state[key]["in_flight"] += 1
            self.current_index = (index + 1) % len(self.keys)
            self.current_key = key
            return key

    async def release_key(self, key, error=None):
        if not key:
            return
        async with self._lock:
            self._ensure_key_state()
            state = self._key_state.get(key)
            if not state:
                return

            state["in_flight"] = max(int(state["in_flight"]) - 1, 0)
            if error is None:
                state["failures"] = 0
                state["cooldown_until"] = 0.0
                return

            state["failures"] = int(state["failures"]) + 1
            if _is_permission_error(error):
                cooldown = self.permission_cooldown_seconds
            elif _is_retryable_key_error(error):
                cooldown = min(
                    self.key_cooldown_seconds * (2 ** (state["failures"] - 1)),
                    self.key_cooldown_max_seconds,
                )
            else:
                return
            state["cooldown_until"] = time.monotonic() + cooldown

    async def rotate(self):
        key = await self.acquire_key()
        logger.warning("Switched to the next configured Gemini API key.")
        if key:
            await self.release_key(key)
        return key

    def get_client(self, api_key=None):
        key = api_key or self.current_key
        if not key:
            raise ValueError("API key is missing or not configured.")
        return genai.Client(api_key=key)


def _error_text(error):
    return str(error).lower()


def _is_retryable_ai_error(error):
    error_msg = _error_text(error)
    return any(
        marker in error_msg
        for marker in (
            "429", "500", "502", "503", "504", "deadline", "exhausted",
            "high demand", "quota", "temporarily", "timeout", "try again later", "unavailable",
        )
    )


def _is_retryable_key_error(error):
    error_msg = _error_text(error)
    return any(
        marker in error_msg
        for marker in (
            "429", "500", "502", "503", "504", "deadline", "exhausted",
            "high demand", "quota", "temporarily", "timeout", "try again later", "unavailable",
        )
    )


def _should_failover_key(error):
    return _is_retryable_key_error(error) or _is_permission_error(error)


def _is_high_demand_error(error):
    error_msg = _error_text(error)
    return any(
        marker in error_msg
        for marker in (
            "503", "high demand", "temporarily", "try again later", "unavailable",
        )
    )


def _is_permission_error(error):
    error_msg = _error_text(error)
    return any(
        marker in error_msg
        for marker in (
            "403", "api_key_invalid", "denied access", "forbidden", "permission_denied",
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

# [ارتقا]: تغییر پیام‌های خطا به انگلیسی برای سازگاری با بازارهای بین‌المللی و B2B
def _ai_unavailable_message():
    return "The system is currently experiencing high demand. Please try sending your message again in a few moments."


def _permission_message():
    return (
        "Configuration Error: The Gemini API key was rejected. "
        "Please check your Google AI Studio project settings and ensure the key is active."
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

        # [ارتقا]: خواندن متغیرهای هویتی از تنظیمات محیطی برای داینامیک شدن کامل رفتار
        self.store_brand = os.getenv("STORE_BRAND", "Our Company")
        self.ai_name = os.getenv("AI_ASSISTANT_NAME", "AI Assistant")
        self.business_model = os.getenv("BUSINESS_MODEL", "B2C_CART")
        self.target_market = os.getenv("TARGET_MARKET", "International")

        # تعیین نقش داینامیک بر اساس نوع بیزینس (فروشگاهی یا مهندسی/B2B)
        if self.business_model == "B2B_INQUIRY":
            role_description = f"Pre-Sales Technical Engineer and Consultant for {self.store_brand}"
        else:
            role_description = f"advanced AI shopping assistant for {self.store_brand}"

        self.base_instruction = (
            f"You are {self.ai_name}, an {role_description}. "
            f"Your target market is {self.target_market}. "
            "Language rule: detect the language of the user's latest message and write the entire reply "
            "in that same language, unless the user explicitly asks for another language. "
            "If the user switches languages during the conversation, switch with them. "
            "Do not default to Persian, English, or the store language unless that is the user's current language. "
            "Adapt your tone to be highly professional, helpful, and technical in that language.\n\n"
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

    def _language_override(self, user_message):
        return (
            "\n\nCRITICAL RESPONSE LANGUAGE OVERRIDE:\n"
            "Detect the response language ONLY from the user's latest raw message below. "
            "Ignore the language of these instructions, the product catalog, store rules, and older chat history when choosing the reply language.\n"
            "RAW_USER_MESSAGE_START\n"
            f"{user_message}\n"
            "RAW_USER_MESSAGE_END\n"
            "Write the entire visible final reply in the same language as RAW_USER_MESSAGE. "
            "If RAW_USER_MESSAGE mixes languages, use the dominant language. "
            "If the user explicitly asks for translation or for another language, follow that explicit request. "
            "Do not explain this language rule to the user.\n"
        )

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
                "System Error: Google API key is not configured.",
                error_code="missing_api_key",
                status_code=503,
            )

        contents = self._format_history(chat_history)
        contents.append({"role": "user", "parts": [{"text": user_message}]})

        combined_instruction = (
            self.base_instruction
            + (system_instruction or "")
            + self._language_override(user_message)
        )
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
                    key = request_key
                    client = None
                    try:
                        if request_key:
                            client = genai.Client(api_key=request_key)
                        else:
                            key = await self.key_manager.acquire_key()
                            if not key:
                                raise ValueError("API key is missing or not configured.")
                            client = self.key_manager.get_client(key)

                        response = await client.aio.models.generate_content(
                            model=model_name,
                            contents=contents,
                            config=config,
                        )
                        if not request_key:
                            await self.key_manager.release_key(key)

                        return AgentOutput(
                            text=_response_text(response),
                            function_calls=getattr(response, "function_calls", []),
                            raw_response=response,
                        )
                    except Exception as error:
                        last_error = error
                        if not request_key:
                            await self.key_manager.release_key(key, error)
                        if (
                            _should_failover_key(error)
                            and not request_key
                            and key_attempt < key_attempts - 1
                        ):
                            logger.warning(
                                "Gemini API key failed or reached a limit. Trying the next configured key."
                            )
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
                "Unfortunately, an error occurred while processing the technical information. Please try again in a few moments.",
                error_code="ai_api_error",
                status_code=502,
            )

        return _agent_error(
            _ai_unavailable_message(),
            error_code="ai_unavailable",
            status_code=503,
        )


ai_agent = AIAgent()