# نصب اکستنشن مستقل OpenCart

این نسخه دیگر به Django نیاز ندارد. همه چیز داخل خود OpenCart انجام می‌شود:

- نمایش ویجت چت
- خواندن کاتالوگ محصولات OpenCart
- تماس مستقیم سمت سرور با Gemini
- ثبت لاگ چت‌ها داخل دیتابیس OpenCart
- اجرای اکشن‌های سبد خرید، مشاهده سبد، پرداخت، کوپن، درخواست فاکتور و رفتن به صفحه‌های سایت

## فایل آماده آپلود

```text
dist/ai_shopping_assist.ocmod.zip
```

## مراحل نصب در OpenCart 4

1. وارد پنل ادمین OpenCart شوید.
2. بروید به:

```text
Extensions > Installer
```

3. فایل `dist/ai_shopping_assist.ocmod.zip` را آپلود کنید.
4. روی install کلیک کنید.
5. بروید به:

```text
Extensions > Extensions > Modules
```

6. ماژول `AI Shopping Assist` را نصب کنید.
7. وارد تنظیمات ماژول شوید و این موارد را پر کنید:

```text
Gemini API keys = یک یا چند کلید API از Google AI Studio، جداشده با کاما
Gemini model = gemini-2.5-flash
Status = Enabled
Auto-inject widget = Enabled
```

8. Save بزنید.
9. کش OpenCart را پاک کنید:

```text
Dashboard > Developer Settings/Gear > Clear Cache
```

اگر صفحه `Extensions > Modifications` دارید، Refresh هم بزنید.

## کلید Gemini

کلیدها را از Google AI Studio بسازید. می‌توانید چند کلید را با کاما وارد کنید:

```text
KEY_1, KEY_2, KEY_3
```

OpenCart اگر یک کلید خطا یا محدودیت بدهد، کلید بعدی را امتحان می‌کند. این کلیدها فقط سمت سرور OpenCart استفاده می‌شوند و داخل JavaScript یا مرورگر کاربر قرار نمی‌گیرند.

## تنظیمات مهم

`Prompt catalog limit`: تعداد محصولاتی است که در هر پیام برای Gemini فرستاده می‌شود. مقدار پیشنهادی `80` است.

`Store brand`: نام فروشگاه که دستیار در جواب‌ها استفاده می‌کند.

`Assistant name`: نام دستیار، مثلا `پشتیبان هوشمند`.

`Catalog token`: اختیاری است و فقط برای endpoint خروجی JSON کاتالوگ کاربرد دارد. برای چت عادی لازم نیست.

## لاگ چت‌ها

لاگ‌ها داخل جدول OpenCart ذخیره می‌شوند و در تنظیمات ماژول، بخش `Recent chat logs` قابل مشاهده‌اند.

## routeهای داخلی اکستنشن

```text
index.php?route=extension/ai_shopping_assist/module/ai_shopping_assist.chat
index.php?route=extension/ai_shopping_assist/module/ai_shopping_assist.getCatalog
index.php?route=extension/ai_shopping_assist/module/ai_shopping_assist.getCart
index.php?route=extension/ai_shopping_assist/module/ai_shopping_assist.cartAction
index.php?route=extension/ai_shopping_assist/module/ai_shopping_assist.sendInvoice
```

## ساخت دوباره zip

اگر فایل‌های اکستنشن را تغییر دادید، از ریشه پروژه اجرا کنید:

```powershell
Remove-Item -Force dist\ai_shopping_assist.ocmod.zip -ErrorAction SilentlyContinue
Compress-Archive -Path opencart_extension\ai_shopping_assist_oc4\* -DestinationPath dist\ai_shopping_assist.ocmod.zip
```
