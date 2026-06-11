<?php
$_['heading_title'] = 'AI Shopping Assist';

$_['text_extension'] = 'Extensions';
$_['text_success'] = 'Success: You have modified AI Shopping Assist!';
$_['text_edit'] = 'Edit AI Shopping Assist';
$_['text_enabled'] = 'Enabled';
$_['text_disabled'] = 'Disabled';
$_['text_logs'] = 'Recent chat logs';
$_['text_no_logs'] = 'No chat logs yet.';
$_['text_clear_logs'] = 'Clear logs';
$_['text_export_excel'] = 'Export Excel';

$_['entry_status'] = 'Status';
$_['entry_gemini_api_key'] = 'Gemini API keys';
$_['entry_gemini_model'] = 'Gemini model';
$_['entry_gemini_temperature'] = 'Temperature';
$_['entry_catalog_limit'] = 'Prompt catalog limit';
$_['entry_store_brand'] = 'Store brand';
$_['entry_assistant_name'] = 'Assistant name';
$_['entry_catalog_token'] = 'Catalog token';
$_['entry_lead_webhook_url'] = 'Lead webhook URL';
$_['entry_lead_webhook_secret'] = 'Lead webhook secret';
$_['entry_footer_injection'] = 'Auto-inject widget';
$_['entry_widget_title'] = 'Widget title';
$_['entry_widget_button'] = 'Button text';

$_['help_gemini_api_key'] = 'Add one or more Google AI Studio keys separated by commas. OpenCart tries the next key if one fails or is rate-limited. Keys are used server-side and never exposed to the browser.';
$_['help_catalog_limit'] = 'Maximum products sent to Gemini in each prompt. Keep this moderate for speed and token cost.';
$_['help_catalog_token'] = 'Optional. If set, catalog JSON requests must include X-AI-Assistant-Token.';
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

$_['error_permission'] = 'Warning: You do not have permission to modify AI Shopping Assist!';
$_['error_gemini_api_key'] = 'At least one Gemini API key is required when the module is enabled.';
