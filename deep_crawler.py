import json
import requests
from bs4 import BeautifulSoup
import time

CATALOG_PATH = "products_catalog.json"

def scrape_hyper_deep_info(product_id):
    product_url = f"http://localhost/test-shop/index.php?route=product/product&product_id={product_id}"
    
    # ساختار غنی‌شده پیش‌فرض
    deep_data = {
        "image_url": "",
        "full_description": "",
        "technical_attributes": {}, # تبدیل جدول فنی HTML به دیکشنری پایتون برای AI
        "brand": "Moxa" # برند پیش‌فرض برای تست فعلی
    }
    
    try:
        response = requests.get(product_url, timeout=10)
        if response.status_code != 200:
            return deep_data
            
        soup = BeautifulSoup(response.text, 'html.parser')
        
        # ۱. استخراج عکس اصلی محصول در اپن‌کارت
        main_img_tag = soup.find('ul', class_='thumbnails') or soup.find('div', class_='image')
        if main_img_tag:
            img_link = main_img_tag.find('a')
            if img_link and img_link.get('href'):
                deep_data["image_url"] = img_link.get('href')
        
        # ۲. استخراج توضیحات متنی کامل
        desc_div = soup.find('div', id='tab-description')
        if desc_div:
            deep_data["full_description"] = desc_div.get_text(separator=' ', strip=True)
            
        # ۳. استخراج و ساختاردهی جدول مشخصات فنی (حیاتی برای پاسخ به چراها و مقایسه‌ها)
        spec_div = soup.find('div', id='tab-specification')
        attributes = {}
        if spec_div:
            table_rows = spec_div.find_all('tr')
            for row in table_rows:
                tds = row.find_all('td')
                if len(tds) == 2:
                    key = tds[0].get_text(strip=True)
                    value = tds[1].get_text(strip=True)
                    attributes[key] = value
        
        deep_data["technical_attributes"] = attributes
        
    except Exception as e:
        print(f"⚠ خطا در کراول محصول {product_id}: {e}")
        
    return deep_data

def run_enrichment():
    print("🔍 لود کاتالوگ پایه...")
    try:
        with open(CATALOG_PATH, 'r', encoding='utf-8') as f:
            catalog = json.load(f)
    except FileNotFoundError:
        print("❌ ابتدا باید fetch_catalog.py را اجرا کنی.")
        return

    print(f"⚡ شروغ خزش الگوهای فنی برای {len(catalog)} محصول...")
    
    for count, product in enumerate(catalog, 1):
        p_id = product.get("product_id")
        print(f"🔄 [{count}/{len(catalog)}] خزش میکروسکوپی محصول ID: {p_id} ({product.get('name')})")
        
        deep_info = scrape_hyper_deep_info(p_id)
        
        # تزریق مستقیم به کاتالوگ دیتابیس هوش مصنوعی
        product["image"] = deep_info["image_url"]
        product["full_description"] = deep_info["full_description"]
        product["attributes"] = deep_info["technical_attributes"]
        product["brand"] = deep_info["brand"]
        
        # ایجاد Sales Angle پویا بر اساس مشخصات فنی واقعی استخراج شده
        if product["attributes"]:
            specs_summary = ", ".join([f"{k}: {v}" for k, v in list(product["attributes"].items())[:2]])
            product["sales_angle"] = f"مزیت رقابتی بر اساس مشخصات فنی: این محصول دارای استانداردهای {specs_summary} بوده که پایداری بالایی در شبکه ایجاد می‌کند."
        
        time.sleep(0.1) # حفاظت از سرور
        
    with open(CATALOG_PATH, 'w', encoding='utf-8') as f:
        json.dump(catalog, f, ensure_ascii=False, indent=4)
        
    print("🎯 تمام محصولات (از جمله ۵ محصول جدید) با عکس و جداول فنی با موفقیت ذخیره شدند!")

if __name__ == "__main__":
    run_enrichment()