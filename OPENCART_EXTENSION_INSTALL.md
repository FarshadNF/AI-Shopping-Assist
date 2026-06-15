# نصب مستقل AI Shopping Assist برای OpenCart 4

این extension کاملاً داخل OpenCart اجرا می‌شود و به Python، Django، Docker، ChromaDB یا سرویس Brain نیاز ندارد.

اجزای runtime:

- PHP و MySQL خود OpenCart
- کاتالوگ زنده OpenCart برای قیمت، موجودی و لینک محصول
- پایگاه دانش محلی برای توضیحات، مشخصات فنی و `datasheet_content`
- تماس مستقیم و امن PHP با Google Gemini API

## فایل نصب

```text
dist/ai_shopping_assist.ocmod.zip
```

## نصب در OpenCart 4

1. در پنل ادمین به `Extensions > Installer` بروید.
2. فایل ZIP بالا را آپلود و Install کنید.
3. به `Extensions > Extensions > Modules` بروید.
4. ماژول `AI Shopping Assist` را نصب کنید.
5. وارد تنظیمات ماژول شوید.
6. حداقل یک کلید Google AI Studio را در `Gemini API keys` وارد کنید.
7. گزینه‌های `Status` و `Auto-inject widget` را فعال و تنظیمات را ذخیره کنید.
8. کش OpenCart را از Developer Settings پاک کنید.

چند کلید را می‌توان با کاما جدا کرد:

```text
KEY_1, KEY_2, KEY_3
```

کلیدها فقط در PHP سمت سرور استفاده می‌شوند و به JavaScript مرورگر فرستاده نمی‌شوند.

## پایگاه دانش محلی

هنگام نصب، فایل زیر که داخل خود extension قرار دارد به جدول MySQL محلی وارد می‌شود:

```text
install_data/enriched_cache.json
```

جدول ساخته‌شده:

```text
oc_ai_shopping_assist_knowledge
```

پیشوند `oc_` متناسب با تنظیمات فروشگاه تغییر می‌کند.

برای به‌روزرسانی دانش:

1. وارد تنظیمات ماژول شوید.
2. در بخش `Local knowledge base` فایل `enriched_cache.json` جدید را انتخاب کنید.
3. روی `Import JSON` بزنید.

Import جدید، دانش قبلی را به‌صورت کامل جایگزین می‌کند. فیلدهای زیر پشتیبانی می‌شوند:

```text
product_id
name
brand
full_description
technical_attributes
datasheet_content
sales_angle
```

جست‌وجوی دانش با PHP و MySQL انجام می‌شود. برای قیمت، موجودی و خرید همیشه داده زنده کاتالوگ OpenCart اولویت دارد.

## نکته runtime

برای کار extension نیازی به اجرای `docker compose up` یا `python manage.py runserver` نیست. فقط وب‌سرور عادی OpenCart باید فعال باشد و سرور بتواند به Google Gemini API دسترسی اینترنتی داشته باشد.

## ساخت دوباره ZIP

از ریشه پروژه:

```powershell
Remove-Item -Force dist\ai_shopping_assist.ocmod.zip -ErrorAction SilentlyContinue
Compress-Archive -Path opencart_extension\ai_shopping_assist_oc4\* -DestinationPath dist\ai_shopping_assist.ocmod.zip
```
