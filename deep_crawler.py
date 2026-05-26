import json
import requests
from bs4 import BeautifulSoup
import time

CATALOG_PATH = "products_catalog.json"
ENRICHED_CATALOG_PATH = "products_catalog.json" # دیتا را روی همان فایل اوررایت می‌کنیم تا خدمات خدمات سیستم یکپارچه بماند

def scrape_deep_product_info(product_id):
    """
    این تابع به صفحه اختصاصی محصول در اپن‌کارت رفته و تمام جزئیات متنی و مشخصات
    پنهان در HTML را استخراج می‌کند.
    """
    # آدرس استاندارد صفحه محصول در اپن‌کارت دمو
    product_url = f"http://localhost/test-shop/index.php?route=product/product&product_id={product_id}"
    
    deep_data = {
        "full_description": "",
        "meta_keywords": "",
        "technical_tags": []
    }
    
    try:
        response = requests.get(product_url, timeout=10)
        if response.status_code != 200:
            return deep_data
            
        soup = BeautifulSoup(response.text, 'html.parser')
        
        # ۱. استخراج توضیحات کامل محصول (معمولاً در تگ div با آی‌دی tab-description است)
        desc_div = soup.find('div', id='tab-description')
        if desc_div:
            deep_data["full_description"] = desc_div.get_text(separator=' ', strip=True)
            
        # ۲. استخراج کلمات کلیدی متاتگ برای درک سئو و مفاهیم بیشتر توسط AI
        meta_keywords = soup.find('meta', { 'name': 'keywords' })
        if meta_keywords and meta_keywords.get('content'):
            deep_data["meta_keywords"] = meta_keywords.get('content').strip()
            
        # ۳. استخراج تگ‌ها یا مدل‌های فنی موجود در صفحه
        # این بخش بسته به قالب دمو اپن‌کارت ممکن است تگ‌های li یا کلاس‌های خاصی باشد
        tags_container = soup.find('div', class_='tags')
        if tags_container:
            links = tags_container.find_all('a')
            deep_data["technical_tags"] = [link.get_text(strip=True) for link in links]
            
    except Exception as e:
        print(f"⚠ خطا در خزش صفحه محصول {product_id}: {e}")
        
    return deep_data

def enrich_catalog():
    print("🔍 بارگذاری کاتالوگ پایه‌ای...")
    try:
        with open(CATALOG_PATH, 'r', encoding='utf-8') as f:
            catalog = json.load(f)
    except FileNotFoundError:
        print("❌ فایل کاتالوگ پایه یافت نشد! ابتدا fetch_catalog.py را اجرا کنید.")
        return

    print(f"🎯 یافتن {len(catalog)} محصول برای خزش عمیق جزئیات...")
    
    enriched_catalog = []
    
    for count, product in enumerate(catalog, 1):
        p_id = product.get("product_id")
        p_name = product.get("name")
        
        print(f" [{count}/{len(catalog)}] در حال شخم زدن صفحه محصول: {p_name} (ID: {p_id})...")
        
        # خزش اطلاعات عمیق از وب‌سایت
        deep_info = scrape_deep_product_info(p_id)
        
        # ادغام دیتای عمیق با دیتای کاتالوگ قبلی
        product["full_description"] = deep_info["full_description"]
        product["meta_keywords"] = deep_info["meta_keywords"]
        product["technical_tags"] = deep_info["technical_tags"]
        
        # اگر توضیحات کامل وجود داشت، sales_angle را هم برای هوش مصنوعی غنی‌تر می‌کنیم
        if product["full_description"] and product["sales_angle"].startswith("تجهیزات صنعتی باکیفیت"):
            # خلاصه‌ای کوتاه از توضیحات واقعی سایت را چاشنی کار می‌کنیم
            product["sales_angle"] = f"مشاوره تخصصی بر اساس کاتالوگ: {product['full_description'][:150]}..."
            
        enriched_catalog.append(product)
        
        # یک تاخیر بسیار کوتاه برای اینکه سرور محلی لوکال‌هاست زیر فشار کرش نکند
        time.sleep(0.2)
        
    # ذخیره‌سازی مجدد فایل با دیتای فوق‌العاده عمیق
    with open(ENRICHED_CATALOG_PATH, 'w', encoding='utf-8') as f:
        json.dump(enriched_catalog, f, ensure_ascii=False, indent=4)
        
    print("✅ فرآیند غنی‌سازی کاتالوگ با موفقیت به پایان رسید! هوش مصنوعی اکنون آماده مشاوره تخصصی است.")

if __name__ == "__main__":
    enrich_catalog()
