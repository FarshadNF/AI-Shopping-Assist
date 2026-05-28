import requests
import json

BASE_URL = "http://localhost/test-shop/index.php?route=extension/opencart/checkout/ai_assistant.getCatalog"

def fetch_all_products_at_once():
    print("🚀 آغاز فرآیند استخراج جامع محصولات از سایت دمو...")
    try:
        # اجبار اپن‌کارت به ارسال ۱۰۰۰ محصول در یک پکت برای دور زدن محدودیت ۲۰ تایی
        url = f"{BASE_URL}&limit=1000"
        response = requests.get(url, timeout=15)
        
        if response.status_code != 200:
            print(f"❌ خطا در لود سایت دمو. کد: {response.status_code}")
            return []
            
        result = response.json()
        if not result.get('success'):
            print("❌ اپن‌کارت دیتایی ارسال نکرد.")
            return []
            
        raw_products = result.get('data', [])
        print(f"📦 تعداد {len(raw_products)} محصول اولیه از API دریافت شد.")
        
        optimized_catalog = []
        for item in raw_products:
            optimized_catalog.append({
                "product_id": str(item.get("product_id", "")),
                "name": item.get("name", "").strip(),
                "price": str(item.get("price", "0.0000")),
                "stock": int(item.get("quantity", 0)),
                "image": "", # در گام بعد توسط کراولر عمیق پر می‌شود
                "attributes": {}, # در گام بعد غنی‌سازی می‌شود
                "full_description": ""
            })
        return optimized_catalog
    except Exception as e:
        print(f"❌ خطا: {e}")
        return []

if __name__ == "__main__":
    products = fetch_all_products_at_once()
    if products:
        with open('products_catalog.json', 'w', encoding='utf-8') as f:
            json.dump(products, f, ensure_ascii=False, indent=4)
        print("💾 کاتالوگ پایه ذخیره شد. حالا نوبت اجرای deep_crawler.py است.")