# AI Shopping Assist

نسخه Django/DRF دستیار خرید هوشمند.

## اجرا

```powershell
pip install -r requirements.txt
python manage.py migrate
python manage.py runserver 127.0.0.1:8000
```

برای دیدن وضعیت API:

```text
http://127.0.0.1:8000/
```

این پروژه فعلاً UI ندارد و فقط API JSON ارائه می‌کند.

## API

مسیر قبلی چت حفظ شده است:

```http
POST /api/chat/
Content-Type: application/json

{"message": "یک گوشی خوب پیشنهاد بده"}
```

پاسخ:

```json
{
  "status": "success",
  "reply": "..."
}
```

اگر مدل عبارت `[ACTION: ADD_TO_CART: Product_Name]` را برگرداند، پاسخ API یک فیلد `action` هم دارد.

## تنظیمات

با متغیرهای محیطی زیر می‌توانید اتصال به Ollama را تغییر دهید:

```powershell
$env:OLLAMA_CHAT_URL="http://192.168.55.133:11434/api/chat"
$env:OLLAMA_MODEL="qwen2.5:7b"
$env:OLLAMA_TIMEOUT="60"
```
