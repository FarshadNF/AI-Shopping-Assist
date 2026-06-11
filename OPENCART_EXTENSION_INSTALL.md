# نصب اکستنشن مستقل OpenCart

این نسخه دیگر به Django نیاز ندارد. همه چیز داخل خود OpenCart انجام می‌شود:

- نمایش ویجت چت
- خواندن کاتالوگ محصولات OpenCart
- تماس مستقیم سمت سرور با Gemini
- ثبت لاگ چت‌ها داخل دیتابیس OpenCart
- اجرای اکشن‌های سبد خرید، مشاهده سبد، پرداخت، کوپن، درخواست فاکتور و رفتن به صفحه‌های سایت
- نمایش suggestionهای آماده و پیشنهادی داخل چت
- نمایش product card با عکس، قیمت، موجودی، مشخصات و دکمه‌های `Learn more` و `Add to cart`

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

## Google Sheets lead webhook

Use this when bulk order leads should be saved into Google Sheets.

1. Create a Google Sheet and copy its spreadsheet ID from the URL.
2. Go to `Extensions > Apps Script`.
3. Paste this script and replace `SHEET_ID` and `SECRET`.

```javascript
const SHEET_ID = 'PASTE_SPREADSHEET_ID_HERE';
const SECRET = 'CHANGE_THIS_SECRET';
const SHEET_NAME = 'Bulk Leads';
const HEADERS = [
  'id',
  'Product Name',
  'QTY',
  'Name',
  'Company',
  'Contact Number',
  'Email',
  'Delivery Location',
  'date'
];

function doPost(e) {
  const payload = JSON.parse((e && e.postData && e.postData.contents) || '{}');

  if (SECRET && payload.secret !== SECRET) {
    return ContentService
      .createTextOutput(JSON.stringify({ ok: false, error: 'unauthorized' }))
      .setMimeType(ContentService.MimeType.JSON);
  }

  const spreadsheet = SpreadsheetApp.openById(SHEET_ID);
  let sheet = spreadsheet.getSheetByName(SHEET_NAME);

  if (sheet && sheet.getLastRow() > 0) {
    const existingHeaders = sheet
      .getRange(1, 1, 1, Math.min(sheet.getLastColumn(), HEADERS.length))
      .getValues()[0]
      .join('|');

    if (existingHeaders !== HEADERS.join('|')) {
      const backupName = SHEET_NAME + ' Backup ' + Utilities.formatDate(new Date(), Session.getScriptTimeZone(), 'yyyyMMddHHmmss');
      sheet.setName(backupName);
      sheet = null;
    }
  }

  if (!sheet) {
    sheet = spreadsheet.insertSheet(SHEET_NAME);
  }

  if (sheet.getLastRow() === 0) {
    sheet.appendRow(HEADERS);
  }

  const lead = payload.lead || {};

  sheet.appendRow([
    payload.id || payload.conversation_id || '',
    lead.product_name || '',
    lead.qty || '',
    lead.name || '',
    lead.company || '',
    lead.contact_number || '',
    lead.email || '',
    lead.delivery_location || '',
    payload.date ? new Date(payload.date) : new Date()
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
Lead webhook URL = Google Apps Script Web App URL
Lead webhook secret = same SECRET value used in Apps Script
```

## Internal extension routes

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
