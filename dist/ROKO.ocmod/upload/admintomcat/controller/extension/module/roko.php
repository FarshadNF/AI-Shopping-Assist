<?php
class ControllerExtensionModuleRoko extends Controller {
	private const SETTING_CODE = 'module_roko';
	private const EVENT_CONTROLLER = 'roko_footer_controller';
	private const EVENT_VIEW = 'roko_footer_view';
	private const DEFAULT_LEAD_WEBHOOK_URL = 'https://script.google.com/macros/s/AKfycbwV1zaw6C3iKdWVaK-gN8hyAzvW8_RygWTp9Q2ggjYUWcAftM2c7ipOIM5l6UowTsCS/exec';
	private const DEFAULT_LEAD_WEBHOOK_SECRET = 'f8c9d2a7e1b4c6f9a3d8e7b2c5f1a9d4';

	public function index(): void {
		$this->load->language('extension/module/roko');
		$this->createLogTable();

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
			'href' => $this->url->link('extension/module/roko', 'user_token=' . $this->session->data['user_token'])
		];

		$data['save'] = $this->url->link('extension/module/roko/save', 'user_token=' . $this->session->data['user_token']);
		$data['clear_logs'] = $this->url->link('extension/module/roko/clearLogs', 'user_token=' . $this->session->data['user_token']);
		$data['export_logs'] = $this->url->link('extension/module/roko/exportLogs', 'user_token=' . $this->session->data['user_token']);
		$data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module');

		$data['module_roko_status'] = (int)$this->config->get('module_roko_status');
		$data['module_roko_gemini_api_key'] = (string)$this->config->get('module_roko_gemini_api_key');
		$data['module_roko_gemini_model'] = (string)($this->config->get('module_roko_gemini_model') ?: 'gemini-2.5-flash');
		$data['module_roko_gemini_temperature'] = (string)($this->config->get('module_roko_gemini_temperature') ?: '0.3');
		$data['module_roko_catalog_limit'] = (int)($this->config->get('module_roko_catalog_limit') ?: 80);
		$data['module_roko_store_brand'] = (string)($this->config->get('module_roko_store_brand') ?: $this->config->get('config_name'));
		$assistant_name = (string)$this->config->get('module_roko_assistant_name');
		$widget_title = (string)$this->config->get('module_roko_widget_title');
		$widget_button = (string)$this->config->get('module_roko_widget_button');

		if ($assistant_name === '' || $assistant_name === 'پشتیبان هوشمند') {
			$assistant_name = 'ROKO';
		}

		if ($widget_title === '' || $widget_title === 'دستیار هوشمند خرید') {
			$widget_title = 'ROKO';
		}

		if ($widget_button === '' || $widget_button === 'دستیار خرید') {
			$widget_button = 'Chat';
		}

		$data['module_roko_assistant_name'] = $assistant_name;
		$data['module_roko_catalog_token'] = (string)$this->config->get('module_roko_catalog_token');
		$data['module_roko_lead_webhook_url'] = (string)($this->config->get('module_roko_lead_webhook_url') ?: self::DEFAULT_LEAD_WEBHOOK_URL);
		$data['module_roko_lead_webhook_secret'] = (string)($this->config->get('module_roko_lead_webhook_secret') ?: self::DEFAULT_LEAD_WEBHOOK_SECRET);
		$data['module_roko_footer_injection'] = $this->config->get('module_roko_footer_injection') !== null ? (int)$this->config->get('module_roko_footer_injection') : 1;
		$data['module_roko_widget_title'] = $widget_title;
		$data['module_roko_widget_button'] = $widget_button;
		$data['logs'] = $this->getRecentLogs();

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/module/roko', $data));
	}

	public function save(): void {
		$this->load->language('extension/module/roko');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/module/roko')) {
			$json['error'] = $this->language->get('error_permission');
		}

		$status = (int)($this->request->post['module_roko_status'] ?? 0);
		$gemini_api_key = $this->normalizeApiKeys((string)($this->request->post['module_roko_gemini_api_key'] ?? ''));

		if (!$json && $status && $gemini_api_key === '') {
			$json['error'] = $this->language->get('error_gemini_api_key');
		}

		if (!$json) {
			$this->createLogTable();

			$this->load->model('setting/setting');

			$temperature = (float)($this->request->post['module_roko_gemini_temperature'] ?? 0.3);
			$temperature = min(1, max(0, $temperature));
			$catalog_limit = (int)($this->request->post['module_roko_catalog_limit'] ?? 80);
			$catalog_limit = min(200, max(5, $catalog_limit));

			$settings = [
				'module_roko_status' => $status,
				'module_roko_gemini_api_key' => $gemini_api_key,
				'module_roko_gemini_model' => trim((string)($this->request->post['module_roko_gemini_model'] ?? 'gemini-2.5-flash')) ?: 'gemini-2.5-flash',
				'module_roko_gemini_temperature' => (string)$temperature,
				'module_roko_catalog_limit' => $catalog_limit,
				'module_roko_store_brand' => trim((string)($this->request->post['module_roko_store_brand'] ?? $this->config->get('config_name'))) ?: $this->config->get('config_name'),
				'module_roko_assistant_name' => trim((string)($this->request->post['module_roko_assistant_name'] ?? 'ROKO')) ?: 'ROKO',
				'module_roko_catalog_token' => trim((string)($this->request->post['module_roko_catalog_token'] ?? '')),
				'module_roko_lead_webhook_url' => trim((string)($this->request->post['module_roko_lead_webhook_url'] ?? self::DEFAULT_LEAD_WEBHOOK_URL)),
				'module_roko_lead_webhook_secret' => trim((string)($this->request->post['module_roko_lead_webhook_secret'] ?? self::DEFAULT_LEAD_WEBHOOK_SECRET)),
				'module_roko_footer_injection' => (int)($this->request->post['module_roko_footer_injection'] ?? 0),
				'module_roko_widget_title' => trim((string)($this->request->post['module_roko_widget_title'] ?? 'ROKO')),
				'module_roko_widget_button' => trim((string)($this->request->post['module_roko_widget_button'] ?? 'Chat'))
			];

			$this->model_setting_setting->editSetting(self::SETTING_CODE, $settings);

			$json['success'] = $this->language->get('text_success');
		}

		if (!$this->isAjaxRequest()) {
			if (!empty($json['error'])) {
				$this->session->data['error'] = $json['error'];
			} else {
				$this->session->data['success'] = $json['success'];
			}

			$redirect = html_entity_decode($this->url->link('extension/module/roko', 'user_token=' . $this->session->data['user_token'], true), ENT_QUOTES, 'UTF-8');
			$this->response->redirect($redirect);
			return;
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function clearLogs(): void {
		$this->load->language('extension/module/roko');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/module/roko')) {
			$json['error'] = $this->language->get('error_permission');
		}

		if (!$json) {
			$this->db->query('TRUNCATE TABLE `' . DB_PREFIX . 'roko_chat_log`');
			$json['success'] = $this->language->get('text_success');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function exportLogs(): void {
		$this->load->language('extension/module/roko');

		if (!$this->user->hasPermission('access', 'extension/module/roko')) {
			$this->response->addHeader(($this->request->server['SERVER_PROTOCOL'] ?? 'HTTP/1.1') . ' 403 Forbidden');
			$this->response->setOutput($this->language->get('error_permission'));
			return;
		}

		$filename = 'roko-conversations-' . date('Y-m-d-His') . '.xls';

		$this->response->addHeader('Content-Type: application/vnd.ms-excel; charset=utf-8');
		$this->response->addHeader('Content-Disposition: attachment; filename="' . $filename . '"');
		$this->response->addHeader('Cache-Control: max-age=0');
		$this->response->setOutput($this->buildLogsExcel($this->getExportLogs()));
	}

	public function install(): void {
		$this->createLogTable();

		$this->load->model('setting/setting');
		$this->model_setting_setting->editSetting(self::SETTING_CODE, [
			'module_roko_status' => 0,
			'module_roko_gemini_api_key' => '',
			'module_roko_gemini_model' => 'gemini-2.5-flash',
			'module_roko_gemini_temperature' => '0.3',
			'module_roko_catalog_limit' => 80,
			'module_roko_store_brand' => $this->config->get('config_name'),
			'module_roko_assistant_name' => 'ROKO',
			'module_roko_catalog_token' => '',
			'module_roko_lead_webhook_url' => self::DEFAULT_LEAD_WEBHOOK_URL,
			'module_roko_lead_webhook_secret' => self::DEFAULT_LEAD_WEBHOOK_SECRET,
			'module_roko_footer_injection' => 1,
			'module_roko_widget_title' => 'ROKO',
			'module_roko_widget_button' => 'Chat'
		]);

		$this->load->model('setting/event');
		$this->model_setting_event->deleteEventByCode(self::EVENT_CONTROLLER);
		$this->model_setting_event->deleteEventByCode(self::EVENT_VIEW);

		$this->model_setting_event->addEvent(self::EVENT_CONTROLLER, 'catalog/controller/common/footer/after', 'extension/module/roko/inject', 1, 10);

		$this->model_setting_event->addEvent(self::EVENT_VIEW, 'catalog/view/common/footer/after', 'extension/module/roko/inject', 1, 10);
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
			CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "roko_chat_log` (
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

	private function getRecentLogs(): array {
		try {
			$query = $this->db->query("
				SELECT *
				FROM `" . DB_PREFIX . "roko_chat_log`
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
				FROM `" . DB_PREFIX . "roko_chat_log`
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

	private function isAjaxRequest(): bool {
		return isset($this->request->server['HTTP_X_REQUESTED_WITH'])
			&& strtolower((string)$this->request->server['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
	}
}
