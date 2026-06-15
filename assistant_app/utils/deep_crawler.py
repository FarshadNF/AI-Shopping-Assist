import os
import sys
import django
import json
import time
import requests
import io
import xml.etree.ElementTree as ET
from bs4 import BeautifulSoup
from pypdf import PdfReader
from urllib.parse import urljoin, urlparse

# ۱. تنظیم محیط جانگو (باید قبل از هر چیز دیگری باشد)
PROJECT_ROOT = r"C:\AI_Assistant_Project"
sys.path.append(PROJECT_ROOT)

os.environ['DEBUG'] = 'True'
os.environ.setdefault('DJANGO_SETTINGS_MODULE', 'shopping_assist.settings')

try:
    django.setup()
except Exception as e:
    print(f"[ERROR] Could not setup Django: {e}")
    sys.exit(1)

# وارد کردن موتور دیتابیس برداری
try:
    from assistant_app.utils.vector_handler import RockfordVectorStore
except ImportError:
    from .vector_handler import RockfordVectorStore

# تنظیم مسیر فایل‌های کش در پوشه data
DATA_DIR = os.path.join(os.path.dirname(os.path.dirname(os.path.dirname(__file__))), "data")
os.makedirs(DATA_DIR, exist_ok=True)

DISCOVERED_URLS_PATH = os.path.join(DATA_DIR, "discovered_urls.json")
ENRICHED_CACHE_PATH = os.path.join(DATA_DIR, "enriched_cache.json")

def extract_pdf_text_from_url(pdf_url):
    """دانلود در لحظه و استخراج متن جداول فنی داخل فایل‌های PDF"""
    headers = {"User-Agent": "RockfordAI-DatasheetParser/3.0"}
    try:
        print(f"   [PDF-EXTRACT] Downloading datasheet: {pdf_url}")
        response = requests.get(pdf_url, headers=headers, timeout=20)
        if response.status_code != 200:
            return ""
            
        pdf_file = io.BytesIO(response.content)
        reader = PdfReader(pdf_file)
        
        pdf_text = ""
        for page_num, page in enumerate(reader.pages):
            page_content = page.extract_text()
            if page_content:
                pdf_text += f"\n--- Datasheet Page {page_num+1} ---\n" + page_content
        return pdf_text.strip()
    except Exception as e:
        print(f"   [PDF-ERROR] Failed to parse PDF: {str(e)}")
        return ""

def auto_discover_urls(base_url):
    """ربات جستجوگر برای پیدا کردن خودکار تمام لینک‌های سایت راکفورد"""
    print("[SPIDER] Initiating auto-discovery of site structure...")
    discovered_urls = set()
    headers = {"User-Agent": "RockfordAI-Spider/3.0"}
    
    # روش اول: استفاده از فید استاندارد و قدرتمند Sitemap XML اپن‌کارت (بهترین و سریع‌ترین راه)
    xml_sitemaps = [
        f"{base_url}/sitemap.xml",
        f"{base_url}/index.php?route=feed/google_sitemap",
        f"{base_url}/index.php?route=extension/feed/google_sitemap"
    ]
    
    for sitemap in xml_sitemaps:
        try:
            response = requests.get(sitemap, headers=headers, timeout=15)
            if response.status_code == 200 and 'xml' in response.headers.get('Content-Type', '').lower():
                print(f"[SPIDER] Found active XML sitemap: {sitemap}")
                root = ET.fromstring(response.content)
                for loc in root.iter('{http://www.sitemaps.org/schemas/sitemap/0.9}loc'):
                    if loc.text and base_url in loc.text:
                        discovered_urls.add(loc.text.strip())
                if discovered_urls:
                    print(f"[SPIDER] Extracted {len(discovered_urls)} URLs from XML Sitemap.")
                    return list(discovered_urls)
        except Exception:
            continue

    # روش دوم (جایگزین): استخراج از صفحه HTML سایت‌مپ که در تنظیمات فرستادید
    print("[SPIDER] XML not found. Falling back to HTML Sitemap parsing...")
    html_sitemap = f"{base_url}/index.php?route=information/sitemap"
    try:
        response = requests.get(html_sitemap, headers=headers, timeout=15)
        soup = BeautifulSoup(response.text, 'html.parser')
        for link in soup.find_all('a', href=True):
            href = link['href']
            # فقط لینک‌های داخلی سایت خودمان را جمع می‌کنیم
            if href.startswith(base_url) or href.startswith('/'):
                full_url = urljoin(base_url, href)
                discovered_urls.add(full_url)
    except Exception as e:
        print(f"[SPIDER-ERROR] Failed to parse HTML sitemap: {e}")
        
    print(f"[SPIDER] Extracted {len(discovered_urls)} potential URLs from HTML map.")
    return list(discovered_urls)

def scrape_hyper_deep_info(url, base_url):
    """بررسی عمیق یک صفحه وب: تشخیص اینکه آیا کالا است یا خیر و استخراج دیتا"""
    deep_data = {
        "is_product": False,
        "product_id": None,
        "name": "",
        "brand": "Generic",
        "image_url": "",
        "full_description": "",
        "technical_attributes": {},
        "datasheet_content": ""
    }
    
    headers = {"User-Agent": "RockfordAI-DeepCrawler/3.0"}
    try:
        response = requests.get(url, headers=headers, timeout=15)
        if response.status_code != 200:
            return deep_data
            
        soup = BeautifulSoup(response.text, 'html.parser')
        
        # تشخیص طلایی: در اپن‌کارت تمام صفحات محصول یک input مخفی به نام product_id دارند
        product_id_input = soup.find('input', {'name': 'product_id'})
        if not product_id_input:
            # این صفحه محصول نیست (شاید دسته بندی یا مقاله باشد)، رها کن
            return deep_data
            
        deep_data["is_product"] = True
        deep_data["product_id"] = product_id_input.get('value')
        
        # ۱. نام محصول (معمولاً تگ h1)
        h1 = soup.find('h1')
        if h1:
            deep_data["name"] = h1.get_text(strip=True)

        # ۲. استخراج برند
        brand_link = soup.find('a', href=lambda href: href and 'manufacturer_id' in href)
        if brand_link:
            deep_data["brand"] = brand_link.get_text(strip=True)
        else:
            for li in soup.find_all('li'):
                if "Brand:" in li.get_text():
                    deep_data["brand"] = li.get_text().replace("Brand:", "").strip()
                    break
        
        # ۳. استخراج تصویر
        main_img_tag = soup.find('ul', class_='thumbnails') or soup.find('div', class_='image')
        if main_img_tag:
            img_link = main_img_tag.find('a')
            if img_link and img_link.get('href'):
                deep_data["image_url"] = img_link.get('href')
                
        # ۴. توضیحات کامل
        desc_div = soup.find('div', id='tab-description')
        if desc_div:
            deep_data["full_description"] = desc_div.get_text(separator=' ', strip=True)
            
        # ۵. مشخصات فنی
        spec_div = soup.find('div', id='tab-specification')
        if spec_div:
            for row in spec_div.find_all('tr'):
                tds = row.find_all('td')
                if len(tds) == 2:
                    key = tds[0].get_text(strip=True)
                    val = tds[1].get_text(strip=True)
                    if key and not key.startswith(':'):
                        deep_data["technical_attributes"][key] = val
                        
        # ۶. دیتاشیت‌های PDF
        detected_pdf_texts = []
        for link in soup.find_all('a', href=True):
            href = link['href']
            if href.endswith('.pdf') or 'datasheet' in href.lower():
                pdf_url = urljoin(base_url, href)
                pdf_raw_text = extract_pdf_text_from_url(pdf_url)
                if pdf_raw_text:
                    detected_pdf_texts.append(f"Source PDF: {pdf_url}\n{pdf_raw_text}")
                    
        if detected_pdf_texts:
            deep_data["datasheet_content"] = "\n\n================\n\n".join(detected_pdf_texts)
            
    except Exception as e:
        print(f"   [SCRAPE-ERROR] Failed to scrape {url}: {e}")
        
    return deep_data

def generate_dynamic_sales_angle(product_name, brand, attributes):
    combined_context = f"{product_name} {brand} {list(attributes.keys())}".lower()
    industrial_keywords = ['switch', 'moxa', 'port', 'industrial', 'ethernet', 'serial', 'modbus', 'converter', 'rail']
    
    specs_summary = ", ".join([f"{k}: {v}" for k, v in list(attributes.items())[:2]]) if attributes else ""
    feature_suffix = f" with technical specs [{specs_summary}]" if specs_summary else ""
    
    if any(k in combined_context for k in industrial_keywords):
        return f"Industrial grade reliability engineered for harsh environments, ensuring zero packet loss and maximum deployment uptime{feature_suffix}."
    return f"High-performance option optimized for seamless productivity and exceptional ecosystem synergy{feature_suffix}."

def save_atomically(data, path):
    temp_path = f"{path}.tmp"
    try:
        with open(temp_path, 'w', encoding='utf-8') as f:
            json.dump(data, f, ensure_ascii=False, indent=4)
        os.replace(temp_path, path)
    except IOError:
        pass

def load_json(path, default):
    try:
        with open(path, 'r', encoding='utf-8') as f:
            return json.load(f)
    except (FileNotFoundError, json.JSONDecodeError):
        return default

def run_pipeline():
    base_url = os.getenv("OPENCART_BASE_URL", "https://rockford-qatar.com").rstrip("/")
    
    # ---------------------------------------------------------
    # فاز ۱: اکتشاف لینک‌ها (Spider Discovery)
    # ---------------------------------------------------------
    urls_list = load_json(DISCOVERED_URLS_PATH, [])
    if not urls_list:
        urls_list = auto_discover_urls(base_url)
        save_atomically(urls_list, DISCOVERED_URLS_PATH)
        
    if not urls_list:
        print("[ABORT] Spider couldn't find any URLs to crawl. Check site accessibility.")
        return

    # ---------------------------------------------------------
    # فاز ۲: استخراج عمیق محصولات (Deep Scrape)
    # ---------------------------------------------------------
    cache = load_json(ENRICHED_CACHE_PATH, {})
    total_urls = len(urls_list)
    print(f"[PIPELINE] Starting deep inspection of {total_urls} URLs...")
    
    for count, url in enumerate(urls_list, 1):
        # بررسی می‌کنیم آیا قبلاً این آدرس بررسی و کش شده است؟
        # از URL به عنوان کلید یکتا برای شناسایی استفاده می‌کنیم
        url_key = urlparse(url).path + "?" + urlparse(url).query
        if url_key in cache:
            continue
            
        print(f"[{count}/{total_urls}] Inspecting: {url}")
        scraped_data = scrape_hyper_deep_info(url, base_url)
        
        # اگر ربات فهمید که این صفحه محصول نیست، آن را با برچسب is_product=False کش می‌کند تا دفعه بعد دوباره سراغش نرود
        if not scraped_data["is_product"]:
            cache[url_key] = {"is_product": False}
        else:
            # تولید زاویه فروش هوشمند
            sales_angle = generate_dynamic_sales_angle(
                scraped_data["name"], scraped_data["brand"], scraped_data["technical_attributes"]
            )
            scraped_data["sales_angle"] = sales_angle
            cache[url_key] = scraped_data
            print(f"   [SUCCESS] Extracted Product: {scraped_data['name']} (ID: {scraped_data['product_id']})")
            
        # ذخیره هر ۱۰ لینک یک‌بار
        if count % 10 == 0:
            save_atomically(cache, ENRICHED_CACHE_PATH)
            
        time.sleep(0.5) # جلوگیری از فشار به سرور راکفورد
        
    save_atomically(cache, ENRICHED_CACHE_PATH)
    print("[SUCCESS] All links processed and products cached.")

    # ---------------------------------------------------------
    # فاز ۳: تزریق به پایگاه داده برداری (Vector Knowledge Base)
    # ---------------------------------------------------------
    print("[VECTOR-PHASE] Initializing Rockford Vector Store connection...")
    vector_store = RockfordVectorStore()
    vector_payload = []
    
    # فیلتر کردن فقط محصولاتی که موفقیت آمیز ذخیره شده‌اند
    products = [data for url_key, data in cache.items() if data.get("is_product") == True]
    
    for product in products:
        specs_str = " | ".join([f"{k}: {v}" for k, v in product.get("technical_attributes", {}).items()])
        
        combined_knowledge = f"""
Product Name: {product.get('name')}
Brand: {product.get('brand')}
Model ID: {product.get('product_id')}
Sales Position: {product.get('sales_angle')}
Description: {product.get('full_description')}
Technical Specifications: {specs_str}
        """.strip()
        
        product_url = f"{base_url}/index.php?route=product/product&product_id={product.get('product_id')}"
        
        vector_payload.append({
            "source": product_url,
            "type": "product_page",
            "content": combined_knowledge
        })
        
        if product.get("datasheet_content"):
            vector_payload.append({
                "source": product_url + " (Embedded Datasheet)",
                "type": "datasheet_pdf",
                "content": product.get("datasheet_content")
            })
            
    print(f"[VECTOR-PHASE] Injecting {len(vector_payload)} rich sources into Vector Database...")
    vector_store.inject_knowledge_base(vector_payload)
    print("[ALL-DONE] Rockford Vector Brain successfully synchronized and fully educated!")

if __name__ == "__main__":
    run_pipeline()