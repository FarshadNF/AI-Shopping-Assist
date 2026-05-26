import requests

url = "http://localhost/test-shop/index.php?route=extension/opencart/checkout/ai_assistant.addToCart"

payload = {
    'product_id': 42,
    'quantity': 1
}

try:
    response = requests.post(url, data=payload)
    print(response.status_code)
    print(response.text)
except Exception as e:
    print(e)