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
		$data['upload_sitemap'] = $this->url->link('extension/module/roko/uploadSitemap', 'user_token=' . $this->session->data['user_token']);
		$data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module');
		$data['success'] = (string)($this->session->data['success'] ?? '');
		$data['error_warning'] = (string)($this->session->data['error'] ?? '');
		unset($this->session->data['success'], $this->session->data['error']);

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
		$data['module_roko_sitemap_url'] = (string)$this->config->get('module_roko_sitemap_url');
		$data['module_roko_system_prompt'] = (string)$this->config->get('module_roko_system_prompt');
		$data['module_roko_catalog_token'] = (string)$this->config->get('module_roko_catalog_token');
		$data['module_roko_lead_webhook_url'] = (string)($this->config->get('module_roko_lead_webhook_url') ?: self::DEFAULT_LEAD_WEBHOOK_URL);
		$data['module_roko_lead_webhook_secret'] = (string)($this->config->get('module_roko_lead_webhook_secret') ?: self::DEFAULT_LEAD_WEBHOOK_SECRET);
		$data['module_roko_footer_injection'] = $this->config->get('module_roko_footer_injection') !== null ? (int)$this->config->get('module_roko_footer_injection') : 1;
		$data['module_roko_widget_title'] = $widget_title;
		$data['module_roko_widget_button'] = $widget_button;
		$data['logs'] = $this->getRecentLogs();
		$data['sitemap_pages'] = $this->getIndexedSitemapPages();
		$data['sitemap_pages_total'] = $this->getIndexedSitemapPagesTotal($data['sitemap_pages']);
		$data['sitemap_cache_status'] = $this->getSitemapCacheStatus($data['sitemap_pages_total']);
		$data['uploaded_sitemap_status'] = $this->getUploadedSitemapStatus();
		$data['uploaded_sitemap_diagnostics'] = $this->getUploadedSitemapDiagnostics();
		$data['warm_sitemap_url'] = $this->getWarmSitemapUrl();

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
				'module_roko_sitemap_url' => $this->normalizeUrl((string)($this->request->post['module_roko_sitemap_url'] ?? '')),
				'module_roko_system_prompt' => $this->limitSettingText((string)($this->request->post['module_roko_system_prompt'] ?? ''), 12000),
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

	public function uploadSitemap(): void {
		$this->load->language('extension/module/roko');

		if (!$this->user->hasPermission('modify', 'extension/module/roko')) {
			$this->session->data['error'] = $this->language->get('error_permission');
			$this->redirectToModule();
			return;
		}

		$file = $this->request->files['sitemap_file'] ?? null;

		if (!is_array($file) || empty($file['tmp_name']) || !is_uploaded_file((string)$file['tmp_name'])) {
			$error_code = is_array($file) ? (int)($file['error'] ?? 0) : 0;
			$this->session->data['error'] = $this->language->get('error_upload_sitemap') . ' ' . $this->uploadErrorMessage($error_code) . ' ' . $this->uploadPhpLimitSummary();
			$this->redirectToModule();
			return;
		}

		$name = strtolower((string)($file['name'] ?? ''));

		if (!preg_match('/\.(xml|xml\.gz|gz)$/i', $name)) {
			$this->session->data['error'] = $this->language->get('error_upload_sitemap_type');
			$this->redirectToModule();
			return;
		}

		$body = file_get_contents((string)$file['tmp_name']);

		if (!is_string($body) || $body === '') {
			$this->session->data['error'] = $this->language->get('error_upload_sitemap') . ' Uploaded temporary file could not be read. ' . $this->uploadPhpLimitSummary();
			$this->redirectToModule();
			return;
		}

		if (preg_match('/\.gz$/i', $name)) {
			if (!function_exists('gzdecode')) {
				$this->session->data['error'] = $this->language->get('error_upload_sitemap_type');
				$this->redirectToModule();
				return;
			}

			$decoded = @gzdecode($body);

			if (!is_string($decoded) || $decoded === '') {
				$this->session->data['error'] = $this->language->get('error_upload_sitemap_content');
				$this->redirectToModule();
				return;
			}

			$body = $decoded;
		}

		if (!preg_match('~<(urlset|sitemapindex|loc)\b~i', $body)) {
			$this->session->data['error'] = $this->language->get('error_upload_sitemap_content');
			$this->redirectToModule();
			return;
		}

		$written = false;
		$write_results = [];

		foreach ($this->uploadedSitemapCachePaths() as $path) {
			$wrote = $path !== '' && is_dir(dirname($path)) && is_writable(dirname($path)) && @file_put_contents($path, $body) !== false;
			$write_results[] = $this->sitemapPathDiagnostics($path, $wrote ? 'write=ok' : 'write=failed');

			if ($wrote) {
				$written = true;
			}
		}

		if (!$written) {
			$this->session->data['error'] = $this->language->get('error_upload_sitemap_write') . ' Tried: ' . implode(' || ', $write_results);
			$this->redirectToModule();
			return;
		}

		$this->clearSitemapCaches();

		$url_count = preg_match_all('~<loc>~i', $body);
		$this->session->data['success'] = sprintf($this->language->get('text_upload_sitemap_success'), (int)$url_count);
		$this->redirectToModule();
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
			'module_roko_sitemap_url' => '',
			'module_roko_system_prompt' => '',
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

		$this->createSitemapPageTable();
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

	private function getIndexedSitemapPages(): array {
		$db_pages = $this->getIndexedSitemapPagesFromDb();

		if ($db_pages) {
			return $db_pages;
		}

		$path = $this->findSitemapContentCachePath();

		if ($path === '' || !is_file($path)) {
			return [];
		}

		$payload = json_decode((string)file_get_contents($path), true);

		if (!is_array($payload)) {
			return [];
		}

		$pages = [];

		foreach ($payload as $url => $entry) {
			if (!is_array($entry)) {
				continue;
			}

			$page_url = trim((string)($entry['url'] ?? $url));

			if ($page_url === '') {
				continue;
			}

			$fetched_at = (int)($entry['fetched_at'] ?? 0);
			$pages[] = [
				'title' => $this->shortText((string)($entry['title'] ?? $page_url), 120),
				'url' => $page_url,
				'content_type' => (string)($entry['content_type'] ?? 'page'),
				'summary' => $this->shortText((string)($entry['description'] ?? $entry['content'] ?? ''), 220),
				'fetched_at' => $fetched_at ? date('Y-m-d H:i:s', $fetched_at) : ''
			];
		}

		usort($pages, function ($a, $b) {
			return strcmp((string)($b['fetched_at'] ?? ''), (string)($a['fetched_at'] ?? ''));
		});

		return array_slice($pages, 0, 200);
	}

	private function getIndexedSitemapPagesFromDb(): array {
		try {
			$this->createSitemapPageTable();

			$query = $this->db->query("
				SELECT `title`, `url`, `content_type`, `image`, `description`, `content`, `fetched_at`
				FROM `" . DB_PREFIX . "roko_sitemap_page`
				ORDER BY `fetched_at` DESC, `page_id` DESC
				LIMIT 500
			");

			$pages = [];

			foreach ($query->rows as $row) {
				$fetched_at = (int)($row['fetched_at'] ?? 0);
				$pages[] = [
					'title' => $this->shortText((string)($row['title'] ?? $row['url'] ?? ''), 120),
					'url' => (string)($row['url'] ?? ''),
					'content_type' => (string)($row['content_type'] ?? 'page'),
					'image' => (string)($row['image'] ?? ''),
					'summary' => $this->shortText((string)($row['description'] ?? $row['content'] ?? ''), 220),
					'fetched_at' => $fetched_at ? date('Y-m-d H:i:s', $fetched_at) : ''
				];
			}

			return $pages;
		} catch (\Throwable $exception) {
			return [];
		}
	}

	private function getIndexedSitemapPagesTotal(array $displayed_pages): int {
		try {
			$this->createSitemapPageTable();
			$query = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "roko_sitemap_page`");
			$total = (int)($query->row['total'] ?? 0);

			return $total > 0 ? $total : count($displayed_pages);
		} catch (\Throwable $exception) {
			return count($displayed_pages);
		}
	}

	private function getUploadedSitemapStatus(): string {
		$path = $this->uploadedSitemapCachePath();

		if ($path === '' || !is_file($path)) {
			return $this->language->get('text_no_uploaded_sitemap');
		}

		$body = file_get_contents($path);
		$url_count = is_string($body) ? (int)preg_match_all('~<loc>~i', $body) : 0;

		return sprintf($this->language->get('text_uploaded_sitemap_status'), $url_count, date('Y-m-d H:i:s', (int)filemtime($path)));
	}

	private function getUploadedSitemapDiagnostics(): string {
		$lines = [
			$this->uploadPhpLimitSummary(),
			'CONTENT_LENGTH=' . (string)($this->request->server['CONTENT_LENGTH'] ?? ''),
			'DIR_CACHE=' . (defined('DIR_CACHE') ? (string)DIR_CACHE : 'not defined'),
			'DIR_STORAGE=' . (defined('DIR_STORAGE') ? (string)DIR_STORAGE : 'not defined')
		];

		foreach ($this->uploadedSitemapCachePaths() as $path) {
			$lines[] = $this->sitemapPathDiagnostics($path);
		}

		return implode("\n", $lines);
	}

	private function uploadPhpLimitSummary(): string {
		return 'upload_max_filesize=' . (string)ini_get('upload_max_filesize') . ', post_max_size=' . (string)ini_get('post_max_size') . ', max_file_uploads=' . (string)ini_get('max_file_uploads');
	}

	private function uploadErrorMessage(int $code): string {
		$messages = [
			UPLOAD_ERR_INI_SIZE => 'Upload error 1: file is larger than upload_max_filesize.',
			UPLOAD_ERR_FORM_SIZE => 'Upload error 2: file is larger than form MAX_FILE_SIZE.',
			UPLOAD_ERR_PARTIAL => 'Upload error 3: file was only partially uploaded.',
			UPLOAD_ERR_NO_FILE => 'Upload error 4: no file reached PHP.',
			UPLOAD_ERR_NO_TMP_DIR => 'Upload error 6: missing temporary upload directory.',
			UPLOAD_ERR_CANT_WRITE => 'Upload error 7: PHP could not write the uploaded file to disk.',
			UPLOAD_ERR_EXTENSION => 'Upload error 8: a PHP extension stopped the upload.'
		];

		return $messages[$code] ?? ('Upload error code: ' . $code);
	}

	private function sitemapPathDiagnostics(string $path, string $prefix = ''): string {
		if ($path === '') {
			return trim($prefix . ' path=empty');
		}

		$dir = dirname($path);
		$parts = [
			$prefix,
			'path=' . $path,
			'dir_exists=' . (is_dir($dir) ? 'yes' : 'no'),
			'dir_writable=' . (is_dir($dir) && is_writable($dir) ? 'yes' : 'no'),
			'file_exists=' . (is_file($path) ? 'yes' : 'no'),
			'file_readable=' . (is_file($path) && is_readable($path) ? 'yes' : 'no')
		];

		if (is_file($path)) {
			$parts[] = 'bytes=' . (string)(int)filesize($path);
			$parts[] = 'mtime=' . date('Y-m-d H:i:s', (int)filemtime($path));
		}

		return trim(implode(' ', array_filter($parts, 'strlen')));
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

	private function clearSitemapCaches(): void {
		if (!defined('DIR_CACHE')) {
			return;
		}

		$cache_roots = [DIR_CACHE];

		foreach ($this->uploadedSitemapCachePaths() as $path) {
			$cache_roots[] = dirname($path);
		}

		$patterns = [
			'cache.roko.sitemap.v*.json',
			'cache.roko.sitemap.upload.v*.json',
			'cache.roko.sitemap.content.v1.*.json',
			'cache.roko.sitemap.content.v2.*.json'
		];

		foreach (array_unique($cache_roots) as $cache_root_path) {
			$cache_root = realpath($cache_root_path);

			if (!$cache_root) {
				continue;
			}

			foreach ($patterns as $pattern) {
				$matches = glob(rtrim($cache_root_path, '/\\') . DIRECTORY_SEPARATOR . $pattern);

				if (!is_array($matches)) {
					continue;
				}

				foreach ($matches as $path) {
					$real_path = realpath($path);

					if ($real_path && strpos($real_path, $cache_root . DIRECTORY_SEPARATOR) === 0 && is_file($real_path)) {
						@unlink($real_path);
					}
				}
			}
		}
	}

	private function redirectToModule(): void {
		$redirect = html_entity_decode($this->url->link('extension/module/roko', 'user_token=' . $this->session->data['user_token'], true), ENT_QUOTES, 'UTF-8');
		$this->response->redirect($redirect);
	}

	private function getSitemapCacheStatus(int $cached_pages): string {
		if (!$this->sitemapUrlCandidates() && !is_file($this->uploadedSitemapCachePath())) {
			return $this->language->get('text_sitemap_not_configured');
		}

		$db_fetched_at = $this->getLatestSitemapDbFetchedAt();

		if ($cached_pages > 0 && $db_fetched_at > 0) {
			return sprintf($this->language->get('text_sitemap_cached_count'), $cached_pages, date('Y-m-d H:i:s', $db_fetched_at));
		}

		$content_path = $this->findSitemapContentCachePath();

		if ($content_path === '' || !is_file($content_path)) {
			return $this->language->get('text_sitemap_not_warmed');
		}

		return sprintf($this->language->get('text_sitemap_cached_count'), $cached_pages, date('Y-m-d H:i:s', (int)filemtime($content_path)));
	}

	private function getLatestSitemapDbFetchedAt(): int {
		try {
			$this->createSitemapPageTable();
			$query = $this->db->query("SELECT MAX(`fetched_at`) AS `fetched_at` FROM `" . DB_PREFIX . "roko_sitemap_page`");

			return (int)($query->row['fetched_at'] ?? 0);
		} catch (\Throwable $exception) {
			return 0;
		}
	}

	private function configuredSitemapUrl(): string {
		$candidates = $this->sitemapUrlCandidates();
		return $candidates[0] ?? '';
	}

	private function sitemapUrlCandidates(): array {
		$urls = [];
		$configured = trim((string)$this->config->get('module_roko_sitemap_url'));

		if ($configured !== '') {
			$urls[] = $configured;
		}

		foreach ($this->siteBaseUrlCandidates() as $base) {
			$urls[] = rtrim($base, '/') . '/sitemap.xml';
		}

		$expanded = [];

		foreach ($urls as $url) {
			$url = trim((string)$url);
			$parts = parse_url($url);

			if (!$parts || empty($parts['scheme']) || empty($parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
				continue;
			}

			$path = (string)($parts['path'] ?? '/sitemap.xml');
			$query = isset($parts['query']) ? '?' . $parts['query'] : '';
			$host = (string)$parts['host'];
			$port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
			$schemes = array_unique([strtolower((string)$parts['scheme']), strtolower((string)$parts['scheme']) === 'https' ? 'http' : 'https']);
			$hosts = array_unique([$host, strpos(strtolower($host), 'www.') === 0 ? substr($host, 4) : 'www.' . $host]);

			foreach ($schemes as $scheme) {
				foreach ($hosts as $candidate_host) {
					$expanded[] = $scheme . '://' . $candidate_host . $port . $path . $query;
				}
			}
		}

		return array_values(array_unique($expanded));
	}

	private function siteBaseUrlCandidates(): array {
		$bases = [];

		foreach (['config_url', 'config_ssl'] as $key) {
			$value = trim((string)$this->config->get($key));

			if ($value !== '') {
				$bases[] = $value;
			}
		}

		foreach (['HTTP_CATALOG', 'HTTPS_CATALOG', 'HTTPS_SERVER', 'HTTP_SERVER'] as $constant) {
			if (defined($constant)) {
				$value = trim((string)constant($constant));

				if ($value !== '') {
					$bases[] = $value;
				}
			}
		}

		return array_values(array_unique($bases));
	}

	private function findSitemapContentCachePath(): string {
		foreach ($this->sitemapUrlCandidates() as $url) {
			$path = $this->cacheFilePath('roko.sitemap.content.v1.' . md5($url));

			if ($path !== '' && is_file($path)) {
				return $path;
			}
		}

		if (!defined('DIR_CACHE')) {
			return '';
		}

		$matches = [];

		foreach (['cache.roko.sitemap.content.v2.*.json', 'cache.roko.sitemap.content.v1.*.json'] as $pattern) {
			$found = glob(rtrim(DIR_CACHE, '/\\') . DIRECTORY_SEPARATOR . $pattern);

			if (is_array($found)) {
				$matches = array_merge($matches, $found);
			}
		}

		if (!$matches) {
			return '';
		}

		usort($matches, function ($a, $b) {
			return (int)filemtime($b) <=> (int)filemtime($a);
		});

		return (string)$matches[0];
	}

	private function getWarmSitemapUrl(): string {
		$base = (string)$this->config->get('config_url');

		if ($base === '' && defined('HTTP_CATALOG')) {
			$base = (string)HTTP_CATALOG;
		}

		if ($base === '' && defined('HTTP_SERVER')) {
			$base = (string)HTTP_SERVER;
		}

		if ($base === '') {
			return '';
		}

		$query = 'route=extension/module/roko/warmSitemap&limit=50&refresh=1';
		$token = trim((string)$this->config->get('module_roko_catalog_token'));

		if ($token !== '') {
			$query .= '&token=' . urlencode($token);
		}

		return rtrim($base, '/') . '/index.php?' . $query;
	}

	private function cacheFilePath(string $cache_key): string {
		if (!defined('DIR_CACHE')) {
			return '';
		}

		return rtrim(DIR_CACHE, '/\\') . DIRECTORY_SEPARATOR . 'cache.' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $cache_key) . '.json';
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

	private function shortText(string $value, int $limit): string {
		$value = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($value, ENT_QUOTES, 'UTF-8'))));

		if ($value === '' || $limit <= 0) {
			return $value;
		}

		if (function_exists('mb_strlen') && function_exists('mb_substr')) {
			return mb_strlen($value, 'UTF-8') > $limit ? rtrim(mb_substr($value, 0, $limit, 'UTF-8')) . '...' : $value;
		}

		return strlen($value) > $limit ? rtrim(substr($value, 0, $limit)) . '...' : $value;
	}

	private function isAjaxRequest(): bool {
		return isset($this->request->server['HTTP_X_REQUESTED_WITH'])
			&& strtolower((string)$this->request->server['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
	}
}
