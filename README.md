# AI Shopping Assist

این repository شامل API اختیاری Django/DRF و extension مستقل OpenCart است. فایل `dist/ai_shopping_assist.ocmod.zip` فقط با PHP و MySQL خود OpenCart اجرا می‌شود و به Django، Python، Docker یا ChromaDB وابسته نیست.

## اجرای Docker

ابتدا تنظیمات نمونه را کپی کنید و کلید Gemini را داخل `.env` قرار دهید:

```powershell
Copy-Item .env.example .env
```

متغیرهای اصلی مدل:

```text
GEMINI_API_KEY=your-google-gemini-api-key
GEMINI_MODEL=gemini-2.5-flash
```

سپس سرویس‌ها را اجرا کنید:

```powershell
docker compose up --build
```

در حالت پیش‌فرض فقط Django API و PostgreSQL بالا می‌آیند. مدل از طریق Google Gemini API صدا زده می‌شود و نیازی به Ollama نیست.

## اجرای بدون Docker

```powershell
pip install -r requirements.txt
python manage.py migrate
python manage.py runserver 127.0.0.1:8000
```

فایل `.env` در اجرای local هم خوانده می‌شود، پس کافی است `GEMINI_API_KEY` یا `GOOGLE_API_KEY` در همان فایل تنظیم شده باشد.

## API

وضعیت سرویس:

```http
GET http://127.0.0.1:8000/
```

چت:

```http
POST http://127.0.0.1:8000/api/chat/
Content-Type: application/json

{"message": "یک گوشی خوب پیشنهاد بده"}
```

پاسخ:

```json
{
  "status": "success",
  "reply": "...",
  "conversation_id": "..."
}
```

اگر Gemini ابزار `add_to_cart` را فراخوانی کند، پاسخ API فیلد `action` هم خواهد داشت.

## حافظه چت

هر پاسخ چت یک `conversation_id` دارد. برای ادامه همان مکالمه، مقدار آن را در درخواست بعدی بفرستید:

```json
{
  "message": "حالا بر اساس حرف قبلی پیشنهاد بده",
  "conversation_id": "PASTE_CONVERSATION_ID_HERE"
}
```

اگر کلاینت HTTP کوکی‌ها را نگه دارد، API از session جنگو هم برای ادامه خودکار همان مکالمه استفاده می‌کند.

## کاتالوگ OpenCart

برای sync زنده کاتالوگ، یکی از این متغیرها را تنظیم کنید:

```text
OPENCART_BASE_URL=http://localhost/test-shop/
OPENCART_CATALOG_URL=
```

اگر `OPENCART_CATALOG_URL` خالی باشد، مسیر از `OPENCART_BASE_URL` و `OPENCART_CATALOG_ROUTE` ساخته می‌شود.

این تنظیمات فقط مربوط به API مستقل Django هستند. extension نسخه 3.4.0 مستقیماً کاتالوگ و پایگاه دانش MySQL خود OpenCart را می‌خواند و هیچ درخواستی به این API نمی‌فرستد.

## همگام‌سازی Vector Brain

با هر import کاتالوگ، اطلاعات محصول به‌صورت خودکار وارد Vector Brain می‌شود. برای crawl عمیق صفحه محصولات و PDFهای datasheet اجرا کنید:

```powershell
python manage.py sync_vector_brain
```

برای نادیده گرفتن cache قبلی:

```powershell
python manage.py sync_vector_brain --force
```
