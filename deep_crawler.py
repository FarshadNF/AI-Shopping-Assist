import json
import requests
import re
import os
import time
from bs4 import BeautifulSoup

CATALOG_PATH = "products_catalog.json"

def scrape_hyper_deep_info(product_id):
    product_url = f"http://localhost/test-shop/index.php?route=product/product&product_id={product_id}"
    
    # Default rich data structure
    deep_data = {
        "image_url": "",
        "full_description": "",
        "technical_attributes": {},
        "brand": "Generic"  # Safe dynamic fallback instead of hardcoded Moxa
    }
    
    try:
        response = requests.get(product_url, timeout=12)
        if response.status_code != 200:
            return deep_data
            
        soup = BeautifulSoup(response.text, 'html.parser')
        
        # 1. Dynamic Brand Extraction (Adapting to standard OpenCart DOM structures)
        # Strategy A: Look for manufacturer links
        brand_link = soup.find('a', href=lambda href: href and 'manufacturer_id' in href)
        if brand_link:
            deep_data["brand"] = brand_link.get_text(strip=True)
        else:
            # Strategy B: Scan text list items for Brand metadata
            for li in soup.find_all('li'):
                text = li.get_text()
                if "Brand:" in text:
                    deep_data["brand"] = text.replace("Brand:", "").strip()
                    break
        
        # 2. Main Image Extraction
        main_img_tag = soup.find('ul', class_='thumbnails') or soup.find('div', class_='image')
        if main_img_tag:
            img_link = main_img_tag.find('a')
            if img_link and img_link.get('href'):
                deep_data["image_url"] = img_link.get('href')
        
        # 3. Full HTML Description Clean up
        desc_div = soup.find('div', id='tab-description')
        if desc_div:
            deep_data["full_description"] = desc_div.get_text(separator=' ', strip=True)
            
        # 4. Technical Specifications Table Parser
        spec_div = soup.find('div', id='tab-specification')
        attributes = {}
        if spec_div:
            table_rows = spec_div.find_all('tr')
            for row in table_rows:
                tds = row.find_all('td')
                if len(tds) == 2:
                    key = tds[0].get_text(strip=True)
                    value = tds[1].get_text(strip=True)
                    # Sanitize keys to prevent JSON structural issues
                    if key and not key.startswith(':'):
                        attributes[key] = value
                        
        deep_data["technical_attributes"] = attributes
        
    except requests.RequestException as e:
        print(f"⚠ Network timeout or connectivity issue on Product ID {product_id}: {e}")
    except Exception as e:
        print(f"⚠ Unexpected parsing exception on Product ID {product_id}: {e}")
        
    return deep_data

def generate_dynamic_sales_angle(product_name, brand, attributes):
    """
    Context-aware sales positioning analyzer based on industrial vs retail keywords
    """
    combined_context = f"{product_name} {brand} {list(attributes.keys())}".lower()
    
    # Define industrial target footprints
    industrial_keywords = ['switch', 'moxa', 'port', 'industrial', 'ethernet', 'serial', 'modbus', 'converter', 'rail']
    
    # Pick top 2 features if available
    specs_summary = ""
    if attributes:
        specs_summary = ", ".join([f"{k}: {v}" for k, v in list(attributes.items())[:2]])

    if any(keyword in combined_context for keyword in industrial_keywords):
        feature_suffix = f" with technical specs [{specs_summary}]" if specs_summary else ""
        return f"Industrial grade reliability engineered for harsh environments, ensuring zero packet loss and maximum deployment uptime{feature_suffix}."
    else:
        feature_suffix = f" featuring {specs_summary}" if specs_summary else ""
        return f"High-performance option optimized for seamless productivity, modern UI workflows, and exceptional ecosystem synergy{feature_suffix}."

def save_catalog_atomically(catalog, path):
    """
    Prevents file corruption/truncation by writing to a temporary file first,
    then swapping it with the target production catalog file.
    """
    temp_path = f"{path}.tmp"
    try:
        with open(temp_path, 'w', encoding='utf-8') as f:
            json.dump(catalog, f, ensure_ascii=False, indent=4)
        os.replace(temp_path, path)
    except IOError as e:
        print(f"❌ Atomic save failed. Device IO issue: {e}")

def run_enrichment():
    print("🔍 Loading base catalog...")
    try:
        with open(CATALOG_PATH, 'r', encoding='utf-8') as f:
            catalog = json.load(f)
    except (FileNotFoundError, json.JSONDecodeError):
        print("❌ Base catalog missing or corrupted. Run fetch_catalog.py first.")
        return

    total_products = len(catalog)
    print(f"⚡ Deep scraping engine initialized for {total_products} items...")
    
    for count, product in enumerate(catalog, 1):
        # RESUME CHECK: Skip processing if already enriched in a previous run
        if product.get("full_description") and product.get("brand") != "Generic" and product.get("brand") != "":
            print(f"⏭ [{count}/{total_products}] Skipping ID: {product.get('product_id')} (Already Enriched)")
            continue
            
        p_id = product.get("product_id")
        print(f"🔄 [{count}/{total_products}] Deep parsing properties for ID: {p_id} ({product.get('name')})")
        
        deep_info = scrape_hyper_deep_info(p_id)
        
        # Map fields seamlessly
        product["image"] = deep_info["image_url"]
        product["full_description"] = deep_info["full_description"]
        product["attributes"] = deep_info["technical_attributes"]
        product["brand"] = deep_info["brand"]
        
        # Build adaptive AI sales angles
        product["sales_angle"] = generate_dynamic_sales_angle(
            product.get("name"), 
            product["brand"], 
            product["attributes"]
        )
        
        # Incremental save every 5 products to safeguard progress during execution
        if count % 5 == 0 or count == total_products:
            save_catalog_atomically(catalog, CATALOG_PATH)
            print(f"💾 Progress saved atomically up to item {count}/{total_products}.")
            
        time.sleep(0.2)  # Defensive throttling delay
        
    print("\n🎯 Enrichment phase finalized. All dynamic parameters loaded smoothly!")

if __name__ == "__main__":
    run_enrichment()