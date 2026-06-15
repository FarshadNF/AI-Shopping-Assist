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

## تنظیمات مهم

`Prompt catalog limit`: تعداد محصولاتی است که در هر پیام برای Gemini فرستاده می‌شود. مقدار پیشنهادی `80` است.

`Store brand`: نام فروشگاه که دستیار در جواب‌ها استفاده می‌کند.

`Assistant name`: نام دستیار، مثلا `پشتیبان هوشمند`.

`Catalog token`: اختیاری است و فقط برای endpoint خروجی JSON کاتالوگ کاربرد دارد. برای چت عادی لازم نیست.

## لاگ چت‌ها

لاگ‌ها داخل جدول OpenCart ذخیره می‌شوند و در تنظیمات ماژول، بخش `Recent chat logs` قابل مشاهده‌اند.

## routeهای داخلی اکستنشن

## Google Sheets lead webhook

Use this when bulk order leads should be saved into Google Sheets.

1. Create a Google Sheet and copy its spreadsheet ID from the URL.
2. Go to `Extensions > Apps Script`.
3. Paste this script and replace `SHEET_ID` and `SECRET`.

```javascript
const SHEET_ID = 'PASTE_SPREADSHEET_ID_HERE';
const SECRET = 'CHANGE_THIS_SECRET';

function doPost(e) {
  const payload = JSON.parse((e && e.postData && e.postData.contents) || '{}');

  if (SECRET && payload.secret !== SECRET) {
    return ContentService
      .createTextOutput(JSON.stringify({ ok: false, error: 'unauthorized' }))
      .setMimeType(ContentService.MimeType.JSON);
  }

  const spreadsheet = SpreadsheetApp.openById(SHEET_ID);
  const sheet = spreadsheet.getSheetByName('Bulk Leads') || spreadsheet.insertSheet('Bulk Leads');

  if (sheet.getLastRow() === 0) {
    sheet.appendRow([
      'Date',
      'Store',
      'Conversation ID',
      'Product Name',
      'QTY',
      'Name',
      'Company',
      'Contact Number',
      'Email',
      'Delivery Location',
      'Page URL',
      'Customer ID',
      'IP',
      'User Agent'
    ]);
  }

  const lead = payload.lead || {};

  sheet.appendRow([
    new Date(),
    payload.store || '',
    payload.conversation_id || '',
    lead.product_name || '',
    lead.qty || '',
    lead.name || '',
    lead.company || '',
    lead.contact_number || '',
    lead.email || '',
    lead.delivery_location || '',
    payload.page_url || '',
    payload.customer_id || '',
    payload.ip || '',
    payload.user_agent || ''
  ]);

  return ContentService
    .createTextOutput(JSON.stringify({ ok: true }))
    .setMimeType(ContentService.MimeType.JSON);
}
```

4. Deploy it from `Deploy > New deployment > Web app`.
5. Set `Execute as` to your account.
6. Set `Who has access` to `Anyone`.
7. Copy the Web App URL ending in `/exec`.
8. In OpenCart admin, open `AI Shopping Assist` and fill:

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
