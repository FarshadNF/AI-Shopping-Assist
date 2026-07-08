<?php
$_['heading_title'] = 'دستیار هوشمند خرید';

$_['text_extension'] = 'افزونه‌ها';
$_['text_success'] = 'تنظیمات دستیار هوشمند خرید با موفقیت ذخیره شد.';
$_['text_edit'] = 'ویرایش دستیار هوشمند خرید';
$_['text_enabled'] = 'فعال';
$_['text_disabled'] = 'غیرفعال';
$_['text_logs'] = 'آخرین لاگ‌های چت';
$_['text_no_logs'] = 'هنوز لاگی ثبت نشده است.';
$_['text_clear_logs'] = 'پاک‌کردن لاگ‌ها';
$_['text_export_excel'] = 'خروجی اکسل';
$_['text_knowledge'] = 'پایگاه دانش محلی';
$_['text_knowledge_count'] = '%d رکورد';
$_['text_knowledge_imported'] = '%d رکورد دانش محلی با موفقیت وارد شد.';
$_['text_knowledge_cleared'] = 'پایگاه دانش محلی پاک شد.';
$_['text_clear_knowledge'] = 'پاک‌کردن دانش محلی';

$_['entry_status'] = 'وضعیت';
$_['entry_gemini_api_key'] = 'کلیدهای API جمینای';
$_['entry_gemini_model'] = 'مدل جمینای';
$_['entry_gemini_temperature'] = 'Temperature';
$_['entry_catalog_limit'] = 'تعداد محصول در پرامپت';
$_['entry_store_brand'] = 'نام فروشگاه';
$_['entry_assistant_name'] = 'نام دستیار';
$_['entry_sitemap_url'] = 'لینک سایت‌مپ';
$_['entry_system_prompt'] = 'سیستم پرامپت';
$_['entry_catalog_token'] = 'توکن کاتالوگ';
$_['entry_lead_webhook_url'] = 'آدرس وبهوک لید';
$_['entry_lead_webhook_secret'] = 'رمز وبهوک لید';
$_['entry_footer_injection'] = 'تزریق خودکار ویجت';
$_['entry_widget_title'] = 'عنوان ویجت';
$_['entry_widget_button'] = 'متن دکمه';
$_['entry_knowledge_file'] = 'فایل JSON دانش';

$_['help_gemini_api_key'] = 'یک یا چند کلید Google AI Studio را با کاما جدا کنید. اگر یکی خطا یا محدودیت بدهد، OpenCart کلید بعدی را امتحان می‌کند. کلیدها فقط سمت سرور استفاده می‌شوند و داخل مرورگر نمایش داده نمی‌شوند.';
$_['help_catalog_limit'] = 'حداکثر تعداد محصولی که در هر پیام برای جمینای فرستاده می‌شود. برای سرعت و هزینه توکن، مقدار متوسط بهتر است.';
$_['help_sitemap_url'] = 'اختیاری است. لینک سایت‌مپ فروشگاه را وارد کنید تا دستیار برای لینک‌ها، ناوبری و پیشنهادها از آدرس‌های واقعی سایت استفاده کند.';
$_['help_system_prompt'] = 'اختیاری است. قوانین اختصاصی فروشگاه، لحن پاسخ‌گویی، راهنمای لینک‌دهی، سیاست پیشنهاد محصول یا شرایط فروش را اینجا وارد کنید. این متن به هر پرامپت جمینای اضافه می‌شود.';
$_['help_catalog_token'] = 'اختیاری است. اگر مقدار داشته باشد، درخواست JSON کاتالوگ باید هدر X-AI-Assistant-Token بفرستد.';
$_['help_lead_webhook_url'] = 'اختیاری است. آدرس Web App گوگل Apps Script را اینجا بگذارید تا فرم‌های خرید عمده به Google Sheet ارسال شوند.';
$_['help_lead_webhook_secret'] = 'اختیاری است. همین مقدار را داخل Apps Script هم بگذارید تا درخواست‌های ناشناس رد شوند.';
$_['help_knowledge_file'] = 'فایل enriched_cache.json یا JSON سازگار را وارد کنید. توضیحات، مشخصات فنی و datasheet_content داخل MySQL خود OpenCart ذخیره و با PHP جست‌وجو می‌شوند.';

$_['button_import_knowledge'] = 'ورود JSON';

$_['column_date'] = 'تاریخ';
$_['column_log_id'] = 'شناسه لاگ';
$_['column_conversation'] = 'مکالمه';
$_['column_session'] = 'نشست';
$_['column_customer'] = 'مشتری';
$_['column_role'] = 'نقش';
$_['column_content'] = 'متن';
$_['column_ip'] = 'IP';
$_['column_user_agent'] = 'مرورگر کاربر';

$_['error_permission'] = 'هشدار: شما اجازه ویرایش دستیار هوشمند خرید را ندارید.';
$_['error_gemini_api_key'] = 'وقتی ماژول فعال است، حداقل یک کلید API جمینای الزامی است.';
$_['error_knowledge_file'] = 'یک فایل JSON برای ورود انتخاب کنید.';
$_['error_knowledge_size'] = 'حجم فایل دانش باید حداکثر ۲۵ مگابایت باشد.';
$_['error_knowledge_json'] = 'فایل انتخاب‌شده JSON معتبر نیست.';
$_['error_knowledge_empty'] = 'هیچ دانش محصول قابل استفاده‌ای در فایل پیدا نشد.';
$_['error_knowledge_import'] = 'عملیات پایگاه دانش ناموفق بود. لاگ خطای OpenCart را بررسی کنید.';
