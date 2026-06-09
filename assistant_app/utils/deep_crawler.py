import json
import os
import time
import requests
from bs4 import BeautifulSoup

CATALOG_PATH = "products_catalog.json"
ENRICHED_CACHE_PATH = "enriched_cache.json"

def scrape_hyper_deep_info(product_url):
    deep_data = {
        "image_url": "",
        "full_description": "",
        "technical_attributes": {},
        "brand": "Generic"
    }
    
    try:
        response = requests.get(product_url, timeout=12)
        if response.status_code != 200:
            return deep_data
            
        soup = BeautifulSoup(response.text, 'html.parser')
        
        brand_link = soup.find('a', href=lambda href: href and 'manufacturer_id' in href)
        if brand_link:
            deep_data["brand"] = brand_link.get_text(strip=True)
        else:
            for li in soup.find_all('li'):
                text = li.get_text()
                if "Brand:" in text:
                    deep_data["brand"] = text.replace("Brand:", "").strip()
                    break
        
        main_img_tag = soup.find('ul', class_='thumbnails') or soup.find('div', class_='image')
        if main_img_tag:
            img_link = main_img_tag.find('a')
            if img_link and img_link.get('href'):
                deep_data["image_url"] = img_link.get('href')
        
        desc_div = soup.find('div', id='tab-description')
        if desc_div:
            deep_data["full_description"] = desc_div.get_text(separator=' ', strip=True)
            
        spec_div = soup.find('div', id='tab-specification')
        attributes = {}
        if spec_div:
            table_rows = spec_div.find_all('tr')
            for row in table_rows:
                tds = row.find_all('td')
                if len(tds) == 2:
                    key = tds[0].get_text(strip=True)
                    value = tds[1].get_text(strip=True)
                    if key and not key.startswith(':'):
                        attributes[key] = value
                        
        deep_data["technical_attributes"] = attributes
        
    except requests.RequestException:
        pass
    except Exception:
        pass
        
    return deep_data

def generate_dynamic_sales_angle(product_name, brand, attributes):
    combined_context = f"{product_name} {brand} {list(attributes.keys())}".lower()
    industrial_keywords = ['switch', 'moxa', 'port', 'industrial', 'ethernet', 'serial', 'modbus', 'converter', 'rail']
    
    specs_summary = ""
    if attributes:
        specs_summary = ", ".join([f"{k}: {v}" for k, v in list(attributes.items())[:2]])

    if any(keyword in combined_context for keyword in industrial_keywords):
        feature_suffix = f" with technical specs [{specs_summary}]" if specs_summary else ""
        return f"Industrial grade reliability engineered for harsh environments, ensuring zero packet loss and maximum deployment uptime{feature_suffix}."
    else:
        feature_suffix = f" featuring {specs_summary}" if specs_summary else ""
        return f"High-performance option optimized for seamless productivity, modern UI workflows, and exceptional ecosystem synergy{feature_suffix}."

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

def run_enrichment():
    catalog = load_json(CATALOG_PATH, [])
    if not catalog:
        return

    cache = load_json(ENRICHED_CACHE_PATH, {})
    base_url = os.getenv("OPENCART_BASE_URL", "http://localhost/test-shop").rstrip("/")
    total_products = len(catalog)
    
    for count, product in enumerate(catalog, 1):
        p_id = str(product.get("product_id"))
        
        if p_id in cache:
            product.update(cache[p_id])
            continue
            
        if product.get("full_description") and product.get("brand") not in ["Generic", "", None]:
            cache[p_id] = {
                "image": product.get("image", ""),
                "full_description": product.get("full_description"),
                "attributes": product.get("attributes", {}),
                "brand": product.get("brand"),
                "sales_angle": product.get("sales_angle", "")
            }
            continue

        product_url = f"{base_url}/index.php?route=product/product&product_id={p_id}"
        deep_info = scrape_hyper_deep_info(product_url)
        
        sales_angle = generate_dynamic_sales_angle(
            product.get("name"), 
            deep_info["brand"], 
            deep_info["technical_attributes"]
        )
        
        enriched_data = {
            "image": deep_info["image_url"],
            "full_description": deep_info["full_description"],
            "attributes": deep_info["technical_attributes"],
            "brand": deep_info["brand"],
            "sales_angle": sales_angle
        }
        
        product.update(enriched_data)
        cache[p_id] = enriched_data
        
        if count % 5 == 0 or count == total_products:
            save_atomically(catalog, CATALOG_PATH)
            save_atomically(cache, ENRICHED_CACHE_PATH)
            
        time.sleep(0.2)
        
    save_atomically(catalog, CATALOG_PATH)
    save_atomically(cache, ENRICHED_CACHE_PATH)

if __name__ == "__main__":
    run_enrichment()