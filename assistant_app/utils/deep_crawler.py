import io
import json
import os
import sys
import time
import xml.etree.ElementTree as ET
from pathlib import Path
from urllib.parse import urljoin, urlparse

import django
import requests
from bs4 import BeautifulSoup
from django.conf import settings
from pypdf import PdfReader

PROJECT_ROOT = Path(__file__).resolve().parents[2]
DATA_DIR = PROJECT_ROOT / "data"
DISCOVERED_URLS_PATH = DATA_DIR / "discovered_urls.json"
ENRICHED_CACHE_PATH = DATA_DIR / "enriched_cache.json"

DATA_DIR.mkdir(parents=True, exist_ok=True)


def setup_django():
    project_root = str(PROJECT_ROOT)
    if project_root not in sys.path:
        sys.path.insert(0, project_root)
    os.environ.setdefault("DJANGO_SETTINGS_MODULE", "shopping_assist.settings")
    django.setup()


def is_internal_url(candidate, base_url):
    candidate_url = urlparse(urljoin(base_url + "/", candidate))
    base = urlparse(base_url)
    return candidate_url.scheme in {"http", "https"} and candidate_url.netloc == base.netloc


def extract_pdf_text_from_url(pdf_url):
    headers = {"User-Agent": "ManuAutoAI-DatasheetParser/3.1"}
    max_bytes = int(
        getattr(settings, "AI_ASSISTANT_CRAWLER_MAX_PDF_BYTES", 15 * 1024 * 1024)
    )
    max_pages = int(getattr(settings, "AI_ASSISTANT_CRAWLER_MAX_PDF_PAGES", 80))

    try:
        print(f"   [PDF-EXTRACT] Downloading datasheet: {pdf_url}")
        response = requests.get(pdf_url, headers=headers, timeout=30)
        response.raise_for_status()
        if len(response.content) > max_bytes:
            print(f"   [PDF-SKIP] File exceeds {max_bytes} bytes.")
            return ""

        reader = PdfReader(io.BytesIO(response.content))
        pages = []
        for page_number, page in enumerate(reader.pages[:max_pages], start=1):
            page_content = page.extract_text()
            if page_content:
                pages.append(
                    f"--- Datasheet Page {page_number} ---\n{page_content.strip()}"
                )
        return "\n\n".join(pages)
    except Exception as exc:
        print(f"   [PDF-ERROR] Failed to parse PDF: {exc}")
        return ""


def _sitemap_locations(sitemap_url, base_url, headers, depth=0):
    if depth > 1:
        return set()

    response = requests.get(sitemap_url, headers=headers, timeout=20)
    response.raise_for_status()
    root = ET.fromstring(response.content)
    locations = {
        element.text.strip()
        for element in root.iter()
        if element.tag.endswith("loc") and element.text
    }

    if root.tag.endswith("sitemapindex"):
        discovered = set()
        for nested_sitemap in list(locations)[:50]:
            if is_internal_url(nested_sitemap, base_url):
                try:
                    discovered.update(
                        _sitemap_locations(
                            nested_sitemap,
                            base_url,
                            headers,
                            depth=depth + 1,
                        )
                    )
                except Exception:
                    continue
        return discovered

    return {
        location
        for location in locations
        if is_internal_url(location, base_url)
    }


def auto_discover_urls(base_url):
    print("[SPIDER] Initiating auto-discovery of site structure...")
    headers = {"User-Agent": "ManuAutoAI-Spider/3.1"}
    xml_sitemaps = [
        f"{base_url}/sitemap.xml",
        f"{base_url}/index.php?route=feed/google_sitemap",
        f"{base_url}/index.php?route=extension/feed/google_sitemap",
    ]

    for sitemap in xml_sitemaps:
        try:
            discovered_urls = _sitemap_locations(sitemap, base_url, headers)
            if discovered_urls:
                print(
                    f"[SPIDER] Extracted {len(discovered_urls)} URLs from {sitemap}."
                )
                return sorted(discovered_urls)
        except Exception:
            continue

    print("[SPIDER] XML not found. Falling back to HTML sitemap parsing...")
    discovered_urls = set()
    html_sitemap = f"{base_url}/index.php?route=information/sitemap"
    try:
        response = requests.get(html_sitemap, headers=headers, timeout=20)
        response.raise_for_status()
        soup = BeautifulSoup(response.text, "html.parser")
        for link in soup.find_all("a", href=True):
            full_url = urljoin(base_url + "/", link["href"])
            if is_internal_url(full_url, base_url):
                discovered_urls.add(full_url)
    except Exception as exc:
        print(f"[SPIDER-ERROR] Failed to parse HTML sitemap: {exc}")

    print(
        f"[SPIDER] Extracted {len(discovered_urls)} potential URLs from HTML map."
    )
    return sorted(discovered_urls)


def scrape_hyper_deep_info(url, base_url):
    deep_data = {
        "is_product": False,
        "product_id": None,
        "name": "",
        "brand": "Generic",
        "image_url": "",
        "full_description": "",
        "technical_attributes": {},
        "datasheet_content": "",
    }
    headers = {"User-Agent": "ManuAutoAI-DeepCrawler/3.1"}

    try:
        response = requests.get(url, headers=headers, timeout=20)
        response.raise_for_status()
        soup = BeautifulSoup(response.text, "html.parser")

        product_id_input = soup.find("input", {"name": "product_id"})
        if not product_id_input:
            return deep_data

        deep_data["is_product"] = True
        deep_data["product_id"] = product_id_input.get("value")

        heading = soup.find("h1")
        if heading:
            deep_data["name"] = heading.get_text(strip=True)

        brand_link = soup.find(
            "a", href=lambda href: href and "manufacturer_id" in href
        )
        if brand_link:
            deep_data["brand"] = brand_link.get_text(strip=True)
        else:
            for item in soup.find_all("li"):
                item_text = item.get_text(" ", strip=True)
                if "Brand:" in item_text:
                    deep_data["brand"] = item_text.replace("Brand:", "").strip()
                    break

        main_image = soup.find("ul", class_="thumbnails") or soup.find(
            "div", class_="image"
        )
        if main_image:
            image_link = main_image.find("a", href=True)
            if image_link:
                deep_data["image_url"] = urljoin(base_url + "/", image_link["href"])

        description = soup.find("div", id="tab-description")
        if description:
            deep_data["full_description"] = description.get_text(
                separator=" ", strip=True
            )

        specifications = soup.find("div", id="tab-specification")
        if specifications:
            for row in specifications.find_all("tr"):
                cells = row.find_all("td")
                if len(cells) != 2:
                    continue
                key = cells[0].get_text(" ", strip=True)
                value = cells[1].get_text(" ", strip=True)
                if key and value and not key.startswith(":"):
                    deep_data["technical_attributes"][key] = value

        detected_pdf_texts = []
        seen_pdf_urls = set()
        for link in soup.find_all("a", href=True):
            href = link["href"]
            normalized_href = href.lower().split("?", 1)[0]
            if not (
                normalized_href.endswith(".pdf") or "datasheet" in normalized_href
            ):
                continue

            pdf_url = urljoin(base_url + "/", href)
            if pdf_url in seen_pdf_urls or not is_internal_url(pdf_url, base_url):
                continue
            seen_pdf_urls.add(pdf_url)

            pdf_text = extract_pdf_text_from_url(pdf_url)
            if pdf_text:
                detected_pdf_texts.append(f"Source PDF: {pdf_url}\n{pdf_text}")

        if detected_pdf_texts:
            deep_data["datasheet_content"] = "\n\n================\n\n".join(
                detected_pdf_texts
            )
    except Exception as exc:
        print(f"   [SCRAPE-ERROR] Failed to scrape {url}: {exc}")

    return deep_data


def generate_dynamic_sales_angle(product_name, brand, attributes):
    combined_context = f"{product_name} {brand} {list(attributes.keys())}".lower()
    industrial_keywords = (
        "switch",
        "moxa",
        "port",
        "industrial",
        "ethernet",
        "serial",
        "modbus",
        "converter",
        "rail",
    )
    specs_summary = (
        ", ".join(f"{key}: {value}" for key, value in list(attributes.items())[:2])
        if attributes
        else ""
    )
    feature_suffix = (
        f" with technical specs [{specs_summary}]" if specs_summary else ""
    )

    if any(keyword in combined_context for keyword in industrial_keywords):
        return (
            "Industrial grade reliability engineered for harsh environments, "
            "ensuring zero packet loss and maximum deployment uptime"
            f"{feature_suffix}."
        )
    return (
        "High-performance option optimized for seamless productivity and "
        f"exceptional ecosystem synergy{feature_suffix}."
    )


def save_atomically(data, path):
    path = Path(path)
    temp_path = path.with_suffix(path.suffix + ".tmp")
    with temp_path.open("w", encoding="utf-8") as output:
        json.dump(data, output, ensure_ascii=False, indent=2)
    temp_path.replace(path)


def load_json(path, default):
    try:
        with Path(path).open("r", encoding="utf-8") as source:
            return json.load(source)
    except (FileNotFoundError, json.JSONDecodeError):
        return default


def build_vector_payload(products, base_url):
    payload = []
    for product in products:
        specifications = " | ".join(
            f"{key}: {value}"
            for key, value in product.get("technical_attributes", {}).items()
        )
        product_url = (
            f"{base_url}/index.php?route=product/product"
            f"&product_id={product.get('product_id')}"
        )
        payload.append(
            {
                "source": product_url,
                "type": "product_page",
                "content": "\n".join(
                    [
                        f"Product Name: {product.get('name', '')}",
                        f"Brand: {product.get('brand', '')}",
                        f"Model ID: {product.get('product_id', '')}",
                        f"Sales Position: {product.get('sales_angle', '')}",
                        f"Description: {product.get('full_description', '')}",
                        f"Technical Specifications: {specifications}",
                    ]
                ),
            }
        )
        if product.get("datasheet_content"):
            payload.append(
                {
                    "source": product_url + " (Embedded Datasheet)",
                    "type": "datasheet_pdf",
                    "content": product["datasheet_content"],
                }
            )
    return payload


def run_pipeline(force_refresh=False):
    setup_django()
    from assistant_app.utils.vector_handler import ManuAutoVectorStore

    base_url = (
        getattr(settings, "OPENCART_BASE_URL", "")
        or os.getenv("OPENCART_BASE_URL", "")
    ).rstrip("/")
    if not base_url:
        raise RuntimeError("OPENCART_BASE_URL must be configured before crawling.")

    urls_list = [] if force_refresh else load_json(DISCOVERED_URLS_PATH, [])
    if not urls_list:
        urls_list = auto_discover_urls(base_url)
        save_atomically(urls_list, DISCOVERED_URLS_PATH)
    if not urls_list:
        raise RuntimeError("The crawler could not discover any OpenCart URLs.")

    cache = {} if force_refresh else load_json(ENRICHED_CACHE_PATH, {})
    total_urls = len(urls_list)
    print(f"[PIPELINE] Starting deep inspection of {total_urls} URLs...")

    for count, url in enumerate(urls_list, start=1):
        url_key = urlparse(url).path + "?" + urlparse(url).query
        if url_key in cache:
            continue

        print(f"[{count}/{total_urls}] Inspecting: {url}")
        scraped_data = scrape_hyper_deep_info(url, base_url)
        if not scraped_data["is_product"]:
            cache[url_key] = {"is_product": False}
        else:
            scraped_data["sales_angle"] = generate_dynamic_sales_angle(
                scraped_data["name"],
                scraped_data["brand"],
                scraped_data["technical_attributes"],
            )
            cache[url_key] = scraped_data
            print(
                "   [SUCCESS] Extracted Product: "
                f"{scraped_data['name']} (ID: {scraped_data['product_id']})"
            )

        if count % 10 == 0:
            save_atomically(cache, ENRICHED_CACHE_PATH)
        time.sleep(float(getattr(settings, "AI_ASSISTANT_CRAWLER_DELAY", 0.5)))

    save_atomically(cache, ENRICHED_CACHE_PATH)
    products = [
        data for data in cache.values() if data.get("is_product") is True
    ]
    vector_payload = build_vector_payload(products, base_url)

    print(
        f"[VECTOR-PHASE] Injecting {len(vector_payload)} sources into Vector Brain..."
    )
    indexed_chunks = ManuAutoVectorStore().inject_knowledge_base(
        vector_payload,
        namespace="deep_crawler",
        replace_namespace=True,
    )
    print(
        "[ALL-DONE] ManuAuto Vector Brain synchronized: "
        f"{len(products)} products, {indexed_chunks} chunks."
    )
    return {
        "products": len(products),
        "sources": len(vector_payload),
        "chunks": indexed_chunks,
    }


if __name__ == "__main__":
    run_pipeline(force_refresh="--force" in sys.argv)
