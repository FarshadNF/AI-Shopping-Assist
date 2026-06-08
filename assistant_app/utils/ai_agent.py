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

    def rotate(self):
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


def _is_retryable_key_error(error):
    error_msg = _error_text(error)
    return any(
        marker in error_msg
        for marker in (
            "429",
            "403",
            "api_key_invalid",
            "denied access",
            "exhausted",
            "forbidden",
            "permission_denied",
            "quota",
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
    def __init__(self, text=None, function_calls=None, raw_response=None):
        self.text = text or ""
        self.function_calls = function_calls or []
        self.raw_response = raw_response


class AIAgent:
    def __init__(self, model_name=None):
        self.key_manager = GeminiKeyManager()
        self.model_name = model_name or os.getenv("GEMINI_MODEL", "gemini-2.5-flash")

    def _format_history(self, chat_history):
        formatted = []
        for msg in chat_history:
            role = "model" if msg["role"] in ["assistant", "model"] else "user"
            formatted.append(
                {"role": role, "parts": [{"text": msg["content"]}]}
            )
        return formatted

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
            return AgentOutput(text="خطای سیستم: کلید API گوگل تنظیم نشده است.")

        max_retries = 1 if request_key else len(self.key_manager.keys)
        
        contents = self._format_history(chat_history)
        contents.append({"role": "user", "parts": [{"text": user_message}]})

        config = types.GenerateContentConfig(
            system_instruction=system_instruction,
            temperature=temperature,
            tools=tools,
            **kwargs
        )

        last_error = None

        for _ in range(max_retries):
            client = genai.Client(api_key=request_key) if request_key else self.key_manager.get_client()
            try:
                response = await client.aio.models.generate_content(
                    model=self.model_name,
                    contents=contents,
                    config=config
                )
                
                return AgentOutput(
                    text=response.text,
                    function_calls=getattr(response, "function_calls", []),
                    raw_response=response
                )

            except Exception as e:
                last_error = e
                if _is_retryable_key_error(e) and not request_key:
                    logger.warning("Gemini API key failed or reached a limit. Trying the next configured key.")
                    self.key_manager.rotate()
                    await asyncio.sleep(0.5)
                    continue
                else:
                    logger.error(f"AI API Error: {e}")
                    return AgentOutput(text="متأسفانه در پردازش اطلاعات فنی مشکلی پیش آمد. لطفاً چند لحظه دیگر امتحان کنید.")

        if last_error and _is_permission_error(last_error):
            logger.error("All configured Gemini API keys were rejected by Google. Check project access and API key permissions.")
            return AgentOutput(text="خطای تنظیمات: Google کلید Gemini فعلی یا پروژه متصل به آن را رد کرد. در Google AI Studio یک کلید فعال بسازید، پروژه را import کنید و مطمئن شوید کلید برای Gemini API مجاز است.")

        return AgentOutput(text="ترافیک کاربران در حال حاضر بسیار بالاست. لطفاً یک دقیقه دیگر پیام خود را تکرار کنید.")

    
ai_agent = AIAgent()
