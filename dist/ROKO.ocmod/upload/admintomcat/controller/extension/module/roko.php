<?php
class ControllerExtensionModuleRoko extends Controller {
	private const SETTING_CODE = 'module_roko';
	private const EVENT_CONTROLLER = 'roko_footer_controller';
	private const EVENT_VIEW = 'roko_footer_view';

	public function index(): void {
		$this->load->language('extension/module/roko');
		$this->createLogTable();
		$this->importLegacyLeadsIfNeeded();
		$this->load->model('setting/setting');

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
		$data['clear_redirect_logs'] = $this->url->link('extension/module/roko/clearRedirectLogs', 'user_token=' . $this->session->data['user_token']);
		$data['export_redirect_logs'] = $this->url->link('extension/module/roko/exportRedirectLogs', 'user_token=' . $this->session->data['user_token']);
		$data['clear_leads'] = $this->url->link('extension/module/roko/clearLeads', 'user_token=' . $this->session->data['user_token']);
		$data['repair_lead_storage'] = $this->url->link('extension/module/roko/repairLeadStorage', 'user_token=' . $this->session->data['user_token']);
		$data['export_leads'] = $this->url->link('extension/module/roko/exportLeads', 'user_token=' . $this->session->data['user_token']);
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
		$data['module_roko_footer_injection'] = $this->config->get('module_roko_footer_injection') !== null ? (int)$this->config->get('module_roko_footer_injection') : 1;
		$data['module_roko_suggest_next_questions'] = $this->config->get('module_roko_suggest_next_questions') !== null ? (int)$this->config->get('module_roko_suggest_next_questions') : 1;
		$data['module_roko_suggest_blogs'] = $this->config->get('module_roko_suggest_blogs') !== null ? (int)$this->config->get('module_roko_suggest_blogs') : 1;
		$data['module_roko_suggest_categories'] = $this->config->get('module_roko_suggest_categories') !== null ? (int)$this->config->get('module_roko_suggest_categories') : 1;
		$data['module_roko_suggest_products'] = $this->config->get('module_roko_suggest_products') !== null ? (int)$this->config->get('module_roko_suggest_products') : 1;
		$data['module_roko_widget_title'] = $widget_title;
		$data['module_roko_widget_button'] = $widget_button;
		$data['module_roko_redirect_utm'] = (string)$this->config->get('module_roko_redirect_utm');
		$data['user_token'] = $this->session->data['user_token'];
		$data['module_url'] = $this->url->link('extension/module/roko', 'user_token=' . $data['user_token']);
		$active_tab = (string)($this->request->get['tab'] ?? 'logs');
		$active_tab = in_array($active_tab, ['logs', 'indexed-pages', 'redirects', 'leads'], true) ? $active_tab : 'logs';
		$log_page = max(1, (int)($this->request->get['log_page'] ?? 1));
		$sitemap_page = max(1, (int)($this->request->get['sitemap_page'] ?? 1));
		$log_limit = 30;
		$sitemap_limit = 100;
		$sitemap_search = $this->normalizeFilterSearch((string)($this->request->get['sitemap_search'] ?? ''));
		$sitemap_type = $this->normalizeFilterType((string)($this->request->get['sitemap_type'] ?? ''));
		$sitemap_filters = [
			'search' => $sitemap_search,
			'type' => $sitemap_type
		];

		$logs_data = $this->getRecentLogs($log_page, $log_limit);
		$sitemap_data = $this->getIndexedSitemapPages($sitemap_page, $sitemap_limit, $sitemap_filters);

		$data['active_tab'] = $active_tab;
		$data['logs'] = $logs_data['rows'];
		$data['sitemap_pages'] = $sitemap_data['rows'];
		$data['sitemap_pages_total'] = $sitemap_data['total'];
		$data['sitemap_search'] = $sitemap_search;
		$data['sitemap_type'] = $sitemap_type;
		$data['sitemap_types'] = $this->getSitemapContentTypes();
		$redirect_page = max(1, (int)($this->request->get['redirect_page'] ?? 1));
		$redirect_limit = 50;
		$redirect_search = $this->normalizeFilterSearch((string)($this->request->get['redirect_search'] ?? ''));
		$redirect_action = $this->normalizeFilterAction((string)($this->request->get['redirect_action'] ?? ''));
		$redirect_filters = [
			'search' => $redirect_search,
			'action' => $redirect_action
		];
		$redirect_data = $this->getRedirectLogs($redirect_page, $redirect_limit, $redirect_filters);
		$data['redirect_logs'] = $redirect_data['rows'];
		$data['redirect_logs_total'] = $redirect_data['total'];
		$data['redirect_search'] = $redirect_search;
		$data['redirect_action'] = $redirect_action;
		$data['redirect_actions'] = $this->getRedirectActionTypes();
		$lead_page = max(1, (int)($this->request->get['lead_page'] ?? 1));
		$lead_limit = 50;
		$lead_search = $this->normalizeFilterSearch((string)($this->request->get['lead_search'] ?? ''));
		$lead_filters = ['search' => $lead_search];
		$lead_data = $this->getLeads($lead_page, $lead_limit, $lead_filters);
		$data['leads'] = $lead_data['rows'];
		$data['leads_total'] = $lead_data['total'];
		$data['lead_search'] = $lead_search;
		$data['sitemap_cache_status'] = $this->getSitemapCacheStatus($data['sitemap_pages_total']);
		$data['uploaded_sitemap_status'] = $this->getUploadedSitemapStatus();
		$data['uploaded_sitemap_diagnostics'] = $this->getUploadedSitemapDiagnostics();
		$data['warm_sitemap_url'] = $this->getWarmSitemapUrl();
		$pagination_common = [
			'log_page' => $log_page,
			'sitemap_page' => $sitemap_page,
			'redirect_page' => $redirect_page,
			'lead_page' => $lead_page,
			'sitemap_search' => $sitemap_search,
			'sitemap_type' => $sitemap_type,
			'redirect_search' => $redirect_search,
			'redirect_action' => $redirect_action,
			'lead_search' => $lead_search
		];
		$data['logs_pagination'] = $this->buildPagination($logs_data['total'], $log_page, $log_limit, 'log_page', array_merge($pagination_common, ['tab' => 'logs']));
		$data['sitemap_pages_pagination'] = $this->buildPagination($sitemap_data['total'], $sitemap_page, $sitemap_limit, 'sitemap_page', array_merge($pagination_common, ['tab' => 'indexed-pages']));
		$data['redirect_logs_pagination'] = $this->buildPagination($redirect_data['total'], $redirect_page, $redirect_limit, 'redirect_page', array_merge($pagination_common, ['tab' => 'redirects']));
		$data['leads_pagination'] = $this->buildPagination($lead_data['total'], $lead_page, $lead_limit, 'lead_page', array_merge($pagination_common, ['tab' => 'leads']));

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
				'module_roko_footer_injection' => (int)($this->request->post['module_roko_footer_injection'] ?? 0),
				'module_roko_suggest_next_questions' => (int)($this->request->post['module_roko_suggest_next_questions'] ?? 0),
				'module_roko_suggest_blogs' => (int)($this->request->post['module_roko_suggest_blogs'] ?? 0),
				'module_roko_suggest_categories' => (int)($this->request->post['module_roko_suggest_categories'] ?? 0),
				'module_roko_suggest_products' => (int)($this->request->post['module_roko_suggest_products'] ?? 0),
				'module_roko_widget_title' => trim((string)($this->request->post['module_roko_widget_title'] ?? 'ROKO')),
				'module_roko_widget_button' => trim((string)($this->request->post['module_roko_widget_button'] ?? 'Chat')),
				'module_roko_redirect_utm' => $this->limitSettingText((string)($this->request->post['module_roko_redirect_utm'] ?? ''), 500)
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

	public function clearRedirectLogs(): void {
		$this->load->language('extension/module/roko');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/module/roko')) {
			$json['error'] = $this->language->get('error_permission');
		}

		if (!$json) {
			$this->createRedirectLogTable();
			$this->db->query('TRUNCATE TABLE `' . DB_PREFIX . 'roko_redirect_log`');
			$json['success'] = $this->language->get('text_success');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function exportRedirectLogs(): void {
		$this->load->language('extension/module/roko');

		if (!$this->user->hasPermission('access', 'extension/module/roko')) {
			$this->response->addHeader(($this->request->server['SERVER_PROTOCOL'] ?? 'HTTP/1.1') . ' 403 Forbidden');
			$this->response->setOutput($this->language->get('error_permission'));
			return;
		}

		$filename = 'roko-redirect-logs-' . date('Y-m-d-His') . '.xls';

		$this->response->addHeader('Content-Type: application/vnd.ms-excel; charset=utf-8');
		$this->response->addHeader('Content-Disposition: attachment; filename="' . $filename . '"');
		$this->response->addHeader('Cache-Control: max-age=0');
		$this->response->setOutput($this->buildRedirectLogsExcel($this->getExportRedirectLogs()));
	}

	public function clearLeads(): void {
		$this->load->language('extension/module/roko');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/module/roko')) {
			$json['error'] = $this->language->get('error_permission');
		}

		if (!$json) {
			if ($this->createLeadTable()) {
				$this->db->query('TRUNCATE TABLE `' . DB_PREFIX . 'roko_lead`');
				$json['success'] = $this->language->get('text_success');
			} else {
				$json['error'] = sprintf($this->language->get('error_lead_storage'), DB_PREFIX . 'roko_lead');
			}
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function repairLeadStorage(): void {
		$this->load->language('extension/module/roko');
		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/module/roko')) {
			$json['error'] = $this->language->get('error_permission');
		} elseif ($this->createLeadTable()) {
			$json['success'] = sprintf($this->language->get('text_lead_storage_ready'), DB_PREFIX . 'roko_lead');
		} else {
			$json['error'] = sprintf($this->language->get('error_lead_storage'), DB_PREFIX . 'roko_lead');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	public function exportLeads(): void {
		$this->load->language('extension/module/roko');

		if (!$this->user->hasPermission('access', 'extension/module/roko')) {
			$this->response->addHeader(($this->request->server['SERVER_PROTOCOL'] ?? 'HTTP/1.1') . ' 403 Forbidden');
			$this->response->setOutput($this->language->get('error_permission'));
			return;
		}

		$filename = 'roko-leads-' . date('Y-m-d-His') . '.xls';

		$this->response->addHeader('Content-Type: application/vnd.ms-excel; charset=utf-8');
		$this->response->addHeader('Content-Disposition: attachment; filename="' . $filename . '"');
		$this->response->addHeader('Cache-Control: max-age=0');
		$this->response->setOutput($this->buildLeadsExcel($this->getExportLeads()));
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
			'module_roko_footer_injection' => 1,
			'module_roko_suggest_next_questions' => 1,
			'module_roko_suggest_blogs' => 1,
			'module_roko_suggest_categories' => 1,
			'module_roko_suggest_products' => 1,
			'module_roko_widget_title' => 'ROKO',
			'module_roko_widget_button' => 'Chat',
			'module_roko_redirect_utm' => 'utm_source=roko&utm_medium=assistant&utm_campaign=chat'
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
		$this->createRedirectLogTable();
		$this->createLeadTable();
	}

	private function createLeadTable(): bool {
		if ($this->leadTableExists()) {
			return $this->ensureLeadTableColumns();
		}

		$table = DB_PREFIX . 'roko_lead';
		$definition = "
			CREATE TABLE IF NOT EXISTS `" . $table . "` (
				`lead_id` int(11) NOT NULL AUTO_INCREMENT,
				`conversation_id` varchar(80) NOT NULL DEFAULT '',
				`request_id` varchar(48) NOT NULL DEFAULT '',
				`item_index` int(11) NOT NULL DEFAULT 1,
				`product_name` varchar(180) NOT NULL DEFAULT '',
				`brand` varchar(180) NOT NULL DEFAULT '',
				`model` varchar(180) NOT NULL DEFAULT '',
				`part_number` varchar(180) NOT NULL DEFAULT '',
				`qty` varchar(40) NOT NULL DEFAULT '',
				`unit` varchar(40) NOT NULL DEFAULT '',
				`item_details` text NULL,
				`requirements` text NULL,
				`source_request` text NULL,
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
				KEY `request_id` (`request_id`),
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
					return $this->ensureLeadTableColumns();
				}
			} catch (\Throwable $exception) {
				$errors[] = $exception->getMessage();
			}
		}

		$this->log->write('ROKO lead table setup failed for ' . $table . ': ' . implode(' | ', array_unique($errors)));
		return false;
	}

	private function ensureLeadTableColumns(): bool {
		$table = DB_PREFIX . 'roko_lead';
		$definitions = [
			'request_id' => "varchar(48) NOT NULL DEFAULT '' AFTER `conversation_id`",
			'item_index' => "int(11) NOT NULL DEFAULT 1 AFTER `request_id`",
			'brand' => "varchar(180) NOT NULL DEFAULT '' AFTER `product_name`",
			'model' => "varchar(180) NOT NULL DEFAULT '' AFTER `brand`",
			'part_number' => "varchar(180) NOT NULL DEFAULT '' AFTER `model`",
			'unit' => "varchar(40) NOT NULL DEFAULT '' AFTER `qty`",
			'item_details' => "text NULL AFTER `unit`",
			'requirements' => "text NULL AFTER `item_details`",
			'source_request' => "text NULL AFTER `requirements`"
		];

		try {
			$columns_query = $this->db->query("SHOW COLUMNS FROM `" . $table . "`");
			$columns = [];

			foreach ($columns_query->rows as $row) {
				$columns[(string)($row['Field'] ?? '')] = true;
			}

			foreach ($definitions as $column => $definition) {
				if (!isset($columns[$column])) {
					$this->db->query("ALTER TABLE `" . $table . "` ADD COLUMN `" . $column . "` " . $definition);
				}
			}

			$indexes_query = $this->db->query("SHOW INDEX FROM `" . $table . "`");
			$has_request_index = false;

			foreach ($indexes_query->rows as $row) {
				if ((string)($row['Key_name'] ?? '') === 'request_id') {
					$has_request_index = true;
					break;
				}
			}

			if (!$has_request_index) {
				$this->db->query("ALTER TABLE `" . $table . "` ADD KEY `request_id` (`request_id`)");
			}

			return true;
		} catch (\Throwable $exception) {
			$this->log->write('ROKO lead table migration failed for ' . $table . ': ' . $exception->getMessage());
			return false;
		}
	}

	private function leadTableExists(): bool {
		try {
			$this->db->query("SELECT `lead_id` FROM `" . DB_PREFIX . "roko_lead` LIMIT 1");
			return true;
		} catch (\Throwable $exception) {
			return false;
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

	private function getRecentLogs(int $page = 1, int $limit = 30): array {
		$page = max(1, $page);
		$limit = min(200, max(10, $limit));
		$start = ($page - 1) * $limit;

		try {
			$total_query = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "roko_chat_log`");
			$total = (int)($total_query->row['total'] ?? 0);
			$query = $this->db->query("
				SELECT *
				FROM `" . DB_PREFIX . "roko_chat_log`
				ORDER BY `log_id` DESC
				LIMIT " . (int)$start . ", " . (int)$limit . "
			");

			return [
				'rows' => $query->rows,
				'total' => $total
			];
		} catch (\Throwable $exception) {
			return ['rows' => [], 'total' => 0];
		}
	}

	private function getIndexedSitemapPages(int $page = 1, int $limit = 100, array $filters = []): array {
		$page = max(1, $page);
		$limit = min(500, max(20, $limit));
		$db_pages = $this->getIndexedSitemapPagesFromDb($page, $limit, $filters);

		if ($this->countSitemapDbRows() > 0) {
			return [
				'rows' => $db_pages['rows'],
				'total' => (int)($db_pages['total'] ?? 0)
			];
		}

		$path = $this->findSitemapContentCachePath();

		if ($path === '' || !is_file($path)) {
			return ['rows' => [], 'total' => 0];
		}

		$payload = json_decode((string)file_get_contents($path), true);

		if (!is_array($payload)) {
			return ['rows' => [], 'total' => 0];
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
			$row = [
				'title' => $this->shortText((string)($entry['title'] ?? $page_url), 120),
				'url' => $page_url,
				'content_type' => (string)($entry['content_type'] ?? 'page'),
				'summary' => $this->shortText((string)($entry['description'] ?? $entry['content'] ?? ''), 220),
				'fetched_at' => $fetched_at ? date('Y-m-d H:i:s', $fetched_at) : ''
			];

			if (!$this->pageMatchesSitemapFilters($row, $filters)) {
				continue;
			}

			$pages[] = $row;
		}

		usort($pages, function ($a, $b) {
			return strcmp((string)($b['fetched_at'] ?? ''), (string)($a['fetched_at'] ?? ''));
		});

		$total = count($pages);
		$offset = ($page - 1) * $limit;

		return [
			'rows' => array_slice($pages, $offset, $limit),
			'total' => $total
		];
	}

	private function getIndexedSitemapPagesFromDb(int $page = 1, int $limit = 100, array $filters = []): array {
		$page = max(1, $page);
		$limit = min(500, max(20, $limit));
		$offset = ($page - 1) * $limit;

		try {
			$this->createSitemapPageTable();
			$where = $this->buildSitemapWhereSql($filters);
			$total_query = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "roko_sitemap_page`" . $where);
			$total = (int)($total_query->row['total'] ?? 0);
			$query = $this->db->query("
				SELECT `title`, `url`, `content_type`, `image`, `description`, `content`, `fetched_at`
				FROM `" . DB_PREFIX . "roko_sitemap_page`
				" . $where . "
				ORDER BY `fetched_at` DESC, `page_id` DESC
				LIMIT " . (int)$offset . ", " . (int)$limit . "
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

			return [
				'rows' => $pages,
				'total' => $total
			];
		} catch (\Throwable $exception) {
			return ['rows' => [], 'total' => 0];
		}
	}

	private function buildPagination(int $total, int $page, int $limit, string $page_key, array $extra = []): string {
		$pagination = new Pagination();
		$pagination->total = max(0, $total);
		$pagination->page = max(1, $page);
		$pagination->limit = max(1, $limit);
		$params = ['user_token=' . $this->session->data['user_token']];

		foreach ($extra as $key => $value) {
			if ($value === '' || $value === null) {
				continue;
			}

			$params[] = $key . '=' . urlencode((string)$value);
		}

		$params[] = $page_key . '={page}';
		$pagination->url = $this->url->link('extension/module/roko', implode('&', $params));

		return $pagination->render();
	}

	private function getRedirectLogs(int $page = 1, int $limit = 50, array $filters = []): array {
		$page = max(1, $page);
		$limit = min(200, max(10, $limit));
		$start = ($page - 1) * $limit;

		try {
			$this->createRedirectLogTable();
			$where = $this->buildRedirectWhereSql($filters);
			$total_query = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "roko_redirect_log`" . $where);
			$total = (int)($total_query->row['total'] ?? 0);
			$query = $this->db->query("
				SELECT `date_added`, `conversation_id`, `action_type`, `source_url`, `destination_url`, `destination_url_utm`, `utm_payload`
				FROM `" . DB_PREFIX . "roko_redirect_log`
				" . $where . "
				ORDER BY `redirect_log_id` DESC
				LIMIT " . (int)$start . ", " . (int)$limit . "
			");

			return [
				'rows' => $query->rows,
				'total' => $total
			];
		} catch (\Throwable $exception) {
			return ['rows' => [], 'total' => 0];
		}
	}

	private function countSitemapDbRows(): int {
		try {
			$this->createSitemapPageTable();
			$query = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "roko_sitemap_page`");

			return (int)($query->row['total'] ?? 0);
		} catch (\Throwable $exception) {
			return 0;
		}
	}

	private function buildSitemapWhereSql(array $filters): string {
		$parts = [];
		$search = trim((string)($filters['search'] ?? ''));
		$type = trim((string)($filters['type'] ?? ''));

		if ($type !== '') {
			$parts[] = "`content_type` = '" . $this->db->escape(substr($type, 0, 20)) . "'";
		}

		if ($search !== '') {
			$like = "'%" . $this->db->escape($search) . "%'";
			$parts[] = "(`title` LIKE " . $like . " OR `url` LIKE " . $like . " OR `description` LIKE " . $like . " OR `content` LIKE " . $like . ")";
		}

		return $parts ? ' WHERE ' . implode(' AND ', $parts) : '';
	}

	private function buildRedirectWhereSql(array $filters): string {
		$parts = [];
		$search = trim((string)($filters['search'] ?? ''));
		$action = trim((string)($filters['action'] ?? ''));

		if ($action !== '') {
			$parts[] = "`action_type` = '" . $this->db->escape(substr($action, 0, 40)) . "'";
		}

		if ($search !== '') {
			$like = "'%" . $this->db->escape($search) . "%'";
			$parts[] = "(`conversation_id` LIKE " . $like . " OR `action_type` LIKE " . $like . " OR `source_url` LIKE " . $like . " OR `destination_url` LIKE " . $like . " OR `destination_url_utm` LIKE " . $like . " OR `utm_payload` LIKE " . $like . ")";
		}

		return $parts ? ' WHERE ' . implode(' AND ', $parts) : '';
	}

	private function pageMatchesSitemapFilters(array $page, array $filters): bool {
		$search = trim((string)($filters['search'] ?? ''));
		$type = trim((string)($filters['type'] ?? ''));

		if ($type !== '' && (string)($page['content_type'] ?? '') !== $type) {
			return false;
		}

		if ($search === '') {
			return true;
		}

		$haystack = strtolower(implode(' ', [
			(string)($page['title'] ?? ''),
			(string)($page['url'] ?? ''),
			(string)($page['summary'] ?? '')
		]));

		return strpos($haystack, strtolower($search)) !== false;
	}

	private function getSitemapContentTypes(): array {
		$types = [];

		try {
			$this->createSitemapPageTable();
			$query = $this->db->query("
				SELECT DISTINCT `content_type`
				FROM `" . DB_PREFIX . "roko_sitemap_page`
				WHERE `content_type` <> ''
				ORDER BY `content_type` ASC
			");

			foreach ($query->rows as $row) {
				$type = trim((string)($row['content_type'] ?? ''));

				if ($type !== '') {
					$types[] = $type;
				}
			}
		} catch (\Throwable $exception) {
		}

		if (!$types) {
			$types = ['page', 'product', 'blog', 'category'];
		}

		return array_values(array_unique($types));
	}

	private function getRedirectActionTypes(): array {
		try {
			$this->createRedirectLogTable();
			$query = $this->db->query("
				SELECT DISTINCT `action_type`
				FROM `" . DB_PREFIX . "roko_redirect_log`
				WHERE `action_type` <> ''
				ORDER BY `action_type` ASC
			");
			$types = [];

			foreach ($query->rows as $row) {
				$type = trim((string)($row['action_type'] ?? ''));

				if ($type !== '') {
					$types[] = $type;
				}
			}

			return $types;
		} catch (\Throwable $exception) {
			return [];
		}
	}

	private function normalizeFilterSearch(string $value, int $max = 120): string {
		$value = trim($value);
		$value = str_replace(['%', '_'], '', $value);

		return substr($value, 0, $max);
	}

	private function normalizeFilterType(string $value): string {
		$value = trim($value);
		$value = preg_replace('/[^a-z0-9_-]/i', '', $value) ?? '';

		return substr($value, 0, 20);
	}

	private function normalizeFilterAction(string $value): string {
		$value = trim($value);
		$value = preg_replace('/[^a-z0-9_-]/i', '', $value) ?? '';

		return substr($value, 0, 40);
	}

	private function getLeads(int $page = 1, int $limit = 50, array $filters = []): array {
		$page = max(1, $page);
		$limit = min(200, max(10, $limit));
		$start = ($page - 1) * $limit;

		try {
			$this->createLeadTable();
			$where = $this->buildLeadWhereSql($filters);
			$total_query = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "roko_lead`" . $where);
			$total = (int)($total_query->row['total'] ?? 0);
			$query = $this->db->query("
				SELECT
					`date_added`,
					`conversation_id`,
					`request_id`,
					`item_index`,
					`product_name`,
					`brand`,
					`model`,
					`part_number`,
					`qty`,
					`unit`,
					`item_details`,
					`requirements`,
					`name`,
					`company`,
					`contact_number`,
					`email`,
					`delivery_location`,
					`page_url`,
					`page_title`,
					`customer_id`
				FROM `" . DB_PREFIX . "roko_lead`
				" . $where . "
				ORDER BY `lead_id` DESC
				LIMIT " . (int)$start . ", " . (int)$limit . "
			");

			$rows = [];

			foreach ($query->rows as $row) {
				$rows[] = [
					'date_added' => (string)($row['date_added'] ?? ''),
					'conversation_id' => (string)($row['conversation_id'] ?? ''),
					'request_id' => (string)($row['request_id'] ?? ''),
					'item_index' => (int)($row['item_index'] ?? 1),
					'product_name' => (string)($row['product_name'] ?? ''),
					'brand' => (string)($row['brand'] ?? ''),
					'model' => (string)($row['model'] ?? ''),
					'part_number' => (string)($row['part_number'] ?? ''),
					'qty' => (string)($row['qty'] ?? ''),
					'unit' => (string)($row['unit'] ?? ''),
					'item_details' => (string)($row['item_details'] ?? ''),
					'requirements' => (string)($row['requirements'] ?? ''),
					'name' => (string)($row['name'] ?? ''),
					'company' => (string)($row['company'] ?? ''),
					'contact_number' => (string)($row['contact_number'] ?? ''),
					'email' => (string)($row['email'] ?? ''),
					'delivery_location' => (string)($row['delivery_location'] ?? ''),
					'page_url' => (string)($row['page_url'] ?? ''),
					'page_title' => (string)($row['page_title'] ?? ''),
					'customer_id' => (int)($row['customer_id'] ?? 0)
				];
			}

			return [
				'rows' => $rows,
				'total' => $total
			];
		} catch (\Throwable $exception) {
			return ['rows' => [], 'total' => 0];
		}
	}

	private function buildLeadWhereSql(array $filters): string {
		$search = trim((string)($filters['search'] ?? ''));

		if ($search === '') {
			return '';
		}

		$like = "'%" . $this->db->escape($search) . "%'";

		return " WHERE (`conversation_id` LIKE " . $like
			. " OR `request_id` LIKE " . $like
			. " OR `product_name` LIKE " . $like
			. " OR `brand` LIKE " . $like
			. " OR `model` LIKE " . $like
			. " OR `part_number` LIKE " . $like
			. " OR `qty` LIKE " . $like
			. " OR `unit` LIKE " . $like
			. " OR `item_details` LIKE " . $like
			. " OR `requirements` LIKE " . $like
			. " OR `name` LIKE " . $like
			. " OR `company` LIKE " . $like
			. " OR `contact_number` LIKE " . $like
			. " OR `email` LIKE " . $like
			. " OR `delivery_location` LIKE " . $like
			. " OR `page_url` LIKE " . $like
			. " OR `page_title` LIKE " . $like . ")";
	}

	private function importLegacyLeadsIfNeeded(): void {
		try {
			$this->createLeadTable();
			$count_query = $this->db->query("SELECT COUNT(*) AS `total` FROM `" . DB_PREFIX . "roko_lead`");

			if ((int)($count_query->row['total'] ?? 0) > 0) {
				return;
			}

			$query = $this->db->query("
				SELECT `conversation_id`, `customer_id`, `content`, `ip`, `user_agent`, `date_added`
				FROM `" . DB_PREFIX . "roko_chat_log`
				WHERE `role` = 'lead'
				ORDER BY `log_id` ASC
			");

			foreach ($query->rows as $row) {
				$payload = json_decode((string)($row['content'] ?? ''), true);

				if (!is_array($payload) || !is_array($payload['lead'] ?? null)) {
					continue;
				}

				$lead = $payload['lead'];
				$date_added = (string)($row['date_added'] ?? '');

				$this->db->query("
					INSERT INTO `" . DB_PREFIX . "roko_lead`
					SET
						`conversation_id` = '" . $this->db->escape(substr((string)($row['conversation_id'] ?? ''), 0, 80)) . "',
						`request_id` = '',
						`item_index` = '1',
						`product_name` = '" . $this->db->escape(substr((string)($lead['product_name'] ?? ''), 0, 180)) . "',
						`brand` = '',
						`model` = '',
						`part_number` = '',
						`qty` = '" . $this->db->escape(substr((string)($lead['qty'] ?? ''), 0, 40)) . "',
						`unit` = '',
						`item_details` = '',
						`requirements` = '',
						`source_request` = '',
						`name` = '" . $this->db->escape(substr((string)($lead['name'] ?? ''), 0, 180)) . "',
						`company` = '" . $this->db->escape(substr((string)($lead['company'] ?? ''), 0, 180)) . "',
						`contact_number` = '" . $this->db->escape(substr((string)($lead['contact_number'] ?? ''), 0, 180)) . "',
						`email` = '" . $this->db->escape(substr((string)($lead['email'] ?? ''), 0, 180)) . "',
						`delivery_location` = '" . $this->db->escape(substr((string)($lead['delivery_location'] ?? ''), 0, 2000)) . "',
						`page_url` = '',
						`page_title` = '',
						`customer_id` = '" . (int)($row['customer_id'] ?? 0) . "',
						`ip` = '" . $this->db->escape(substr((string)($row['ip'] ?? ''), 0, 45)) . "',
						`user_agent` = '" . $this->db->escape(substr((string)($row['user_agent'] ?? ''), 0, 255)) . "',
						`date_added` = " . ($date_added !== '' ? "'" . $this->db->escape($date_added) . "'" : 'NOW()') . "
				");
			}
		} catch (\Throwable $exception) {
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

		$query = 'route=extension/module/roko/warmSitemap&limit=120&refresh=1';
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

	private function getExportRedirectLogs(): array {
		try {
			$this->createRedirectLogTable();
			$query = $this->db->query("
				SELECT
					`redirect_log_id`,
					`conversation_id`,
					`action_type`,
					`source_url`,
					`destination_url`,
					`destination_url_utm`,
					`utm_payload`,
					`ip`,
					`user_agent`,
					`date_added`
				FROM `" . DB_PREFIX . "roko_redirect_log`
				ORDER BY `redirect_log_id` ASC
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

	private function buildRedirectLogsExcel(array $rows): string {
		$columns = [
			['key' => 'redirect_log_id', 'title' => $this->language->get('column_log_id'), 'type' => 'Number'],
			['key' => 'date_added', 'title' => $this->language->get('column_date'), 'type' => 'String'],
			['key' => 'conversation_id', 'title' => $this->language->get('column_conversation'), 'type' => 'String'],
			['key' => 'action_type', 'title' => $this->language->get('column_action_type'), 'type' => 'String'],
			['key' => 'source_url', 'title' => $this->language->get('column_source_url'), 'type' => 'String'],
			['key' => 'destination_url', 'title' => $this->language->get('column_destination_url'), 'type' => 'String'],
			['key' => 'destination_url_utm', 'title' => $this->language->get('column_destination_url_utm'), 'type' => 'String'],
			['key' => 'utm_payload', 'title' => $this->language->get('column_utm_payload'), 'type' => 'String'],
			['key' => 'ip', 'title' => $this->language->get('column_ip'), 'type' => 'String'],
			['key' => 'user_agent', 'title' => $this->language->get('column_user_agent'), 'type' => 'String']
		];

		$output = [];
		$output[] = '<?xml version="1.0" encoding="UTF-8"?>';
		$output[] = '<?mso-application progid="Excel.Sheet"?>';
		$output[] = '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet" xmlns:html="http://www.w3.org/TR/REC-html40">';
		$output[] = '<Styles><Style ss:ID="header"><Font ss:Bold="1"/><Interior ss:Color="#EADCFD" ss:Pattern="Solid"/></Style></Styles>';
		$output[] = '<Worksheet ss:Name="Redirect logs"><Table>';
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

	private function getExportLeads(): array {
		try {
			$this->createLeadTable();
			$query = $this->db->query("
				SELECT
					`lead_id`,
					`date_added`,
					`conversation_id`,
					`request_id`,
					`item_index`,
					`product_name`,
					`brand`,
					`model`,
					`part_number`,
					`qty`,
					`unit`,
					`item_details`,
					`requirements`,
					`source_request`,
					`name`,
					`company`,
					`contact_number`,
					`email`,
					`delivery_location`,
					`page_url`,
					`page_title`,
					`customer_id`,
					`ip`,
					`user_agent`
				FROM `" . DB_PREFIX . "roko_lead`
				ORDER BY `lead_id` ASC
			");

			return $query->rows;
		} catch (\Throwable $exception) {
			return [];
		}
	}

	private function buildLeadsExcel(array $rows): string {
		$columns = [
			['key' => 'lead_id', 'title' => $this->language->get('column_log_id'), 'type' => 'Number'],
			['key' => 'date_added', 'title' => $this->language->get('column_date'), 'type' => 'String'],
			['key' => 'conversation_id', 'title' => $this->language->get('column_conversation'), 'type' => 'String'],
			['key' => 'request_id', 'title' => $this->language->get('column_request_id'), 'type' => 'String'],
			['key' => 'item_index', 'title' => $this->language->get('column_item'), 'type' => 'Number'],
			['key' => 'product_name', 'title' => $this->language->get('column_product_name'), 'type' => 'String'],
			['key' => 'brand', 'title' => $this->language->get('column_brand'), 'type' => 'String'],
			['key' => 'model', 'title' => $this->language->get('column_model'), 'type' => 'String'],
			['key' => 'part_number', 'title' => $this->language->get('column_part_number'), 'type' => 'String'],
			['key' => 'qty', 'title' => $this->language->get('column_qty'), 'type' => 'String'],
			['key' => 'unit', 'title' => $this->language->get('column_unit'), 'type' => 'String'],
			['key' => 'item_details', 'title' => $this->language->get('column_item_details'), 'type' => 'String'],
			['key' => 'requirements', 'title' => $this->language->get('column_requirements'), 'type' => 'String'],
			['key' => 'source_request', 'title' => $this->language->get('column_source_request'), 'type' => 'String'],
			['key' => 'name', 'title' => $this->language->get('column_name'), 'type' => 'String'],
			['key' => 'company', 'title' => $this->language->get('column_company'), 'type' => 'String'],
			['key' => 'contact_number', 'title' => $this->language->get('column_contact_number'), 'type' => 'String'],
			['key' => 'email', 'title' => $this->language->get('column_email'), 'type' => 'String'],
			['key' => 'delivery_location', 'title' => $this->language->get('column_delivery_location'), 'type' => 'String'],
			['key' => 'page_url', 'title' => $this->language->get('column_page_url'), 'type' => 'String'],
			['key' => 'page_title', 'title' => $this->language->get('column_page_title'), 'type' => 'String'],
			['key' => 'customer_id', 'title' => $this->language->get('column_customer'), 'type' => 'Number'],
			['key' => 'ip', 'title' => $this->language->get('column_ip'), 'type' => 'String'],
			['key' => 'user_agent', 'title' => $this->language->get('column_user_agent'), 'type' => 'String']
		];

		$output = [];
		$output[] = '<?xml version="1.0" encoding="UTF-8"?>';
		$output[] = '<?mso-application progid="Excel.Sheet"?>';
		$output[] = '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet" xmlns:html="http://www.w3.org/TR/REC-html40">';
		$output[] = '<Styles><Style ss:ID="header"><Font ss:Bold="1"/><Interior ss:Color="#EADCFD" ss:Pattern="Solid"/></Style></Styles>';
		$output[] = '<Worksheet ss:Name="Leads"><Table>';
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
