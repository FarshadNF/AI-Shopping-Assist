<?php
class ControllerExtensionModuleRoko extends Controller {
	private const VERSION = '3.4.0-ui-refresh';
	private const MARKER = '<!-- ROKO_WIDGET -->';
	private const GEMINI_MAX_OUTPUT_TOKENS = 1024;
	private const MAX_RESPONSE_PRODUCTS = 3;
	private const MAX_RESPONSE_SUGGESTIONS = 3;
	private const PROMPT_CATALOG_HARD_LIMIT = 24;
	private const CONVERSATION_HISTORY_LIMIT = 8;
	private const SITEMAP_CACHE_TTL = 86400;
	private const SITEMAP_PAGE_CACHE_TTL = 604800;
	private const SITEMAP_CRAWL_MAX_PAGES = 50000;
	private const SITEMAP_CRAWL_BATCH = 6;
	private const SITEMAP_RELEVANT_CONTEXT_LIMIT = 8;
	private const SITEMAP_PAGE_TEXT_LIMIT = 3500;
	private const REMOTE_TEXT_LIMIT = 25000000;
	private static $sitemap_pages_cache = [];
	private static $sitemap_content_cache = [];
	private static $last_remote_error = '';

	public function inject(&$route, &$data, &$output = null): void {
		if (!is_string($output) || $output === '') {
			return;
		}

		if (!$this->config->get('module_roko_status') || !$this->config->get('module_roko_footer_injection')) {
			return;
		}

		if (strpos($output, self::MARKER) !== false) {
			return;
		}

		$asset_base = 'catalog/view/javascript/roko/';
		$widget_title = (string)$this->config->get('module_roko_widget_title');
		$widget_button = (string)$this->config->get('module_roko_widget_button');

		if ($widget_title === '' || $widget_title === 'دستیار هوشمند خرید') {
			$widget_title = 'ROKO';
		}

		if ($widget_button === '' || $widget_button === 'دستیار خرید') {
			$widget_button = 'Chat';
		}

		$config = [
			'apiBase' => '',
			'chatRoute' => 'index.php?route=extension/module/roko/chat',
			'catalogRoute' => 'index.php?route=extension/module/roko/getCatalog',
			'cartRoute' => 'index.php?route=checkout/cart',
			'productRoute' => 'index.php?route=product/product',
			'searchRoute' => 'index.php?route=product/search',
			'cartInfoRoute' => 'index.php?route=extension/module/roko/getCart',
			'cartActionRoute' => 'index.php?route=extension/module/roko/cartAction',
			'checkoutRoute' => 'index.php?route=checkout/checkout',
			'couponRoute' => 'index.php?route=extension/total/coupon/coupon',
			'invoiceRoute' => 'index.php?route=extension/module/roko/sendInvoice',
			'redirectLogRoute' => 'index.php?route=extension/module/roko/logRedirect',
			'redirectUtm' => trim((string)$this->config->get('module_roko_redirect_utm')),
			'showNextQuestionSuggestions' => $this->suggestionSettingEnabled('module_roko_suggest_next_questions'),
			'showBlogSuggestions' => $this->suggestionSettingEnabled('module_roko_suggest_blogs'),
			'showCategorySuggestions' => $this->suggestionSettingEnabled('module_roko_suggest_categories'),
			'showProductSuggestions' => $this->suggestionSettingEnabled('module_roko_suggest_products'),
			'title' => $widget_title,
			'buttonText' => $widget_button,
			'avatarUrl' => $asset_base . 'roko-character.png?v=' . self::VERSION,
			'iconUrl' => $asset_base . 'roko-icon.png?v=' . self::VERSION,
			'redirectDelayMs' => 700
		];

		$config_json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$version = self::VERSION;

		$block = self::MARKER . "\n"
			. '<link rel="stylesheet" href="' . $asset_base . 'widget.css?v=' . $version . '">' . "\n"
			. '<script>window.ROKO_CONFIG = Object.assign({}, window.ROKO_CONFIG || {}, ' . $config_json . ');</script>' . "\n"
			. '<script src="' . $asset_base . 'widget.js?v=' . $version . '" defer></script>' . "\n";

		if (stripos($output, '</body>') !== false) {
			$output = preg_replace('~</body>~i', $block . '</body>', $output, 1);
			return;
		}

		$output .= "\n" . $block;
	}

	public function chat(): void {
		if (!$this->config->get('module_roko_status')) {
			$this->outputJson(['status' => 'error', 'reply' => 'ROKO is disabled.'], 403);
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
			$response_status = 'success';

			if ($missing_fields) {
				$reply = $this->bulkLeadMissingMessage($missing_fields);
			} else {
				$stored = $this->writeLeadRecord($lead, $conversation_id, $input);
				$this->writeChatLog($conversation_id, 'lead', json_encode([
					'stored_locally' => $stored,
					'lead' => $lead
				], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
				if ($stored) {
					$reply = $this->localizedText(
						$message,
						'Thanks. Your bulk order request has been received. Our sales team will contact you shortly.',
						'ممنون. درخواست خرید عمده شما ثبت شد و تیم فروش به‌زودی با شما تماس می‌گیرد.'
					);
				} else {
					$response_status = 'error';
					$reply = $this->localizedText(
						$message,
						'Your request could not be saved. Please try again after the lead storage is repaired in the ROKO admin panel.',
						'درخواست شما ذخیره نشد. لطفاً بعد از ترمیم جدول لیدها در پنل مدیریت ROKO دوباره تلاش کنید.'
					);
				}
			}

			$this->writeChatLog($conversation_id, 'assistant', $reply);
			$this->outputJson([
				'status' => $response_status,
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

		$result = $this->askGemini($message, $conversation_id, is_array($input['page_context'] ?? null) ? $input['page_context'] : []);

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

	public function warmSitemap(): void {
		if (!$this->isCatalogRequestAllowed()) {
			$this->outputJson(['success' => false, 'error' => 'Invalid catalog token.'], 403);
			return;
		}

		$default_limit = $this->hasUploadedSitemap() ? 80 : 120;
		$limit = min(self::SITEMAP_CRAWL_MAX_PAGES, max(1, (int)($this->request->get['limit'] ?? $default_limit)));
		$force_refresh = !empty($this->request->get['refresh']);
		$result = $this->warmSitemapContent('', $limit, $force_refresh);

		unset($result['content']);
		$output = array_merge([
			'success' => true,
			'sitemap_url' => $this->configuredSitemapUrl(),
			'sitemap_source' => $this->hasUploadedSitemap() ? 'uploaded_xml' : 'url',
			'fetch_error' => self::$last_remote_error
		], $result);

		if (!empty($this->request->get['debug'])) {
			$output['debug'] = $this->getSitemapDebug();
		}

		$this->outputJson($output);
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

		if (!$this->config->get('module_roko_status')) {
			$this->outputJson(['success' => false, 'error' => 'ROKO is disabled.'], 403);
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

	public function logRedirect(): void {
		if (!$this->config->get('module_roko_status')) {
			$this->outputJson(['status' => 'error', 'message' => 'ROKO is disabled.'], 403);
			return;
		}

		$input = $this->getInputData();
		$conversation_id = substr(trim((string)($input['conversation_id'] ?? $this->getLocalConversationId())), 0, 80);
		$action_type = substr(trim((string)($input['action_type'] ?? 'redirect')), 0, 40);
		$source_url = $this->limitLogUrl((string)($input['source_url'] ?? ''));
		$destination_url = $this->limitLogUrl((string)($input['destination_url'] ?? ''));
		$destination_url_utm = $this->limitLogUrl((string)($input['destination_url_utm'] ?? ''));
		$utm_payload = substr(trim((string)($input['utm_payload'] ?? (string)$this->config->get('module_roko_redirect_utm'))), 0, 500);

		if ($destination_url === '' && $destination_url_utm === '') {
			$this->outputJson(['status' => 'error', 'message' => 'Destination URL is required.'], 400);
			return;
		}

		$this->writeRedirectLog($conversation_id, $action_type, $source_url, $destination_url, $destination_url_utm, $utm_payload);
		$this->outputJson(['status' => 'success']);
	}

	private function askGemini(string $message, string $conversation_id, array $page_context = []): array {
		$api_keys = $this->getGeminiApiKeys();

		if (!$api_keys) {
			return ['status' => 500, 'body' => '', 'error' => 'Gemini API keys are missing.'];
		}

		$model = trim((string)$this->config->get('module_roko_gemini_model')) ?: 'gemini-2.5-flash';
		$model_path = strpos($model, 'models/') === 0 ? $model : 'models/' . $model;
		$url = 'https://generativelanguage.googleapis.com/v1beta/' . $model_path . ':generateContent';

		$temperature = (float)($this->config->get('module_roko_gemini_temperature') ?: 0.3);
		$temperature = min(1, max(0, $temperature));
		$generation_config = [
			'temperature' => $temperature,
			'responseMimeType' => 'application/json',
			'maxOutputTokens' => self::GEMINI_MAX_OUTPUT_TOKENS
		];

		if (preg_match('/^gemini-2\.5-flash(?:-lite)?(?:$|-)/', $model)) {
			$generation_config['thinkingConfig'] = [
				'thinkingBudget' => 0
			];
		}

		$payload = [
			'contents' => [
				[
					'role' => 'user',
					'parts' => [
						['text' => $this->buildGeminiPrompt($message, $conversation_id, $page_context)]
					]
				]
			],
			'generationConfig' => $generation_config
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

			$finish_reason = strtoupper((string)($response['candidates'][0]['finishReason'] ?? ''));

			if ($finish_reason === 'MAX_TOKENS') {
				$this->log->write('ROKO Gemini response was truncated at the output token limit.');
				return ['status' => 502, 'body' => '', 'error' => 'Gemini response was truncated.'];
			}

			return ['status' => 200, 'body' => $text, 'error' => ''];
		}

		return $last_error;
	}

	private function getGeminiApiKeys(): array {
		$raw = (string)$this->config->get('module_roko_gemini_api_key');
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

	private function buildGeminiPrompt(string $message, string $conversation_id, array $page_context = []): string {
		$brand = (string)($this->config->get('module_roko_store_brand') ?: $this->config->get('config_name'));
		$assistant_name = (string)($this->config->get('module_roko_assistant_name') ?: 'ROKO');
		$current_page = $this->resolveCurrentPageContext($page_context);
		$catalog = $this->getPromptCatalog($message);
		$navigation = $this->getNavigationPromptCatalog();
		$site_content = $this->getRelevantSiteContent($message, $current_page);
		$history = $this->getConversationHistory($conversation_id, $message);
		$sitemap_url = $this->configuredSitemapUrl();
		$custom_system_prompt = $this->customSystemPrompt();
		$suggestion_controls = [
			'next_question_suggestions' => $this->suggestionSettingEnabled('module_roko_suggest_next_questions'),
			'blog_and_page_cards' => $this->suggestionSettingEnabled('module_roko_suggest_blogs'),
			'category_cards' => $this->suggestionSettingEnabled('module_roko_suggest_categories'),
			'product_cards' => $this->suggestionSettingEnabled('module_roko_suggest_products')
		];

		return implode("\n\n", [
			'You are a real online sales assistant inside the OpenCart store "' . $brand . '". Your name is "' . $assistant_name . '".',
			$custom_system_prompt !== '' ? "Store-specific system prompt:\n" . $custom_system_prompt : '',
			'Default to English. If the latest user message is clearly Persian or another language, you may reply in that language, but your base persona and concise style are English-first.',
			'Use only the product catalog below for product-specific claims. Check stock before recommending purchase. Keep replies short, natural, and sales-focused.',
			'If Current page context JSON is present, treat it as the exact page the user is viewing right now. When they ask to summarize "this page/article/post", ask about what they are reading, or refer to the current page, answer from Current page context first.',
			'Use Relevant crawled site pages for blog, article, category, and general site knowledge. For technical/networking questions, prefer relevant blog/article pages when they answer the question.',
			'When the user wants an action, include it in actions. Supported action types: enquire, show_cart, redirect_to_cart, redirect_to_product, redirect_to_page, update_cart_item, remove_from_cart, clear_cart, apply_coupon, redirect_to_checkout, send_invoice.',
			'For any product purchase, quote, availability, contact-sales, or enquiry intent, use the enquire action. Never use add_to_cart and never add a product to the cart automatically.',
			'For bulk, wholesale, B2B, corporate, or high-quantity requests, use enquiry and do not send the user to checkout. Ask for lead details using this exact field list: Product Name, QTY, Name, Company, Contact Number, Email, Delivery Location.',
			'For navigating to any non-product site page, use {"type":"redirect_to_page","page":"home/contact/account/login/register/orders/wishlist/specials/search/category/information page name","route":"optional OpenCart route","url":"optional internal URL"}. Prefer exact URLs from Known site pages JSON and the configured sitemap. Do not say you cannot navigate.',
			'Suggestion output controls JSON: ' . json_encode($suggestion_controls, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '. Obey these controls exactly: when next_question_suggestions is false return suggestions as []; when a card type is false do not include that content_type in products. The blog_and_page_cards control covers blog, article, news, and page cards.',
			'Return ONLY one complete valid JSON object with this shape: {"reply":"visible message","suggestions":[{"title":"short title","text":"short next question"}],"products":[{"product_id":"id","name":"exact catalog name or article title","content_type":"product/blog/page/category","product_url":"URL only for non-product pages","reason":"short reason"}],"actions":[{"type":"enquire","product_name":"exact name","product_id":"id","qty":1}]} .',
			'Keep the JSON compact: maximum 3 suggestions and maximum 3 product/page cards; each reason must be at most 18 words. Never output image URLs. For catalog products output only product_id, name, content_type and reason because the server fills URL, image, price and stock from OpenCart. Include a blog/page card only when the user explicitly asks for an article, guide, blog or site page; ordinary product searches must return content_type="product" cards only. Use empty arrays when suggestions/products/actions are not needed, and always close the JSON object.',
			$sitemap_url !== '' ? 'Configured sitemap URL: ' . $sitemap_url : '',
			'Known site pages JSON: ' . json_encode($navigation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			'Conversation history JSON: ' . json_encode($history, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			'Current page context JSON: ' . json_encode($current_page, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			'Relevant crawled site pages JSON: ' . json_encode($site_content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			'Product catalog JSON: ' . json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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

		foreach ($this->getConfiguredSitemapPages() as $sitemap_page) {
			$pages[] = $sitemap_page;
		}

		return $this->uniqueNavigationPages($pages);
	}

	private function configuredSitemapUrl(): string {
		$url = trim((string)$this->config->get('module_roko_sitemap_url'));

		if ($url === '') {
			$url = $this->defaultSitemapUrl();
		}

		$parts = parse_url($url);

		if (!$parts || empty($parts['scheme']) || empty($parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
			return '';
		}

		return $url;
	}

	private function defaultSitemapUrl(): string {
		$base = '';

		foreach (['config_url', 'config_ssl'] as $key) {
			$value = trim((string)$this->config->get($key));

			if ($value !== '') {
				$base = $value;
				break;
			}
		}

		if ($base === '' && defined('HTTPS_SERVER')) {
			$base = (string)HTTPS_SERVER;
		}

		if ($base === '' && defined('HTTP_SERVER')) {
			$base = (string)HTTP_SERVER;
		}

		if ($base === '') {
			return '';
		}

		return rtrim($base, '/') . '/sitemap.xml';
	}

	private function customSystemPrompt(): string {
		$value = str_replace(["\r\n", "\r"], "\n", trim((string)$this->config->get('module_roko_system_prompt')));

		if ($value === '') {
			return '';
		}

		if (function_exists('mb_substr')) {
			return mb_substr($value, 0, 12000, 'UTF-8');
		}

		return substr($value, 0, 12000);
	}

	private function getConfiguredSitemapPages(bool $force_refresh = false, bool $allow_remote_fetch = false): array {
		$url = $this->configuredSitemapUrl();
		$uploaded_body = $this->getUploadedSitemapBody();
		$using_uploaded = $uploaded_body !== '';

		if (!$using_uploaded && ($url === '' || !$this->isInternalAbsoluteUrl($url))) {
			return [];
		}

		if ($url === '') {
			$url = $this->defaultSitemapUrl() ?: 'https://uploaded-sitemap.local/sitemap.xml';
		}

		$cache_key = $using_uploaded ? 'roko.sitemap.upload.v1.' . $this->uploadedSitemapCacheToken() : 'roko.sitemap.v4.' . md5($url);

		if (!$force_refresh && isset(self::$sitemap_pages_cache[$cache_key])) {
			return self::$sitemap_pages_cache[$cache_key];
		}

		if (!$force_refresh) {
			try {
				$cached = $this->cache->get($cache_key);

				if (
					is_array($cached)
					&& isset($cached['expires'], $cached['pages'])
					&& (int)$cached['expires'] > time()
					&& is_array($cached['pages'])
					&& $cached['pages']
				) {
					self::$sitemap_pages_cache[$cache_key] = $cached['pages'];
					return $cached['pages'];
				}
			} catch (\Throwable $exception) {
			}

			$file_cached = $this->readSitemapFileCache($cache_key);

			if (is_array($file_cached) && $file_cached) {
				try {
					$this->cache->set($cache_key, [
						'expires' => time() + self::SITEMAP_CACHE_TTL,
						'pages' => $file_cached
					]);
				} catch (\Throwable $exception) {
				}

				self::$sitemap_pages_cache[$cache_key] = $file_cached;
				return $file_cached;
			}
		}

		if (!$allow_remote_fetch) {
			return [];
		}

		$pages = $this->collectConfiguredSitemapPages($url, $uploaded_body);

		$pages = $this->uniqueNavigationPages(array_slice($pages, 0, self::SITEMAP_CRAWL_MAX_PAGES), self::SITEMAP_CRAWL_MAX_PAGES);

		if ($pages) {
			try {
				$this->cache->set($cache_key, [
					'expires' => time() + self::SITEMAP_CACHE_TTL,
					'pages' => $pages
				]);
			} catch (\Throwable $exception) {
			}

			$this->writeSitemapFileCache($cache_key, $pages);
			self::$sitemap_pages_cache[$cache_key] = $pages;
		}

		return $pages;
	}

	private function collectConfiguredSitemapPages(string $url, string $root_body = ''): array {
		$pages = [];
		$pending = [$url];
		$seen_sitemaps = [];

		while ($pending && count($pages) < self::SITEMAP_CRAWL_MAX_PAGES && count($seen_sitemaps) < 1000) {
			$sitemap_url = array_shift($pending);
			$sitemap_url = trim((string)$sitemap_url);

			if ($sitemap_url === '') {
				continue;
			}

			$key = strtolower($sitemap_url);

			if (isset($seen_sitemaps[$key])) {
				continue;
			}

			$seen_sitemaps[$key] = true;
			$is_root = count($seen_sitemaps) === 1;
			$body = $is_root && $root_body !== '' ? $root_body : $this->getRemoteText($sitemap_url, $is_root ? 20 : 12);

			if ($body === '') {
				continue;
			}

			$allow_uploaded_urls = $root_body !== '' && $is_root;

			foreach ($this->extractSitemapPages($body, $sitemap_url, $allow_uploaded_urls) as $page) {
				$pages[] = $page;

				if (count($pages) >= self::SITEMAP_CRAWL_MAX_PAGES) {
					break;
				}
			}

			foreach ($this->extractNestedSitemapUrls($body, $sitemap_url, $allow_uploaded_urls) as $nested_url) {
				$nested_key = strtolower($nested_url);

				if (!isset($seen_sitemaps[$nested_key])) {
					$pending[] = $nested_url;
				}
			}
		}

		return $pages;
	}

	private function hasUploadedSitemap(): bool {
		$path = $this->uploadedSitemapCachePath();
		return $path !== '' && is_file($path) && is_readable($path);
	}

	private function getUploadedSitemapBody(): string {
		$path = $this->uploadedSitemapCachePath();

		if ($path === '' || !is_file($path) || !is_readable($path)) {
			return '';
		}

		$body = file_get_contents($path);

		return is_string($body) ? substr($body, 0, self::REMOTE_TEXT_LIMIT) : '';
	}

	private function uploadedSitemapCachePath(): string {
		foreach ($this->uploadedSitemapCachePaths() as $path) {
			if (is_file($path) && is_readable($path)) {
				return $path;
			}
		}

		$paths = $this->uploadedSitemapCachePaths();
		return $paths[0] ?? '';
	}

	private function uploadedSitemapCachePaths(): array {
		$paths = [];

		if (!defined('DIR_CACHE')) {
			return [];
		}

		$paths[] = rtrim(DIR_CACHE, '/\\') . DIRECTORY_SEPARATOR . 'cache.roko.sitemap.upload.xml';

		if (defined('DIR_STORAGE')) {
			$paths[] = rtrim((string)DIR_STORAGE, '/\\') . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'cache.roko.sitemap.upload.xml';
		}

		if (defined('DIR_SYSTEM')) {
			$paths[] = rtrim(dirname(rtrim((string)DIR_SYSTEM, '/\\')), '/\\') . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'cache.roko.sitemap.upload.xml';
		}

		if (defined('DIR_APPLICATION')) {
			$paths[] = rtrim(dirname(rtrim((string)DIR_APPLICATION, '/\\')), '/\\') . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'cache.roko.sitemap.upload.xml';
		}

		return array_values(array_unique(array_filter($paths)));
	}

	private function uploadedSitemapCacheToken(): string {
		$path = $this->uploadedSitemapCachePath();

		if ($path === '' || !is_file($path)) {
			return '';
		}

		return md5($path . '|' . (int)filesize($path) . '|' . (int)filemtime($path));
	}

	private function sitemapSourceCacheKey(): string {
		if ($this->hasUploadedSitemap()) {
			return 'upload.v1.' . $this->uploadedSitemapCacheToken();
		}

		$url = $this->configuredSitemapUrl();

		return $url !== '' ? 'url.v1.' . md5($url) : '';
	}

	private function getSitemapDebug(): array {
		$url = $this->configuredSitemapUrl();
		$uploaded_path = $this->uploadedSitemapCachePath();
		$uploaded_body = $this->getUploadedSitemapBody();

		if ($url === '') {
			$url = $this->defaultSitemapUrl();
		}

		$body = $url !== '' ? $this->getRemoteText($url, 20) : '';
		$locs = [];

		if ($body !== '' && preg_match_all('~<loc>\s*(.*?)\s*</loc>~is', $body, $matches)) {
			foreach (array_slice($matches[1], 0, 20) as $raw_url) {
				$locs[] = html_entity_decode(strip_tags((string)$raw_url), ENT_QUOTES, 'UTF-8');
			}
		}

		$pages = $this->getConfiguredSitemapPages(false);
		$page_urls = [];

		foreach (array_slice($pages, 0, 20) as $page) {
			$page_urls[] = (string)($page['url'] ?? '');
		}

		return [
			'uploaded_sitemap_found' => $uploaded_path !== '' && is_file($uploaded_path),
			'uploaded_sitemap_path' => $uploaded_path,
			'uploaded_sitemap_bytes' => $uploaded_body !== '' ? strlen($uploaded_body) : 0,
			'uploaded_sitemap_loc_tags' => $uploaded_body !== '' ? preg_match_all('~<loc>~i', $uploaded_body) : 0,
			'uploaded_sitemap_candidate_paths' => $this->uploadedSitemapCachePaths(),
			'root_bytes' => $body !== '' ? strlen($body) : 0,
			'root_loc_tags' => $body !== '' ? preg_match_all('~<loc>~i', $body) : 0,
			'root_url_tags' => $body !== '' ? preg_match_all('~<url\b~i', $body) : 0,
			'root_sitemap_tags' => $body !== '' ? preg_match_all('~<sitemap\b~i', $body) : 0,
			'root_is_sitemap_index' => $body !== '' ? $this->isSitemapIndexBody($body) : false,
			'root_first_locs' => $locs,
			'parsed_page_sample' => $page_urls
		];
	}

	private function readSitemapFileCache(string $cache_key): ?array {
		$path = $this->sitemapFileCachePath($cache_key);

		if ($path === '' || !is_file($path) || time() - (int)filemtime($path) > self::SITEMAP_CACHE_TTL) {
			return null;
		}

		$payload = json_decode((string)file_get_contents($path), true);

		return is_array($payload) ? $payload : null;
	}

	private function writeSitemapFileCache(string $cache_key, array $pages): void {
		$path = $this->sitemapFileCachePath($cache_key);

		if ($path === '') {
			return;
		}

		$dir = dirname($path);

		if (!is_dir($dir) || !is_writable($dir)) {
			return;
		}

		@file_put_contents($path, json_encode($pages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	private function sitemapFileCachePath(string $cache_key): string {
		if (!defined('DIR_CACHE')) {
			return '';
		}

		return rtrim(DIR_CACHE, '/\\') . DIRECTORY_SEPARATOR . 'cache.' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $cache_key) . '.json';
	}

	private function getRelevantSiteContent(string $message, array $current_page = []): array {
		$content = $this->getCrawledSitemapContent($message);

		if (!$content) {
			return [];
		}

		$current_url_key = $this->normalizePageUrlKey((string)($current_page['url'] ?? ''));
		$ranked = [];

		foreach ($content as $entry) {
			if (!is_array($entry)) {
				continue;
			}

			if ($current_url_key !== '' && $this->normalizePageUrlKey((string)($entry['url'] ?? '')) === $current_url_key) {
				continue;
			}

			$score = $this->scoreSiteEntry($message, $entry);

			if ($score <= 0) {
				continue;
			}

			$entry['_score'] = $score;
			$ranked[] = $entry;
		}

		usort($ranked, function ($a, $b) {
			return (int)($b['_score'] ?? 0) <=> (int)($a['_score'] ?? 0);
		});

		$results = [];

		foreach (array_slice($ranked, 0, self::SITEMAP_RELEVANT_CONTEXT_LIMIT) as $entry) {
			$results[] = [
				'title' => $this->shortText((string)($entry['title'] ?? ''), 140),
				'url' => (string)($entry['url'] ?? ''),
				'content_type' => (string)($entry['content_type'] ?? 'page'),
				'image' => (string)($entry['image'] ?? ''),
				'summary' => $this->shortText((string)($entry['description'] ?? ''), 260),
				'content' => $this->shortText((string)($entry['content'] ?? ''), 1400)
			];
		}

		return $results;
	}

	private function resolveCurrentPageContext(array $page_context): array {
		$url = trim((string)($page_context['url'] ?? ''));
		$title = trim((string)($page_context['title'] ?? ''));
		$content = trim((string)($page_context['content'] ?? ''));
		$description = trim((string)($page_context['description'] ?? ''));
		$image = trim((string)($page_context['image'] ?? ''));
		$content_type = trim((string)($page_context['content_type'] ?? ''));

		if ($url === '' && $title === '' && $content === '') {
			return [];
		}

		if ($content_type === '') {
			$content_type = $url !== '' ? $this->contentTypeFromUrl($url) : 'page';
		}

		if ($content === '' && $url !== '') {
			$cached = $this->getCachedPageContentByUrl($url);

			if ($cached) {
				$content = trim((string)($cached['content'] ?? ''));

				if ($title === '') {
					$title = trim((string)($cached['title'] ?? ''));
				}

				if ($description === '') {
					$description = trim((string)($cached['description'] ?? ''));
				}

				if ($image === '') {
					$image = trim((string)($cached['image'] ?? ''));
				}
			} else {
				$crawled = $this->crawlSitemapPage([
					'url' => $url,
					'page' => $title
				]);

				if ($crawled) {
					$content = trim((string)($crawled['content'] ?? ''));

					if ($title === '') {
						$title = trim((string)($crawled['title'] ?? ''));
					}

					if ($description === '') {
						$description = trim((string)($crawled['description'] ?? ''));
					}

					if ($image === '') {
						$image = trim((string)($crawled['image'] ?? ''));
					}
				}
			}
		}

		if ($description === '' && $content !== '') {
			$description = $this->shortText($content, 260);
		}

		if ($url === '' && $title === '' && $content === '') {
			return [];
		}

		return [
			'title' => $this->shortText($title, 180),
			'url' => $url,
			'content_type' => $content_type,
			'image' => $image,
			'description' => $this->shortText($description, 300),
			'content' => $this->shortText($content, self::SITEMAP_PAGE_TEXT_LIMIT),
			'is_current_page' => true
		];
	}

	private function getCachedPageContentByUrl(string $url): array {
		$source_key = $this->sitemapSourceCacheKey();

		if ($source_key === '') {
			return [];
		}

		$cache_key = 'roko.sitemap.content.v2.' . $source_key;
		$content = $this->readSitemapContentCache($cache_key);
		$target = $this->normalizePageUrlKey($url);

		if ($target === '') {
			return [];
		}

		foreach ($content as $entry_url => $entry) {
			if (!is_array($entry)) {
				continue;
			}

			if ($this->normalizePageUrlKey((string)$entry_url) === $target) {
				return $entry;
			}

			if ($this->normalizePageUrlKey((string)($entry['url'] ?? '')) === $target) {
				return $entry;
			}
		}

		return [];
	}

	private function normalizePageUrlKey(string $url): string {
		$url = strtolower(trim(html_entity_decode($url, ENT_QUOTES, 'UTF-8')));

		if ($url === '') {
			return '';
		}

		$parts = parse_url($url);

		if (!$parts) {
			return rtrim($url, '/');
		}

		$host = strtolower((string)($parts['host'] ?? ''));
		$path = rtrim((string)($parts['path'] ?? '/'), '/');

		if ($path === '') {
			$path = '/';
		}

		return $host . $path;
	}

	private function getCrawledSitemapContent(string $message): array {
		$source_key = $this->sitemapSourceCacheKey();

		if ($source_key === '') {
			return [];
		}

		$cache_key = 'roko.sitemap.content.v2.' . $source_key;

		if (isset(self::$sitemap_content_cache[$cache_key])) {
			return self::$sitemap_content_cache[$cache_key];
		}

		// Chat requests must never wait for remote sitemap pages. Sitemap crawling
		// is intentionally performed only by the warm-up action in the admin panel.
		$content = $this->readSitemapContentCache($cache_key);
		self::$sitemap_content_cache[$cache_key] = $content;

		return $content;
	}

	private function warmSitemapContent(string $message, int $limit, bool $force_refresh = false): array {
		$source_key = $this->sitemapSourceCacheKey();

		if ($source_key === '') {
			return ['total_pages' => 0, 'cached_pages' => 0, 'crawled_pages' => 0, 'remaining_pages' => 0, 'content' => []];
		}

		$cache_key = 'roko.sitemap.content.v2.' . $source_key;
		$pages = $this->getConfiguredSitemapPages($force_refresh, true);

		if (!$pages) {
			return ['total_pages' => 0, 'cached_pages' => 0, 'crawled_pages' => 0, 'remaining_pages' => 0, 'content' => []];
		}

		$content = $this->readSitemapContentCache($cache_key);
		$changed = false;
		$crawled = 0;
		$limit = min(self::SITEMAP_CRAWL_MAX_PAGES, max(1, $limit));

		foreach ($this->prioritizeSitemapPagesForCrawl($pages, $content, $message) as $page) {
			$url = trim((string)($page['url'] ?? ''));

			if ($url === '' || !$this->sitemapPageNeedsRefresh($content[$url] ?? null)) {
				continue;
			}

			if ($crawled >= $limit) {
				break;
			}

			$entry = $this->crawlSitemapPage($page);
			$crawled++;

			if ($entry) {
				$content[$url] = $entry;
				$changed = true;
			}
		}

		if ($changed) {
			$this->writeSitemapContentCache($cache_key, $content);
		}

		if ($content) {
			$this->persistSitemapContent($content);
		}

		self::$sitemap_content_cache[$cache_key] = $content;

		$remaining = 0;

		foreach ($pages as $page) {
			$url = trim((string)($page['url'] ?? ''));

			if ($url !== '' && $this->sitemapPageNeedsRefresh($content[$url] ?? null)) {
				$remaining++;
			}
		}

		return [
			'total_pages' => count($pages),
			'cached_pages' => count($content),
			'crawled_pages' => $crawled,
			'remaining_pages' => $remaining,
			'content' => $content
		];
	}

	private function prioritizeSitemapPagesForCrawl(array $pages, array $content, string $message): array {
		$ranked = [];

		foreach (array_slice($pages, 0, self::SITEMAP_CRAWL_MAX_PAGES) as $index => $page) {
			if (!is_array($page)) {
				continue;
			}

			$url = trim((string)($page['url'] ?? ''));

			if ($url === '') {
				continue;
			}

			$score = $this->scoreSiteEntry($message, [
				'title' => (string)($page['page'] ?? ''),
				'url' => $url,
				'content_type' => (string)($page['content_type'] ?? $this->contentTypeFromUrl($url)),
				'content' => ''
			]);

			if (!isset($content[$url])) {
				$score += 40;
			} elseif ($this->sitemapPageNeedsRefresh($content[$url])) {
				$score += 20;
			}

			if (($page['content_type'] ?? '') === 'blog') {
				$score += 10;
			}

			$ranked[] = ['score' => $score, 'index' => $index, 'page' => $page];
		}

		usort($ranked, function ($a, $b) {
			$score = (int)$b['score'] <=> (int)$a['score'];
			return $score ?: ((int)$a['index'] <=> (int)$b['index']);
		});

		return array_map(function ($item) {
			return $item['page'];
		}, $ranked);
	}

	private function sitemapPageNeedsRefresh($entry): bool {
		if (!is_array($entry) || empty($entry['fetched_at'])) {
			return true;
		}

		return time() - (int)$entry['fetched_at'] > self::SITEMAP_PAGE_CACHE_TTL;
	}

	private function crawlSitemapPage(array $page): array {
		$url = trim((string)($page['url'] ?? ''));
		$trusted_uploaded = (string)($page['source'] ?? '') === 'uploaded_sitemap';

		if ($url === '' || $this->looksLikeSitemapFile($url)) {
			return [];
		}

		if ($trusted_uploaded) {
			if (!preg_match('~^https?://~i', $url)) {
				return [];
			}
		} elseif (!$this->isInternalAbsoluteUrl($url)) {
			return [];
		}

		$body = $this->getRemoteText($url, 4);

		if ($body === '') {
			return [];
		}

		return $this->extractPageContent($body, $url, (string)($page['page'] ?? ''));
	}

	private function extractPageContent(string $html, string $url, string $fallback_title = ''): array {
		$title = $this->extractHtmlTitle($html);
		$description = $this->extractMetaDescription($html);
		$image = $this->extractPageImage($html, $url);
		$text = preg_replace('~<(script|style|noscript|svg|canvas)\b[^>]*>.*?</\1>~is', ' ', $html);
		$text = preg_replace('~</(p|div|li|h[1-6]|br|section|article)>~i', "\n", (string)$text);
		$text = $this->shortText($text, self::SITEMAP_PAGE_TEXT_LIMIT);

		if ($title === '') {
			$title = $fallback_title !== '' ? $fallback_title : $this->labelFromUrl($url);
		}

		if ($description === '') {
			$description = $this->shortText($text, 260);
		}

		return [
			'title' => $this->shortText($title, 180),
			'url' => $url,
			'content_type' => $this->contentTypeFromUrl($url),
			'image' => $image,
			'description' => $description,
			'content' => $text,
			'fetched_at' => time()
		];
	}

	private function extractHtmlTitle(string $html): string {
		if (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $match)) {
			return $this->shortText((string)$match[1], 180);
		}

		if (preg_match('~<h1[^>]*>(.*?)</h1>~is', $html, $match)) {
			return $this->shortText((string)$match[1], 180);
		}

		return '';
	}

	private function extractMetaDescription(string $html): string {
		if (preg_match('~<meta\b[^>]*(?:name|property)=["\'](?:description|og:description)["\'][^>]*content=["\']([^"\']+)["\'][^>]*>~is', $html, $match)) {
			return $this->shortText((string)$match[1], 300);
		}

		if (preg_match('~<meta\b[^>]*content=["\']([^"\']+)["\'][^>]*(?:name|property)=["\'](?:description|og:description)["\'][^>]*>~is', $html, $match)) {
			return $this->shortText((string)$match[1], 300);
		}

		return '';
	}

	private function extractPageImage(string $html, string $base_url): string {
		$patterns = [
			'~<div\b[^>]*class=["\'][^"\']*\bpost-image\b[^"\']*["\'][^>]*>.*?<img\b[^>]*\bsrc=["\']([^"\']+)["\']~is',
			'~<meta\b[^>]*(?:name|property)=["\'](?:og:image|twitter:image)["\'][^>]*content=["\']([^"\']+)["\'][^>]*>~is',
			'~<meta\b[^>]*content=["\']([^"\']+)["\'][^>]*(?:name|property)=["\'](?:og:image|twitter:image)["\'][^>]*>~is'
		];

		foreach ($patterns as $pattern) {
			if (preg_match($pattern, $html, $match)) {
				$image = $this->normalizePageImageUrl((string)$match[1], $base_url);

				if ($image !== '') {
					return $image;
				}
			}
		}

		if (preg_match_all('~<img\b[^>]*\bsrc=["\']([^"\']+)["\']~is', $html, $matches)) {
			foreach (($matches[1] ?? []) as $raw_image) {
				$image = $this->normalizePageImageUrl((string)$raw_image, $base_url);

				if ($image !== '') {
					return $image;
				}
			}
		}

		return '';
	}

	private function normalizePageImageUrl(string $url, string $base_url): string {
		$url = trim(html_entity_decode($url, ENT_QUOTES, 'UTF-8'));

		if ($url === '' || stripos($url, 'data:') === 0 || stripos($url, 'blob:') === 0) {
			return '';
		}

		$image = $this->absoluteUrl($url, $base_url);

		if ($image === '' || !$this->isInternalAbsoluteUrl($image)) {
			return '';
		}

		$path = strtolower((string)(parse_url($image, PHP_URL_PATH) ?: ''));

		if ($path === '' || preg_match('~\.(svg|ico)$~i', $path) || strpos($path, 'logo') !== false || strpos($path, 'placeholder') !== false) {
			return '';
		}

		return $image;
	}

	private function readSitemapContentCache(string $cache_key): array {
		$path = $this->sitemapFileCachePath($cache_key);

		if ($path !== '' && is_file($path) && time() - (int)filemtime($path) <= self::SITEMAP_PAGE_CACHE_TTL) {
			$payload = json_decode((string)file_get_contents($path), true);

			if (is_array($payload)) {
				return $payload;
			}
		}

		return $this->readSitemapContentDbCache();
	}

	private function writeSitemapContentCache(string $cache_key, array $content): void {
		$path = $this->sitemapFileCachePath($cache_key);

		if ($path === '') {
			return;
		}

		$dir = dirname($path);

		if (!is_dir($dir) || !is_writable($dir)) {
			return;
		}

		@file_put_contents($path, json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	private function createSitemapPageTable(): void {
		$this->db->query("
			CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "roko_sitemap_page` (
				`page_id` int(11) NOT NULL AUTO_INCREMENT,
				`url_hash` char(32) NOT NULL,
				`url` text NOT NULL,
				`title` varchar(255) NOT NULL DEFAULT '',
				`content_type` varchar(20) NOT NULL DEFAULT 'page',
				`image` varchar(1000) NOT NULL DEFAULT '',
				`description` text NOT NULL,
				`content` mediumtext NOT NULL,
				`fetched_at` int(11) NOT NULL DEFAULT 0,
				`date_modified` datetime NOT NULL,
				PRIMARY KEY (`page_id`),
				UNIQUE KEY `url_hash` (`url_hash`),
				KEY `content_type` (`content_type`),
				KEY `fetched_at` (`fetched_at`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		");

		$this->ensureSitemapPageImageColumn();
	}

	private function ensureSitemapPageImageColumn(): void {
		try {
			$query = $this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "roko_sitemap_page` LIKE 'image'");

			if (!$query->num_rows) {
				$this->db->query("ALTER TABLE `" . DB_PREFIX . "roko_sitemap_page` ADD `image` varchar(1000) NOT NULL DEFAULT '' AFTER `content_type`");
			}
		} catch (\Throwable $exception) {
		}
	}

	private function persistSitemapContent(array $content): void {
		if (!$content) {
			return;
		}

		try {
			$this->createSitemapPageTable();

			foreach ($content as $url => $entry) {
				if (!is_array($entry)) {
					continue;
				}

				$page_url = trim((string)($entry['url'] ?? $url));

				if ($page_url === '') {
					continue;
				}

				$this->db->query("
					INSERT INTO `" . DB_PREFIX . "roko_sitemap_page`
					SET
						`url_hash` = '" . $this->db->escape(md5($page_url)) . "',
						`url` = '" . $this->db->escape($page_url) . "',
						`title` = '" . $this->db->escape(substr((string)($entry['title'] ?? $page_url), 0, 255)) . "',
						`content_type` = '" . $this->db->escape(substr((string)($entry['content_type'] ?? 'page'), 0, 20)) . "',
						`image` = '" . $this->db->escape(substr((string)($entry['image'] ?? ''), 0, 1000)) . "',
						`description` = '" . $this->db->escape((string)($entry['description'] ?? '')) . "',
						`content` = '" . $this->db->escape((string)($entry['content'] ?? '')) . "',
						`fetched_at` = '" . (int)($entry['fetched_at'] ?? time()) . "',
						`date_modified` = NOW()
					ON DUPLICATE KEY UPDATE
						`url` = VALUES(`url`),
						`title` = VALUES(`title`),
						`content_type` = VALUES(`content_type`),
						`image` = VALUES(`image`),
						`description` = VALUES(`description`),
						`content` = VALUES(`content`),
						`fetched_at` = VALUES(`fetched_at`),
						`date_modified` = NOW()
				");
			}
		} catch (\Throwable $exception) {
			$this->log->write('ROKO sitemap DB cache failed: ' . $exception->getMessage());
		}
	}

	private function readSitemapContentDbCache(): array {
		try {
			$this->createSitemapPageTable();

			$query = $this->db->query("
				SELECT `title`, `url`, `content_type`, `image`, `description`, `content`, `fetched_at`
				FROM `" . DB_PREFIX . "roko_sitemap_page`
				WHERE `fetched_at` > '" . (int)(time() - self::SITEMAP_PAGE_CACHE_TTL) . "'
				ORDER BY `fetched_at` DESC
				LIMIT " . (int)self::SITEMAP_CRAWL_MAX_PAGES . "
			");

			$content = [];

			foreach ($query->rows as $row) {
				$url = trim((string)($row['url'] ?? ''));

				if ($url === '') {
					continue;
				}

				$content[$url] = [
					'title' => (string)($row['title'] ?? ''),
					'url' => $url,
					'content_type' => (string)($row['content_type'] ?? 'page'),
					'image' => (string)($row['image'] ?? ''),
					'description' => (string)($row['description'] ?? ''),
					'content' => (string)($row['content'] ?? ''),
					'fetched_at' => (int)($row['fetched_at'] ?? 0)
				];
			}

			return $content;
		} catch (\Throwable $exception) {
			return [];
		}
	}

	private function contentTypeFromUrl(string $url): string {
		$path = strtolower(trim((string)(parse_url($url, PHP_URL_PATH) ?: ''), '/'));

		if ($path === '') {
			return 'page';
		}

		if (strpos($path, 'blog') !== false || strpos($path, 'article') !== false || strpos($path, 'news') !== false) {
			return 'blog';
		}

		if (strpos($path, 'product') !== false || strpos($path, 'products') !== false) {
			return 'product';
		}

		if (strpos($path, 'category') !== false || strpos($path, 'categories') !== false) {
			return 'category';
		}

		return 'page';
	}

	private function scoreSiteEntry(string $message, array $entry): int {
		$tokens = $this->contentTokens($message);

		if (!$tokens) {
			return 0;
		}

		$title = $this->foldText((string)($entry['title'] ?? $entry['page'] ?? ''));
		$content = $this->foldText(implode(' ', [
			$title,
			(string)($entry['description'] ?? ''),
			(string)($entry['content'] ?? ''),
			(string)($entry['url'] ?? ''),
			(string)($entry['content_type'] ?? '')
		]));
		$score = 0;

		foreach ($tokens as $token) {
			if (strpos($title, $token) !== false) {
				$score += 8;
			}

			if (strpos($content, $token) !== false) {
				$score += 3;
			}
		}

		if (($entry['content_type'] ?? '') === 'blog') {
			$score += 4;
		}

		return $score;
	}

	private function contentTokens(string $value): array {
		$value = $this->foldText($value);

		if ($value === '') {
			return [];
		}

		preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}_.-]*/u', $value, $matches);
		$stop_words = array_flip(['the', 'and', 'for', 'with', 'this', 'that', 'what', 'how', 'can', 'you', 'please', 'about', 'into', 'from', 'your', 'are', 'is']);
		$tokens = [];

		foreach (($matches[0] ?? []) as $token) {
			$token = trim((string)$token, '.-_');
			$length = function_exists('mb_strlen') ? mb_strlen($token, 'UTF-8') : strlen($token);

			if ($length < 3 || isset($stop_words[$token])) {
				continue;
			}

			$tokens[$token] = true;
		}

		return array_keys($tokens);
	}

	private function extractNestedSitemapUrls(string $body, string $base_url, bool $allow_uploaded_urls = false): array {
		$urls = [];
		$is_sitemap_index = $this->isSitemapIndexBody($body);

		if (preg_match_all('~<loc>\s*(.*?)\s*</loc>~is', $body, $matches)) {
			foreach ($matches[1] as $raw_url) {
				$url = html_entity_decode(strip_tags((string)$raw_url), ENT_QUOTES, 'UTF-8');
				$url = $this->absoluteUrl($url, $base_url);

				$is_allowed_url = $allow_uploaded_urls ? (bool)preg_match('~^https?://~i', $url) : $this->isInternalAbsoluteUrl($url);

				if ($url !== '' && $is_allowed_url && ($is_sitemap_index || $this->looksLikeSitemapFile($url) || $this->looksLikeSitemapRoute($url))) {
					$urls[] = $url;
				}
			}
		}

		return array_values(array_unique($urls));
	}

	private function extractSitemapPages(string $body, string $base_url, bool $allow_uploaded_urls = false): array {
		$pages = [];

		if ($this->isSitemapIndexBody($body)) {
			return [];
		}

		if (preg_match_all('~<loc>\s*(.*?)\s*</loc>~is', $body, $matches)) {
			foreach ($matches[1] as $raw_url) {
				$url = html_entity_decode(strip_tags((string)$raw_url), ENT_QUOTES, 'UTF-8');
				$url = $this->absoluteUrl($url, $base_url);

				$is_allowed_url = $allow_uploaded_urls ? (bool)preg_match('~^https?://~i', $url) : $this->isInternalAbsoluteUrl($url);

				if ($url === '' || !$is_allowed_url || $this->looksLikeSitemapFile($url) || $this->looksLikeSitemapRoute($url)) {
					continue;
				}

				$pages[] = [
					'page' => $this->labelFromUrl($url),
					'url' => $url,
					'content_type' => $this->contentTypeFromUrl($url),
					'source' => $allow_uploaded_urls ? 'uploaded_sitemap' : 'sitemap'
				];
			}
		}

		if (!$pages && preg_match_all('~<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>~is', $body, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $match) {
				$url = $this->absoluteUrl((string)$match[1], $base_url);

				$is_allowed_url = $allow_uploaded_urls ? (bool)preg_match('~^https?://~i', $url) : $this->isInternalAbsoluteUrl($url);

				if ($url === '' || !$is_allowed_url) {
					continue;
				}

				$label = trim(html_entity_decode(strip_tags((string)$match[2]), ENT_QUOTES, 'UTF-8'));
				$pages[] = [
					'page' => $label !== '' ? $this->shortText($label, 120) : $this->labelFromUrl($url),
					'url' => $url,
					'content_type' => $this->contentTypeFromUrl($url),
					'source' => $allow_uploaded_urls ? 'uploaded_sitemap' : 'sitemap'
				];
			}
		}

		return $pages;
	}

	private function isSitemapIndexBody(string $body): bool {
		return (bool)preg_match('~<sitemapindex\b|<sitemap\b~i', $body);
	}

	private function uniqueNavigationPages(array $pages, int $limit = 180): array {
		$unique = [];
		$seen = [];
		$limit = max(1, $limit);

		foreach ($pages as $page) {
			if (!is_array($page)) {
				continue;
			}

			$name = trim((string)($page['page'] ?? ''));
			$route = trim((string)($page['route'] ?? ''));
			$url = trim((string)($page['url'] ?? ''));

			if ($name === '' && $route === '' && $url === '') {
				continue;
			}

			$key = strtolower($route . '|' . $url . '|' . $this->normalizePageKey($name));

			if (isset($seen[$key])) {
				continue;
			}

			$seen[$key] = true;
			$unique[] = $page;

			if (count($unique) >= $limit) {
				break;
			}
		}

		return $unique;
	}

	private function getRemoteText(string $url, int $timeout = 6): string {
		$timeout = max(3, min(30, $timeout));
		self::$last_remote_error = '';
		$local_body = $this->getLocalInternalText($url);

		if ($local_body !== '') {
			return substr($this->decodeRemoteBody($url, $local_body), 0, self::REMOTE_TEXT_LIMIT);
		}

		if (function_exists('curl_init')) {
			$handle = curl_init($url);
			curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, min(4, $timeout));
			curl_setopt($handle, CURLOPT_TIMEOUT, $timeout);
			curl_setopt($handle, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; ROKO/3.4; +https://rockford-qatar.com)');
			curl_setopt($handle, CURLOPT_ENCODING, '');

			if (!ini_get('open_basedir')) {
				curl_setopt($handle, CURLOPT_FOLLOWLOCATION, true);
			}

			$response = curl_exec($handle);
			$status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
			$error = curl_error($handle);
			curl_close($handle);

			if ($status >= 200 && $status < 300 && is_string($response)) {
				return substr($this->decodeRemoteBody($url, $response), 0, self::REMOTE_TEXT_LIMIT);
			}

			self::$last_remote_error = $error !== '' ? $error : ('HTTP ' . ($status ?: 0));
			return '';
		}

		$context = stream_context_create([
			'http' => [
				'method' => 'GET',
				'timeout' => $timeout,
				'header' => "User-Agent: ROKO/3.4\r\n",
				'ignore_errors' => true
			]
		]);
		$response = @file_get_contents($url, false, $context);

		if (is_string($response)) {
			return substr($this->decodeRemoteBody($url, $response), 0, self::REMOTE_TEXT_LIMIT);
		}

		$error = error_get_last();
		self::$last_remote_error = (string)($error['message'] ?? 'HTTP request failed');

		return '';
	}

	private function getLocalInternalText(string $url): string {
		if (!$this->isInternalAbsoluteUrl($url)) {
			return '';
		}

		$path = (string)(parse_url($url, PHP_URL_PATH) ?: '');
		$path = rawurldecode($path);

		if ($path === '' || substr($path, -1) === '/' || strpos($path, '..') !== false) {
			return '';
		}

		$roots = [];

		foreach (['DIR_APPLICATION', 'DIR_SYSTEM', 'DIR_IMAGE'] as $constant) {
			if (defined($constant)) {
				$root = realpath(dirname(rtrim((string)constant($constant), '/\\')));

				if ($root) {
					$roots[] = $root;
				}
			}
		}

		foreach (array_unique($roots) as $root) {
			$file = $root . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
			$real_file = realpath($file);

			if ($real_file && strpos($real_file, $root . DIRECTORY_SEPARATOR) === 0 && is_file($real_file) && is_readable($real_file)) {
				$body = file_get_contents($real_file);
				return is_string($body) ? $body : '';
			}
		}

		return '';
	}

	private function decodeRemoteBody(string $url, string $body): string {
		$path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?: ''));

		if (substr($path, -3) === '.gz' && function_exists('gzdecode')) {
			$decoded = @gzdecode($body);

			if (is_string($decoded) && $decoded !== '') {
				return $decoded;
			}
		}

		return $body;
	}

	private function absoluteUrl(string $url, string $base_url): string {
		$url = trim(html_entity_decode($url, ENT_QUOTES, 'UTF-8'));

		if ($url === '' || preg_match('~^(mailto:|tel:|javascript:)~i', $url)) {
			return '';
		}

		if (preg_match('~^https?://~i', $url)) {
			return $url;
		}

		$base = parse_url($base_url);

		if (!$base || empty($base['scheme']) || empty($base['host'])) {
			return '';
		}

		$origin = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');

		if (strpos($url, '/') === 0) {
			return $origin . $url;
		}

		$path = isset($base['path']) ? preg_replace('~/[^/]*$~', '/', $base['path']) : '/';

		return $origin . $path . $url;
	}

	private function isInternalAbsoluteUrl(string $url): bool {
		$parts = parse_url($url);

		if (!$parts || empty($parts['host'])) {
			return false;
		}

		$allowed_hosts = [];

		foreach (['HTTP_SERVER', 'HTTPS_SERVER'] as $constant) {
			if (defined($constant)) {
				$server_parts = parse_url((string)constant($constant));

				if (!empty($server_parts['host'])) {
					$allowed_hosts[] = strtolower($server_parts['host']);
				}
			}
		}

		foreach (['config_url', 'config_ssl'] as $key) {
			$config_url = (string)$this->config->get($key);
			$config_parts = parse_url($config_url);

			if (!empty($config_parts['host'])) {
				$allowed_hosts[] = strtolower($config_parts['host']);
			}
		}

		$target_host = $this->canonicalHost((string)$parts['host']);

		foreach (array_unique($allowed_hosts) as $allowed_host) {
			if ($target_host === $this->canonicalHost($allowed_host)) {
				return true;
			}
		}

		return false;
	}

	private function canonicalHost(string $host): string {
		$host = strtolower(trim($host));
		return strpos($host, 'www.') === 0 ? substr($host, 4) : $host;
	}

	private function looksLikeSitemapFile(string $url): bool {
		$path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?: ''));

		return (bool)preg_match('~(?:^|/)[^/]*sitemap[^/]*\.xml(?:\.gz)?$~', $path)
			|| (bool)preg_match('~\.xml(?:\.gz)?$~', $path);
	}

	private function looksLikeSitemapRoute(string $url): bool {
		$query = strtolower((string)(parse_url($url, PHP_URL_QUERY) ?: ''));
		$path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?: ''));

		return strpos($query, 'sitemap') !== false || strpos($path, 'sitemap') !== false;
	}

	private function labelFromUrl(string $url): string {
		$parts = parse_url($url);
		$path = trim((string)($parts['path'] ?? ''), '/');
		$query = (string)($parts['query'] ?? '');

		if ($path === '' && $query !== '') {
			parse_str($query, $query_parts);
			$path = (string)($query_parts['route'] ?? $query);
		}

		$label = $path !== '' ? basename($path) : 'home';
		$label = preg_replace('/\.[a-z0-9]+$/i', '', $label);
		$label = str_replace(['-', '_', '+'], ' ', $label);
		$label = trim(preg_replace('/\s+/u', ' ', $label));

		return $this->shortText($label !== '' ? $label : $url, 120);
	}

	private function getPromptCatalog(string $message): array {
		$this->load->model('catalog/product');

		$limit = (int)($this->config->get('module_roko_catalog_limit') ?: 80);
		$limit = min(5, self::PROMPT_CATALOG_HARD_LIMIT, max(1, $limit));
		$rows = $this->searchPromptProducts($message, $limit);

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

	private function searchPromptProducts(string $message, int $limit): array {
		$message = $this->normalizeProductSearchText($message);
		$terms = $this->productSearchTerms($message);

		if (!$terms) {
			return [];
		}

		$language_id = (int)$this->config->get('config_language_id');
		$store_id = (int)$this->config->get('config_store_id');
		$limit = min(5, max(1, $limit));
		$where = [];
		$score = [];
		$phrase = $this->db->escape($message);

		if ($message !== '') {
			$where[] = "LOWER(`pd`.`name`) LIKE '%" . $phrase . "%'";
			$where[] = "LOWER(`pd`.`description`) LIKE '%" . $phrase . "%'";
			$where[] = "LOWER(`p`.`model`) LIKE '%" . $phrase . "%'";
			$where[] = "LOWER(`p`.`sku`) LIKE '%" . $phrase . "%'";
			$score[] = "(LOWER(`pd`.`name`) LIKE '%" . $phrase . "%') * 120";
			$score[] = "(LOWER(`p`.`model`) LIKE '%" . $phrase . "%') * 100";
		}

		foreach ($terms as $term) {
			$escaped = $this->db->escape($term);
			$where[] = "LOWER(`pd`.`name`) LIKE '%" . $escaped . "%'";
			$where[] = "LOWER(`pd`.`description`) LIKE '%" . $escaped . "%'";
			$where[] = "LOWER(`pd`.`tag`) LIKE '%" . $escaped . "%'";
			$where[] = "LOWER(`p`.`model`) LIKE '%" . $escaped . "%'";
			$where[] = "LOWER(`p`.`sku`) LIKE '%" . $escaped . "%'";
			$where[] = "LOWER(`m`.`name`) LIKE '%" . $escaped . "%'";
			$score[] = "(LOWER(`pd`.`name`) LIKE '%" . $escaped . "%') * 30";
			$score[] = "(LOWER(`p`.`model`) LIKE '%" . $escaped . "%') * 24";
			$score[] = "(LOWER(`p`.`sku`) LIKE '%" . $escaped . "%') * 24";
			$score[] = "(LOWER(`m`.`name`) LIKE '%" . $escaped . "%') * 18";
			$score[] = "(LOWER(`pd`.`tag`) LIKE '%" . $escaped . "%') * 12";
			$score[] = "(LOWER(`pd`.`description`) LIKE '%" . $escaped . "%') * 6";
		}

		if (!$where || !$score) {
			return [];
		}

		try {
			$query = $this->db->query("
				SELECT
					`p`.*,
					`pd`.`name`,
					`pd`.`description`,
					`pd`.`tag`,
					`m`.`name` AS `manufacturer`,
					(
						SELECT `ps`.`price`
						FROM `" . DB_PREFIX . "product_special` `ps`
						WHERE `ps`.`product_id` = `p`.`product_id`
							AND `ps`.`customer_group_id` = '" . (int)$this->config->get('config_customer_group_id') . "'
							AND (`ps`.`date_start` = '0000-00-00' OR `ps`.`date_start` < NOW())
							AND (`ps`.`date_end` = '0000-00-00' OR `ps`.`date_end` > NOW())
						ORDER BY `ps`.`priority` ASC, `ps`.`price` ASC
						LIMIT 1
					) AS `special`,
					(" . implode(' + ', $score) . ") AS `roko_relevance`
				FROM `" . DB_PREFIX . "product` `p`
				INNER JOIN `" . DB_PREFIX . "product_description` `pd`
					ON (`pd`.`product_id` = `p`.`product_id` AND `pd`.`language_id` = '" . $language_id . "')
				INNER JOIN `" . DB_PREFIX . "product_to_store` `p2s`
					ON (`p2s`.`product_id` = `p`.`product_id` AND `p2s`.`store_id` = '" . $store_id . "')
				LEFT JOIN `" . DB_PREFIX . "manufacturer` `m`
					ON (`m`.`manufacturer_id` = `p`.`manufacturer_id`)
				WHERE `p`.`status` = '1'
					AND `p`.`date_available` <= NOW()
					AND (" . implode(' OR ', $where) . ")
				ORDER BY `roko_relevance` DESC, `p`.`sort_order` ASC, `pd`.`name` ASC
				LIMIT " . $limit . "
			");

			return is_array($query->rows) ? $query->rows : [];
		} catch (\Throwable $exception) {
			$this->log->write('ROKO local product search failed: ' . $exception->getMessage());
			return $this->fallbackPromptProductSearch($terms, $limit);
		}
	}

	private function fallbackPromptProductSearch(array $terms, int $limit): array {
		foreach ($terms as $term) {
			try {
				$rows = $this->model_catalog_product->getProducts([
					'filter_name' => $term,
					'sort' => 'pd.name',
					'order' => 'ASC',
					'start' => 0,
					'limit' => $limit
				]);

				if ($rows) {
					return $rows;
				}
			} catch (\Throwable $exception) {
			}
		}

		return [];
	}

	private function productSearchTerms(string $message): array {
		if ($message === '') {
			return [];
		}

		$stop_words = [
			'a', 'an', 'and', 'are', 'can', 'do', 'for', 'i', 'in', 'is', 'me', 'of', 'please', 'show', 'the', 'to', 'want', 'with',
			'از', 'است', 'این', 'با', 'برای', 'به', 'را', 'رو', 'لطفا', 'لطفاً', 'میخوام', 'می‌خوام', 'میخواهم', 'می‌خواهم',
			'من', 'یک', 'یه', 'چی', 'چه', 'دارید', 'دارین', 'بده', 'بدهید', 'نشون', 'نشان'
		];
		$tokens = preg_split('/[^\p{L}\p{N}._+-]+/u', $message, -1, PREG_SPLIT_NO_EMPTY);
		$terms = [];

		foreach ($tokens ?: [] as $token) {
			$token = trim((string)$token, "._+- \t\n\r\0\x0B");

			if ($token === '' || in_array($token, $stop_words, true)) {
				continue;
			}

			$length = function_exists('mb_strlen') ? mb_strlen($token, 'UTF-8') : strlen($token);

			if ($length < 2 && !ctype_digit($token)) {
				continue;
			}

			$terms[] = $token;
		}

		return array_slice(array_values(array_unique($terms)), 0, 6);
	}

	private function normalizeProductSearchText(string $message): string {
		$message = $this->cleanText($message);
		$message = strtr($message, [
			'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
			'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
			'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
			'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
			'ي' => 'ی', 'ك' => 'ک'
		]);
		$message = function_exists('mb_strtolower') ? mb_strtolower($message, 'UTF-8') : strtolower($message);

		return trim(preg_replace('/\s+/u', ' ', $message));
	}

	private function getConversationHistory(string $conversation_id, string $latest_message): array {
		try {
			$query = $this->db->query("
				SELECT `role`, `content`
				FROM `" . DB_PREFIX . "roko_chat_log`
				WHERE `conversation_id` = '" . $this->db->escape(substr($conversation_id, 0, 80)) . "'
				AND `role` IN ('user', 'assistant')
				ORDER BY `log_id` DESC
				LIMIT " . (int)self::CONVERSATION_HISTORY_LIMIT . "
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

		if (!$parsed) {
			$this->log->write('ROKO rejected an invalid structured Gemini response: ' . substr(trim($raw_text), 0, 600));

			return [
				'status' => 'error',
				'reply' => $this->localizedText(
					$message,
					'I could not complete that response. Please try again with a slightly more specific request.',
					'پاسخ کامل نشد. لطفاً درخواستتان را کمی دقیق‌تر دوباره ارسال کنید.'
				),
				'conversation_id' => $conversation_id
			];
		}

		$reply = trim((string)($parsed['reply'] ?? ''));

		if ($reply === '') {
			$reply = $this->localizedText($message, 'I am ready to help.', 'آماده‌ام کمک کنم.');
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
		$suggestions = $this->suggestionSettingEnabled('module_roko_suggest_next_questions')
			? $this->normalizeSuggestions($parsed['suggestions'] ?? [])
			: [];
		$products = $this->filterSuggestionCards(
			$this->normalizeProductCards($parsed['products'] ?? ($parsed['product_cards'] ?? []))
		);

		if (!$this->allowsNonProductCards($message)) {
			$products = array_values(array_filter($products, function (array $product): bool {
				return ($product['content_type'] ?? 'product') === 'product';
			}));
		}

		if (!$products && $this->suggestionSettingEnabled('module_roko_suggest_products')) {
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

	private function suggestionSettingEnabled(string $key): bool {
		$value = $this->config->get($key);

		return $value === null || $value === '' ? true : (bool)$value;
	}

	private function filterSuggestionCards(array $cards): array {
		return array_values(array_filter($cards, function (array $card): bool {
			$content_type = strtolower(trim((string)($card['content_type'] ?? 'product')));

			if (in_array($content_type, ['blog', 'article', 'news', 'page'], true)) {
				return $this->suggestionSettingEnabled('module_roko_suggest_blogs');
			}

			if ($content_type === 'category') {
				return $this->suggestionSettingEnabled('module_roko_suggest_categories');
			}

			return $this->suggestionSettingEnabled('module_roko_suggest_products');
		}));
	}

	private function allowsNonProductCards(string $message): bool {
		$message = function_exists('mb_strtolower') ? mb_strtolower($message, 'UTF-8') : strtolower($message);
		$keywords = ['article', 'blog', 'guide', 'tutorial', 'post', 'news', 'page', 'category', 'categories', 'مقاله', 'بلاگ', 'راهنما', 'آموزش', 'صفحه', 'دسته', 'دسته‌بندی', 'دسته بندی'];

		foreach ($keywords as $keyword) {
			if (strpos($message, $keyword) !== false) {
				return true;
			}
		}

		return false;
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

		return [];
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

		if ($matched_fields >= 3) {
			return $lead;
		}

		return $this->parseBulkLeadFreeform($message);
	}

	private function parseBulkLeadFreeform(string $message): array {
		$text = trim(preg_replace('/\s+/u', ' ', $message));

		if ($text === '') {
			return [];
		}

		$email = '';

		if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', $text, $match)) {
			$email = $match[0];
		}

		$phone = '';
		$phone_pattern = '/(?:\+\d{1,4}[\s\-()]*)?(?:\d[\s\-()]*){7,15}\d/u';

		if (preg_match_all($phone_pattern, $text, $matches)) {
			foreach ($matches[0] as $candidate) {
				$digits = preg_replace('/\D+/', '', $candidate);

				if (strlen($digits) >= 8 && strlen($digits) <= 16 && strpos($candidate, '-') === false) {
					$phone = trim($candidate);
					break;
				}
			}
		}

		$qty = '';

		if (preg_match_all('/(?<![A-Za-z0-9\-])\d{2,6}(?![A-Za-z0-9\-])/u', $text, $qty_matches)) {
			foreach ($qty_matches[0] as $candidate) {
				$value = (int)$candidate;

				if ($value >= 10) {
					$qty = (string)$value;
					break;
				}
			}
		}

		if ($email === '' || $phone === '' || $qty === '') {
			return [];
		}

		$before_qty = trim(substr($text, 0, strpos($text, $qty)));
		$after_qty = trim(substr($text, strpos($text, $qty) + strlen($qty)));
		$product_name = $before_qty;
		$contact_part = $after_qty;

		if ($email !== '') {
			$contact_part = str_replace($email, ' ', $contact_part);
		}

		if ($phone !== '') {
			$contact_part = str_replace($phone, ' ', $contact_part);
		}

		$contact_part = trim(preg_replace('/\s+/u', ' ', $contact_part));
		$after_email = '';

		if ($email !== '') {
			$email_position = strpos($text, $email);

			if ($email_position !== false) {
				$after_email = trim(substr($text, $email_position + strlen($email)));
			}
		}

		$delivery_location = $after_email !== '' ? $after_email : '';

		if ($delivery_location !== '') {
			$contact_part = trim(str_replace($delivery_location, ' ', $contact_part));
			$contact_part = trim(preg_replace('/\s+/u', ' ', $contact_part));
		}

		$name = '';
		$company = '';
		$tokens = preg_split('/\s+/u', $contact_part, -1, PREG_SPLIT_NO_EMPTY) ?: [];

		if (count($tokens) >= 2) {
			$name = implode(' ', array_slice($tokens, 0, 2));
			$company = implode(' ', array_slice($tokens, 2));
		} elseif (count($tokens) === 1) {
			$name = $tokens[0];
		}

		return [
			'product_name' => $this->shortText($product_name, 180),
			'qty' => $qty,
			'name' => $this->shortText($name, 180),
			'company' => $this->shortText($company, 180),
			'contact_number' => $this->shortText($phone, 180),
			'email' => $this->shortText($email, 180),
			'delivery_location' => $this->shortText($delivery_location, 500)
		];
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

			if (count($suggestions) >= self::MAX_RESPONSE_SUGGESTIONS) {
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

			$raw_url = trim((string)($raw_product['product_url'] ?? $raw_product['url'] ?? $raw_product['href'] ?? ''));
			$content_type = strtolower(trim((string)($raw_product['content_type'] ?? $raw_product['type'] ?? 'product')));

			if ($content_type === 'product' && empty($raw_product['product_id']) && empty($raw_product['id']) && $raw_url !== '') {
				$content_type = $this->contentTypeFromUrl($raw_url);
			}

			if ($content_type === 'article') {
				$content_type = 'blog';
			}

			if (in_array($content_type, ['blog', 'page', 'category'], true)) {
				$url = $this->internalUrl($raw_url);
				$name = trim((string)($raw_product['name'] ?? $raw_product['title'] ?? $raw_product['page'] ?? ''));
				$image = trim((string)($raw_product['image'] ?? ''));

				if ($url === '' || $name === '') {
					continue;
				}

				if ($image === '') {
					$image = $this->cachedSitemapImage($url);
				}

				$key = $content_type . ':' . $url;

				if (isset($seen[$key])) {
					continue;
				}

				$seen[$key] = true;
				$cards[] = [
					'product_id' => '',
					'name' => $this->shortText($name, 180),
					'product_url' => $url,
					'content_type' => $content_type,
					'price' => '',
					'stock' => null,
					'image' => $image,
					'category' => ucfirst($content_type),
					'summary' => $this->shortText((string)($raw_product['reason'] ?? $raw_product['summary'] ?? $raw_product['description'] ?? $raw_product['content'] ?? ''), 360),
					'attributes' => []
				];

				if (count($cards) >= self::MAX_RESPONSE_PRODUCTS) {
					break;
				}

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
				'content_type' => 'product',
				'price' => $product['price'],
				'stock' => $product['stock'],
				'image' => $product['image'],
				'category' => $product['category'],
				'summary' => $this->shortText($reason, 360),
				'attributes' => array_slice($product['attributes'], 0, 6, true)
			];

			if (count($cards) >= self::MAX_RESPONSE_PRODUCTS) {
				break;
			}
		}

		return $cards;
	}

	private function cachedSitemapImage(string $url): string {
		$url = trim($url);

		if ($url === '') {
			return '';
		}

		try {
			$this->createSitemapPageTable();

			$query = $this->db->query("
				SELECT `image`
				FROM `" . DB_PREFIX . "roko_sitemap_page`
				WHERE `url_hash` = '" . $this->db->escape(md5($url)) . "'
				AND `image` <> ''
				LIMIT 1
			");

			return (string)($query->row['image'] ?? '');
		} catch (\Throwable $exception) {
			return '';
		}
	}

	private function productCardsFromActions(array $actions): array {
		$raw_products = [];

		foreach ($actions as $action) {
			if (!in_array($action['type'] ?? '', ['enquire', 'redirect_to_product', 'update_cart_item', 'remove_from_cart'])) {
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
				$type = 'enquire';
			}

			if (in_array($type, ['add_to_cart', 'inquire', 'inquiry', 'request_quote', 'contact_sales'], true)) {
				$type = 'enquire';
			}

			if (in_array($type, ['navigate_to_page', 'open_page', 'go_to_page', 'redirect'])) {
				$type = 'redirect_to_page';
			}

			if (!in_array($type, ['enquire', 'show_cart', 'redirect_to_cart', 'redirect_to_product', 'redirect_to_page', 'update_cart_item', 'remove_from_cart', 'clear_cart', 'apply_coupon', 'redirect_to_checkout', 'send_invoice'])) {
				continue;
			}

			$action = ['type' => $type];

			if (in_array($type, ['enquire', 'redirect_to_product', 'update_cart_item', 'remove_from_cart'])) {
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

			if (in_array($type, ['enquire', 'update_cart_item'])) {
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

		$candidates = $this->searchPromptProducts($product_name, 5);

		if (!$candidates) {
			return [];
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

		$sitemap_page = $this->findSitemapPage($page);

		if ($sitemap_page) {
			return [
				'page' => $sitemap_page['page'],
				'route' => '',
				'url' => $sitemap_page['url']
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

	private function findSitemapPage(string $page): array {
		$page = trim($page);

		if ($page === '') {
			return [];
		}

		return $this->bestNamedRow($page, $this->getConfiguredSitemapPages(), 'page');
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
			'attributes' => $this->catalogAttributes($product),
			'sales_angle' => $this->cleanText((string)($product['description'] ?? '')),
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
		$token = trim((string)$this->config->get('module_roko_catalog_token'));

		if ($token === '') {
			return true;
		}

		$provided = $this->getHeader('X-AI-Assistant-Token');

		if ($provided === '') {
			$provided = (string)($this->request->get['token'] ?? '');
		}

		return hash_equals($token, $provided);
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

	private function writeLeadRecord(array $lead, string $conversation_id, array $input): bool {
		try {
			if (!$this->createLeadTable()) {
				return false;
			}
			$page_context = is_array($input['page_context'] ?? null) ? $input['page_context'] : [];
			$ip = (string)($this->request->server['REMOTE_ADDR'] ?? '');
			$user_agent = substr((string)($this->request->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
			$customer_id = $this->customer->isLogged() ? (int)$this->customer->getId() : 0;

			$this->db->query("
				INSERT INTO `" . DB_PREFIX . "roko_lead`
				SET
					`conversation_id` = '" . $this->db->escape(substr($conversation_id, 0, 80)) . "',
					`product_name` = '" . $this->db->escape(substr((string)($lead['product_name'] ?? ''), 0, 180)) . "',
					`qty` = '" . $this->db->escape(substr((string)($lead['qty'] ?? ''), 0, 40)) . "',
					`name` = '" . $this->db->escape(substr((string)($lead['name'] ?? ''), 0, 180)) . "',
					`company` = '" . $this->db->escape(substr((string)($lead['company'] ?? ''), 0, 180)) . "',
					`contact_number` = '" . $this->db->escape(substr((string)($lead['contact_number'] ?? ''), 0, 180)) . "',
					`email` = '" . $this->db->escape(substr((string)($lead['email'] ?? ''), 0, 180)) . "',
					`delivery_location` = '" . $this->db->escape(substr((string)($lead['delivery_location'] ?? ''), 0, 2000)) . "',
					`page_url` = '" . $this->db->escape(substr((string)($page_context['url'] ?? ''), 0, 2000)) . "',
					`page_title` = '" . $this->db->escape(substr((string)($page_context['title'] ?? ''), 0, 255)) . "',
					`customer_id` = '" . (int)$customer_id . "',
					`ip` = '" . $this->db->escape(substr($ip, 0, 45)) . "',
					`user_agent` = '" . $this->db->escape($user_agent) . "',
					`date_added` = NOW()
			");

			return true;
		} catch (\Throwable $exception) {
			$this->log->write('ROKO lead record failed: ' . $exception->getMessage());
			return false;
		}
	}

	private function createLeadTable(): bool {
		if ($this->leadTableExists()) {
			return true;
		}

		$table = DB_PREFIX . 'roko_lead';
		$definition = "
			CREATE TABLE IF NOT EXISTS `" . $table . "` (
				`lead_id` int(11) NOT NULL AUTO_INCREMENT,
				`conversation_id` varchar(80) NOT NULL DEFAULT '',
				`product_name` varchar(180) NOT NULL DEFAULT '',
				`qty` varchar(40) NOT NULL DEFAULT '',
				`name` varchar(180) NOT NULL DEFAULT '',
				`company` varchar(180) NOT NULL DEFAULT '',
				`contact_number` varchar(180) NOT NULL DEFAULT '',
				`email` varchar(180) NOT NULL DEFAULT '',
				`delivery_location` text NOT NULL,
				`page_url` text NOT NULL,
				`page_title` varchar(255) NOT NULL DEFAULT '',
				`customer_id` int(11) NOT NULL DEFAULT 0,
				`ip` varchar(45) NOT NULL DEFAULT '',
				`user_agent` varchar(255) NOT NULL DEFAULT '',
				`date_added` datetime NOT NULL,
				PRIMARY KEY (`lead_id`),
				KEY `conversation_id` (`conversation_id`),
				KEY `email` (`email`),
				KEY `date_added` (`date_added`)
			)";
		$options = [
			' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
			' ENGINE=InnoDB DEFAULT CHARSET=utf8',
			' ENGINE=MyISAM DEFAULT CHARSET=utf8'
		];
		$errors = [];

		foreach ($options as $option) {
			try {
				$this->db->query($definition . $option);

				if ($this->leadTableExists()) {
					return true;
				}
			} catch (\Throwable $exception) {
				$errors[] = $exception->getMessage();
			}
		}

		$this->log->write('ROKO lead table setup failed for ' . $table . ': ' . implode(' | ', array_unique($errors)));
		return false;
	}

	private function leadTableExists(): bool {
		try {
			$this->db->query("SELECT `lead_id` FROM `" . DB_PREFIX . "roko_lead` LIMIT 1");
			return true;
		} catch (\Throwable $exception) {
			return false;
		}
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
				INSERT INTO `" . DB_PREFIX . "roko_chat_log`
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
			$this->log->write('ROKO log failed: ' . $exception->getMessage());
		}
	}

	private function writeRedirectLog(
		string $conversation_id,
		string $action_type,
		string $source_url,
		string $destination_url,
		string $destination_url_utm,
		string $utm_payload
	): void {
		try {
			$this->createRedirectLogTable();
			$ip = (string)($this->request->server['REMOTE_ADDR'] ?? '');
			$user_agent = substr((string)($this->request->server['HTTP_USER_AGENT'] ?? ''), 0, 255);

			$this->db->query("
				INSERT INTO `" . DB_PREFIX . "roko_redirect_log`
				SET
					`conversation_id` = '" . $this->db->escape($conversation_id) . "',
					`action_type` = '" . $this->db->escape($action_type) . "',
					`source_url` = '" . $this->db->escape($source_url) . "',
					`destination_url` = '" . $this->db->escape($destination_url) . "',
					`destination_url_utm` = '" . $this->db->escape($destination_url_utm) . "',
					`utm_payload` = '" . $this->db->escape($utm_payload) . "',
					`ip` = '" . $this->db->escape(substr($ip, 0, 45)) . "',
					`user_agent` = '" . $this->db->escape($user_agent) . "',
					`date_added` = NOW()
			");
		} catch (\Throwable $exception) {
			$this->log->write('ROKO redirect log failed: ' . $exception->getMessage());
		}
	}

	private function createRedirectLogTable(): void {
		$this->db->query("
			CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "roko_redirect_log` (
				`redirect_log_id` int(11) NOT NULL AUTO_INCREMENT,
				`conversation_id` varchar(80) NOT NULL DEFAULT '',
				`action_type` varchar(40) NOT NULL DEFAULT '',
				`source_url` text NOT NULL,
				`destination_url` text NOT NULL,
				`destination_url_utm` text NOT NULL,
				`utm_payload` text NOT NULL,
				`ip` varchar(45) NOT NULL DEFAULT '',
				`user_agent` varchar(255) NOT NULL DEFAULT '',
				`date_added` datetime NOT NULL,
				PRIMARY KEY (`redirect_log_id`),
				KEY `conversation_id` (`conversation_id`),
				KEY `date_added` (`date_added`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		");
	}

	private function limitLogUrl(string $url): string {
		$url = trim($url);

		if ($url === '') {
			return '';
		}

		return substr($url, 0, 2000);
	}

	private function getLocalConversationId(): string {
		if (empty($this->session->data['roko_conversation_id'])) {
			try {
				$this->session->data['roko_conversation_id'] = bin2hex(random_bytes(16));
			} catch (\Throwable $exception) {
				$this->session->data['roko_conversation_id'] = md5(uniqid('', true));
			}
		}

		return (string)$this->session->data['roko_conversation_id'];
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
			502 => 'Bad Gateway'
		];

		return $map[$status] ?? 'OK';
	}
}
