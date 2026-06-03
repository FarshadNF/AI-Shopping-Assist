import requests
import json
import time

# Target OpenCart local API endpoint
BASE_URL = "http://localhost/test-shop/index.php?route=extension/opencart/checkout/ai_assistant.getCatalog"
CATALOG_PATH = "products_catalog.json"

def fetch_all_products_paged():
    print("🚀 Starting robust multi-page catalog extraction...")
    all_optimized_products = []
    page = 1
    limit = 100  # Safe chunk size to prevent OpenCart memory exhaustion
    
    while True:
        print(f"📥 Fetching page {page}...")
        try:
            # Injecting pagination parameters dynamically
            url = f"{BASE_URL}&page={page}&limit={limit}"
            response = requests.get(url, timeout=15)
            
            if response.status_code != 200:
                print(f"❌ HTTP Error on page {page}. Status Code: {response.status_code}")
                break
            
            try:
                result = response.json()
            except json.JSONDecodeError:
                print(f"❌ Critical: Invalid JSON structure received on page {page}.")
                break
                
            if not result.get('success') or not result.get('data'):
                print(f"🏁 Reached the end of the catalog or API returned no more data.")
                break
                
            raw_products = result.get('data', [])
            print(f"📦 Successfully fetched {len(raw_products)} products from page {page}.")
            
            for item in raw_products:
                all_optimized_products.append({
                    "product_id": str(item.get("product_id", "")),
                    "name": item.get("name", "").strip(),
                    "price": str(item.get("price", "0.0000")),
                    "stock": int(item.get("quantity", 0)),
                    "brand": "",               # Explicitly cleared: Will be deeply scraped from product page
                    "image": "",               # Placeholder for deep_crawler step
                    "attributes": {},          # Placeholder for deep_crawler step
                    "full_description": "",    # Placeholder for deep_crawler step
                    "sales_angle": ""          # Placeholder for dynamic sales positioning
                })
            
            # If the current page has fewer items than the limit, it's the final page
            if len(raw_products) < limit:
                break
                
            page += 1
            time.sleep(0.5)  # Cooldown delay to protect local server from being rate-limited
            
        except requests.RequestException as e:
            print(f"❌ Network/Connection error occurred on page {page}: {e}")
            break

    return all_optimized_products

if __name__ == "__main__":
    products = fetch_all_products_paged()
    if products:
        try:
            with open(CATALOG_PATH, 'w', encoding='utf-8') as f:
                json.dump(products, f, ensure_ascii=False, indent=4)
            print(f"\n💾 Base catalog saved successfully! Total products stored: {len(products)}")
            print("🎯 Ready for the next phase. You can now execute deep_crawler.py.")
        except IOError as e:
            print(f"❌ Hardware/IO error while writing to {CATALOG_PATH}: {e}")
    else:
        print("\n⚠ Process aborted: No product data found. Check your local API status.")