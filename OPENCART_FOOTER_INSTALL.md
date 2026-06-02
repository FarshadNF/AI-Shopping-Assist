# نصب ویجت اوپن‌کارت

1. بک‌اند Django را اجرا کن:

```powershell
docker compose up --build
```

یا بدون Docker:

```powershell
python manage.py migrate
python manage.py runserver 127.0.0.1:8000
```

2. اگر API روی دامنه یا پورت دیگری است، مقدار `apiBase` را در فایل
`opencart_footer_widget.html` عوض کن.

3. اگر فروشگاه از API جداست، origin فروشگاه را در `.env` مجاز کن:

```text
AI_ASSISTANT_ALLOWED_ORIGINS=https://your-shop.com
```

برای تست محلی می‌توانی موقتاً این مقدار را بازتر کنی:

```text
AI_ASSISTANT_CORS_ALLOW_ALL=1
```

4. کد داخل `opencart_footer_widget.html` را قبل از `</body>` در فایل فوتر قالب بگذار:

```text
catalog/view/theme/YOUR_THEME/template/common/footer.twig
```

5. مسیر پیش‌فرض کرال محصول این است:

```text
index.php?route=extension/opencart/checkout/ai_assistant.getCatalog
```

اگر کنترلر اوپن‌کارتت route متفاوتی دارد، مقدار `catalogRoute` را در همان فایل تغییر بده.

ویجت هر ۱۰ دقیقه یک بار کاتالوگ اوپن‌کارت را صفحه‌به‌صفحه می‌خواند، آن را به
`/api/catalog/import/` می‌فرستد، سپس پاسخ‌های `/api/chat/` بر اساس همان
`products_catalog.json` تازه تولید می‌شوند.
