<?php
namespace Opencart\Admin\Controller\Extension\AiShoppingAssist\Module;

class AiShoppingAssist extends \Opencart\System\Engine\Controller {
	private const SETTING_CODE = 'module_ai_shopping_assist';
	private const EVENT_CONTROLLER = 'ai_shopping_assist_footer_controller';
	private const EVENT_VIEW = 'ai_shopping_assist_footer_view';
	private const DEFAULT_LEAD_WEBHOOK_URL = '';
	private const DEFAULT_LEAD_WEBHOOK_SECRET = '';
	private const AGENT_PROMPT_LIMIT = 8000;
	private const AGENT_NAMES = [
		'roko' => 'ROKO',
		'raya' => 'Raya',
		'scout' => 'Scout',
		'dex' => 'Dex',
		'prism' => 'Prism',
		'atlas' => 'Atlas',
		'lia' => 'Lia'
	];

	public function index(): void {
		$this->load->language('extension/ai_shopping_assist/module/ai_shopping_assist');

		$this->document->setTitle($this->language->get('heading_title'));

		$data['breadcrumbs'] = [];
		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'])
		];
		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_extension'),
			'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module')
		];
		$data['breadcrumbs'][] = [
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('extension/ai_shopping_assist/module/ai_shopping_assist', 'user_token=' . $this->session->data['user_token'])
		];

		$data['save'] = $this->url->link('extension/ai_shopping_assist/module/ai_shopping_assist.save', 'user_token=' . $this->session->data['user_token']);
		$data['clear_logs'] = $this->url->link('extension/ai_shopping_assist/module/ai_shopping_assist.clearLogs', 'user_token=' . $this->session->data['user_token']);
		$data['export_logs'] = $this->url->link('extension/ai_shopping_assist/module/ai_shopping_assist.exportLogs', 'user_token=' . $this->session->data['user_token']);
		$data['import_knowledge'] = $this->url->link('extension/ai_shopping_assist/module/ai_shopping_assist.importKnowledge', 'user_token=' . $this->session->data['user_token']);
		$data['rebuild_catalog_knowledge'] = $this->url->link('extension/ai_shopping_assist/module/ai_shopping_assist.rebuildCatalogKnowledge', 'user_token=' . $this->session->data['user_token']);
		$data['clear_knowledge'] = $this->url->link('extension/ai_shopping_assist/module/ai_shopping_assist.clearKnowledge', 'user_token=' . $this->session->data['user_token']);
		$data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module');

		$data['module_ai_shopping_assist_status'] = (int)$this->config->get('module_ai_shopping_assist_status');
		$data['module_ai_shopping_assist_gemini_api_key'] = (string)$this->config->get('module_ai_shopping_assist_gemini_api_key');
		$data['module_ai_shopping_assist_gemini_model'] = (string)($this->config->get('module_ai_shopping_assist_gemini_model') ?: 'gemini-2.5-flash');
		$data['module_ai_shopping_assist_gemini_temperature'] = (string)($this->config->get('module_ai_shopping_assist_gemini_temperature') ?: '0.3');
		$data['module_ai_shopping_assist_catalog_limit'] = (int)($this->config->get('module_ai_shopping_assist_catalog_limit') ?: 12);
		$data['module_ai_shopping_assist_store_brand'] = (string)($this->config->get('module_ai_shopping_assist_store_brand') ?: $this->config->get('config_name'));
		$assistant_name = (string)$this->config->get('module_ai_shopping_assist_assistant_name');
		$widget_title = (string)$this->config->get('module_ai_shopping_assist_widget_title');
		$widget_button = (string)$this->config->get('module_ai_shopping_assist_widget_button');

		if ($assistant_name === '' || $assistant_name === 'پشتیبان هوشمند' || $assistant_name === 'Rockford Assistant') {
			$assistant_name = 'ROKO';
		}

		if ($widget_title === '' || $widget_title === 'دستیار هوشمند خرید' || $widget_title === 'Rockford Assistant') {
			$widget_title = 'ROKO';
		}

		if ($widget_button === '' || $widget_button === 'دستیار خرید' || $widget_button === 'Chat') {
			$widget_button = 'Ask ROKO';
		}

		$data['module_ai_shopping_assist_assistant_name'] = $assistant_name;
		$data['module_ai_shopping_assist_sitemap_url'] = (string)$this->config->get('module_ai_shopping_assist_sitemap_url');
		$data['module_ai_shopping_assist_system_prompt'] = (string)$this->config->get('module_ai_shopping_assist_system_prompt');
		$data['agent_prompts'] = [];

		foreach (self::AGENT_NAMES as $agent_key => $agent_name) {
			$setting_key = 'module_ai_shopping_assist_prompt_' . $agent_key;
			$data['agent_prompts'][] = [
				'key' => $agent_key,
				'name' => $agent_name,
				'role' => $this->language->get('text_agent_role_' . $agent_key),
				'setting_key' => $setting_key,
				'value' => (string)$this->config->get($setting_key)
			];
		}

		$data['module_ai_shopping_assist_catalog_token'] = (string)$this->config->get('module_ai_shopping_assist_catalog_token');
		$data['module_ai_shopping_assist_lead_webhook_url'] = (string)($this->config->get('module_ai_shopping_assist_lead_webhook_url') ?: self::DEFAULT_LEAD_WEBHOOK_URL);
		$data['module_ai_shopping_assist_lead_webhook_secret'] = (string)($this->config->get('module_ai_shopping_assist_lead_webhook_secret') ?: self::DEFAULT_LEAD_WEBHOOK_SECRET);
		$data['module_ai_shopping_assist_footer_injection'] = $this->config->get('module_ai_shopping_assist_footer_injection') !== null ? (int)$this->config->get('module_ai_shopping_assist_footer_injection') : 1;
		$data['module_ai_shopping_assist_widget_title'] = $widget_title;
		$data['module_ai_shopping_assist_widget_button'] = $widget_button;
		$data['knowledge_count'] = $this->getKnowledgeCount();
		$data['leads'] = $this->getRecentLeads();
		$data['logs'] = $this->getRecentLogs();

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/ai_shopping_assist/module/ai_shopping_assist', $data));
	}

	public function save(): void {
		$this->load->language('extension/ai_shopping_assist/module/ai_shopping_assist');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/ai_shopping_assist/module/ai_shopping_assist')) {
			$json['error'] = $this->language->get('error_permission');
		}

		$status = (int)($this->request->post['module_ai_shopping_assist_status'] ?? 0);
		$gemini_api_key = $this->normalizeApiKeys((string)($this->request->post['module_ai_shopping_assist_gemini_api_key'] ?? ''));

		if (!$json && $status && $gemini_api_key === '') {
			$json['error'] = $this->language->get('error_gemini_api_key');
		}

		if (!$json) {
			$this->load->model('setting/setting');

			$temperature = (float)($this->request->post['module_ai_shopping_assist_gemini_temperature'] ?? 0.3);
			$temperature = min(1, max(0, $temperature));
			$catalog_limit = (int)($this->request->post['module_ai_shopping_assist_catalog_limit'] ?? 12);
			$catalog_limit = min(200, max(5, $catalog_limit));

			$settings = [
				'module_ai_shopping_assist_status' => $status,
				'module_ai_shopping_assist_gemini_api_key' => $gemini_api_key,
				'module_ai_shopping_assist_gemini_model' => trim((string)($this->request->post['module_ai_shopping_assist_gemini_model'] ?? 'gemini-2.5-flash')) ?: 'gemini-2.5-flash',
				'module_ai_shopping_assist_gemini_temperature' => (string)$temperature,
				'module_ai_shopping_assist_catalog_limit' => $catalog_limit,
				'module_ai_shopping_assist_store_brand' => trim((string)($this->request->post['module_ai_shopping_assist_store_brand'] ?? $this->config->get('config_name'))) ?: $this->config->get('config_name'),
				'module_ai_shopping_assist_assistant_name' => trim((string)($this->request->post['module_ai_shopping_assist_assistant_name'] ?? 'ROKO')) ?: 'ROKO',
				'module_ai_shopping_assist_sitemap_url' => $this->normalizeUrl((string)($this->request->post['module_ai_shopping_assist_sitemap_url'] ?? '')),
				'module_ai_shopping_assist_system_prompt' => $this->limitSettingText((string)($this->request->post['module_ai_shopping_assist_system_prompt'] ?? ''), 12000),
				'module_ai_shopping_assist_catalog_token' => trim((string)($this->request->post['module_ai_shopping_assist_catalog_token'] ?? '')),
				'module_ai_shopping_assist_lead_webhook_url' => trim((string)($this->request->post['module_ai_shopping_assist_lead_webhook_url'] ?? self::DEFAULT_LEAD_WEBHOOK_URL)),
				'module_ai_shopping_assist_lead_webhook_secret' => trim((string)($this->request->post['module_ai_shopping_assist_lead_webhook_secret'] ?? self::DEFAULT_LEAD_WEBHOOK_SECRET)),
				'module_ai_shopping_assist_footer_injection' => (int)($this->request->post['module_ai_shopping_assist_footer_injection'] ?? 0),
				'module_ai_shopping_assist_widget_title' => trim((string)($this->request->post['module_ai_shopping_assist_widget_title'] ?? 'ROKO')),
				'module_ai_shopping_assist_widget_button' => trim((string)($this->request->post['module_ai_shopping_assist_widget_button'] ?? 'Ask ROKO'))
			];

			foreach (self::AGENT_NAMES as $agent_key => $agent_name) {
				$setting_key = 'module_ai_shopping_assist_prompt_' . $agent_key;
				$settings[$setting_key] = $this->limitSettingText(
					(string)($this->request->post[$setting_key] ?? ''),
					self::AGENT_PROMPT_LIMIT
				);
			}

			$this->model_setting_setting->editSetting(self::SETTING_CODE, $settings);

			$json['success'] = $this->language->get('text_success');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function clearLogs(): void {
		$this->load->language('extension/ai_shopping_assist/module/ai_shopping_assist');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/ai_shopping_assist/module/ai_shopping_assist')) {
			$json['error'] = $this->language->get('error_permission');
		}

		if (!$json) {
			$this->db->query('TRUNCATE TABLE `' . DB_PREFIX . 'ai_shopping_assist_chat_log`');
			$json['success'] = $this->language->get('text_success');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function importKnowledge(): void {
		$this->load->language('extension/ai_shopping_assist/module/ai_shopping_assist');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/ai_shopping_assist/module/ai_shopping_assist')) {
			$json['error'] = $this->language->get('error_permission');
		}

		$file = $this->request->files['knowledge_file'] ?? [];

		if (!$json && (!$file || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)) {
			$json['error'] = $this->language->get('error_knowledge_file');
		}

		if (!$json && (int)($file['size'] ?? 0) > 25 * 1024 * 1024) {
			$json['error'] = $this->language->get('error_knowledge_size');
		}

		if (!$json) {
			$contents = file_get_contents((string)$file['tmp_name']);
			$payload = is_string($contents) ? json_decode($contents, true) : null;

			if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
				$json['error'] = $this->language->get('error_knowledge_json');
			} else {
				try {
					$this->createKnowledgeTable();
					$count = $this->replaceKnowledge($payload);

					if ($count < 1) {
						$json['error'] = $this->language->get('error_knowledge_empty');
					} else {
						$json['success'] = sprintf($this->language->get('text_knowledge_imported'), $count);
					}
				} catch (\Throwable $exception) {
					$this->log->write('AI Shopping Assist knowledge import failed: ' . $exception->getMessage());
					$json['error'] = $this->language->get('error_knowledge_import');
				}
			}
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function clearKnowledge(): void {
		$this->load->language('extension/ai_shopping_assist/module/ai_shopping_assist');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/ai_shopping_assist/module/ai_shopping_assist')) {
			$json['error'] = $this->language->get('error_permission');
		}

		if (!$json) {
			try {
				$this->db->query('DELETE FROM `' . DB_PREFIX . 'ai_shopping_assist_knowledge`');
				$this->touchKnowledgeCacheVersion();
				$json['success'] = $this->language->get('text_knowledge_cleared');
			} catch (\Throwable $exception) {
				$json['error'] = $this->language->get('error_knowledge_import');
			}
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function rebuildCatalogKnowledge(): void {
		$this->load->language('extension/ai_shopping_assist/module/ai_shopping_assist');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/ai_shopping_assist/module/ai_shopping_assist')) {
			$json['error'] = $this->language->get('error_permission');
		}

		$start = max(0, (int)($this->request->post['start'] ?? 0));
		$limit = min(100, max(20, (int)($this->request->post['limit'] ?? 50)));

		if (!$json) {
			try {
				$this->createKnowledgeTable();
				$this->load->model('catalog/product');

				if ($start === 0) {
					$this->db->query("DELETE FROM `" . DB_PREFIX . "ai_shopping_assist_knowledge` WHERE `content_type` IN ('catalog_product', 'brand_summary')");
				}

				$total = (int)$this->model_catalog_product->getTotalProducts([]);
				$products = $this->model_catalog_product->getProducts([
					'sort' => 'p.product_id',
					'order' => 'ASC',
					'start' => $start,
					'limit' => $limit
				]);

				foreach ($products as $product) {
					$this->upsertCatalogKnowledgeProduct($product);
				}

				$next = $start + count($products);

				if ($next >= $total || !$products) {
					$this->rebuildBrandSummaries();
					$this->touchKnowledgeCacheVersion();
				}

				$json = [
					'success' => $next >= $total
						? sprintf($this->language->get('text_catalog_index_complete'), $total)
						: sprintf($this->language->get('text_catalog_index_progress'), $next, $total),
					'processed' => $next,
					'total' => $total,
					'next' => $next,
					'done' => $next >= $total || !$products
				];
			} catch (\Throwable $exception) {
				$this->log->write('AI Shopping Assist catalog index failed: ' . $exception->getMessage());
				$json['error'] = $this->language->get('error_catalog_index');
			}
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function exportLogs(): void {
		$this->load->language('extension/ai_shopping_assist/module/ai_shopping_assist');

		if (!$this->user->hasPermission('access', 'extension/ai_shopping_assist/module/ai_shopping_assist')) {
			$this->response->addHeader(($this->request->server['SERVER_PROTOCOL'] ?? 'HTTP/1.1') . ' 403 Forbidden');
			$this->response->setOutput($this->language->get('error_permission'));
			return;
		}

		$filename = 'ai-shopping-assist-conversations-' . date('Y-m-d-His') . '.xls';

		$this->response->addHeader('Content-Type: application/vnd.ms-excel; charset=utf-8');
		$this->response->addHeader('Content-Disposition: attachment; filename="' . $filename . '"');
		$this->response->addHeader('Cache-Control: max-age=0');
		$this->response->setOutput($this->buildLogsExcel($this->getExportLogs()));
	}

	public function install(): void {
		$this->createLogTable();
		$this->createKnowledgeTable();
		$this->createLeadTable();

		$this->load->model('setting/setting');
		$settings = [
			'module_ai_shopping_assist_status' => 0,
			'module_ai_shopping_assist_gemini_api_key' => '',
			'module_ai_shopping_assist_gemini_model' => 'gemini-2.5-flash',
			'module_ai_shopping_assist_gemini_temperature' => '0.3',
			'module_ai_shopping_assist_catalog_limit' => 12,
			'module_ai_shopping_assist_store_brand' => $this->config->get('config_name'),
			'module_ai_shopping_assist_assistant_name' => 'ROKO',
			'module_ai_shopping_assist_sitemap_url' => '',
			'module_ai_shopping_assist_system_prompt' => '',
			'module_ai_shopping_assist_catalog_token' => '',
			'module_ai_shopping_assist_lead_webhook_url' => self::DEFAULT_LEAD_WEBHOOK_URL,
			'module_ai_shopping_assist_lead_webhook_secret' => self::DEFAULT_LEAD_WEBHOOK_SECRET,
			'module_ai_shopping_assist_footer_injection' => 1,
			'module_ai_shopping_assist_widget_title' => 'ROKO',
			'module_ai_shopping_assist_widget_button' => 'Ask ROKO'
		];

		foreach (self::AGENT_NAMES as $agent_key => $agent_name) {
			$settings['module_ai_shopping_assist_prompt_' . $agent_key] = '';
		}

		$this->model_setting_setting->editSetting(self::SETTING_CODE, $settings);

		$this->load->model('setting/event');
		$this->model_setting_event->deleteEventByCode(self::EVENT_CONTROLLER);
		$this->model_setting_event->deleteEventByCode(self::EVENT_VIEW);

		$this->model_setting_event->addEvent([
			'description' => 'AI Shopping Assist - inject widget after footer controller',
			'code' => self::EVENT_CONTROLLER,
			'trigger' => 'catalog/controller/common/footer/after',
			'action' => 'extension/ai_shopping_assist/module/ai_shopping_assist.inject',
			'status' => 1,
			'sort_order' => 10
		]);

		$this->model_setting_event->addEvent([
			'description' => 'AI Shopping Assist - inject widget after footer view',
			'code' => self::EVENT_VIEW,
			'trigger' => 'catalog/view/common/footer/after',
			'action' => 'extension/ai_shopping_assist/module/ai_shopping_assist.inject',
			'status' => 1,
			'sort_order' => 10
		]);

		$this->importBundledKnowledge();
	}

	public function uninstall(): void {
		$this->load->model('setting/event');
		$this->model_setting_event->deleteEventByCode(self::EVENT_CONTROLLER);
		$this->model_setting_event->deleteEventByCode(self::EVENT_VIEW);

		$this->load->model('setting/setting');
		$this->model_setting_setting->deleteSetting(self::SETTING_CODE);
	}

	private function createLogTable(): void {
		$this->db->query("
			CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "ai_shopping_assist_chat_log` (
				`log_id` int(11) NOT NULL AUTO_INCREMENT,
				`conversation_id` varchar(80) NOT NULL DEFAULT '',
				`session_id` varchar(128) NOT NULL DEFAULT '',
				`customer_id` int(11) NOT NULL DEFAULT 0,
				`role` varchar(20) NOT NULL DEFAULT '',
				`content` text NOT NULL,
				`ip` varchar(45) NOT NULL DEFAULT '',
				`user_agent` varchar(255) NOT NULL DEFAULT '',
				`date_added` datetime NOT NULL,
				PRIMARY KEY (`log_id`),
				KEY `conversation_id` (`conversation_id`),
				KEY `date_added` (`date_added`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		");
	}

	private function createKnowledgeTable(): void {
		$this->db->query("
			CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "ai_shopping_assist_knowledge` (
				`knowledge_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				`source_key` varchar(64) NOT NULL,
				`product_id` varchar(100) NOT NULL DEFAULT '',
				`name` varchar(512) NOT NULL DEFAULT '',
				`brand` varchar(255) NOT NULL DEFAULT '',
				`source_url` text NOT NULL,
				`content_type` varchar(40) NOT NULL DEFAULT 'product',
				`content` mediumtext NOT NULL,
				`date_modified` datetime NOT NULL,
				PRIMARY KEY (`knowledge_id`),
				UNIQUE KEY `source_key` (`source_key`),
				KEY `product_id` (`product_id`),
				KEY `name` (`name`(191))
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		");

		$this->ensureKnowledgeSearchIndex();
	}

	private function createLeadTable(): void {
		$this->db->query("
			CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "ai_shopping_assist_lead` (
				`lead_id` varchar(32) NOT NULL,
				`conversation_id` varchar(80) NOT NULL DEFAULT '',
				`product_name` varchar(255) NOT NULL DEFAULT '',
				`qty` varchar(40) NOT NULL DEFAULT '',
				`name` varchar(180) NOT NULL DEFAULT '',
				`company` varchar(180) NOT NULL DEFAULT '',
				`contact_number` varchar(180) NOT NULL DEFAULT '',
				`email` varchar(180) NOT NULL DEFAULT '',
				`delivery_location` varchar(500) NOT NULL DEFAULT '',
				`page_url` text NOT NULL,
				`status` varchar(30) NOT NULL DEFAULT 'new',
				`date_added` datetime NOT NULL,
				`date_modified` datetime NOT NULL,
				PRIMARY KEY (`lead_id`),
				KEY `conversation_id` (`conversation_id`),
				KEY `status` (`status`),
				KEY `date_added` (`date_added`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
		");
	}

	private function ensureKnowledgeSearchIndex(): void {
		try {
			$index = $this->db->query("SHOW INDEX FROM `" . DB_PREFIX . "ai_shopping_assist_knowledge` WHERE `Key_name` = 'knowledge_search'");

			if (!$index->rows) {
				$this->db->query(
					"ALTER TABLE `" . DB_PREFIX . "ai_shopping_assist_knowledge` "
					. "ADD FULLTEXT KEY `knowledge_search` (`name`, `brand`, `content`)"
				);
			}
		} catch (\Throwable $exception) {
			$this->log->write('AI Shopping Assist FULLTEXT index warning: ' . $exception->getMessage());
		}
	}

	private function upsertCatalogKnowledgeProduct(array $product): void {
		$product_id = (string)($product['product_id'] ?? '');

		if ($product_id === '') {
			return;
		}

		$name = $this->limitKnowledgeText((string)($product['name'] ?? ''), 512);
		$brand = $this->limitKnowledgeText((string)($product['manufacturer'] ?? ''), 255);
		$model = trim((string)($product['model'] ?? ''));
		$description = trim(html_entity_decode(strip_tags((string)($product['description'] ?? '')), ENT_QUOTES, 'UTF-8'));
		$attributes = [];

		if (method_exists($this->model_catalog_product, 'getAttributes')) {
			foreach ($this->model_catalog_product->getAttributes((int)$product_id) as $group) {
				foreach (($group['attribute'] ?? []) as $attribute) {
					$attribute_name = trim((string)($attribute['name'] ?? ''));
					$attribute_value = trim(html_entity_decode(strip_tags((string)($attribute['text'] ?? '')), ENT_QUOTES, 'UTF-8'));

					if ($attribute_name !== '' && $attribute_value !== '') {
						$attributes[$attribute_name] = $attribute_value;
					}
				}
			}
		}

		$content = implode("\n\n", array_filter([
			'Product: ' . $name,
			'Product ID: ' . $product_id,
			$model !== '' ? 'Model: ' . $model : '',
			$brand !== '' ? 'Brand: ' . $brand : '',
			$description !== '' ? "Description and applications:\n" . $description : '',
			$attributes ? "Technical attributes:\n" . json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
		]));
		$source_url = rtrim((string)$this->config->get('config_url'), '/') . '/index.php?route=product/product&product_id=' . rawurlencode($product_id);
		$row = $this->makeKnowledgeRow($source_url, $product_id, $name, $brand, 'catalog_product', $content);

		$this->db->query("
			INSERT INTO `" . DB_PREFIX . "ai_shopping_assist_knowledge`
			SET
				`source_key` = '" . $this->db->escape($row['source_key']) . "',
				`product_id` = '" . $this->db->escape($row['product_id']) . "',
				`name` = '" . $this->db->escape($row['name']) . "',
				`brand` = '" . $this->db->escape($row['brand']) . "',
				`source_url` = '" . $this->db->escape($row['source_url']) . "',
				`content_type` = 'catalog_product',
				`content` = '" . $this->db->escape($row['content']) . "',
				`date_modified` = NOW()
			ON DUPLICATE KEY UPDATE
				`name` = VALUES(`name`),
				`brand` = VALUES(`brand`),
				`source_url` = VALUES(`source_url`),
				`content` = VALUES(`content`),
				`date_modified` = NOW()
		");
	}

	private function getKnowledgeCount(): int {
		try {
			$query = $this->db->query('SELECT COUNT(*) AS `total` FROM `' . DB_PREFIX . 'ai_shopping_assist_knowledge`');

			return (int)($query->row['total'] ?? 0);
		} catch (\Throwable $exception) {
			return 0;
		}
	}

	private function importBundledKnowledge(): void {
		$candidates = [
			dirname(__DIR__, 3) . '/install_data/enriched_cache.json'
		];

		if (defined('DIR_EXTENSION')) {
			array_unshift($candidates, rtrim(DIR_EXTENSION, '/\\') . '/ai_shopping_assist/install_data/enriched_cache.json');
		}

		foreach ($candidates as $path) {
			if (!is_file($path)) {
				continue;
			}

			try {
				$payload = json_decode((string)file_get_contents($path), true);

				if (is_array($payload) && json_last_error() === JSON_ERROR_NONE) {
					$this->replaceKnowledge($payload);
				}
			} catch (\Throwable $exception) {
				$this->log->write('AI Shopping Assist bundled knowledge import failed: ' . $exception->getMessage());
			}

			return;
		}
	}

	private function replaceKnowledge(array $payload): int {
		$rows = $this->buildKnowledgeRows($payload);

		if (!$rows) {
			return 0;
		}

		$this->db->query('START TRANSACTION');

		try {
			$this->db->query(
				"DELETE FROM `" . DB_PREFIX . "ai_shopping_assist_knowledge` "
				. "WHERE `content_type` IN ('product', 'datasheet', 'brand_summary')"
			);

			foreach ($rows as $row) {
				$this->db->query("
					INSERT INTO `" . DB_PREFIX . "ai_shopping_assist_knowledge`
					SET
						`source_key` = '" . $this->db->escape($row['source_key']) . "',
						`product_id` = '" . $this->db->escape($row['product_id']) . "',
						`name` = '" . $this->db->escape($row['name']) . "',
						`brand` = '" . $this->db->escape($row['brand']) . "',
						`source_url` = '" . $this->db->escape($row['source_url']) . "',
						`content_type` = '" . $this->db->escape($row['content_type']) . "',
						`content` = '" . $this->db->escape($row['content']) . "',
						`date_modified` = NOW()
				");
			}

			$this->rebuildBrandSummaries();
			$this->db->query('COMMIT');
			$this->touchKnowledgeCacheVersion();
		} catch (\Throwable $exception) {
			$this->db->query('ROLLBACK');
			throw $exception;
		}

		return count($rows);
	}

	private function getRecentLeads(): array {
		try {
			$query = $this->db->query("
				SELECT `lead_id`, `product_name`, `qty`, `name`, `company`, `contact_number`, `email`, `delivery_location`, `status`, `date_added`
				FROM `" . DB_PREFIX . "ai_shopping_assist_lead`
				ORDER BY `date_added` DESC
				LIMIT 50
			");
			return $query->rows;
		} catch (\Throwable $exception) {
			return [];
		}
	}

	private function rebuildBrandSummaries(): void {
		$this->db->query("DELETE FROM `" . DB_PREFIX . "ai_shopping_assist_knowledge` WHERE `content_type` = 'brand_summary'");
		$brands = $this->db->query("
			SELECT `brand`, COUNT(DISTINCT `product_id`) AS `products`
			FROM `" . DB_PREFIX . "ai_shopping_assist_knowledge`
			WHERE `content_type` IN ('catalog_product', 'product') AND `brand` <> ''
			GROUP BY `brand`
			ORDER BY `brand` ASC
		");

		foreach ($brands->rows as $brand_row) {
			$brand = (string)$brand_row['brand'];
			$products = $this->db->query("
				SELECT `name`, LEFT(`content`, 900) AS `content`
				FROM `" . DB_PREFIX . "ai_shopping_assist_knowledge`
				WHERE `content_type` IN ('catalog_product', 'product')
					AND `brand` = '" . $this->db->escape($brand) . "'
				ORDER BY `name` ASC
				LIMIT 12
			");
			$product_names = [];
			$application_notes = [];

			foreach ($products->rows as $product) {
				$product_names[] = (string)$product['name'];
				$application_notes[] = (string)$product['content'];
			}

			$content = implode("\n\n", [
				'Brand portfolio: ' . $brand,
				'Indexed products: ' . (int)$brand_row['products'],
				'Representative product families: ' . implode(' | ', $product_names),
				"Representative descriptions and applications:\n" . implode("\n---\n", $application_notes)
			]);
			$row = $this->makeKnowledgeRow('', '', $brand . ' product portfolio', $brand, 'brand_summary', $content);

			$this->db->query("
				INSERT INTO `" . DB_PREFIX . "ai_shopping_assist_knowledge`
				SET
					`source_key` = '" . $this->db->escape($row['source_key']) . "',
					`product_id` = '',
					`name` = '" . $this->db->escape($row['name']) . "',
					`brand` = '" . $this->db->escape($row['brand']) . "',
					`source_url` = '',
					`content_type` = 'brand_summary',
					`content` = '" . $this->db->escape($row['content']) . "',
					`date_modified` = NOW()
			");
		}
	}

	private function touchKnowledgeCacheVersion(): void {
		try {
			$this->cache->set('ai_shopping_assist.knowledge_version', (string)microtime(true));
		} catch (\Throwable $exception) {
		}
	}

	private function buildKnowledgeRows(array $payload): array {
		$rows = [];

		foreach ($payload as $source => $item) {
			if (!is_array($item) || array_key_exists('is_product', $item) && !$item['is_product']) {
				continue;
			}

			$source_url = is_string($source) ? trim($source) : '';
			$product_id = $this->limitKnowledgeText((string)($item['product_id'] ?? ''), 100);
			$name = $this->limitKnowledgeText((string)($item['name'] ?? $item['title'] ?? ''), 512);
			$brand = $this->limitKnowledgeText((string)($item['brand'] ?? ''), 255);
			$description = trim((string)($item['full_description'] ?? $item['description'] ?? ''));
			$sales_angle = trim((string)($item['sales_angle'] ?? ''));
			$attributes = $item['technical_attributes'] ?? $item['attributes'] ?? [];
			$attributes_text = '';

			if (is_array($attributes) && $attributes) {
				$attributes_text = (string)json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			} elseif (is_scalar($attributes)) {
				$attributes_text = trim((string)$attributes);
			}

			$content_parts = array_filter([
				$name !== '' ? 'Product: ' . $name : '',
				$product_id !== '' ? 'Product ID: ' . $product_id : '',
				$brand !== '' ? 'Brand: ' . $brand : '',
				$description !== '' ? "Description:\n" . $description : '',
				$attributes_text !== '' ? "Technical attributes:\n" . $attributes_text : '',
				$sales_angle !== '' ? "Sales notes:\n" . $sales_angle : ''
			]);

			if ($content_parts) {
				$rows[] = $this->makeKnowledgeRow(
					$source_url,
					$product_id,
					$name,
					$brand,
					'product',
					implode("\n\n", $content_parts)
				);
			}

			$datasheet = trim((string)($item['datasheet_content'] ?? ''));

			if ($datasheet !== '') {
				$rows[] = $this->makeKnowledgeRow(
					$source_url,
					$product_id,
					$name,
					$brand,
					'datasheet',
					$datasheet
				);
			}
		}

		return $rows;
	}

	private function makeKnowledgeRow(
		string $source_url,
		string $product_id,
		string $name,
		string $brand,
		string $content_type,
		string $content
	): array {
		$identity = $source_url . '|' . $product_id . '|' . $name . '|' . $content_type;

		return [
			'source_key' => hash('sha256', $identity),
			'product_id' => $product_id,
			'name' => $name,
			'brand' => $brand,
			'source_url' => $this->limitKnowledgeText($source_url, 4000),
			'content_type' => $content_type,
			'content' => $this->limitKnowledgeText($content, 500000)
		];
	}

	private function limitKnowledgeText(string $value, int $limit): string {
		$value = str_replace(["\r\n", "\r"], "\n", trim($value));

		if (function_exists('mb_substr')) {
			return mb_substr($value, 0, $limit);
		}

		return substr($value, 0, $limit);
	}

	private function getRecentLogs(): array {
		try {
			$query = $this->db->query("
				SELECT *
				FROM `" . DB_PREFIX . "ai_shopping_assist_chat_log`
				ORDER BY `log_id` DESC
				LIMIT 30
			");

			return $query->rows;
		} catch (\Throwable $exception) {
			return [];
		}
	}

	private function getExportLogs(): array {
		try {
			$query = $this->db->query("
				SELECT
					`log_id`,
					`conversation_id`,
					`session_id`,
					`customer_id`,
					`role`,
					`content`,
					`ip`,
					`user_agent`,
					`date_added`
				FROM `" . DB_PREFIX . "ai_shopping_assist_chat_log`
				ORDER BY `conversation_id` ASC, `log_id` ASC
			");

			return $query->rows;
		} catch (\Throwable $exception) {
			return [];
		}
	}

	private function buildLogsExcel(array $rows): string {
		$columns = [
			['key' => 'log_id', 'title' => $this->language->get('column_log_id'), 'type' => 'Number'],
			['key' => 'conversation_id', 'title' => $this->language->get('column_conversation'), 'type' => 'String'],
			['key' => 'session_id', 'title' => $this->language->get('column_session'), 'type' => 'String'],
			['key' => 'customer_id', 'title' => $this->language->get('column_customer'), 'type' => 'Number'],
			['key' => 'role', 'title' => $this->language->get('column_role'), 'type' => 'String'],
			['key' => 'content', 'title' => $this->language->get('column_content'), 'type' => 'String'],
			['key' => 'ip', 'title' => $this->language->get('column_ip'), 'type' => 'String'],
			['key' => 'user_agent', 'title' => $this->language->get('column_user_agent'), 'type' => 'String'],
			['key' => 'date_added', 'title' => $this->language->get('column_date'), 'type' => 'String']
		];

		$output = [];
		$output[] = '<?xml version="1.0" encoding="UTF-8"?>';
		$output[] = '<?mso-application progid="Excel.Sheet"?>';
		$output[] = '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet" xmlns:html="http://www.w3.org/TR/REC-html40">';
		$output[] = '<Styles><Style ss:ID="header"><Font ss:Bold="1"/><Interior ss:Color="#EADCFD" ss:Pattern="Solid"/></Style></Styles>';
		$output[] = '<Worksheet ss:Name="Conversations"><Table>';
		$output[] = '<Row>';

		foreach ($columns as $column) {
			$output[] = '<Cell ss:StyleID="header"><Data ss:Type="String">' . $this->xmlEscape($column['title']) . '</Data></Cell>';
		}

		$output[] = '</Row>';

		foreach ($rows as $row) {
			$output[] = '<Row>';

			foreach ($columns as $column) {
				$value = (string)($row[$column['key']] ?? '');
				$type = $column['type'];

				if ($type === 'Number' && $value === '') {
					$type = 'String';
				}

				$output[] = '<Cell><Data ss:Type="' . $type . '">' . $this->xmlEscape($value) . '</Data></Cell>';
			}

			$output[] = '</Row>';
		}

		$output[] = '</Table></Worksheet></Workbook>';

		return implode("\n", $output);
	}

	private function xmlEscape(string $value): string {
		$value = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $value) ?? '';

		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	private function normalizeApiKeys(string $value): string {
		$keys = preg_split('/[\r\n,]+/', $value);
		$normalized = [];

		foreach ($keys as $key) {
			$key = trim($key);

			if ($key !== '' && !in_array($key, $normalized, true)) {
				$normalized[] = $key;
			}
		}

		return implode(', ', $normalized);
	}

	private function normalizeUrl(string $value): string {
		$value = trim($value);

		if ($value === '') {
			return '';
		}

		$parts = parse_url($value);

		if (!$parts || empty($parts['scheme']) || empty($parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
			return '';
		}

		return $this->limitSettingText($value, 1000);
	}

	private function limitSettingText(string $value, int $limit): string {
		$value = str_replace(["\r\n", "\r"], "\n", trim($value));

		if (function_exists('mb_substr')) {
			return mb_substr($value, 0, $limit);
		}

		return substr($value, 0, $limit);
	}
}
