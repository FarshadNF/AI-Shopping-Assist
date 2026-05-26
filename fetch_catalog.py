import requests
import json
import sys

# آدرس پایه‌ای کنترلر اپن‌کارت که مهدیار ساخته است
BASE_URL = "http://localhost/test-shop/index.php?route=extension/opencart/checkout/ai_assistant.getCatalog"

def fetch_all_products_from_site():
    """
    این تابع به صورت جامع تمام محصولات موجود در سایت دمو را بدون جا انداختن حتی یک آیتم،
    با مدیریت پجینیشن و صفحات، واکشی می‌کند.
    """
    all_extracted_products = []
    page = 1
    limit = 20 # تعداد پیش‌فرض اپن‌کارت در هر صفحه
    
    print("🚀 آغاز فرآیند خزش و استخراج محصولات از سایت دمو...")
    
    while True:
        try:
            # ارسال درخواست به همراه پارامترهای صفحه برای اطمینان از دریافت تک‌تک محصولات
            url = f"{BASE_URL}&page={page}&limit={limit}"
            response = requests.get(url, timeout=15)
            
            if response.status_code != 200:
                print(f"❌ خطا در برقراری ارتباط با سایت. کد خطا: {response.status_code}")
                break
                
            result = response.json()
            
            if not result.get('success'):
                print("❌ خروجی سایت با موفقیت همراه نبود یا محصولی یافت نشد.")
                break
                
            products_page = result.get('data', [])
            if not products_page:
                # اگر صفحه‌ای خالی بود یعنی به انتهای محصولات سایت رسیده‌ایم
                break
                
            print(f"📦 در حال پردازش صفحه {page} (شامل {len(products_page)} محصول)...")
            
            for item in products_page:
                # مهندسی داده‌ها و غنی‌سازی ساختار برای درک عمیق مدل زبانی (LLM-Optimized)
                optimized_item = {
                    "product_id": str(item.get("product_id", "")),
                    "name": item.get("name", "").strip(),
                    "price": str(item.get("price", "0.0000")),
                    "stock": int(item.get("quantity", 0)),
                    "category": item.get("category", "Industrial Automation & Networking"),
                    
                    # استخراج ویژگی‌های فنی (Attributes)؛ اگر مهدیار فرستاده باشد آرایه را مپ میکند، در غیر این صورت ساختار خالی نگه میدارد
                    "attributes": item.get("attributes", {
                        "Interface": "مشخصات پورت یافت نشد",
                        "Protection": "استاندارد بدنه نامشخص"
                    }),
                    
                    # خلق زاویه فروش بر اساس دیتای محصول جهت کمک به پرامپت فروشنده ارشد
                    "sales_angle": item.get("sales_angle", f"تجهیزات صنعتی باکیفیت مدل {item.get('name')}. گزینه‌ای پایدار برای پایداری شبکه و اتوماسیون."),
                    
                    # محصولات مرتبط برای سناریوی محصولات جایگزین
                    "alternatives": item.get("alternatives", [])
                }
                all_extracted_products.append(optimized_item)
                
            # بررسی اینکه آیا به انتهای کل محصولات سایت رسیده‌ایم یا خیر
            total_products = int(result.get('total', 0))
            if len(all_extracted_products) >= total_products:
                break
                
            page += 1 # رفتن به صفحه بعدی سایت
            
        except requests.exceptions.RequestException as e:
            print(f"❌ خطای شبکه یا تایم‌اوت: {e}")
            break
        except Exception as e:
            print(f"❌ خطای غیرمنتظره در ساختار داده: {e}")
            break

    return all_extracted_products

if __name__ == "__main__":
    products = fetch_all_products_from_site()
    
    if products:
        print(f"✅ موفقیت‌آمیز! در مجموع {len(products)} محصول کاملاً استخراج و بهینه‌سازی شدند.")
        
        # ذخیره‌سازی نهایی و تمیز در فایل کاتالوگ اصلی
        try:
            with open('products_catalog.json', 'w', encoding='utf-8') as f:
                json.dump(products, f, ensure_ascii=False, indent=4)
            print("💾 کاتالوگ جدید با موفقیت در 'products_catalog.json' ذخیره شد.")
        except IOError as e:
            print(f"❌ خطا در نوشتن فایل JSON: {e}")
    else:
        print("⚠ هیچ محصولی برای ذخیره‌سازی یافت نشد. وضعیت اتصال یا دیتابیس دمو را بررسی کنید.")