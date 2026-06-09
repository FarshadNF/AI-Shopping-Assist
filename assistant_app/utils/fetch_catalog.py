import os
import requests
import json
import time

CATALOG_PATH = "products_catalog.json"
ENRICHED_CACHE_PATH = "enriched_cache.json"

def load_json(path, default):
    try:
        with open(path, 'r', encoding='utf-8') as f:
            return json.load(f)
    except (FileNotFoundError, json.JSONDecodeError):
        return default

def fetch_all_products_paged():
    base_url = os.getenv("OPENCART_BASE_URL", "http://localhost/test-shop").rstrip("/")
    api_url = f"{base_url}/index.php?route=extension/opencart/checkout/ai_assistant.getCatalog"
    
    enriched_cache = load_json(ENRICHED_CACHE_PATH, {})
    all_optimized_products = []
    page = 1
    limit = 100
    
    while True:
        try:
            url = f"{api_url}&page={page}&limit={limit}"
            response = requests.get(url, timeout=15)
            
            if response.status_code != 200:
                break
            
            try:
                result = response.json()
            except json.JSONDecodeError:
                break
                
            if not result.get('success') or not result.get('data'):
                break
                
            raw_products = result.get('data', [])
            
            for item in raw_products:
                p_id = str(item.get("product_id", ""))
                cached_data = enriched_cache.get(p_id, {})
                
                all_optimized_products.append({
                    "product_id": p_id,
                    "name": item.get("name", "").strip(),
                    "price": str(item.get("price", "0.0000")),
                    "stock": int(item.get("quantity", 0)),
                    "brand": cached_data.get("brand", ""),
                    "image": cached_data.get("image", ""),
                    "attributes": cached_data.get("attributes", {}),
                    "full_description": cached_data.get("full_description", ""),
                    "sales_angle": cached_data.get("sales_angle", "")
                })
            
            if len(raw_products) < limit:
                break
                
            page += 1
            time.sleep(0.5)
            
        except requests.RequestException:
            break

    return all_optimized_products

def save_atomically(data, path):
    temp_path = f"{path}.tmp"
    try:
        with open(temp_path, 'w', encoding='utf-8') as f:
            json.dump(data, f, ensure_ascii=False, indent=4)
        os.replace(temp_path, path)
    except IOError:
        pass

if __name__ == "__main__":
    products = fetch_all_products_paged()
    if products:
        save_atomically(products, CATALOG_PATH)