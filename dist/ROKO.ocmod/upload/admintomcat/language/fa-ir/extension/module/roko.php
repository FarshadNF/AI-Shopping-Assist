<?php
$_['heading_title'] = 'ROKO';

$_['text_extension'] = 'Extensions';
$_['text_success'] = 'Success: You have modified ROKO!';
$_['text_edit'] = 'Edit ROKO';
$_['text_enabled'] = 'Enabled';
$_['text_disabled'] = 'Disabled';
$_['text_logs'] = 'Recent chat logs';
$_['text_no_logs'] = 'No chat logs yet.';
$_['text_clear_logs'] = 'Clear logs';
$_['text_export_excel'] = 'Export Excel';
$_['text_indexed_pages'] = 'Indexed sitemap pages';
$_['text_no_indexed_pages'] = 'No sitemap pages have been indexed yet.';
$_['text_sitemap_not_configured'] = 'Sitemap URL is not configured.';
$_['text_sitemap_not_warmed'] = 'Sitemap cache has not been warmed yet.';
$_['text_sitemap_cached_count'] = '%d cached pages. Last cache update: %s';
$_['text_cache_status'] = 'Cache status';
$_['text_warm_sitemap'] = 'Warm sitemap cache';
$_['text_upload_sitemap'] = 'Upload sitemap XML';
$_['text_uploaded_sitemap'] = 'Uploaded sitemap XML';
$_['text_no_uploaded_sitemap'] = 'No XML sitemap has been uploaded.';
$_['text_uploaded_sitemap_status'] = '%d URLs. Uploaded: %s';
$_['text_upload_sitemap_success'] = 'Sitemap XML uploaded. %d URLs found. Existing indexed pages were kept; click Warm sitemap cache to index from the uploaded XML.';

$_['entry_status'] = 'Status';
$_['entry_gemini_api_key'] = 'Gemini API keys';
$_['entry_gemini_model'] = 'Gemini model';
$_['entry_gemini_temperature'] = 'Temperature';
$_['entry_catalog_limit'] = 'Prompt catalog limit';
$_['entry_store_brand'] = 'Store brand';
$_['entry_assistant_name'] = 'Assistant name';
$_['entry_sitemap_url'] = 'Sitemap URL';
$_['entry_system_prompt'] = 'System prompt';
$_['entry_catalog_token'] = 'Catalog token';
$_['entry_lead_webhook_url'] = 'Lead webhook URL';
$_['entry_lead_webhook_secret'] = 'Lead webhook secret';
$_['entry_footer_injection'] = 'Auto-inject widget';
$_['entry_widget_title'] = 'Widget title';
$_['entry_widget_button'] = 'Button text';

$_['button_upload_sitemap'] = 'Upload XML';

$_['help_gemini_api_key'] = 'Add one or more Google AI Studio keys separated by commas. Keys are used server-side and never exposed to the browser.';
$_['help_catalog_limit'] = 'Maximum products sent to Gemini in each prompt. Keep this moderate for speed and token cost.';
$_['help_sitemap_url'] = 'Optional. Paste your store sitemap URL so ROKO can use real internal links for navigation and suggestions.';
$_['help_upload_sitemap'] = 'Upload a full sitemap.xml file. ROKO reads this file before the configured Sitemap URL.';
$_['help_system_prompt'] = 'Optional. Add store-specific rules, tone, link guidance, offer policy, or recommendation instructions. These rules are appended to every Gemini prompt.';
$_['help_catalog_token'] = 'Optional. If set, catalog JSON requests must include X-ROKO-Token or X-AI-Assistant-Token.';
$_['help_lead_webhook_url'] = 'Optional. Paste the Google Apps Script Web App URL. Bulk lead forms will be sent to this URL as JSON.';
$_['help_lead_webhook_secret'] = 'Optional shared secret. If set here, use the same value in Apps Script to reject unknown requests.';

$_['column_date'] = 'Date';
$_['column_log_id'] = 'Log ID';
$_['column_conversation'] = 'Conversation';
$_['column_session'] = 'Session';
$_['column_customer'] = 'Customer';
$_['column_role'] = 'Role';
$_['column_content'] = 'Content';
$_['column_ip'] = 'IP';
$_['column_user_agent'] = 'User agent';
$_['column_title'] = 'Title';
$_['column_type'] = 'Type';
$_['column_url'] = 'URL';
$_['column_summary'] = 'Summary';
$_['column_cached_at'] = 'Cached at';

$_['error_permission'] = 'Warning: You do not have permission to modify ROKO!';
$_['error_gemini_api_key'] = 'At least one Gemini API key is required when the module is enabled.';
$_['error_upload_sitemap'] = 'Please choose a sitemap XML file.';
$_['error_upload_sitemap_type'] = 'Only .xml, .xml.gz, or .gz sitemap files are allowed.';
$_['error_upload_sitemap_content'] = 'The uploaded file does not look like a valid sitemap XML.';
$_['error_upload_sitemap_write'] = 'Could not save the uploaded sitemap XML.';
