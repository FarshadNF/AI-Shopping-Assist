<?php
namespace Opencart\Catalog\Controller\Extension\AiShoppingAssist\Module;

class AiShoppingAssist extends \Opencart\System\Engine\Controller {
	private const VERSION = '3.3.0';
	private const MARKER = '<!-- AI_SHOPPING_ASSIST_WIDGET -->';

	public function inject(&$route, &$data, &$output = null): void {
		if (!is_string($output) || $output === '') {
			return;
		}

		if (!$this->config->get('module_ai_shopping_assist_status') || !$this->config->get('module_ai_shopping_assist_footer_injection')) {
			return;
		}

		if (strpos($output, self::MARKER) !== false) {
			return;
		}

		$asset_base = 'extension/ai_shopping_assist/catalog/view/javascript/ai-shopping-assist/';
		$widget_title = (string)$this->config->get('module_ai_shopping_assist_widget_title');
		$widget_button = (string)$this->config->get('module_ai_shopping_assist_widget_button');

		if ($widget_title === '' || $widget_title === 'دستیار هوشمند خرید') {
			$widget_title = 'Rockford Assistant';
		}

		if ($widget_button === '' || $widget_button === 'دستیار خرید') {
			$widget_button = 'Chat';
		}

		$config = [
			'chatRoute' => 'index.php?route=extension/ai_shopping_assist/module/ai_shopping_assist.chat',
			'catalogRoute' => 'index.php?route=extension/ai_shopping_assist/module/ai_shopping_assist.getCatalog',
			'cartRoute' => 'index.php?route=checkout/cart',
			'productRoute' => 'index.php?route=product/product',
			'searchRoute' => 'index.php?route=product/search',
			'cartInfoRoute' => 'index.php?route=extension/ai_shopping_assist/module/ai_shopping_assist.getCart',
			'cartActionRoute' => 'index.php?route=extension/ai_shopping_assist/module/ai_shopping_assist.cartAction',
			'checkoutRoute' => 'index.php?route=checkout/checkout',
			'couponRoute' => 'index.php?route=extension/opencart/total/coupon.save',
			'invoiceRoute' => 'index.php?route=extension/ai_shopping_assist/module/ai_shopping_assist.sendInvoice',
			'title' => $widget_title,
			'buttonText' => $widget_button,
			'avatarUrl' => $asset_base . 'rockford-mas.png?v=' . self::VERSION,
			'iconUrl' => $asset_base . 'rockford-icon.png?v=' . self::VERSION,
			'redirectDelayMs' => 700
		];

		$config_json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$version = self::VERSION;

		$block = self::MARKER . "\n"
			. '<link rel="stylesheet" href="' . $asset_base . 'widget.css?v=' . $version . '">' . "\n"
			. '<script>window.AI_SHOPPING_ASSIST_CONFIG = Object.assign({}, window.AI_SHOPPING_ASSIST_CONFIG || {}, ' . $config_json . ');</script>' . "\n"
			. '<script src="' . $asset_base . 'widget.js?v=' . $version . '" defer></script>' . "\n";

		if (stripos($output, '</body>') !== false) {
			$output = preg_replace('~</body>~i', $block . '</body>', $output, 1);
			return;
		}

		$output .= "\n" . $block;
	}

	public function chat(): void {
		if (!$this->config->get('module_ai_shopping_assist_status')) {
			$this->outputJson(['status' => 'error', 'reply' => 'AI Shopping Assist is disabled.'], 403);
			return;
		}

		$input = $this->getInputData();
		$message = trim((string)($input['message'] ?? ''));

		if ($message === '') {
			$this->outputJson(['status' => 'error', 'reply' => 'Message is required.'], 400);
			return;
		}

		$message_length = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);

		if ($message_length > 2000) {
			$message = function_exists('mb_substr') ? mb_substr($message, 0, 2000) : substr($message, 0, 2000);
		}

		$conversation_id = (string)($input['conversation_id'] ?? $this->getLocalConversationId());
		$this->writeChatLog($conversation_id, 'user', $message);

		$lead = $this->parseBulkLeadSubmission($message);

		if ($lead) {
			$missing_fields = $this->missingBulkLeadFields($lead);
			$reply = '';

			if ($missing_fields) {
				$reply = $this->bulkLeadMissingMessage($missing_fields);
			} else {
				$this->sendBulkLeadToWebhook($lead, $conversation_id, $input);
				$reply = $this->localizedText(
					$message,
					'Thanks. Your bulk order request has been received. Our sales team will contact you shortly.',
					'ممنون. درخواست خرید عمده شما ثبت شد و تیم فروش به‌زودی با شما تماس می‌گیرد.'
				);
			}

			$this->writeChatLog($conversation_id, 'assistant', $reply);
			$this->outputJson([
				'status' => 'success',
				'reply' => $reply,
				'conversation_id' => $conversation_id
			]);
			return;
		}

		if ($this->isBulkLeadRequest($message) && !$this->isBulkLeadSubmission($message)) {
			$reply = $this->bulkLeadMessage();
			$this->writeChatLog($conversation_id, 'assistant', $reply);
			$this->outputJson([
				'status' => 'success',
				'reply' => $reply,
				'conversation_id' => $conversation_id
			]);
			return;
		}

		$result = $this->askGemini($message, $conversation_id);

		if ($result['error']) {
			$this->writeChatLog($conversation_id, 'assistant', 'Gemini error: ' . $result['error']);
			$this->outputJson([
				'status' => 'error',
				'reply' => $this->localizedText($message, 'Assistant service is not configured or unavailable.', 'سرویس دستیار تنظیم نشده یا در دسترس نیست.'),
				'conversation_id' => $conversation_id
			], $result['status'] ?: 502);
			return;
		}

		$response_data = $this->buildAssistantResponse($message, $conversation_id, $result['body']);
		$this->writeChatLog($conversation_id, 'assistant', (string)$response_data['reply']);

		$this->outputJson($response_data);
	}

	public function getCatalog(): void {
		if (!$this->isCatalogRequestAllowed()) {
			$this->outputJson(['success' => false, 'error' => 'Invalid catalog token.'], 403);
			return;
		}

		$page = max(1, (int)($this->request->get['page'] ?? 1));
		$limit = min(200, max(1, (int)($this->request->get['limit'] ?? 100)));
		$start = ($page - 1) * $limit;

		$this->load->model('catalog/product');

		$filter_data = [
			'sort' => 'name',
			'order' => 'ASC',
			'start' => $start,
			'limit' => $limit
		];

		$total = (int)$this->model_catalog_product->getTotalProducts([]);
		$results = $this->model_catalog_product->getProducts($filter_data);
		$products = [];

		foreach ($results as $result) {
			$products[] = $this->normalizeCatalogProduct($result);
		}

		$this->outputJson([
			'success' => true,
			'total' => $total,
			'page' => $page,
			'limit' => $limit,
			'products' => $products,
			'data' => $products
		]);
	}

	public function getCart(): void {
		$items = [];

		foreach ($this->getCartProducts() as $product) {
			$items[] = [
				'key' => (string)($product['cart_id'] ?? $product['key'] ?? ''),
				'product_id' => (string)($product['product_id'] ?? ''),
				'name' => $this->cleanText((string)($product['name'] ?? '')),
				'quantity' => (int)($product['quantity'] ?? 1),
				'price' => $this->formatCurrency((float)($product['price'] ?? 0)),
				'total' => $this->formatCurrency((float)($product['total'] ?? 0))
			];
		}

		$totals = $this->getCartTotals();

		$this->outputJson([
			'success' => true,
			'products' => $items,
			'items' => $items,
			'totals' => $totals['totals'],
			'total' => $totals['total']
		]);
	}

	public function cartAction(): void {
		$input = $this->getInputData();
		$action = (string)($input['action'] ?? '');
		$key = (int)($input['key'] ?? 0);
		$product_id = (int)($input['product_id'] ?? 0);
		$quantity = max(1, (int)($input['quantity'] ?? 1));

		if (!$this->config->get('module_ai_shopping_assist_status')) {
			$this->outputJson(['success' => false, 'error' => 'AI Shopping Assist is disabled.'], 403);
			return;
		}

		if (!$key && $product_id) {
			$key = $this->findCartIdByProductId($product_id);
		}

		switch ($action) {
			case 'update':
				if (!$key) {
					$this->outputJson(['success' => false, 'error' => 'Cart item was not found.'], 404);
					return;
				}

				$this->cart->update($key, $quantity);
				$this->resetCheckoutState();
				$this->outputJson(['success' => true]);
				return;

			case 'remove':
				if (!$key) {
					$this->outputJson(['success' => false, 'error' => 'Cart item was not found.'], 404);
					return;
				}

				$this->cart->remove($key);
				$this->resetCheckoutState();
				$this->outputJson(['success' => true]);
				return;

			case 'clear':
				$this->cart->clear();
				$this->resetCheckoutState();
				$this->outputJson(['success' => true]);
				return;

			case 'coupon':
				$code = trim((string)($input['code'] ?? ''));

				if ($code === '') {
					$this->outputJson(['success' => false, 'error' => 'Coupon code is required.'], 400);
					return;
				}

				$this->session->data['coupon'] = $code;
				$this->resetCheckoutState();
				$this->outputJson(['success' => true]);
				return;
		}

		$this->outputJson(['success' => false, 'error' => 'Unknown cart action.'], 400);
	}

	public function sendInvoice(): void {
		$input = $this->getInputData();
		$conversation_id = (string)($input['conversation_id'] ?? $this->getLocalConversationId());
		$email = trim((string)($input['email'] ?? ''));
		$invoice_type = trim((string)($input['invoice_type'] ?? 'invoice'));
		$note = trim((string)($input['note'] ?? ''));

		$this->writeChatLog($conversation_id, 'invoice', json_encode([
			'email' => $email,
			'invoice_type' => $invoice_type,
			'note' => $note
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

		$this->outputJson(['success' => true, 'message' => 'Invoice request recorded.']);
	}

	private function askGemini(string $message, string $conversation_id): array {
		$api_keys = $this->getGeminiApiKeys();

		if (!$api_keys) {
			return ['status' => 500, 'body' => '', 'error' => 'Gemini API keys are missing.'];
		}

		$model = trim((string)$this->config->get('module_ai_shopping_assist_gemini_model')) ?: 'gemini-2.5-flash';
		$model_path = strpos($model, 'models/') === 0 ? $model : 'models/' . $model;
		$url = 'https://generativelanguage.googleapis.com/v1beta/' . $model_path . ':generateContent';

		$temperature = (float)($this->config->get('module_ai_shopping_assist_gemini_temperature') ?: 0.3);
		$temperature = min(1, max(0, $temperature));

		$payload = [
			'contents' => [
				[
					'role' => 'user',
					'parts' => [
						['text' => $this->buildGeminiPrompt($message, $conversation_id)]
					]
				]
			],
			'generationConfig' => [
				'temperature' => $temperature,
				'responseMimeType' => 'application/json'
			]
		];

		$last_error = ['status' => 502, 'body' => '', 'error' => 'Gemini request failed.'];

		foreach ($api_keys as $api_key) {
			$result = $this->postJson($url, $payload, ['x-goog-api-key: ' . $api_key]);

			if ($result['error']) {
				$last_error = $result;
				continue;
			}

			if ($result['status'] >= 400) {
				$error_payload = json_decode($result['body'], true);
				$error_message = $error_payload['error']['message'] ?? ('Gemini HTTP ' . $result['status']);
				$last_error = ['status' => $result['status'], 'body' => $result['body'], 'error' => $error_message];
				continue;
			}

			$response = json_decode($result['body'], true);
			$text = '';

			if (isset($response['candidates'][0]['content']['parts']) && is_array($response['candidates'][0]['content']['parts'])) {
				foreach ($response['candidates'][0]['content']['parts'] as $part) {
					if (isset($part['text'])) {
						$text .= (string)$part['text'];
					}
				}
			}

			if ($text === '') {
				$last_error = ['status' => 502, 'body' => $result['body'], 'error' => 'Gemini returned no text.'];
				continue;
			}

			return ['status' => 200, 'body' => $text, 'error' => ''];
		}

		return $last_error;
	}

	private function getGeminiApiKeys(): array {
		$raw = (string)$this->config->get('module_ai_shopping_assist_gemini_api_key');
		$keys = preg_split('/[\r\n,]+/', $raw);
		$normalized = [];

		foreach ($keys as $key) {
			$key = trim($key);

			if ($key !== '' && !in_array($key, $normalized, true)) {
				$normalized[] = $key;
			}
		}

		return $normalized;
	}

	private function buildGeminiPrompt(string $message, string $conversation_id): string {
		$brand = (string)($this->config->get('module_ai_shopping_assist_store_brand') ?: $this->config->get('config_name'));
		$assistant_name = (string)($this->config->get('module_ai_shopping_assist_assistant_name') ?: 'Rockford Assistant');
		$catalog = $this->getPromptCatalog($message);
		$knowledge = $this->getLocalKnowledge($message);
		$navigation = $this->getNavigationPromptCatalog();
		$history = $this->getConversationHistory($conversation_id, $message);

		return implode("\n\n", [
			'You are a real online sales assistant inside the OpenCart store "' . $brand . '". Your name is "' . $assistant_name . '".',
			'Default to English. If the latest user message is clearly Persian or another language, you may reply in that language, but your base persona and concise style are English-first.',
			'Use the live product catalog as the authority for price, stock, product IDs, and purchase links. Use the local knowledge base for descriptions, technical specifications, and datasheet facts. Keep replies short, natural, and sales-focused.',
			'When the user wants an action, include it in actions. Supported action types: add_to_cart, show_cart, redirect_to_cart, redirect_to_product, redirect_to_page, update_cart_item, remove_from_cart, clear_cart, apply_coupon, redirect_to_checkout, send_invoice.',
			'For bulk, wholesale, B2B, corporate, or high-quantity requests, do not add items to cart and do not send the user to checkout. Ask for lead details using this exact field list: Product Name, QTY, Name, Company, Contact Number, Email, Delivery Location.',
			'For navigating to any non-product site page, use {"type":"redirect_to_page","page":"home/contact/account/login/register/orders/wishlist/specials/search/category/information page name","route":"optional OpenCart route","url":"optional internal URL"}. Do not say you cannot navigate.',
			'Return ONLY valid JSON with this shape: {"reply":"visible message","suggestions":[{"title":"Product Recommendation","text":"Can you recommend a laptop?"}],"products":[{"product_id":"id","name":"exact catalog name","reason":"Why this is a fit"}],"actions":[{"type":"add_to_cart","product_name":"exact name","product_id":"id","qty":1}]} .',
			'Use suggestions for helpful next questions. Use product cards when recommending, comparing, showing specs, or discussing specific products. Use an empty array for suggestions/products/actions when not needed.',
			'Conversation history JSON: ' . json_encode($history, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			'Known site pages JSON: ' . json_encode($navigation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			'Product catalog JSON: ' . json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			'Relevant local knowledge JSON: ' . json_encode($knowledge, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			'Latest user message: ' . $message
		]);
	}

	private function getNavigationPromptCatalog(): array {
		$pages = [
			['page' => 'home / صفحه اصلی', 'route' => 'common/home'],
			['page' => 'cart / سبد خرید', 'route' => 'checkout/cart'],
			['page' => 'checkout / پرداخت', 'route' => 'checkout/checkout'],
			['page' => 'account / حساب کاربری', 'route' => 'account/account'],
			['page' => 'login / ورود', 'route' => 'account/login'],
			['page' => 'register / ثبت نام', 'route' => 'account/register'],
			['page' => 'wishlist / علاقه مندی ها', 'route' => 'account/wishlist'],
			['page' => 'orders / سفارشات', 'route' => 'account/order'],
			['page' => 'contact / تماس با ما', 'route' => 'information/contact'],
			['page' => 'sitemap / نقشه سایت', 'route' => 'information/sitemap'],
			['page' => 'search / جستجو', 'route' => 'product/search'],
			['page' => 'brands / برندها', 'route' => 'product/manufacturer'],
			['page' => 'specials / تخفیف ها', 'route' => 'product/special']
		];

		try {
			$information_query = $this->db->query("
				SELECT `i`.`information_id`, `id`.`title`
				FROM `" . DB_PREFIX . "information` `i`
				LEFT JOIN `" . DB_PREFIX . "information_description` `id` ON (`i`.`information_id` = `id`.`information_id`)
				LEFT JOIN `" . DB_PREFIX . "information_to_store` `i2s` ON (`i`.`information_id` = `i2s`.`information_id`)
				WHERE `i`.`status` = '1'
				AND `id`.`language_id` = '" . (int)$this->config->get('config_language_id') . "'
				AND `i2s`.`store_id` = '" . (int)$this->config->get('config_store_id') . "'
				LIMIT 50
			");

			foreach ($information_query->rows as $row) {
				$pages[] = [
					'page' => (string)$row['title'],
					'route' => 'information/information',
					'id' => (int)$row['information_id']
				];
			}
		} catch (\Throwable $exception) {
		}

		try {
			$category_query = $this->db->query("
				SELECT `c`.`category_id`, `cd`.`name`
				FROM `" . DB_PREFIX . "category` `c`
				LEFT JOIN `" . DB_PREFIX . "category_description` `cd` ON (`c`.`category_id` = `cd`.`category_id`)
				LEFT JOIN `" . DB_PREFIX . "category_to_store` `c2s` ON (`c`.`category_id` = `c2s`.`category_id`)
				WHERE `c`.`status` = '1'
				AND `cd`.`language_id` = '" . (int)$this->config->get('config_language_id') . "'
				AND `c2s`.`store_id` = '" . (int)$this->config->get('config_store_id') . "'
				LIMIT 80
			");

			foreach ($category_query->rows as $row) {
				$pages[] = [
					'page' => (string)$row['name'],
					'route' => 'product/category',
					'category_id' => (int)$row['category_id']
				];
			}
		} catch (\Throwable $exception) {
		}

		return $pages;
	}

	private function getPromptCatalog(string $message): array {
		$this->load->model('catalog/product');

		$limit = (int)($this->config->get('module_ai_shopping_assist_catalog_limit') ?: 80);
		$limit = min(200, max(5, $limit));
		$rows = [];

		if (trim($message) !== '') {
			try {
				$rows = $this->model_catalog_product->getProducts([
					'filter_search' => $message,
					'sort' => 'name',
					'order' => 'ASC',
					'start' => 0,
					'limit' => $limit
				]);
			} catch (\Throwable $exception) {
				$rows = [];
			}
		}

		if (!$rows) {
			$rows = $this->model_catalog_product->getProducts([
				'sort' => 'name',
				'order' => 'ASC',
				'start' => 0,
				'limit' => $limit
			]);
		}

		$products = [];

		foreach ($rows as $row) {
			$product = $this->normalizeCatalogProduct($row);
			$products[] = [
				'product_id' => $product['product_id'],
				'name' => $product['name'],
				'product_url' => $product['product_url'],
				'price' => $product['price'],
				'stock' => $product['stock'],
				'category' => $product['category'],
				'image' => $product['image'],
				'summary' => $this->shortText($product['sales_angle'], 260),
				'attributes' => array_slice($product['attributes'], 0, 12, true)
			];
		}

		return $products;
	}

	private function getLocalKnowledge(string $message): array {
		$query_text = $this->foldText($message);
		$tokens = $this->knowledgeTokens($query_text);

		if ($query_text === '' || !$tokens) {
			return [];
		}

		try {
			$query = $this->db->query("
				SELECT
					`product_id`,
					`name`,
					`brand`,
					`source_url`,
					`content_type`,
					LEFT(`content`, 60000) AS `content`
				FROM `" . DB_PREFIX . "ai_shopping_assist_knowledge`
				ORDER BY `knowledge_id` ASC
				LIMIT 1000
			");
		} catch (\Throwable $exception) {
			return [];
		}

		$ranked = [];

		foreach ($query->rows as $row) {
			$name = $this->foldText((string)($row['name'] ?? ''));
			$brand = $this->foldText((string)($row['brand'] ?? ''));
			$product_id = $this->foldText((string)($row['product_id'] ?? ''));
			$source_url = $this->foldText((string)($row['source_url'] ?? ''));
			$content = $this->foldText((string)($row['content'] ?? ''));
			$score = 0;

			if ($product_id !== '' && in_array($product_id, $tokens, true)) {
				$score += 120;
			}

			if (strlen($query_text) >= 4 && ($name === $query_text || strpos($name, $query_text) !== false)) {
				$score += 90;
			}

			foreach ($tokens as $token) {
				if (strpos($name, $token) !== false) {
					$score += 30;
				}

				if ($brand !== '' && strpos($brand, $token) !== false) {
					$score += 18;
				}

				if ($source_url !== '' && strpos($source_url, $token) !== false) {
					$score += 12;
				}

				if (strpos($content, $token) !== false) {
					$score += 3;
				}
			}

			if ($score < 8) {
				continue;
			}

			$row['_score'] = $score;
			$ranked[] = $row;
		}

		usort($ranked, function (array $left, array $right): int {
			return (int)$right['_score'] <=> (int)$left['_score'];
		});

		$results = [];
		$total_length = 0;

		foreach (array_slice($ranked, 0, 5) as $row) {
			$content = $this->shortText((string)$row['content'], 6000);
			$total_length += strlen($content);

			if ($total_length > 20000) {
				break;
			}

			$results[] = [
				'product_id' => (string)$row['product_id'],
				'name' => (string)$row['name'],
				'brand' => (string)$row['brand'],
				'content_type' => (string)$row['content_type'],
				'source_url' => (string)$row['source_url'],
				'content' => $content
			];
		}

		return $results;
	}

	private function knowledgeTokens(string $value): array {
		preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}_.-]*/u', $value, $matches);
		$stopwords = [
			'the', 'and', 'for', 'with', 'from', 'this', 'that', 'what', 'which', 'about',
			'product', 'products', 'tell', 'show', 'need', 'want', 'have', 'your', 'please',
			'این', 'آن', 'برای', 'با', 'از', 'در', 'چیست', 'چیه', 'محصول', 'محصولات',
			'لطفا', 'لطفاً', 'میخوام', 'می‌خواهم', 'دارید', 'درباره'
		];
		$tokens = [];

		foreach ($matches[0] ?? [] as $token) {
			$length = function_exists('mb_strlen') ? mb_strlen($token, 'UTF-8') : strlen($token);
			$has_digit = (bool)preg_match('/\p{N}/u', $token);

			if (($length < 3 && !$has_digit) || in_array($token, $stopwords, true) || in_array($token, $tokens, true)) {
				continue;
			}

			$tokens[] = $token;
		}

		return array_slice($tokens, 0, 20);
	}

	private function getConversationHistory(string $conversation_id, string $latest_message): array {
		try {
			$query = $this->db->query("
				SELECT `role`, `content`
				FROM `" . DB_PREFIX . "ai_shopping_assist_chat_log`
				WHERE `conversation_id` = '" . $this->db->escape(substr($conversation_id, 0, 80)) . "'
				AND `role` IN ('user', 'assistant')
				ORDER BY `log_id` DESC
				LIMIT 12
			");

			$rows = array_reverse($query->rows);

			if ($rows && end($rows)['role'] === 'user' && end($rows)['content'] === $latest_message) {
				array_pop($rows);
			}

			return array_map(function ($row) {
				return [
					'role' => $row['role'] === 'assistant' ? 'assistant' : 'user',
					'content' => (string)$row['content']
				];
			}, $rows);
		} catch (\Throwable $exception) {
			return [];
		}
	}

	private function buildAssistantResponse(string $message, string $conversation_id, string $raw_text): array {
		$parsed = $this->parseAssistantJson($raw_text);
		$reply = trim((string)($parsed['reply'] ?? ''));

		if ($reply === '') {
			$reply = trim($raw_text) ?: $this->localizedText($message, 'I am ready to help.', 'آماده‌ام کمک کنم.');
		}

		if ($this->isBulkLeadRequest($message) && !$this->isBulkLeadSubmission($message)) {
			return [
				'status' => 'success',
				'reply' => $this->bulkLeadMessage(),
				'conversation_id' => $conversation_id
			];
		}

		$raw_actions = [];

		if (isset($parsed['actions']) && is_array($parsed['actions'])) {
			$raw_actions = $parsed['actions'];
		} elseif (isset($parsed['action']) && is_array($parsed['action'])) {
			$raw_actions = [$parsed['action']];
		}

		$actions = $this->normalizeAssistantActions($raw_actions);
		$suggestions = $this->normalizeSuggestions($parsed['suggestions'] ?? []);
		$products = $this->normalizeProductCards($parsed['products'] ?? ($parsed['product_cards'] ?? []));

		if (!$products) {
			$products = $this->productCardsFromActions($actions);
		}

		if (!$actions) {
			$fallback_action = $this->fallbackPageNavigationAction($message);

			if ($fallback_action) {
				$actions[] = $fallback_action;
			}
		}

		$response = [
			'status' => 'success',
			'reply' => $reply,
			'conversation_id' => $conversation_id
		];

		if ($actions) {
			$response['actions'] = $actions;
			$response['action'] = $actions[0];
		}

		if ($suggestions) {
			$response['suggestions'] = $suggestions;
		}

		if ($products) {
			$response['products'] = $products;
		}

		return $response;
	}

	private function parseAssistantJson(string $raw_text): array {
		$text = trim($raw_text);
		$decoded = json_decode($text, true);

		if (is_array($decoded)) {
			return $decoded;
		}

		$start = strpos($text, '{');
		$end = strrpos($text, '}');

		if ($start !== false && $end !== false && $end > $start) {
			$decoded = json_decode(substr($text, $start, $end - $start + 1), true);

			if (is_array($decoded)) {
				return $decoded;
			}
		}

		return ['reply' => $text, 'actions' => []];
	}

	private function bulkLeadMessage(): string {
		return "For bulk orders, please share these details:\n\nProduct Name:\nQTY:\nName:\nCompany:\nContact Number:\nEmail:\nDelivery Location:";
	}

	private function parseBulkLeadSubmission(string $message): array {
		$aliases = [
			'product name' => 'product_name',
			'product' => 'product_name',
			'نام محصول' => 'product_name',
			'محصول' => 'product_name',
			'qty' => 'qty',
			'quantity' => 'qty',
			'تعداد' => 'qty',
			'name' => 'name',
			'full name' => 'name',
			'نام' => 'name',
			'company' => 'company',
			'company name' => 'company',
			'شرکت' => 'company',
			'نام شرکت' => 'company',
			'contact number' => 'contact_number',
			'phone' => 'contact_number',
			'mobile' => 'contact_number',
			'tel' => 'contact_number',
			'شماره تماس' => 'contact_number',
			'موبایل' => 'contact_number',
			'تلفن' => 'contact_number',
			'email' => 'email',
			'ایمیل' => 'email',
			'delivery location' => 'delivery_location',
			'delivery address' => 'delivery_location',
			'address' => 'delivery_location',
			'آدرس' => 'delivery_location',
			'محل تحویل' => 'delivery_location',
			'آدرس تحویل' => 'delivery_location'
		];
		$lead = [
			'product_name' => '',
			'qty' => '',
			'name' => '',
			'company' => '',
			'contact_number' => '',
			'email' => '',
			'delivery_location' => ''
		];
		$matched_fields = 0;
		$lines = preg_split('/\R+/', $message) ?: [];

		foreach ($lines as $line) {
			if (!preg_match('/^\s*([^:=]+?)\s*[:=]\s*(.*?)\s*$/u', (string)$line, $match)) {
				continue;
			}

			$label = $this->normalizePageKey((string)$match[1]);
			$value = trim((string)$match[2]);

			if (!isset($aliases[$label])) {
				continue;
			}

			$key = $aliases[$label];
			$lead[$key] = $this->shortText($value, $key === 'delivery_location' ? 500 : 180);
			$matched_fields++;
		}

		return $matched_fields >= 3 ? $lead : [];
	}

	private function missingBulkLeadFields(array $lead): array {
		$labels = $this->bulkLeadFieldLabels();
		$missing = [];

		foreach ($labels as $key => $label) {
			if (trim((string)($lead[$key] ?? '')) === '') {
				$missing[] = $label;
			}
		}

		return $missing;
	}

	private function bulkLeadMissingMessage(array $missing_fields): string {
		return "Thanks. Please complete these missing fields so our sales team can quote you:\n\n- " . implode("\n- ", $missing_fields);
	}

	private function bulkLeadFieldLabels(): array {
		return [
			'product_name' => 'Product Name',
			'qty' => 'QTY',
			'name' => 'Name',
			'company' => 'Company',
			'contact_number' => 'Contact Number',
			'email' => 'Email',
			'delivery_location' => 'Delivery Location'
		];
	}

	private function sendBulkLeadToWebhook(array $lead, string $conversation_id, array $input): bool {
		$url = trim((string)$this->config->get('module_ai_shopping_assist_lead_webhook_url'));

		if ($url === '' || stripos($url, 'https://') !== 0) {
			if ($url !== '') {
				$this->log->write('AI Shopping Assist lead webhook ignored because URL must start with https://');
			}

			return false;
		}

		$page_context = is_array($input['page_context'] ?? null) ? $input['page_context'] : [];
		$payload = [
			'secret' => (string)$this->config->get('module_ai_shopping_assist_lead_webhook_secret'),
			'event' => 'bulk_lead',
			'store' => (string)$this->config->get('config_name'),
			'conversation_id' => $conversation_id,
			'customer_id' => $this->customer->isLogged() ? (int)$this->customer->getId() : 0,
			'page_url' => (string)($page_context['url'] ?? ''),
			'page_title' => (string)($page_context['title'] ?? ''),
			'ip' => (string)($this->request->server['REMOTE_ADDR'] ?? ''),
			'user_agent' => substr((string)($this->request->server['HTTP_USER_AGENT'] ?? ''), 0, 255),
			'date_added' => date('c'),
			'lead' => $lead
		];

		$result = $this->postJson($url, $payload, [], 12);
		$ok = $result['status'] >= 200 && $result['status'] < 300 && $result['error'] === '';

		if (!$ok) {
			$this->log->write('AI Shopping Assist lead webhook failed: HTTP ' . (int)$result['status'] . ' ' . $result['error'] . ' ' . substr((string)$result['body'], 0, 500));
		}

		return $ok;
	}

	private function isBulkLeadRequest(string $message): bool {
		$text = $this->normalizePageKey($message);

		if ($text === '') {
			return false;
		}

		$bulk_words = [
			'bulk',
			'wholesale',
			'b2b',
			'corporate order',
			'large order',
			'large quantity',
			'high quantity',
			'many units',
			'volume order',
			'reseller',
			'quotation',
			'quote for',
			'proforma',
			'عمده',
			'خرید عمده',
			'تعداد بالا',
			'تعداد زیاد',
			'خرید سازمانی',
			'سفارش سازمانی',
			'همکاری',
			'پیش فاکتور',
			'پیش‌فاکتور'
		];

		foreach ($bulk_words as $word) {
			if (strpos($text, $this->normalizePageKey($word)) !== false) {
				return true;
			}
		}

		return (bool)preg_match('/(?:qty|quantity|units|unit|pcs|pieces|عدد|دستگاه|تا)\s*[:=]?\s*(\d{2,})/iu', $text)
			|| (bool)preg_match('/(\d{2,})\s*(?:qty|quantity|units|unit|pcs|pieces|عدد|دستگاه|تا)/iu', $text);
	}

	private function isBulkLeadSubmission(string $message): bool {
		return (bool)$this->parseBulkLeadSubmission($message);
	}

	private function normalizeSuggestions($raw_suggestions): array {
		if (!is_array($raw_suggestions)) {
			return [];
		}

		$suggestions = [];

		foreach ($raw_suggestions as $suggestion) {
			if (is_string($suggestion)) {
				$text = trim($suggestion);
				$title = '';
			} elseif (is_array($suggestion)) {
				$text = trim((string)($suggestion['text'] ?? $suggestion['message'] ?? $suggestion['prompt'] ?? ''));
				$title = trim((string)($suggestion['title'] ?? $suggestion['label'] ?? ''));
			} else {
				continue;
			}

			if ($text === '') {
				continue;
			}

			$suggestions[] = [
				'title' => $this->shortText($title, 70),
				'text' => $this->shortText($text, 180)
			];

			if (count($suggestions) >= 4) {
				break;
			}
		}

		return $suggestions;
	}

	private function normalizeProductCards($raw_products): array {
		if (!is_array($raw_products)) {
			return [];
		}

		$cards = [];
		$seen = [];

		foreach ($raw_products as $raw_product) {
			if (is_string($raw_product)) {
				$raw_product = ['name' => $raw_product];
			}

			if (!is_array($raw_product)) {
				continue;
			}

			$product = $this->findCatalogProduct(
				(string)($raw_product['name'] ?? $raw_product['product_name'] ?? ''),
				(string)($raw_product['product_id'] ?? $raw_product['id'] ?? '')
			);

			if (!$product) {
				continue;
			}

			$key = $product['product_id'] ?: $product['name'];

			if (isset($seen[$key])) {
				continue;
			}

			$seen[$key] = true;
			$reason = trim((string)($raw_product['reason'] ?? $raw_product['summary'] ?? $raw_product['description'] ?? $product['sales_angle']));

			$cards[] = [
				'product_id' => $product['product_id'],
				'name' => $product['name'],
				'product_url' => $product['product_url'],
				'price' => $product['price'],
				'stock' => $product['stock'],
				'image' => $product['image'],
				'category' => $product['category'],
				'summary' => $this->shortText($reason, 360),
				'attributes' => array_slice($product['attributes'], 0, 6, true)
			];

			if (count($cards) >= 4) {
				break;
			}
		}

		return $cards;
	}

	private function productCardsFromActions(array $actions): array {
		$raw_products = [];

		foreach ($actions as $action) {
			if (!in_array($action['type'] ?? '', ['add_to_cart', 'redirect_to_product', 'update_cart_item', 'remove_from_cart'])) {
				continue;
			}

			$raw_products[] = [
				'product_id' => $action['product_id'] ?? '',
				'name' => $action['product_name'] ?? '',
				'reason' => ''
			];
		}

		return $this->normalizeProductCards($raw_products);
	}

	private function fallbackPageNavigationAction(string $message): array {
		$text = $this->normalizePageKey($message);
		$navigation_words = [
			'go',
			'open',
			'navigate',
			'take me',
			'show',
			'برو',
			'بریم',
			'ببر',
			'باز کن',
			'نشون بده',
			'نمایش بده',
			'هدایت کن'
		];

		$has_navigation_intent = false;

		foreach ($navigation_words as $word) {
			if (strpos($text, $this->normalizePageKey($word)) !== false) {
				$has_navigation_intent = true;
				break;
			}
		}

		if (!$has_navigation_intent) {
			return [];
		}

		foreach ($this->knownPageAliases() as $alias) {
			if (strpos($text, $this->normalizePageKey($alias)) !== false) {
				$resolved = $this->resolveSitePage($alias);

				if ($resolved) {
					return [
						'type' => 'redirect_to_page',
						'page' => $resolved['page'],
						'route' => $resolved['route'],
						'url' => $resolved['url']
					];
				}
			}
		}

		$category_hint = $this->extractAfterAny($message, ['category', 'دسته بندی', 'دسته‌بندی']);

		if ($category_hint !== '') {
			$resolved = $this->resolveSitePage($category_hint);

			if ($resolved) {
				return [
					'type' => 'redirect_to_page',
					'page' => $resolved['page'],
					'route' => $resolved['route'],
					'url' => $resolved['url']
				];
			}
		}

		return [];
	}

	private function normalizeAssistantActions(array $raw_actions): array {
		$actions = [];

		foreach ($raw_actions as $raw_action) {
			if (!is_array($raw_action)) {
				continue;
			}

			$type = strtolower(trim((string)($raw_action['type'] ?? $raw_action['action'] ?? '')));

			if ($type === '' && !empty($raw_action['product_id'])) {
				$type = 'add_to_cart';
			}

			if (in_array($type, ['navigate_to_page', 'open_page', 'go_to_page', 'redirect'])) {
				$type = 'redirect_to_page';
			}

			if (!in_array($type, ['add_to_cart', 'show_cart', 'redirect_to_cart', 'redirect_to_product', 'redirect_to_page', 'update_cart_item', 'remove_from_cart', 'clear_cart', 'apply_coupon', 'redirect_to_checkout', 'send_invoice'])) {
				continue;
			}

			$action = ['type' => $type];

			if (in_array($type, ['add_to_cart', 'redirect_to_product', 'update_cart_item', 'remove_from_cart'])) {
				$product = $this->findCatalogProduct((string)($raw_action['product_name'] ?? ''), (string)($raw_action['product_id'] ?? ''));

				if ($product) {
					$action['product_name'] = $product['name'];
					$action['product_id'] = $product['product_id'];
					$action['product_url'] = $product['product_url'];
					$action['price'] = $product['price'];
					$action['stock'] = $product['stock'];
					$action['image'] = $product['image'];
				} else {
					$action['product_name'] = (string)($raw_action['product_name'] ?? '');
					$action['product_id'] = (string)($raw_action['product_id'] ?? '');
				}
			}

			if (in_array($type, ['add_to_cart', 'update_cart_item'])) {
				$action['requested_qty'] = max(1, (int)($raw_action['requested_qty'] ?? $raw_action['qty'] ?? $raw_action['quantity'] ?? 1));
			}

			if ($type === 'redirect_to_page') {
				$page = trim((string)($raw_action['page'] ?? $raw_action['page_name'] ?? $raw_action['target'] ?? ''));
				$route = trim((string)($raw_action['route'] ?? ''));
				$url = trim((string)($raw_action['url'] ?? $raw_action['href'] ?? ''));
				$resolved = $this->resolveSitePage($page, $route, $url);

				if (!$resolved) {
					continue;
				}

				$action['page'] = $resolved['page'];
				$action['route'] = $resolved['route'];
				$action['url'] = $resolved['url'];
			}

			if ($type === 'apply_coupon') {
				$action['code'] = trim((string)($raw_action['code'] ?? $raw_action['coupon'] ?? ''));
			}

			if ($type === 'send_invoice') {
				$action['email'] = trim((string)($raw_action['email'] ?? ''));
				$action['invoice_type'] = trim((string)($raw_action['invoice_type'] ?? 'invoice')) ?: 'invoice';
				$action['note'] = trim((string)($raw_action['note'] ?? ''));
			}

			$actions[] = $action;
		}

		return $actions;
	}

	private function findCatalogProduct(string $product_name = '', string $product_id = ''): array {
		$this->load->model('catalog/product');

		if ($product_id !== '') {
			$product = $this->model_catalog_product->getProduct((int)$product_id);

			if ($product) {
				return $this->normalizeCatalogProduct($product);
			}
		}

		$product_name = trim($product_name);

		if ($product_name === '') {
			return [];
		}

		$candidates = [];

		try {
			$candidates = $this->model_catalog_product->getProducts([
				'filter_search' => $product_name,
				'sort' => 'name',
				'order' => 'ASC',
				'start' => 0,
				'limit' => 20
			]);
		} catch (\Throwable $exception) {
			$candidates = [];
		}

		if (!$candidates) {
			$candidates = $this->model_catalog_product->getProducts([
				'sort' => 'name',
				'order' => 'ASC',
				'start' => 0,
				'limit' => 100
			]);
		}

		$best = [];
		$best_score = -1;

		foreach ($candidates as $candidate) {
			$name = (string)($candidate['name'] ?? '');
			$score = $this->matchScore($product_name, $name);

			if ($score > $best_score) {
				$best_score = $score;
				$best = $candidate;
			}
		}

		return $best ? $this->normalizeCatalogProduct($best) : [];
	}

	private function resolveSitePage(string $page = '', string $route = '', string $url = ''): array {
		$page = trim($page);
		$route = trim($route);
		$url = trim($url);

		if ($url !== '') {
			$internal_url = $this->internalUrl($url);

			if ($internal_url !== '') {
				return [
					'page' => $page ?: $url,
					'route' => '',
					'url' => $internal_url
				];
			}
		}

		if ($route !== '') {
			$route_target = $this->openCartUrlFromRouteSpec($route);

			if ($route_target) {
				return [
					'page' => $page ?: $route_target['route'],
					'route' => $route_target['route'],
					'url' => $route_target['url']
				];
			}
		}

		$route = $this->routeForKnownPage($page);

		if ($route !== '') {
			return [
				'page' => $page,
				'route' => $route,
				'url' => html_entity_decode($this->url->link($route), ENT_QUOTES, 'UTF-8')
			];
		}

		$information = $this->findInformationPage($page);

		if ($information) {
			return [
				'page' => $information['title'],
				'route' => 'information/information',
				'url' => html_entity_decode($this->url->link('information/information', 'information_id=' . (int)$information['information_id']), ENT_QUOTES, 'UTF-8')
			];
		}

		$category = $this->findCategoryPage($page);

		if ($category) {
			return [
				'page' => $category['name'],
				'route' => 'product/category',
				'url' => html_entity_decode($this->url->link('product/category', 'path=' . (int)$category['category_id']), ENT_QUOTES, 'UTF-8')
			];
		}

		if ($page !== '') {
			return [
				'page' => $page,
				'route' => 'product/search',
				'url' => html_entity_decode($this->url->link('product/search', 'search=' . urlencode($page)), ENT_QUOTES, 'UTF-8')
			];
		}

		return [];
	}

	private function routeForKnownPage(string $page): string {
		$key = $this->normalizePageKey($page);
		$map = $this->knownPageRouteMap();

		return $map[$key] ?? '';
	}

	private function knownPageAliases(): array {
		return array_keys($this->knownPageRouteMap());
	}

	private function knownPageRouteMap(): array {
		return [
			'home' => 'common/home',
			'homepage' => 'common/home',
			'mainpage' => 'common/home',
			'index' => 'common/home',
			'خانه' => 'common/home',
			'صفحه اصلی' => 'common/home',
			'هوم' => 'common/home',
			'هوم پیج' => 'common/home',
			'cart' => 'checkout/cart',
			'basket' => 'checkout/cart',
			'سبد خرید' => 'checkout/cart',
			'checkout' => 'checkout/checkout',
			'payment' => 'checkout/checkout',
			'پرداخت' => 'checkout/checkout',
			'تسویه حساب' => 'checkout/checkout',
			'account' => 'account/account',
			'my account' => 'account/account',
			'حساب کاربری' => 'account/account',
			'پنل کاربری' => 'account/account',
			'login' => 'account/login',
			'ورود' => 'account/login',
			'register' => 'account/register',
			'signup' => 'account/register',
			'ثبت نام' => 'account/register',
			'logout' => 'account/logout',
			'خروج' => 'account/logout',
			'wishlist' => 'account/wishlist',
			'favorites' => 'account/wishlist',
			'علاقه مندی' => 'account/wishlist',
			'علاقه مندی ها' => 'account/wishlist',
			'orders' => 'account/order',
			'order history' => 'account/order',
			'سفارش ها' => 'account/order',
			'سفارشات' => 'account/order',
			'returns' => 'account/returns',
			'مرجوعی' => 'account/returns',
			'downloads' => 'account/download',
			'دانلودها' => 'account/download',
			'newsletter' => 'account/newsletter',
			'خبرنامه' => 'account/newsletter',
			'contact' => 'information/contact',
			'contact us' => 'information/contact',
			'تماس' => 'information/contact',
			'تماس با ما' => 'information/contact',
			'sitemap' => 'information/sitemap',
			'site map' => 'information/sitemap',
			'نقشه سایت' => 'information/sitemap',
			'search' => 'product/search',
			'جستجو' => 'product/search',
			'brands' => 'product/manufacturer',
			'manufacturers' => 'product/manufacturer',
			'برندها' => 'product/manufacturer',
			'تولیدکنندگان' => 'product/manufacturer',
			'specials' => 'product/special',
			'special offers' => 'product/special',
			'تخفیف ها' => 'product/special',
			'پیشنهاد ویژه' => 'product/special',
			'compare' => 'product/compare',
			'مقایسه' => 'product/compare',
			'voucher' => 'checkout/voucher',
			'gift voucher' => 'checkout/voucher',
			'کارت هدیه' => 'checkout/voucher',
			'affiliate' => 'account/affiliate',
			'همکاری در فروش' => 'account/affiliate'
		];
	}

	private function findInformationPage(string $page): array {
		$page = trim($page);

		if ($page === '') {
			return [];
		}

		try {
			$query = $this->db->query("
				SELECT `i`.`information_id`, `id`.`title`
				FROM `" . DB_PREFIX . "information` `i`
				LEFT JOIN `" . DB_PREFIX . "information_description` `id` ON (`i`.`information_id` = `id`.`information_id`)
				LEFT JOIN `" . DB_PREFIX . "information_to_store` `i2s` ON (`i`.`information_id` = `i2s`.`information_id`)
				WHERE `i`.`status` = '1'
				AND `id`.`language_id` = '" . (int)$this->config->get('config_language_id') . "'
				AND `i2s`.`store_id` = '" . (int)$this->config->get('config_store_id') . "'
			");

			return $this->bestNamedRow($page, $query->rows, 'title');
		} catch (\Throwable $exception) {
			return [];
		}
	}

	private function findCategoryPage(string $page): array {
		$page = trim($page);

		if ($page === '') {
			return [];
		}

		try {
			$query = $this->db->query("
				SELECT `c`.`category_id`, `cd`.`name`
				FROM `" . DB_PREFIX . "category` `c`
				LEFT JOIN `" . DB_PREFIX . "category_description` `cd` ON (`c`.`category_id` = `cd`.`category_id`)
				LEFT JOIN `" . DB_PREFIX . "category_to_store` `c2s` ON (`c`.`category_id` = `c2s`.`category_id`)
				WHERE `c`.`status` = '1'
				AND `cd`.`language_id` = '" . (int)$this->config->get('config_language_id') . "'
				AND `c2s`.`store_id` = '" . (int)$this->config->get('config_store_id') . "'
			");

			return $this->bestNamedRow($page, $query->rows, 'name');
		} catch (\Throwable $exception) {
			return [];
		}
	}

	private function bestNamedRow(string $needle, array $rows, string $field): array {
		$best = [];
		$best_score = 0;

		foreach ($rows as $row) {
			$score = $this->matchScore($needle, (string)($row[$field] ?? ''));

			if ($score > $best_score) {
				$best_score = $score;
				$best = $row;
			}
		}

		return $best_score >= 45 ? $best : [];
	}

	private function openCartUrlFromRouteSpec(string $route_spec): array {
		$route_spec = trim(html_entity_decode($route_spec, ENT_QUOTES, 'UTF-8'));
		$route_spec = preg_replace('/^index\.php\?route=/i', '', $route_spec);
		$args = '';

		if (strpos($route_spec, '?') !== false) {
			[$route, $args] = explode('?', $route_spec, 2);
		} elseif (strpos($route_spec, '&') !== false) {
			[$route, $args] = explode('&', $route_spec, 2);
		} else {
			$route = $route_spec;
		}

		$route = trim($route);
		$args = trim($args);

		if (!preg_match('/^[a-z0-9_\/.]+$/i', $route)) {
			return [];
		}

		if ($args !== '' && !preg_match('/^[a-z0-9_=&%.+\-\[\]]+$/i', $args)) {
			return [];
		}

		return [
			'route' => $route,
			'url' => html_entity_decode($this->url->link($route, $args), ENT_QUOTES, 'UTF-8')
		];
	}

	private function internalUrl(string $url): string {
		$url = trim(html_entity_decode($url, ENT_QUOTES, 'UTF-8'));

		if ($url === '') {
			return '';
		}

		if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
			return $url;
		}

		if (stripos($url, 'index.php?route=') === 0) {
			$route_target = $this->openCartUrlFromRouteSpec($url);
			return $route_target['url'] ?? '';
		}

		$parts = parse_url($url);

		if (!$parts || empty($parts['host'])) {
			return '';
		}

		$store_parts = parse_url(HTTP_SERVER);
		$store_host = $store_parts['host'] ?? '';

		if (strtolower($parts['host']) !== strtolower($store_host)) {
			return '';
		}

		return $url;
	}

	private function normalizePageKey(string $page): string {
		$page = $this->foldText($page);
		$page = str_replace(['‌', '-', '_'], ' ', $page);
		return trim(preg_replace('/\s+/u', ' ', $page));
	}

	private function extractAfterAny(string $text, array $markers): string {
		$folded = $this->normalizePageKey($text);

		foreach ($markers as $marker) {
			$marker = $this->normalizePageKey($marker);
			$position = strpos($folded, $marker);

			if ($position !== false) {
				return trim(substr($folded, $position + strlen($marker)));
			}
		}

		return '';
	}

	private function matchScore(string $needle, string $haystack): int {
		$needle = $this->foldText($needle);
		$haystack = $this->foldText($haystack);

		if ($needle === '' || $haystack === '') {
			return 0;
		}

		if ($needle === $haystack) {
			return 1000;
		}

		if (strpos($haystack, $needle) !== false || strpos($needle, $haystack) !== false) {
			return 700;
		}

		similar_text($needle, $haystack, $percent);
		return (int)$percent;
	}

	private function foldText(string $text): string {
		$text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
		$text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
		return trim(preg_replace('/\s+/u', ' ', $text));
	}

	private function normalizeCatalogProduct(array $product): array {
		$product_id = (int)($product['product_id'] ?? 0);
		$price = isset($product['special']) && $product['special'] !== null && $product['special'] !== ''
			? (float)$product['special']
			: (float)($product['price'] ?? 0);

		return [
			'product_id' => (string)$product_id,
			'name' => $this->cleanText((string)($product['name'] ?? '')),
			'product_url' => $this->productUrl($product_id),
			'price' => $this->formatProductPrice($price, (int)($product['tax_class_id'] ?? 0)),
			'stock' => (int)($product['quantity'] ?? 0),
			'category' => $this->cleanText((string)($product['manufacturer'] ?? ($product['model'] ?? 'General'))),
			'brand' => $this->cleanText((string)($product['manufacturer'] ?? '')),
			'attributes' => $this->catalogAttributes($product),
			'sales_angle' => $this->cleanText((string)($product['description'] ?? '')),
			'full_description' => $this->cleanText((string)($product['description'] ?? '')),
			'image' => $this->productImage((string)($product['image'] ?? '')),
			'alternatives' => []
		];
	}

	private function catalogAttributes(array $product): array {
		$attributes = [];

		foreach (['model', 'sku', 'mpn', 'ean', 'jan', 'isbn', 'upc', 'location'] as $field) {
			if (!empty($product[$field])) {
				$attributes[ucfirst($field)] = $this->cleanText((string)$product[$field]);
			}
		}

		$product_id = (int)($product['product_id'] ?? 0);

		if ($product_id && method_exists($this->model_catalog_product, 'getAttributes')) {
			try {
				foreach ($this->model_catalog_product->getAttributes($product_id) as $group) {
					foreach (($group['attribute'] ?? []) as $attribute) {
						$name = $this->cleanText((string)($attribute['name'] ?? ''));
						$text = $this->cleanText((string)($attribute['text'] ?? ''));

						if ($name !== '' && $text !== '') {
							$attributes[$name] = $text;
						}
					}
				}
			} catch (\Throwable $exception) {
				return $attributes;
			}
		}

		return $attributes;
	}

	private function productImage(string $image): string {
		if ($image === '') {
			return '';
		}

		try {
			$this->load->model('tool/image');
			return (string)$this->model_tool_image->resize($image, 300, 300);
		} catch (\Throwable $exception) {
			return '';
		}
	}

	private function productUrl(int $product_id): string {
		if (!$product_id) {
			return '';
		}

		return html_entity_decode($this->url->link('product/product', 'product_id=' . $product_id), ENT_QUOTES, 'UTF-8');
	}

	private function getCartProducts(): array {
		try {
			$this->load->model('checkout/cart');
			return $this->model_checkout_cart->getProducts();
		} catch (\Throwable $exception) {
			return $this->cart->getProducts();
		}
	}

	private function getCartTotals(): array {
		$totals = [];
		$total = 0.0;

		try {
			$this->load->model('checkout/cart');
			$taxes = $this->cart->getTaxes();
			$this->model_checkout_cart->getTotals($totals, $taxes, $total);
		} catch (\Throwable $exception) {
			$total = (float)$this->cart->getTotal();
		}

		$formatted_totals = [];

		foreach ($totals as $total_row) {
			$formatted_totals[] = [
				'title' => $this->cleanText((string)($total_row['title'] ?? '')),
				'value' => (float)($total_row['value'] ?? 0),
				'text' => $this->formatCurrency((float)($total_row['value'] ?? 0))
			];
		}

		return [
			'totals' => $formatted_totals,
			'total' => $this->formatCurrency((float)$total)
		];
	}

	private function findCartIdByProductId(int $product_id): int {
		foreach ($this->cart->getProducts() as $product) {
			if ((int)($product['product_id'] ?? 0) === $product_id) {
				return (int)($product['cart_id'] ?? 0);
			}
		}

		return 0;
	}

	private function resetCheckoutState(): void {
		foreach (['order_id', 'shipping_method', 'shipping_methods', 'payment_method', 'payment_methods', 'reward'] as $key) {
			unset($this->session->data[$key]);
		}
	}

	private function isCatalogRequestAllowed(): bool {
		$token = trim((string)$this->config->get('module_ai_shopping_assist_catalog_token'));

		if ($token === '') {
			return true;
		}

		return hash_equals($token, $this->getHeader('X-AI-Assistant-Token'));
	}

	private function getHeader(string $name): string {
		$key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
		return (string)($this->request->server[$key] ?? '');
	}

	private function getInputData(): array {
		$raw = file_get_contents('php://input');

		if (is_string($raw) && trim($raw) !== '') {
			$decoded = json_decode($raw, true);

			if (is_array($decoded)) {
				return $decoded;
			}
		}

		return is_array($this->request->post) ? $this->request->post : [];
	}

	private function postJson(string $url, array $payload, array $headers = [], int $timeout = 35): array {
		$body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$request_headers = array_merge(['Accept: application/json', 'Content-Type: application/json'], $headers);
		$timeout = max(5, min(60, $timeout));

		if (function_exists('curl_init')) {
			$handle = curl_init($url);
			curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($handle, CURLOPT_POST, true);
			curl_setopt($handle, CURLOPT_HTTPHEADER, $request_headers);
			curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
			curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, min(10, $timeout));
			curl_setopt($handle, CURLOPT_TIMEOUT, $timeout);

			if (!ini_get('open_basedir')) {
				curl_setopt($handle, CURLOPT_FOLLOWLOCATION, true);
			}

			$response = curl_exec($handle);
			$error = curl_error($handle);
			$status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
			curl_close($handle);

			return [
				'status' => $status ?: 502,
				'body' => is_string($response) ? $response : '',
				'error' => $error ?: ''
			];
		}

		$context = stream_context_create([
			'http' => [
				'method' => 'POST',
				'header' => implode("\r\n", $request_headers) . "\r\n",
				'content' => $body,
				'timeout' => $timeout,
				'ignore_errors' => true
			]
		]);

		$response = @file_get_contents($url, false, $context);
		$status = 502;

		if (isset($http_response_header[0]) && preg_match('~\s(\d{3})\s~', $http_response_header[0], $match)) {
			$status = (int)$match[1];
		}

		if (!is_string($response)) {
			$error = error_get_last();
			return [
				'status' => $status,
				'body' => '',
				'error' => (string)($error['message'] ?? 'HTTP request failed')
			];
		}

		return ['status' => $status, 'body' => $response, 'error' => ''];
	}

	private function writeChatLog(string $conversation_id, string $role, string $content): void {
		if ($content === '') {
			return;
		}

		try {
			$session_id = method_exists($this->session, 'getId') ? (string)$this->session->getId() : '';
			$customer_id = $this->customer->isLogged() ? (int)$this->customer->getId() : 0;
			$ip = (string)($this->request->server['REMOTE_ADDR'] ?? '');
			$user_agent = substr((string)($this->request->server['HTTP_USER_AGENT'] ?? ''), 0, 255);

			$this->db->query("
				INSERT INTO `" . DB_PREFIX . "ai_shopping_assist_chat_log`
				SET
					`conversation_id` = '" . $this->db->escape(substr($conversation_id, 0, 80)) . "',
					`session_id` = '" . $this->db->escape(substr($session_id, 0, 128)) . "',
					`customer_id` = '" . (int)$customer_id . "',
					`role` = '" . $this->db->escape(substr($role, 0, 20)) . "',
					`content` = '" . $this->db->escape($content) . "',
					`ip` = '" . $this->db->escape(substr($ip, 0, 45)) . "',
					`user_agent` = '" . $this->db->escape($user_agent) . "',
					`date_added` = NOW()
			");
		} catch (\Throwable $exception) {
			$this->log->write('AI Shopping Assist log failed: ' . $exception->getMessage());
		}
	}

	private function getLocalConversationId(): string {
		if (empty($this->session->data['ai_shopping_assist_conversation_id'])) {
			try {
				$this->session->data['ai_shopping_assist_conversation_id'] = bin2hex(random_bytes(16));
			} catch (\Throwable $exception) {
				$this->session->data['ai_shopping_assist_conversation_id'] = md5(uniqid('', true));
			}
		}

		return (string)$this->session->data['ai_shopping_assist_conversation_id'];
	}

	private function formatProductPrice(float $price, int $tax_class_id): string {
		$taxed = $this->tax->calculate($price, $tax_class_id, $this->config->get('config_tax'));
		return $this->formatCurrency((float)$taxed);
	}

	private function localizedText(string $source, string $english, string $persian): string {
		$rtl_count = preg_match_all('/[\x{0590}-\x{08ff}]/u', $source, $rtl_matches);
		$latin_count = preg_match_all('/[A-Za-z]/', $source, $latin_matches);

		return $rtl_count > $latin_count ? $persian : $english;
	}

	private function formatCurrency(float $value): string {
		$currency = (string)($this->session->data['currency'] ?? $this->config->get('config_currency'));
		return $this->currency->format($value, $currency);
	}

	private function cleanText(string $value): string {
		return trim(html_entity_decode(strip_tags($value), ENT_QUOTES, 'UTF-8'));
	}

	private function shortText(string $value, int $limit): string {
		$value = trim(preg_replace('/\s+/u', ' ', $this->cleanText($value)));

		if ($value === '' || $limit <= 0) {
			return $value;
		}

		if (function_exists('mb_strlen') && function_exists('mb_substr')) {
			return mb_strlen($value, 'UTF-8') > $limit ? rtrim(mb_substr($value, 0, $limit, 'UTF-8')) . '...' : $value;
		}

		return strlen($value) > $limit ? rtrim(substr($value, 0, $limit)) . '...' : $value;
	}

	private function outputJson(array $json, int $status = 200): void {
		if ($status !== 200) {
			$this->response->addHeader(($this->request->server['SERVER_PROTOCOL'] ?? 'HTTP/1.1') . ' ' . $status . ' ' . $this->statusText($status));
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	private function statusText(int $status): string {
		$map = [
			400 => 'Bad Request',
			403 => 'Forbidden',
			404 => 'Not Found',
			500 => 'Internal Server Error',
			502 => 'Bad Gateway',
			503 => 'Service Unavailable',
			504 => 'Gateway Timeout'
		];

		return $map[$status] ?? 'OK';
	}
}
