import ollama
import json

# ==========================================
# ۱. بارگذاری کاتالوگ (فقط در لپ‌تاپ شما)
# ==========================================
try:
    with open('products_catalog.json', 'r', encoding='utf-8') as f:
        products_data = json.load(f)
    catalog_string = json.dumps(products_data, ensure_ascii=False, indent=2)
except FileNotFoundError:
    print("Error: products_catalog.json not found.")
    exit()

# ==========================================
# ۲. اتصال فوق‌محرمانه به موتور گرافیکی همکار
# ==========================================
# آی‌پی سیستم همکارت را اینجا وارد کن
COLLEAGUE_IP = "192.168.1.50" 
client = ollama.Client(host=f"http://{COLLEAGUE_IP}:11434")

# ==========================================
# ۳. مهندسی پرامپت (اسکلت‌بندی محرمانه ما)
# ==========================================
system_instruction = f"""
تو یک مشاور فروش ارشد، متخصص دیجیتال مارکتینگ و دستیار صوتی هوشمند هستی. 
هدف تو خلق یک مکالمه طبیعی، جذاب و متقاعدکننده با مشتری است.

کاتالوگ محصولات و موجودی انبار ما:
{catalog_string}

قوانین حیاتی تو:
۱. تو متخصص تمام محصولات دنیا هستی. اگر کاربر درباره ویژگی‌ها، نحوه استفاده یا مقایسه هر محصولی پرسید، با دانش عمومی خودت کامل و تخصصی توضیح بده.
۲. سناریوی ناموجود (Out of Stock): اگر کاربر محصولی خواست که در کاتالوگ نیست یا موجودی آن صفر است، به هیچ‌وجه نگو "نداریم". بلکه بگو: "این محصول بسیار پرطرفدار است و فعلاً موجودی انبار صفر شده، اما به عنوان یک متخصص پیشنهاد می‌کنم..." و سپس یک محصول مشابه از کاتالوگ خودمان پیشنهاد بده.
۳. روان، صمیمی و بدون لحن رباتی به زبان فارسی صحبت کن.
۴. فرمان سایت: اگر مشتری قطعی گفت محصولی را می‌خرد، در انتهای صحبتت دقیقاً این فرمت را اضافه کن: [ACTION: ADD_TO_CART: Product_Name]
"""

# ==========================================
# ۴. توابع اجرایی و چرخه اصلی
# ==========================================
def speech_to_text():
    return input("\n🎙️ شما: ")

def text_to_speech(text_output):
    clean_text = text_output.split("[ACTION:")[0].strip()
    print(f"🤖 دستیار هوشمند: {clean_text}")

def execute_site_action(ai_response):
    if "[ACTION: ADD_TO_CART:" in ai_response:
        try:
            parts = ai_response.split("[ACTION: ADD_TO_CART:")
            product_name = parts[1].split("]")[0].strip()
            print(f"\n⚡ [سیستم سایت]: اتصال به API... محصول '{product_name}' به سبد خرید اضافه شد!🛒")
        except:
            pass

# متغیر برای نگهداری حافظه مکالمات (Chat History)
chat_history = [
    {'role': 'system', 'content': system_instruction}
]

if __name__ == "__main__":
    print(f"=== سیستم دستیار هوشمند (متصل به موتور پردازشی {COLLEAGUE_IP}) روشن شد ===")
    print("برای خروج عبارت 'exit' را تایپ کنید.")
    
    while True:
        user_voice = speech_to_text()
        if user_voice.lower() == 'exit':
            print("سیستم خاموش شد.")
            break
            
        print("🧠 در حال ارسال امن داده‌ها به موتور پردازشی...")
        chat_history.append({'role': 'user', 'content': user_voice})
        
        try:
            # ارسال درخواست به سیستم همکار و دریافت پاسخ
            response = client.chat(
                model='qwen2.5:7b', # نام دقیق مدلی که روی سیستم همکارت نصب کرده‌ای
                messages=chat_history
            )
            
            ai_reply = response['message']['content']
            chat_history.append({'role': 'assistant', 'content': ai_reply})
            
            text_to_speech(ai_reply)
            execute_site_action(ai_reply)
            
        except Exception as e:
            print(f"خطا در ارتباط با موتور پردازشی: {e}")