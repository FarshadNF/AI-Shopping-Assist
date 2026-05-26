import requests


URL = "http://localhost/test-shop/index.php?route=extension/opencart/checkout/ai_assistant.addToCart"


def main():
    payload = {
        "product_id": 42,
        "quantity": 1,
    }

    try:
        response = requests.post(URL, data=payload)
        print(response.status_code)
        print(response.text)
    except Exception as e:
        print(e)


if __name__ == "__main__":
    main()
