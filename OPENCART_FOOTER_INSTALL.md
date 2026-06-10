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

## وضعیت اتصال در Django admin

بعد از `python manage.py migrate` یک بخش به admin اضافه می‌شود:

```text
Assistant app > OpenCart connection
```

اگر ویجت فوتر حداقل یک بار catalog sync موفق انجام داده باشد، این صفحه وضعیت را
`Connected` نشان می‌دهد. برای بررسی زنده از سمت Django هم یکی از این تنظیمات را
در `.env` بگذار:

```text
OPENCART_BASE_URL=http://localhost/test-shop/
```

یا آدرس کامل endpoint:

```text
OPENCART_CATALOG_URL=http://localhost/test-shop/index.php?route=extension/opencart/checkout/ai_assistant.getCatalog
```

ویجت هر ۱۰ دقیقه یک بار کاتالوگ اوپن‌کارت را صفحه‌به‌صفحه می‌خواند، آن را به
`/api/catalog/import/` می‌فرستد، سپس پاسخ‌های `/api/chat/` بر اساس همان
`products_catalog.json` تازه تولید می‌شوند.
## Assistant actions

The widget can now execute multiple assistant actions returned by `/api/chat/`.

Supported actions:

- `add_to_cart`: posts `product_id` and `requested_qty` to OpenCart cart routes.
- `show_cart`: reads the live cart from `cartInfoRoute`; falls back to parsing `cartRoute`; finally falls back to the widget's local cart snapshot.
- `redirect_to_cart`: redirects the browser to `cartRoute`.
- `redirect_to_product`: opens the product detail page by `product_url` when available, otherwise by `productRoute + product_id`, otherwise by `searchRoute`.
- `update_cart_item`: changes a cart item quantity through `cartActionRoute` or OpenCart cart edit routes.
- `remove_from_cart`: removes a cart item through `cartActionRoute` or OpenCart cart remove routes.
- `clear_cart`: empties the cart through `cartActionRoute` or by removing known cart lines.
- `apply_coupon`: applies a coupon through `cartActionRoute` or `couponRoute`.
- `redirect_to_checkout`: redirects the browser to `checkoutRoute`.
- `send_invoice`: posts an invoice/proforma/quote request to `invoiceRoute`.

Default widget routes:

```js
cartRoute: 'index.php?route=checkout/cart',
productRoute: 'index.php?route=product/product',
searchRoute: 'index.php?route=product/search',
cartInfoRoute: 'index.php?route=extension/opencart/checkout/ai_assistant.getCart',
cartActionRoute: 'index.php?route=extension/opencart/checkout/ai_assistant.cartAction',
checkoutRoute: 'index.php?route=checkout/checkout',
couponRoute: 'index.php?route=extension/total/coupon.coupon',
invoiceRoute: 'index.php?route=extension/opencart/checkout/ai_assistant.sendInvoice',
redirectDelayMs: 700
```

For the most reliable cart actions, implement these optional OpenCart extension routes:

- `cartInfoRoute`: return JSON cart contents.
- `cartActionRoute`: accept same-origin `POST` actions.

`cartInfoRoute` should return JSON shaped like:

```json
{
  "success": true,
  "products": [
    {
      "key": "cart-line-key",
      "product_id": "40",
      "name": "iPhone",
      "quantity": 2,
      "price": "$100.00",
      "total": "$200.00"
    }
  ],
  "total": "$200.00"
}
```

`cartActionRoute` receives:

```text
action=update|remove|clear|coupon
product_id
key
quantity
code
```

Return JSON such as:

```json
{"success": true}
```

To send real invoices from OpenCart, implement `invoiceRoute` in your OpenCart extension. It should accept a same-origin `POST` with:

```text
email
invoice_type
note
conversation_id
```

Return JSON such as:

```json
{"success": true}
```

If `invoiceRoute` is missing or returns an error, the widget shows a short fallback message and redirects the user to checkout.
