# AI Shopping Assist

نسخه Django/DRF دستیار خرید هوشمند. پروژه فعلاً UI ندارد و فقط API JSON ارائه می‌کند.

## اجرای Docker

کپی تنظیمات نمونه:

```powershell
Copy-Item .env.example .env
```

بالا آوردن Django API، Ollama و دانلود مدل:

```powershell
docker compose up --build
```

در اجرای اول، سرویس `ollama-pull` مدل `qwen2.5:7b` را داخل volume داکر دانلود می‌کند. این مرحله بسته به اینترنت و سخت‌افزار زمان‌بر است.

Ollama به صورت پیش‌فرض روی هاست publish نمی‌شود، چون API داخل شبکه Docker با `http://ollama:11434` به آن وصل می‌شود. این کار جلوی خطای اشغال بودن پورت `11434` را می‌گیرد.

اگر خواستید خود Ollama را از ویندوز هم مستقیم صدا بزنید:

```powershell
docker compose -f docker-compose.yml -f docker-compose.ollama-host.yml up --build
```

در این حالت Ollama روی `http://127.0.0.1:11435` در دسترس است. پورت را می‌توانید در `.env` با `OLLAMA_HOST_PORT` تغییر دهید.

برای استفاده از GPU انویدیا، بعد از نصب NVIDIA Container Toolkit:

```powershell
docker compose -f docker-compose.yml -f docker-compose.gpu.yml up --build
```

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
  "reply": "..."
}
```

اگر مدل عبارت `[ACTION: ADD_TO_CART: Product_Name]` را برگرداند، پاسخ API یک فیلد `action` هم دارد.

## تنظیم مدل

مدل پیش‌فرض در `.env.example` این است:

```text
OLLAMA_MODEL=qwen2.5:7b
```

برای تغییر مدل، مقدار `OLLAMA_MODEL` را در `.env` عوض کنید و دوباره اجرا کنید:

```powershell
docker compose up --build
```

## اجرای بدون Docker

اگر Ollama را جداگانه روی سیستم خودتان اجرا کرده‌اید:

```powershell
pip install -r requirements.txt
python manage.py migrate
python manage.py runserver 127.0.0.1:8000
```

در این حالت مقدار پیش‌فرض `OLLAMA_CHAT_URL` برابر `http://localhost:11434/api/chat` است.

## حافظه چت

Docker Compose حالا یک دیتابیس PostgreSQL برای حافظه دائمی بات بالا می‌آورد. API هر رفت‌وبرگشت کاربر/دستیار را ذخیره می‌کند و در درخواست‌های بعدی آخرین `CHAT_MEMORY_LIMIT` پیام را دوباره به مدل می‌فرستد.

هر پاسخ چت یک `conversation_id` دارد. برای ادامه همان حافظه، آن را در درخواست بعدی بفرستید:

```json
{
  "message": "حالا بر اساس حرف قبلیم پیشنهاد بده",
  "conversation_id": "PASTE_CONVERSATION_ID_HERE"
}
```

اگر کلاینت HTTP شما کوکی‌ها را نگه دارد، API از session جنگو هم برای ادامه خودکار همان مکالمه استفاده می‌کند. PostgreSQL به‌صورت پیش‌فرض از روی هاست روی `127.0.0.1:5433` در دسترس است و داده‌ها داخل volume به نام `postgres_data` می‌مانند.

برای اجرای مستقیم `ai_agent.py` هم می‌توانید بعد از `docker compose up -d db` و `python manage.py migrate`، مقدار `CHAT_MEMORY_DATABASE_URL` داخل `.env` را نگه دارید. اسکریپت `.env` را می‌خواند و با `CHAT_MEMORY_SESSION_KEY` همان حافظه قبلی را ادامه می‌دهد.
