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
        for i in range(1, 12):
            key = os.getenv(f"GEMINI_API_KEY_{i}")
            if key:
                self.keys.append(key.strip())
        
        if not self.keys:
            default_key = os.getenv("GEMINI_API_KEY")
            if default_key:
                self.keys.append(default_key.strip())
            else:
                logger.error("No API keys found in environment variables.")

        if self.keys:
            self.key_cycle = cycle(self.keys)
            self.current_key = next(self.key_cycle)
        else:
            self.current_key = None

    def rotate(self):
        if self.keys:
            self.current_key = next(self.key_cycle)
            logger.warning("API key limit reached. Switched to the next key.")
        return self.current_key

    def get_client(self):
        if not self.current_key:
            raise ValueError("API key is missing or not configured.")
        return genai.Client(api_key=self.current_key)


class AgentOutput:
    def __init__(self, text=None, function_calls=None, raw_response=None):
        self.text = text or ""
        self.function_calls = function_calls or []
        self.raw_response = raw_response


class AIAgent:
    def __init__(self, model_name="gemini-2.5-flash"):
        self.key_manager = GeminiKeyManager()
        self.model_name = model_name

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
        **kwargs
    ) -> AgentOutput:
        if not self.key_manager.keys:
            return AgentOutput(text="خطای سیستم: کلید API تنظیم نشده است.")

        max_retries = len(self.key_manager.keys)
        
        contents = self._format_history(chat_history)
        contents.append({"role": "user", "parts": [{"text": user_message}]})

        config = types.GenerateContentConfig(
            system_instruction=system_instruction,
            temperature=temperature,
            tools=tools,
            **kwargs
        )

        for _ in range(max_retries):
            client = self.key_manager.get_client()
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
                error_msg = str(e).lower()
                if "429" in error_msg or "exhausted" in error_msg or "quota" in error_msg:
                    self.key_manager.rotate()
                    await asyncio.sleep(0.5)
                    continue
                else:
                    logger.error(f"AI API Error: {e}")
                    return AgentOutput(text="متأسفانه در پردازش اطلاعات فنی مشکلی پیش آمد. لطفاً چند لحظه دیگر امتحان کنید.")
        
        return AgentOutput(text="ترافیک کاربران در حال حاضر بسیار بالاست. لطفاً یک دقیقه دیگر پیام خود را تکرار کنید.")

    
ai_agent = AIAgent(model_name="gemini-2.5-flash")