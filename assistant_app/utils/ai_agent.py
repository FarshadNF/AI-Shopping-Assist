import os
import logging
import asyncio
from itertools import cycle
from google import genai
from google.genai import types

logger = logging.getLogger(__name__)

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
            "403",
            "api_key_invalid",
            "denied access",
            "deadline",
            "exhausted",
            "forbidden",
            "high demand",
            "permission_denied",
            "quota",
            "temporarily",
            "timeout",
            "try again later",
            "unavailable",
        )
    )


def _is_retryable_key_error(error):
    return _is_retryable_ai_error(error)


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
    return "ترافیک کاربران در حال حاضر بسیار بالاست. لطفاً یک دقیقه دیگر پیام خود را تکرار کنید."


def _permission_message():
    return "خطای تنظیمات: Google کلید Gemini فعلی یا پروژه متصل به آن را رد کرد. در Google AI Studio یک کلید فعال بسازید، پروژه را import کنید و مطمئن شوید کلید برای Gemini API مجاز است."


class AIAgent:
    def __init__(self, model_name=None):
        self.key_manager = GeminiKeyManager()
        self.model_name = model_name or os.getenv("GEMINI_MODEL", "gemini-2.5-flash")
        self.base_instruction = (
            "You are an advanced, multilingual AI shopping assistant. "
            "You must fluently understand and respond in the exact language the user communicates in, "
            "unless explicitly requested otherwise. Adapt your tone to be helpful and professional in that language.\n\n"
        )

    def _format_history(self, chat_history):
        formatted = []
        for msg in chat_history:
            role = "model" if msg["role"] in ["assistant", "model"] else "user"
            formatted.append(
                {"role": role, "parts": [{"text": msg["content"]}]}
            )
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
        **kwargs
    ) -> AgentOutput:
        request_key = api_key.strip() if api_key else None
        if not request_key and not self.key_manager.keys:
            return _agent_error(
                "خطای سیستم: کلید API گوگل تنظیم نشده است.",
                error_code="missing_api_key",
                status_code=503,
            )

        max_retries = 1 if request_key else len(self.key_manager.keys)
        
        contents = self._format_history(chat_history)
        contents.append({"role": "user", "parts": [{"text": user_message}]})

        combined_instruction = self.base_instruction + (system_instruction or "")

        config = types.GenerateContentConfig(
            system_instruction=combined_instruction,
            temperature=temperature,
            tools=tools,
            **kwargs
        )

        last_error = None

        for attempt in range(max_retries):
            client = genai.Client(api_key=request_key) if request_key else self.key_manager.get_client()
            try:
                response = await client.aio.models.generate_content(
                    model=self.model_name,
                    contents=contents,
                    config=config
                )
                
                extracted_text = response.text if hasattr(response, "text") and response.text else ""
                extracted_calls = self._extract_function_calls(response)
                
                return AgentOutput(
                    text=response.text,
                    function_calls=getattr(response, "function_calls", []),
                    raw_response=response
                )

            except Exception as e:
                last_error = e
                if _is_retryable_key_error(e) and not request_key and attempt < max_retries - 1:
                    logger.warning("Gemini API key failed or reached a limit. Trying the next configured key.")
                    await self.key_manager.rotate()
                    await asyncio.sleep(0.5)
                    continue
                else:
                    logger.error(f"AI API Error: {e}")
                    if _is_permission_error(e):
                        return _agent_error(
                            _permission_message(),
                            error_code="gemini_permission_denied",
                            status_code=503,
                        )
                    if _is_retryable_ai_error(e):
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

        if last_error and _is_permission_error(last_error):
            logger.error("All configured Gemini API keys were rejected by Google. Check project access and API key permissions.")
            return AgentOutput(text="خطای تنظیمات: Google کلید Gemini فعلی یا پروژه متصل به آن را رد کرد. در Google AI Studio یک کلید فعال بسازید، پروژه را import کنید و مطمئن شوید کلید برای Gemini API مجاز است.")

        return AgentOutput(text="ترافیک کاربران در حال حاضر بسیار بالاست. لطفاً یک دقیقه دیگر پیام خود را تکرار کنید.")

    
ai_agent = AIAgent()
