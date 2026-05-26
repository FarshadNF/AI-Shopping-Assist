import requests
import json

url = "http://localhost/test-shop/index.php?route=extension/opencart/checkout/ai_assistant.getCatalog"

try:
    response = requests.get(url)
    print("Status Code:", response.status_code)
    
    data = response.json()
    if data.get('success'):
        print(f"Successfully fetched {data.get('total')} products.")
        
        with open('products_catalog.json', 'w', encoding='utf-8') as f:
            json.dump(data['data'], f, ensure_ascii=False, indent=4)
            
        print("Catalog saved to 'products_catalog.json'")
    else:
        print("Failed to fetch catalog.")
except Exception as e:
    print(f"Error: {e}")