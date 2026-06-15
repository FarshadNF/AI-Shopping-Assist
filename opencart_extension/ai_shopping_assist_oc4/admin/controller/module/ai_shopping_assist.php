<?php
namespace Opencart\Admin\Controller\Extension\AiShoppingAssist\Module;

class AiShoppingAssist extends \Opencart\System\Engine\Controller {
	private const SETTING_CODE = 'module_ai_shopping_assist';
	private const EVENT_CONTROLLER = 'ai_shopping_assist_footer_controller';
	private const EVENT_VIEW = 'ai_shopping_assist_footer_view';

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
		$data['clear_knowledge'] = $this->url->link('extension/ai_shopping_assist/module/ai_shopping_assist.clearKnowledge', 'user_token=' . $this->session->data['user_token']);
		$data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module');

		$data['module_ai_shopping_assist_status'] = (int)$this->config->get('module_ai_shopping_assist_status');
		$data['module_ai_shopping_assist_gemini_api_key'] = (string)$this->config->get('module_ai_shopping_assist_gemini_api_key');
		$data['module_ai_shopping_assist_gemini_model'] = (string)($this->config->get('module_ai_shopping_assist_gemini_model') ?: 'gemini-2.5-flash');
		$data['module_ai_shopping_assist_gemini_temperature'] = (string)($this->config->get('module_ai_shopping_assist_gemini_temperature') ?: '0.3');
		$data['module_ai_shopping_assist_catalog_limit'] = (int)($this->config->get('module_ai_shopping_assist_catalog_limit') ?: 80);
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
		$data['module_ai_shopping_assist_catalog_token'] = (string)$this->config->get('module_ai_shopping_assist_catalog_token');
		$data['module_ai_shopping_assist_lead_webhook_url'] = (string)$this->config->get('module_ai_shopping_assist_lead_webhook_url');
		$data['module_ai_shopping_assist_lead_webhook_secret'] = (string)$this->config->get('module_ai_shopping_assist_lead_webhook_secret');
		$data['module_ai_shopping_assist_footer_injection'] = $this->config->get('module_ai_shopping_assist_footer_injection') !== null ? (int)$this->config->get('module_ai_shopping_assist_footer_injection') : 1;
		$data['module_ai_shopping_assist_widget_title'] = $widget_title;
		$data['module_ai_shopping_assist_widget_button'] = $widget_button;
		$data['knowledge_count'] = $this->getKnowledgeCount();
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
			$catalog_limit = (int)($this->request->post['module_ai_shopping_assist_catalog_limit'] ?? 80);
			$catalog_limit = min(200, max(5, $catalog_limit));

			$settings = [
				'module_ai_shopping_assist_status' => $status,
				'module_ai_shopping_assist_gemini_api_key' => $gemini_api_key,
				'module_ai_shopping_assist_gemini_model' => trim((string)($this->request->post['module_ai_shopping_assist_gemini_model'] ?? 'gemini-2.5-flash')) ?: 'gemini-2.5-flash',
				'module_ai_shopping_assist_gemini_temperature' => (string)$temperature,
				'module_ai_shopping_assist_catalog_limit' => $catalog_limit,
				'module_ai_shopping_assist_store_brand' => trim((string)($this->request->post['module_ai_shopping_assist_store_brand'] ?? $this->config->get('config_name'))) ?: $this->config->get('config_name'),
				'module_ai_shopping_assist_assistant_name' => trim((string)($this->request->post['module_ai_shopping_assist_assistant_name'] ?? 'ROKO')) ?: 'ROKO',
				'module_ai_shopping_assist_catalog_token' => trim((string)($this->request->post['module_ai_shopping_assist_catalog_token'] ?? '')),
				'module_ai_shopping_assist_lead_webhook_url' => trim((string)($this->request->post['module_ai_shopping_assist_lead_webhook_url'] ?? '')),
				'module_ai_shopping_assist_lead_webhook_secret' => trim((string)($this->request->post['module_ai_shopping_assist_lead_webhook_secret'] ?? '')),
				'module_ai_shopping_assist_footer_injection' => (int)($this->request->post['module_ai_shopping_assist_footer_injection'] ?? 0),
				'module_ai_shopping_assist_widget_title' => trim((string)($this->request->post['module_ai_shopping_assist_widget_title'] ?? 'ROKO')),
				'module_ai_shopping_assist_widget_button' => trim((string)($this->request->post['module_ai_shopping_assist_widget_button'] ?? 'Ask ROKO'))
			];

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
				$json['success'] = $this->language->get('text_knowledge_cleared');
			} catch (\Throwable $exception) {
				$json['error'] = $this->language->get('error_knowledge_import');
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

		$this->load->model('setting/setting');
		$this->model_setting_setting->editSetting(self::SETTING_CODE, [
			'module_ai_shopping_assist_status' => 0,
			'module_ai_shopping_assist_gemini_api_key' => '',
			'module_ai_shopping_assist_gemini_model' => 'gemini-2.5-flash',
			'module_ai_shopping_assist_gemini_temperature' => '0.3',
			'module_ai_shopping_assist_catalog_limit' => 80,
			'module_ai_shopping_assist_store_brand' => $this->config->get('config_name'),
			'module_ai_shopping_assist_assistant_name' => 'ROKO',
			'module_ai_shopping_assist_catalog_token' => '',
			'module_ai_shopping_assist_lead_webhook_url' => '',
			'module_ai_shopping_assist_lead_webhook_secret' => '',
			'module_ai_shopping_assist_footer_injection' => 1,
			'module_ai_shopping_assist_widget_title' => 'ROKO',
			'module_ai_shopping_assist_widget_button' => 'Ask ROKO'
		]);

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
			$this->db->query('DELETE FROM `' . DB_PREFIX . 'ai_shopping_assist_knowledge`');

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

			$this->db->query('COMMIT');
		} catch (\Throwable $exception) {
			$this->db->query('ROLLBACK');
			throw $exception;
		}

		return count($rows);
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
}
