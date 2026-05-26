from fastapi import FastAPI
from pydantic import BaseModel
import requests
import json

app = FastAPI()

# ==========================================
# ۱. بارگذاری کاتالوگ (امن روی سیستم تو)
# ==========================================
try:
    with open('products_catalog.json', 'r', encoding='utf-8') as f:
        products_data = json.load(f)
    catalog_string = json.dumps(products_data, ensure_ascii=False, indent=2)
except FileNotFoundError:
    catalog_string = "کاتالوگ یافت نشد."

# ==========================================
# ۲. آدرس موتور پردازشی (سیستم همکار)
# ==========================================
# آی‌پی سیستم همکارت که گرافیک قدرتمند دارد را اینجا بگذار
COLLEAGUE_OLLAMA_URL = "http://192.168.55.133:11434/api/chat"

system_instruction = f"""
تو یک مشاور فروش ارشد، متخصص دیجیتال مارکتینگ و دستیار صوتی هوشمند هستی. 
هدف تو خلق یک مکالمه طبیعی، جذاب و متقاعدکننده با مشتری است.

قوانین حیاتی تو:
۱. تو متخصص تمام محصولات دنیا هستی.
۲. اگر کاربر محصولی خواست که موجودی آن صفر است، به هیچ‌وجه نگو "نداریم". پیشنهاد جایگزین بده.
۳. روان، صمیمی و بدون لحن رباتی صحبت کن.
۴. در صورت تایید خرید، عبارت [ACTION: ADD_TO_CART: Product_Name] را اضافه کن.

کاتالوگ محصولات ما:
{catalog_string}
"""

class UserRequest(BaseModel):
    message: str

# ==========================================
# ۳. درگاه ارتباطی (API) برای اتصال همکارت
# ==========================================
@app.post("/api/chat")
def chat_with_ai(req: UserRequest):
    # این تابع، پیام کاربر را با پرامپت‌های مخفی تو ترکیب می‌کند 
    # و بی‌صدا برای کارت گرافیک همکارت می‌فرستد.
    payload = {
        "model": "qwen2.5:7b",
        "messages": [
            {"role": "system", "content": system_instruction},
            {"role": "user", "content": req.message}
        ],
        "stream": False
    }
    
    try:
        response = requests.post(COLLEAGUE_OLLAMA_URL, json=payload)
        ai_reply = response.json()["message"]["content"]
        return {"status": "success", "reply": ai_reply}
    except Exception as e:
        return {"status": "error", "reply": str(e)}