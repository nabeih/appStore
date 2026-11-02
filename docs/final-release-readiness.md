> تحديث بعد الموافقة: نُفذت الترحيلات الأربعة وصلاحيات المتجر على app_store المحلية، دون نسخة احتياطية جديدة وفق توجيهك الأخير. هذا التقرير يوثق الفحص السابق للترقية؛ النتيجة الحالية في [local-upgrade-result.md](local-upgrade-result.md). الفرق250.00 وفترة الإعداد بقيا دون معالجة.
# Final Release Readiness — appStore
التاريخ: 2026-11-01. تجهيز محلي وتجربة ترقية؛ ليس إذن نشر أو اعتماد أرصدة.
لا اتصال بالإنتاج أو aaPanel، ولا كتابة إلى app_store أو Commit/Push/Deploy.

## 1. القرار
| البند | الحالة | الدليل/المعنى |
|---|---|---|
| الميزات القائمة والاختبارات | PASS | الحزمة الكاملة ناجحة؛ التفاصيل أدناه |
| Schema الحالية | PASS | 53 جدولًا مطابقًا لتعريفات الترحيلات المنفذة بمرجع نظيف |
| النسخ والاستعادة والترقية | PASS | استعادة مطابقة ثم الترحيلات الأربعة على نسخة مستقلة |
| سلامة الأثر القديم | PASS | بصمات الصفوف والمجاميع والقيود والحركات القديمة متطابقة |
| ترقية app_store | BLOCKED | تنتظر الموافقة الصريحة؛ لم تبدأ كتابة إليها |
| تشغيل البيع رسميًا | BLOCKED | مستند افتتاحي بلا قيد، فرق مخزون/دفتر، تهيئة مبيعات غير مكتملة وإعداد مفتوح |
| السيرفر وSMTP والهواتف | NOT TESTED | لا وصول للإنتاج أو اختبار Flutter/iPhone فعلي |
| النشر العام الآن | BLOCKED | يحتاج معالجة الموانع واعتماد البيئة |

**GO مشروط لتحديث Schema المحلية بعد الموافقة؛ NO-GO لبدء البيع الرسمي أو النشر العام الآن.**
نجاح DDL لا يعتمد أرصدة البداية ولا يغلق فترة الإعداد.

## 2. Current Git State والميزات
بداية المهمة: تعديل مرحل سابق و46 ملفًا متتبعًا معدلًا، وملفات غير متتبعة كثيرة تمثل ميزات كاملة. بقيت جميعها محفوظة، بما فيها الملف المرحل غير المعتاد في الجذر. لا reset/checkout/clean.
راجعت git status وdiff الفعلي ومصادر Routes وActions وMiddleware والصلاحيات والترحيلات والتقارير الخمسة المطلوبة. هذه المرحلة تضيف أدوات فحص ووثائق واختبارات.
لا تستخدم git archive HEAD وحده: سيستبعد الميزات غير المتتبعة. يلزم مراجعة حزمة الملفات الحالية واعتماد إصدار/Commit مستقل لاحقًا.

الموجود ومثبت بالاختبارات: Web/Auth/RBAC والفروع؛ المنتجات وصور اللون WebP؛ المخزون والافتتاح والمشتريات؛ البيع والمرتجع والذمم والقيود؛ SA-01/SA-02؛ Sanctum و/api/v1 وBootstrap/Pull/Push وUUID؛ المتجر والطلبات والحجز والتوصيل؛ الإشعارات والحد الأدنى وتسويات المزود.
لم يُعد بناء الوحدات ولم يتغير منطق إنتاجها في هذه المهمة. الجديد: أداة استعادة/ترقية محلية محروسة، توسعة تغطية المزامنة وحجز آخر قطعة، وأمثلة النشر والتقرير.

## 3. سجل جميع الترحيلات — المصدر والسجل الفعلي

الجداول والأعمدة من محتوى up. تعريفات Indexes وFK الفعلية كاملة في final-release-rehearsal.json. المطبق لا يعاد تشغيله؛ الاعتمادات تشمل الجداول المرجعية السابقة زمنيًا.

| الملف | الجداول | أعمدة/مفاتيح up | app_store | الاعتماد والأمان والصلاحيات |
|---|---|---|---|---|
| 0001_01_01_000000_create_users_table.php | users, password_reset_tokens, sessions | name, username, email, password, is_active, token, created_at, id, user_id, ip_address, user_agent, payload, last_activity | PASS: مطبق | PASS: مطبق؛ لا تعاد DDL. مطابق للمرجع النظيف. CREATE/ALTER/INDEX/REFERENCES عند تثبيت جديد. |
| 0001_01_01_000001_create_cache_table.php | cache, cache_locks | key, value, expiration, owner | PASS: مطبق | PASS: مطبق؛ لا تعاد DDL. مطابق للمرجع النظيف. CREATE/ALTER/INDEX/REFERENCES عند تثبيت جديد. |
| 0001_01_01_000002_create_jobs_table.php | jobs, job_batches, failed_jobs | queue, payload, attempts, reserved_at, available_at, created_at, id, name, total_jobs, pending_jobs, failed_jobs, failed_job_ids, options, cancelled_at, finished_at, uuid, connection, exception, failed_at | PASS: مطبق | PASS: مطبق؛ لا تعاد DDL. مطابق للمرجع النظيف. CREATE/ALTER/INDEX/REFERENCES عند تثبيت جديد. |
| 2026_10_11_110955_create_colors_table.php | colors | name, is_active, code | PASS: مطبق | PASS: مطبق؛ لا تعاد DDL. مطابق للمرجع النظيف. CREATE/ALTER/INDEX/REFERENCES عند تثبيت جديد. |
| 2026_10_11_111117_create_sizes_table.php | sizes | name, sort_order, is_active, code | PASS: مطبق | PASS: مطبق؛ لا تعاد DDL. مطابق للمرجع النظيف. CREATE/ALTER/INDEX/REFERENCES عند تثبيت جديد. |
| 2026_10_12_105129_create_branches_table.php | branches | name, address, phone, type, is_active | PASS: مطبق | PASS: مطبق؛ لا تعاد DDL. مطابق للمرجع النظيف. CREATE/ALTER/INDEX/REFERENCES عند تثبيت جديد. |
| 2026_10_12_111300_create_categories_table.php | categories | parent_id, name, description, is_active | PASS: مطبق | PASS: مطبق؛ لا تعاد DDL. مطابق للمرجع النظيف. CREATE/ALTER/INDEX/REFERENCES عند تثبيت جديد. |
| 2026_10_12_111726_create_roles_table.php | roles | name, display_name, is_active | PASS: مطبق | PASS: مطبق؛ لا تعاد DDL. مطابق للمرجع النظيف. CREATE/ALTER/INDEX/REFERENCES عند تثبيت جديد. |
| 2026_10_12_112143_create_permissions_table.php | permissions | name, display_name, group | PASS: مطبق | PASS: مطبق؛ لا تعاد DDL. مطابق للمرجع النظيف. CREATE/ALTER/INDEX/REFERENCES عند تثبيت جديد. |
| 2026_10_12_112637_create_permission_role_table.php | permission_role | role_id, permission_id | PASS: مطبق | PASS: مطبق؛ لا تعاد DDL. مطابق للمرجع النظيف. CREATE/ALTER/INDEX/REFERENCES عند تثبيت جديد. |
| 2026_10_12_121524_create_payment_methods_table.php | payment_methods | name, type, is_active | PASS: مطبق | PASS: مطبق؛ لا تعاد DDL. مطابق للمرجع النظيف. CREATE/ALTER/INDEX/REFERENCES عند تثبيت جديد. |
| 2026_10_12_122152_create_customers_table.php | customers | name, phone, address, notes | PASS: مطبق | PASS: مطبق؛ لا تعاد DDL. مطابق للمرجع النظيف. CREATE/ALTER/INDEX/REFERENCES عند تثبيت جديد. |
| 2026_10_12_122620_create_suppliers_table.php | suppliers | name, phone1, phone2, address, notes | PASS: مطبق | PASS: مطبق؛ لا تعاد DDL. مطابق للمرجع النظيف. CREATE/ALTER/INDEX/REFERENCES عند تثبيت جديد. |
| 2026_10_13_201541_add_cloums_to_users_table.php | users | role_id, branch_id | PASS: مطبق | PASS: مطبق؛ لا تعاد DDL. مطابق للمرجع النظيف. CREATE/ALTER/INDEX/REFERENCES عند تثبيت جديد. |
| 2026_10_14_163339_create_products_table.php | products | category_id, code, name, description, image_path, is_active | PASS: مطبق | PASS: مطبق؛ لا تعاد DDL. مطابق للمرجع النظيف. CREATE/ALTER/INDEX/REFERENCES عند تثبيت جديد. |
| 2026_10_15_124806_create_product_variants_table.php | product_variants | product_id, color_id, size_id, sku, selling_price, minimum_selling_price, is_active | PASS: مطبق | PASS: مطبق؛ لا تعاد DDL. مطابق للمرجع النظيف. CREATE/ALTER/INDEX/REFERENCES عند تثبيت جديد. |
| 2026_10_17_105717_create_inventories_table.php | inventories | branch_id, product_variant_id, quantity, average_cost | PASS: مطبق | PASS: مطبق؛ لا تعاد DDL. مطابق للمرجع النظيف. CREATE/ALTER/INDEX/REFERENCES عند تثبيت جديد. |
| 2026_10_17_105944_create_opening_stock_entries_table.php | opening_stock_entries | client_uuid, branch_id, created_by, approved_by, status, notes, approved_at | PASS: مطبق | PASS: مطبق؛ لا تعاد DDL. مطابق للمرجع النظيف. CREATE/ALTER/INDEX/REFERENCES عند تثبيت جديد. |
| 2026_10_17_110420_create_opening_stock_items_table.php | opening_stock_items | opening_stock_entry_id, product_variant_id, quantity, unit_cost | PASS: مطبق | PASS: مطبق؛ لا تعاد DDL. مطابق للمرجع النظيف. CREATE/ALTER/INDEX/REFERENCES عند تثبيت جديد. |
| 2026_10_17_110541_create_stock_movements_table.php | stock_movements | branch_id, product_variant_id, user_id, movement_type, quantity_change, unit_cost, source, note, created_at | PASS: مطبق | PASS: مطبق؛ لا تعاد DDL. مطابق للمرجع النظيف. CREATE/ALTER/INDEX/REFERENCES عند تثبيت جديد. |
| 2026_10_17_144420_create_product_images_table.php | product_images | product_id, color_id, image_path | PASS: مطبق | PASS: مطبق؛ لا تعاد DDL. مطابق للمرجع النظيف. CREATE/ALTER/INDEX/REFERENCES عند تثبيت جديد. |
| 2026_10_18_193208_create_personal_access_tokens_table.php | personal_access_tokens | tokenable, name, token, abilities, last_used_at, expires_at | PASS: مطبق | PASS: مطبق؛ لا تعاد DDL. مطابق للمرجع النظيف. CREATE/ALTER/INDEX/REFERENCES عند تثبيت جديد. |
| 2026_10_21_121822_create_product_code_counters_table.php | product_code_counters | last_number | PASS: مطبق | PASS: مطبق؛ لا تعاد DDL. مطابق للمرجع النظيف. CREATE/ALTER/INDEX/REFERENCES عند تثبيت جديد. |
| 2026_10_22_100928_complete_opening_stock_documents.php | opening_stock_entries, opening_stock_items, stock_movements, product_variants | created_by, opening_date, request_hash, total_quantity, total_cost, product_code, product_name, category_name, color_name, size_name, sku, selling_price, line_total, user_id | PASS: مطبق | PASS: مطبق؛ لا تعاد DDL. مطابق للمرجع النظيف. CREATE/ALTER/INDEX/REFERENCES عند تثبيت جديد. |
| 2026_10_22_105419_create_inventory_setup_changes_table.php | inventory_setup_changes | inventory_id, branch_id, product_variant_id, opening_stock_entry_id, opening_stock_item_id, user_id, operation, reason, before_state, after_state | PASS: مطبق | PASS: مطبق؛ لا تعاد DDL. مطابق للمرجع النظيف. CREATE/ALTER/INDEX/REFERENCES عند تثبيت جديد. |
| 2026_10_22_112451_add_hex_code_to_colors_table.php | colors | hex_code | PASS: مطبق | PASS: مطبق؛ لا تعاد DDL. مطابق للمرجع النظيف. CREATE/ALTER/INDEX/REFERENCES عند تثبيت جديد. |
| 2026_10_22_131703_create_purchase_documents_tables.php | purchase_invoices, purchase_invoice_items, supplier_payments | client_uuid, request_hash, supplier_id, branch_id, created_by, approved_by, supplier_name, branch_name, supplier_invoice_number, invoice_date, status, version, approved_revision, total_quantity, total_minor, paid_minor, initial_payment_minor, payment_method, notes, approved_at, purchase_invoice_id, product_variant_id, product_name, product_code, category_name, color_name, size_name, sku, quantity, unit_cost_minor, line_total_minor, amount_minor, method, payment_date, reference | PASS: مطبق | PASS: مطبق؛ لا تعاد DDL. مطابق للمرجع النظيف. CREATE/ALTER/INDEX/REFERENCES عند تثبيت جديد. |
| 2026_10_25_124921_create_sales_and_financial_posting_tables.php | product_code_counters, branches, inventories, stock_movements, opening_stock_entries, sales_controls, financial_accounts, audit_events, sale_invoices, sale_items, sale_returns, sale_return_items, sale_exchanges, customer_receipts, receipt_parts, receipt_allocations, credit_usages, journal_entries, journal_lines | lock_version, opening_stock_closed_at, opening_stock_closed_by, financial_closed_through, value_minor, quarantined_quantity, quarantined_value_minor, damaged_quantity, value_change_minor, stock_bucket, effective_date, version, activated_at, owner_id, code, name, type, branch_id, reference, is_active, subject_type, subject_id, user_id, action, reason, data, created_at, client_uuid, request_hash, customer_id, created_by, approved_by, customer_name, branch_name, cashier_name, invoice_date, status, approved_revision, total_quantity, gross_minor, line_discount_minor, invoice_discount_minor, total_minor, cost_minor, paid_minor, returned_minor, released_minor, change_minor, payment_instructions, discount_instructions, notes, posted_at, sale_invoice_id, product_variant_id, product_name, product_code, color_name, size_name, sku, image_path, quantity, base_price_minor, unit_price_minor, net_minor, unit_cost, returned_quantity, price_reason, kind, transaction_date, debt_reduction_minor, refund_minor, credit_minor, reversed_at, sale_return_id, sale_item_id, condition, recoverable_minor, original_invoice_id, new_invoice_id, origin, origin_id, amount_minor, customer_receipt_id, financial_account_id, tendered_minor, source_type, source_id, event, reversal_of_id, description, journal_entry_id, debit_minor | PASS: مطبق | PASS: مطبق؛ لا تعاد DDL. مطابق للمرجع النظيف. CREATE/ALTER/INDEX/REFERENCES عند تثبيت جديد. |
| 2026_10_25_194950_integrate_supplier_expense_and_treasury_documents.php | purchase_invoices, supplier_payments, expenses, expense_payments, cash_counts, treasury_documents, journal_lines | financial_posted_at, payment_details, financial_account_id, status, reversed_at, client_uuid, request_hash, branch_id, created_by, approved_by, title, category, payee, period_start, period_end, transaction_date, amount_minor, reference, external_source, external_reference, notes, posted_at, expense_id, reason, expected_minor, actual_minor, difference_minor, last_journal_line_id, kind, from_account_id, to_account_id, cash_count_id, supplier_id, purchase_invoice_id | PASS: مطبق | PASS: مطبق؛ لا تعاد DDL. مطابق للمرجع النظيف. CREATE/ALTER/INDEX/REFERENCES عند تثبيت جديد. |
| 2026_10_26_153708_add_thumbnail_path_to_product_images_table.php | product_images | thumbnail_path | PASS: مطبق | PASS: مطبق؛ لا تعاد DDL. مطابق للمرجع النظيف. CREATE/ALTER/INDEX/REFERENCES عند تثبيت جديد. |
| 2026_10_27_120326_create_product_color_swatches_table.php | product_color_swatches | product_id, color_id, image_path | PASS: مطبق | PASS: مطبق؛ لا تعاد DDL. مطابق للمرجع النظيف. CREATE/ALTER/INDEX/REFERENCES عند تثبيت جديد. |
| 2026_10_28_074550_create_product_image_embeddings_table.php | product_image_embeddings | product_image_id, model_version, image_path, image_hash, generation, dimensions, embedding, status, last_error, indexed_at | PASS: مطبق | PASS: مطبق؛ لا تعاد DDL. مطابق للمرجع النظيف. CREATE/ALTER/INDEX/REFERENCES عند تثبيت جديد. |
| 2026_10_31_121937_create_application_installations_table.php | application_installations | id, status, token_hash, delivery_id, token_expires_at, completed_at, owner_id | PASS: مطبق | PASS: مطبق؛ لا تعاد DDL. مطابق للمرجع النظيف. CREATE/ALTER/INDEX/REFERENCES عند تثبيت جديد. |
| 2026_10_31_135148_add_account_security_and_first_branch_onboarding.php | users, password_reset_tokens, application_installations | email_verified_at, email, token, created_at, first_branch_required, first_branch_completed_at, initial_branch_id | PASS: مطبق | PASS: مطبق؛ لا تعاد DDL. مطابق للمرجع النظيف. CREATE/ALTER/INDEX/REFERENCES عند تثبيت جديد. |
| 2026_10_31_204108_create_mobile_sync_infrastructure.php | mobile_devices, mobile_operations, mobile_entity_mappings, mobile_sync_clock, mobile_sync_changes, mobile_sync_sessions, mobile_sync_records | id, user_id, personal_access_token_id, name, credential_hash, revoked_at, device_id, operation_uuid, branch_id, operation_type, request_hash, payload, dependencies, client_created_at, status, result, entity, client_uuid, server_id, version, record_id, created_at, access_hash, bootstrap_watermark, expires_at, session_id | WARNING: معلق | PASS على النسخة: 7 جداول mobile؛ FK للمستخدم/الرمز/الفرع، وصف clock. CREATE/INDEX/REFERENCES/INSERT قبل tracking. |
| 2026_10_31_204109_install_mobile_sync_change_tracking.php | 20 جدول مصدر -> mobile_sync_clock/changes | 60 AFTER Triggers, Backfill, customer_balances | WARNING: معلق | PASS على النسخة: بعد infrastructure؛ TRIGGER + SELECT/INSERT/UPDATE. backfill يكتب التعقب فقط. إعادة التنفيذ قد تضيف أحداث تعقب؛ ليست بلا أثر. |
| 2026_11_01_104756_create_store_orders_content_and_alerts.php | inventories, store_settings, store_sections, store_listings, store_banners, delivery_zones, store_offers, store_orders, store_order_items, store_reservations, store_order_events, store_order_payments, app_alert_events, app_alerts, sale_price_exceptions | reserved_quantity, id, branch_id, name, phone, policy, seo_title, seo_description, is_open, category_id, slug, description, sort_order, is_active, product_id, store_section_id, badge, is_published, title, image_path, thumbnail_path, fee_minor, courier_delivery_minor, courier_refusal_minor, type, percent_basis_points, minimum_minor, minimum_inclusive, zone_ids, product_ids, starts_at, ends_at, client_uuid, request_hash, customer_id, delivery_zone_id, sale_invoice_id, courier_expense_id, sale_uuid, status, version, customer_name, address, notes, courier_name, products_minor, original_delivery_minor, delivery_minor, courier_due_minor, courier_paid_minor, fee_due_minor, fee_paid_minor, collected_minor, collection_status, settlement_status, delivery_snapshot, accepted_policy, policy_version, policy_accepted_at, confirmed_at, dispatched_at, returned_at, delivered_at, store_order_id, product_variant_id, product_name, sku, color_name, size_name, quantity, base_price_minor, unit_price_minor, discount_minor, net_minor, offer_snapshot, state, released_at, user_id, operation_uuid, from_status, to_status, reason, financial_account_id, kind, received_by, amount_minor, reference, event_key, subject_id, message, app_alert_event_id, read_at, sale_item_id, minimum_unit_minor, effective_total_minor | WARNING: معلق | PASS على النسخة: 14 جدولًا + reserved_quantity + 3 Triggers؛ TRIGGER وCREATE/ALTER/INDEX/REFERENCES. metadata locks وDDL جزئية ممكنة. لا يفتح الموقع أو يمنح الصلاحيات. |
| 2026_11_01_121014_add_method_to_store_order_payments.php | store_order_payments | method | WARNING: معلق | PASS على النسخة: بعد store_order_payments؛ ALTER method nullable دون backfill لطريقة قديمة. |

## 4. Pending Migrations وترتيب الاعتماد
app_store:34 مطبقة و4 معلقة:
1. 2026_10_31_204108_create_mobile_sync_infrastructure.php.
2. 2026_10_31_204109_install_mobile_sync_change_tracking.php.
3. 2026_11_01_104756_create_store_orders_content_and_alerts.php.
4. 2026_11_01_121014_add_method_to_store_order_payments.php.

الأولى: mobile_devices،mobile_operations،mobile_entity_mappings،mobile_sync_clock،mobile_sync_changes،mobile_sync_sessions،mobile_sync_memberships.
الثانية:60 Trigger على20 جدولًا وBackfill للتعقب فقط؛ لا إعادة مخزون أو قيود.
الثالثة:14 جدول متجر وreserved_quantity وثلاثة Triggers حجز. الرابعة:method nullable بلا تخمين لطريقة قديمة.
ترحيلات الأمان والمالية والتثبيت والفرع الأول مطبقة؛ لا تعاد تهيئتها.

PASS: الجداول الـ74 وIndexes وFK بعد الترقية تطابق المرجع النظيف. لا Index فوق64 حرفًا ولا FK يتيم. أجسام63 Trigger متطابقة.
تحديث الحجز يغير reserved_quantity فتلتقطه Triggers inventories؛ لا يعيد خصم quantity أو كتابة StockLedger/Journal. أحداث OLD/NEW المتعددة مقصودة وPull يجمع الحالة؛ ليست قيودًا مكررة.
WARNING: MySQL DDL جزئية عند الفشل وALTER قد تنتظر Metadata Locks؛ لا تعاد تخمينيًا. tracking قد يعيد Backfill إن أعيد تشغيله بعد العبث بسجله.
يتطلب النشر CREATE/ALTER/INDEX/REFERENCES وDML وTRIGGER وقراءة Metadata. راجع صلاحيات routines/events/definer وBinary Logging مع DBA؛ لا تمنح SUPER أو تغير إعدادًا عامًا تلقائيًا.

## 5. Backup & Upgrade Rehearsal
PASS. النسخة الخاصة:
storage/app/private/release-rehearsal/20261101_a68eaee5/app_store.sql
الحجم110703 بايت؛ SHA-256:
a0f646223721d046e29a6adebd8ecf2eeb842324402f8c166922a3cc706d32e7

mysqldump --single-transaction مع Triggers/Routines/Events وhex-blob؛ جميع الجداول InnoDB. لا كلمة مرور في argv أو التقرير؛ ملف Client مؤقت أزيل بعد الاستعادة.
استعيدت في appstore_release_rehearsal_20261101_a68eaee5؛ Schema وبصمات جميع صفوف53 جدولًا تطابق المصدر قبل الترقية. نُسخ57 ملفًا من storage/app/public مع SHA-256 ومراجعة عدم تغيره أثناء النسخ.
دليل النسخة له Windows ACL محمية للمستخدم الحالي وSYSTEM وAdministrators؛ لا ادعاء لاختبار Linux.
طبقت الترحيلات الأربع بآلية Laravel على النسخة فقط:74 جدولًا،63 Trigger،reserved_total=0،32 حدث Backfill. لا أثر مالي أو مخزني تجاري جديد.
جُرب app:store-access مرتين: الأدوار والصلاحيات والحسابات بعد التشغيلين متطابقة. أضيف حسابان نظاميان فقط، دون أرصدة أو مخزون.
لا حذف للنسخة أو القاعدة؛ تحتويان بيانات محلية وحساباتها، فلا ترفعهما للعميل أو إلى مجلد عام.

الأداة tests/prepare-release-rehearsal.php محروسة بـlocal/app_store/127.0.0.1 وعدم Config Cache، وتقرأ المصدر باتصال SQL READ ONLY. --backup-restore-upgrade ينشئ نسخة جديدة فقط. ليست أداة إنتاج؛ لا تعِدها بلا حاجة، فقد تحدث ملفات أدلتها وتنشئ قاعدة أخرى.
migrate:status نُفذ داخل Artisan مع اتصال SQL READ ONLY.

## 6. Database Integrity Checks والموانع
| المؤشر | قبل | بعد ترقية النسخة | الحالة |
|---|---:|---:|---|
| المنتجات / المتغيرات / الفروع | 2 /2 /1 | نفسها | PASS |
| أرصدة المخزون / الكمية | 2 /55 | نفسها | PASS |
| قيمة المخزون | 1,750.00 | 1,750.00 | PASS للحفاظ |
| حساب المخزون بالدفتر | 1,500.00 | 1,500.00 | PASS للحفاظ؛ BLOCKED للمطابقة |
| حركات المخزون / مجموعها | 2 /55 | نفسها | PASS |
| القيود / السطور | 2 /4 | نفسها | PASS |
| المدين / الدائن | 21,500.00 /21,500.00 | نفسها | PASS |
| فاتورة الشراء / القيمة / المدفوع | 1 /1,500.00 /0.00 | نفسها | PASS |
| مستحق الموردين | 1,500.00 | 1,500.00 | PASS |
| فواتير البيع | 0 | 0 | PASS |
| قيد الفحص: كمية / قيمة | 0 /0.00 | نفسها | PASS |

بصمات الجداول القديمة متطابقة عدا إضافة عمود الحجز الصفري وسجل migrations. تغييرات الصلاحيات والحسابين النظاميين والتدقيق على النسخة موثقة منفصلة.
app_store طابقت البداية لكل الجداول عند نهاية التجربة؛ لا نضمن ثباتها إذا عدّلها المستخدم لاحقًا.

BLOCKED لبدء التشغيل:
- فرق250.00 بين قيمة المخزون والدفتر.
- OS-000001 معتمد مخزنيًا وبلا قيد مالي داعم؛ legacy_openings=1.
- sales_controls غير مكتمل، وفترة الإعداد مفتوحة وcan_close=false.
لا فرق كمية مثبت حاليًا، ولا مشتريات أو دفعات موردين غير مرحّلة في completeness. الفارق المثبت للافتتاح.
لم نرحل المستند أو نخمن تكلفة أو نستخدم مقابل افتتاح لإخفاء فرق أو نغلق الفترة؛ المعالجة المالية تحتاج موافقة ودليل ومطابقة مستقلة.

## 7. Permission Initialization Plan
المالك الفعّال موجود والتثبيت completed؛ لا مالك جديد أو إعادة setup أو إنشاء فرع أول.
تنقص المالك5 صلاحيات فقط: store.manage،store.orders.view،store.orders.manage،store.courier_settle،notifications.price_alerts.
app:store-access يعيد استخدام InitializeAccessAction ويضيف delivery_revenue وcourier_receivables دون أرصدة. PASS: صلاحيات غير المالك لم تتغير في النسخة.
Flutter يستخدم RBAC الحالي لكل كيان وعملية وفرع. الكاشير يرى الحد الأدنى وسعر البيع والمتاح؛ لا COGS أو شراء أو ربح.
WARNING: app:sales-setup خارج موافقة الترقية المقترحة؛ يغير sales_controls وقد ينشئ درجًا، فيحتاج مراجعة الحسابات وموافقة منفصلة. لا تشغيل تلقائي أو إغلاق إعداد.

## 8. Test Results
PASS: الحزمة الكاملة:559 حالة؛540 ناجحة و19 متجاوزة اختياريًا؛5355 assertions، دون Errors/Failures؛15:18 دقيقة.
الحزمة الأساسية على appstore_golive_20261031_tests/MySQL؛ تضم8 اختبارات قديمة على SQLite مؤقت. لا تُنسب النتائج كلها إلى MySQL.
المتجاوز:12 MySQL قديم opt-in،6 بحث بصري تتطلب نموذجًا وصورًا حقيقية،واختبار Snapshot واحد. لا تعتبر ناجحة أو اختبار حمل.
تغطي Web/Auth/RBAC والفروع والمنتجات والصور والمخزون والافتتاح والمشتريات والبيع والمرتجع والدفتر والذمم والمزامنة والطلبات والحجز والتوصيل والتسويات والإشعارات والحد الأدنى والتزامن.

بعد الحزمة وُسعت سيناريوهات MobileSynchronizationTest وأعيدت:10 اختبارات و133 assertions ناجحة:
- منتج API جديد ثم شراء4 ×20 ودفع20، وبيع1 بسعر35 وقبض10، ثم إعادة العمليات: already_applied دون قيد/حركة/سند جديد؛ Pull يعيد مخزون3 وقيمة60 والمتاح3.
- حجز آخر قطعة من الموقع: physical=1/reserved=1/available=0. revision قديم يرفض؛ حتى الحالي لا يسمح ببيع المحجوز. الكاشير لكمية1 يرفض422 دون فاتورة أو قيد أو حركة.
أول تشغيل للاختبار الإضافي كشف تداخل RequestGuard عند الانتقال من API إلى الويب في بيئة الاختبار؛ عُزل web guard وأعيدت المجموعة بنجاح. لا تعديل على مصادقة الإنتاج أو تغيير توقع العمل.

PASS: Composer check-platform-reqs --no-dev على PHP8.5.12؛ GD/WebP وpdo_mysql موجودان. Laravel13.35.0 وSanctum4.3.3 وPHPUnit12.5.38 من composer show.
PASS: npm ci من lock نفسه مع ignore-scripts ثم Vite build؛ لا تعديل اعتماديات أو lock. عولجت شهادة Node بمخزن النظام دون تعطيل TLS. أضيف public/build كأثر بناء.
PASS: Pint وlint الأداة وgit diff --check.
NOT TESTED: Flutter/iPhone/Safari فعلي، SMTP، السيرفر والإنتاج والحمل. فحص Chrome السابق16 عرضًا في تقرير المتجر؛ لم يُعد هنا.

## 9. Deployment Requirements
المحلي:local وdebug=true وHTTP وsecure_cookie=false وMAIL_MAILER=log؛ لا يصلح نسخه كما هو للإنتاج. JSON session serialization وHttpOnly وSameSite=lax في الكود.
أمثلة جاهزة للمراجعة: aapanel-production.env.example وaapanel-nginx-locations.conf.example وaapanel-workers.conf.example. لا أسرار أو اتصال بسيرفر.
Root وPHP CLI يراجعان وفق [وثائق aaPanel الرسمية](https://www.aapanel.com/docs/Function/php.html). إعدادات الأمثلة لم تُثبت على السيرفر.

- PHP CLI/FPM8.5؛ فحص Composer platform على السيرفر، وpdo_mysql وGD/WebP وfileinfo وmbstring وopenssl وcurl وdom/xml وintl وبقية المتطلبات. PHP PATH المحلي8.3؛ استخدم المسار الصريح.
- APP_ENV=production وAPP_DEBUG=false وHTTPS وAPP_URL الصحيح، وSession secure. احتفظ بـAPP_KEY الإنتاج الحالي؛ لا تنقل مفتاح التطوير أو تستخدم key:generate على القاعدة القائمة. راجع trusted proxies وفق البنية الفعلية.
- رموز Sanctum للأجهزة30 يومًا مع abilities وفحص الأهلية والفرع والبصمة حيًا؛ لا رموز في URL/logs. Native Flutter لا يحتاج CORS؛ Flutter Web يحتاج Origin محددًا بعد معرفة عنوانه، لا wildcard مع credentials.
- storage وbootstrap/cache قابلة للكتابة لحساب الخدمة؛ لا chmod777. env والنسخ/private خارج public. storage:link يربط storage/app/public فقط.
- احتفظ بصور العميل في storage؛ صورتا WebP ومشاركة اللون باقية. app:images-optimize --dry-run للمراجعة أولًا؛ لا معالجة/حذف أصل تلقائيًا مع الترقية.
- CLI upload/post الحالي2G مرتفع. مثال النشر: Nginx16M وPHP upload8M/post16M؛ API يبقى4MiB للصورة و6MiB multipart و2MiB JSON. Web bulk قد يحتاج تقسيم الرفع؛ لا توسع قواعد التحقق لهذا السبب.
- Cache دائم database/Redis لحدود الطلبات، لا array. Scheduler كل دقيقة للإشعارات وWorker default؛ عامل ai-indexing مستقل إن فعّل البحث البصري.
- SMTP مملوك ومجرب؛ log الحالي يرفض روابط الحماية. لا دليل وصول بريد حقيقي.
- نَقّح/عطل access logs لمسارات reset/verification وAuthorization وPOST secrets؛ حذف query لا يخفي token داخل path.
- المعاينة محمية بـIP allowlist/VPN وnoindex. Basic Auth شامل قد يتعارض مع Bearer؛ لا تعتمد الجمع في Authorization واحد.
- لا تنقل Preview/Flutter fixtures أو قواعدها أو credentials أو venv Windows أو نموذج اختبار إلى العميل. إعداد AI Runtime المعتمد إجراء منفصل؛ flags=false مؤقتًا تعني أن البحث البصري غير مفعل ويجب التصريح بذلك.
- تأكد من عدم وجود public/hot في حزمة النشر. لا composer run setup: يولد مفاتيح ويشغل migrations؛ استخدم الأوامر المراجعة أدناه.

## 10. Exact Local Upgrade Commands — لم تُنفذ على app_store
بعد الموافقة فقط: نسخة حديثة، وإيقاف كتابات الواجهة والـWorkers طوال DDL،وفحص الاتصال والـpending مجددًا.
~~~powershell
Set-Location 'D:/laravel/Laravel projects/appStore'
$phpBin='C:/laragon/bin/php/php-8.5/php.exe'
$env:APP_ENV='local'
$env:DB_CONNECTION='mysql'
$env:DB_HOST='127.0.0.1'
$env:DB_DATABASE='app_store'
$env:DB_URL=''
& $phpBin artisan migrate:status --no-interaction
& $phpBin artisan migrate --path=database/migrations/2026_10_31_204108_create_mobile_sync_infrastructure.php --no-interaction
if($LASTEXITCODE -ne 0){throw 'Stop: review partial DDL'}
& $phpBin artisan migrate --path=database/migrations/2026_10_31_204109_install_mobile_sync_change_tracking.php --no-interaction
if($LASTEXITCODE -ne 0){throw 'Stop: review partial DDL'}
& $phpBin artisan migrate --path=database/migrations/2026_11_01_104756_create_store_orders_content_and_alerts.php --no-interaction
if($LASTEXITCODE -ne 0){throw 'Stop: review partial DDL'}
& $phpBin artisan migrate --path=database/migrations/2026_11_01_121014_add_method_to_store_order_payments.php --no-interaction
if($LASTEXITCODE -ne 0){throw 'Stop: review partial DDL'}
$ownerUsername=Read-Host 'اسم المالك الحالي'
& $phpBin artisan app:store-access "--owner=$ownerUsername" --confirm --no-interaction
if($LASTEXITCODE -ne 0){throw 'Stop: review access initialization'}
& $phpBin artisan migrate:status --no-interaction
~~~
بعدها تحقق من63 Trigger وتعريفاتها والحجز الصفري وبصمات القيود والذمم وصلاحيات المالك فقط والدخول وإغلاق setup. لا setup-token أو Seeder أو sales-setup أو إغلاق فترة تلقائي. متغيرات Shell لا تستبدل فحص اتصال Laravel الفعلي وConfig Cache.


## 11. Exact aaPanel Commands — خطة فقط، لم تُنفذ
مطلوب أولًا: مسؤول السيرفر يراجع Schema وmigrate:status هناك بقراءة فقط. لا تفترض أن الإنتاج مطابق للمحلي أو أن34 ترحيلًا منفذًا عليه. إذا اختلف، توقف وأعد تجربة نسخة مطابقة له قبل اعتماد قائمة الترحيلات.
املأ RELEASE_DIR وPHP_BIN وCOMPOSER_PHAR وDB_NAME من إعداداته الفعلية؛ socket مثال Nginx يحتاج تحققًا. هذه ليست بيانات دخول.
~~~bash
set -euo pipefail
RELEASE_DIR='/www/wwwroot/REPLACE_RELEASE_PATH'
PHP_BIN='/www/server/php/85/bin/php'
COMPOSER_PHAR='/usr/local/bin/composer'
DB_NAME='REPLACE_APPROVED_DATABASE'
BACKUP_DIR='/www/backup/appstore/REPLACE_CHANGE_ID'
test -x "$PHP_BIN"
test -f "$COMPOSER_PHAR"
cd "$RELEASE_DIR"
"$PHP_BIN" -v
# اربط env الإنتاج المحفوظ وstorage الحقيقية قبل تشغيل Laravel.
# لا تنقل env/storage من ZIP أو قاعدة المعاينة.
mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
"$PHP_BIN" "$COMPOSER_PHAR" install --no-dev --prefer-dist --optimize-autoloader --no-interaction
"$PHP_BIN" "$COMPOSER_PHAR" check-platform-reqs --no-dev
"$PHP_BIN" artisan migrate:status --no-interaction
~~~

بعد اعتماد الخطة والاتصال والـBackup: نافذة صيانة توقف الويب وكتابات Flutter وWorkers، لا HTTP وحده. أمر down لا يوقف العامل أو عملية Queue بدأت مسبقًا.
نسخة حديثة في مجلد جديد0700، ببيانات MySQL login-path مهيأة عبر قناة خاصة، دون password في argv:
~~~bash
umask 077
mkdir "$BACKUP_DIR"
mysqldump --login-path=appstore-release --single-transaction --triggers --routines --events --hex-blob --no-tablespaces --set-gtid-purged=OFF "$DB_NAME" > "$BACKUP_DIR/database.sql"
tar -czf "$BACKUP_DIR/public-media.tgz" -C "$RELEASE_DIR/storage/app" public
sha256sum "$BACKUP_DIR/database.sql" "$BACKUP_DIR/public-media.tgz" > "$BACKUP_DIR/SHA256SUMS"
~~~
تحقق من الاستعادة في قاعدة جديدة منفصلة، ولا تستورد إلى الأصل للتجربة. بيانات login-path واسم/مسار النسخة يحتاجان إعدادًا فعليًا من مسؤول السيرفر؛ لم ننفذه هنا.

إذا تطابقت قائمة pending مع الأربع المعتمدة فقط، شغّلها صراحة بالترتيب بدل migrate مفتوح النطاق:
~~~bash
cd "$RELEASE_DIR"
for migration in 2026_10_31_204108_create_mobile_sync_infrastructure 2026_10_31_204109_install_mobile_sync_change_tracking 2026_11_01_104756_create_store_orders_content_and_alerts 2026_11_01_121014_add_method_to_store_order_payments
do
  "$PHP_BIN" artisan migrate --path="database/migrations/$migration.php" --force --no-interaction
done
read -r -p 'Existing owner username: ' OWNER_USERNAME
"$PHP_BIN" artisan app:store-access --owner="$OWNER_USERNAME" --confirm --no-interaction
"$PHP_BIN" artisan migrate:status --no-interaction
"$PHP_BIN" artisan storage:link --no-interaction
"$PHP_BIN" artisan config:cache --no-interaction
"$PHP_BIN" artisan route:cache --no-interaction
"$PHP_BIN" artisan view:cache --no-interaction
"$PHP_BIN" artisan queue:restart --no-interaction
~~~
إذا public/storage موجود فتحقق من هدفه؛ لا تحذفه تلقائيًا لإعادة إنشاء الرابط.
صلاحيات الكتابة لحساب خدمة aaPanel الفعلي، مع0750/0640 وفق مجموعته وACL؛ لا تنسخ أذونات Windows ولا تستخدم chmod777. لا تستخدم أوامر chown عامة على مسار غير متحقق.
إذا لم تتضمن الحزمة public/build: ابنِها على بيئة Build موثوقة بـnpm ci --ignore-scripts ثم npm run build، وانقلها مع Manifest. لا تنسخ node_modules.
Cron واحد لكل دقيقة:
~~~text
* * * * * cd /www/wwwroot/REPLACE_RELEASE_PATH && /www/server/php/85/bin/php artisan schedule:run >> /dev/null 2>&1
~~~
في aaPanel راجع Supervisor template للمسارات والمستخدم، ثم شغّل default worker بعد اكتمال الفحص. ai-indexing فقط بعد اختبار Runtime واعتماد تفعيله. timeout60 أقل من retry_after90/120 الحاليين.
راجع nginx -t ثم إعادة تحميل Nginx عبر آلية aaPanel المعتمدة؛ لا استبدال لشهادة أو vhost عامل بمثالنا.
Smoke بعد ذلك: HTTPS/login وإغلاق setup،صلاحيات المالك والكاشير والفروع،رفض تكلفة/API غير مصرح،503 مفهوم قبل إعداد المتجر، ثم نشر منتج وإجراء تجارب على staging المعزول فقط.
لا ترفع maintenance أو تفتح الموقع للعامة أو تبدأ بيعًا فعليًا قبل معالجة الموانع المالية وموافقة صاحب المحل. إعداد/إغلاق البداية من المسار الحالي الموثق، وليس تعديل DB مباشرًا.
مراجع التنفيذ: [نشر Laravel](https://laravel.com/framework/docs/deployment)،[Scheduler](https://laravel.com/framework/docs/scheduling)،[Queue workers](https://github.com/laravel/docs/blob/13.x/queues.md). الأوامر أمثلة مراجعة وليست أثرًا منفذًا على سيرفر.

## 12. Rollback / Recovery Plan
- قبل الترقية: نسخة DB وملفات media وenv/APP_KEY المحفوظ، وإصدار الكود السابق، وقائمة DDL/Triggers والمجاميع وتاريخ توقف الكتابات.
- فشل DDL: أبقِ الصيانة وWorkers متوقفة، ولا fresh/wipe/reset/rollback ولا حذف Trigger أو تغيير migrations يدويًا لإخفاء الفشل. افحص ما اكتمل فعليًا.
- استعد النسخة إلى قاعدة جديدة، وتحقق من Counts/بصمات/قيود وTriggers، واربط الكود السابق بها ضمن قرار استرداد معتمد. لا تكتب فوق الأصل تلقائيًا، واحتفظ بالنسخة الفاشلة للتدقيق.
- إذا قُبلت معاملات بعد التحديث: يمنع استرداد نسخة أقدم بصمت؛ تحتاج حفظ/تسوية العمليات اللاحقة وإيصالات الهاتف وOutbox وإدارة cursors والأجهزة. فقد هذه الإيصالات قد يكرر عملية مالية عند إعادة الإرسال.
- down في ترحيلات الأمان والمزامنة والمتجر يرفض الحذف المتعمد؛ ليست أداة تراجع تلقائي. المفاتيح الخاصة والأصول التاريخية لا تعاد تصنيفها.
- لا يُقترح أي قيد مقابل افتتاح أو تصفير فرق لمعالجة فشل ترقية.

## 13. Remaining Risks وما يلزم قبل التسليم
BLOCKED: موافقة ترقية app_store نفسها لم تُعط بعد؛ يوجد فرق250.00 ومستند افتتاحي بلا قيد وفترة مفتوحة.
WARNING: المستودع Dirty وغير متتبع جزئيًا؛ لا تعتمد HEAD كحزمة الإصدار، ولا تنقل أدوات الاختبار/النسخ/credentials/ملفات Runtime الخاصة.
WARNING: مصححات رسم الطلب أو توريد العهدة تحتاج المستند المالي المناسب؛ لا واجهة عكس مستقلة جديدة في هذه المرحلة، كما وُثق في تقرير المتجر.
NOT TESTED: استعادة/صلاحيات/SSL/FPM/SMTP وإعداد AI على aaPanel،ومطابقة بيئة الإنتاج الحقيقية،وFlutter/الهاتف الفعلي،واختبار الحمل.
PASS: لا خدمات مدفوعة أو نماذج جديدة أو نسخ بيانات معاينة إلى الأصل.

## 14. الأدلة والملفات المتغيرة في هذه المهمة
- docs/final-release-readiness.md وهذا جدول الترحيلات والأمثلة الثلاثة.
- tests/prepare-release-rehearsal.php: Backup/Restore محليان مع حراس، دون كتابة المصدر.
- tests/Feature/Mobile/MobileSynchronizationTest.php: Pull بعد السلسلة وحجز آخر قطعة، دون تعديل منطق التطبيق.
- public/build: ناتج npm build؛ ignored ومطلوب ضمن حزمة النشر.
الأدلة داخل storage/app/reports:
final-release-rehearsal.json،final-release-rehearsal-run.txt،final-release-clone-migrations.txt،final-release-migration-status.txt،final-release-migration-inventory.{json,md}،final-release-integrity.json،final-release-schema-validation.json،final-release-full-suite.txt،final-release-mobile-scenarios.txt،final-release-pint.txt،final-release-frontend-build.txt،final-release-npm-install-retry.txt.
كل بيانات الدخول والـBackup خارج docs/public؛ لا أسرار في التقرير.

## 15. الموافقة المطلوبة
المستهدف app_store المحلية فقط على127.0.0.1، وليس الإنتاج.
المطلوب لاحقًا: إعادة فحص اتصالها وأخذ Backup حديث، ثم الترحيلات الأربعة أعلاه وأمر app:store-access للمالك الحالي (5 صلاحيات وحسابان نظاميان بلا رصيد).
تجربة النسخة ناجحة، والنسخة السابقة قابلة للاستعادة؛ لا مبرر لحذف جدول أو إعادة إدخال مخزون.
الموافقة لا تشمل ترحيل فرق250.00 أو تهيئة المبيعات أو إغلاق الافتتاح أو تشغيل Workers أو نشر الإنتاج. كل ذلك يحتاج نطاقًا وموافقة منفصلين.
توقف التنفيذ قبل أول كتابة إلى app_store.

## 16. الحزمة المحلية القابلة للمراجعة
PASS: storage/app/private/release-candidate/20261101-6d8e9ba9/appstore-release-candidate.zip
الحجم8549077 بايت،581 ملفًا، SHA-256:
bc92de66ad1dcf2bcada7f374e8a440dd8eb87781e2bfd04996f8536faeac220
manifest.json بجانبها يحوي SHA-256 لكل ملف، وفُحص ZIP CRC والمحتويات والبصمات. الحزمة من الملفات الحالية لا HEAD وحده، وتشمل public/build.
مستبعد: env وAPP_KEY وcredentials وDB data وprivate storage وfixtures وtests وvendor وnode_modules وpublic/storage وbootstrap caches. لا ترفع مجلد release-candidate كله؛ ZIP فقط بعد مراجعة وإذن نشر مستقل.
مجلد verification بجانبها لتجربة تثبيت الإنتاج محليًا؛ ليس جزءًا من ZIP ولا يسلم للعميل. قد يحتوي لاحقًا Cache إعدادات اختبارية خاصة.
أمثلة الاتصال والمسارات تتطلب ملئها ومراجعتها؛ الحزمة لا تنشئ env أو تربط بيانات العميل تلقائيًا. وثيقة الجاهزية هذه منفصلة عن ZIP، وAPI contract وأمثلة النشر داخله.
بعد تحديث صلاحيات/نطاق مستخدم هاتف قد يتغير scope fingerprint؛ عند409 أعد Bootstrap وفق العقد مع الاحتفاظ بـOutbox، لا حذف العمليات غير المرسلة.

## 17. فحص حزمة الإنتاج المعزولة
PASS: فك ZIP في verification-offline، واستخدم Vendor من النسخة المثبتة المقفلة، ثم Composer install --no-dev --no-scripts دون شبكة داخل النسخة فقط؛ أزيلت35 حزمة تطوير،installed.dev=false،وVendor المشروع الأصلي بقي كما هو.
WARNING: محاولة التثبيت الجديدة عبر الشبكة فشلت بسبب الشهادة/الوصول إلى GitHub؛ لا ادعاء للتحقق من تنزيل Composer على سيرفر العميل. النسخ من المكتبات المثبتة ثم تثبيت الإنتاج Offline بديل محلي للتحقق الوظيفي، لا بديل لإجراءات تثبيت السيرفر.

PASS: package:discover وconfig:cache وroute:cache وview:cache بإعداد production/debug=false على قاعدة النسخة،دون كتابة app_store.
PASS: HTTP Kernel حقيقي من الحزمة مع Route/Config Cache:
| الطلب | النتيجة | التفسير |
|---|---:|---|
| /login | 200 | نموذج الدخول |
| /setup | 404 | التثبيت المكتمل لا يفتح تسجيل مالك |
| /store-orders | 302 | تحويل الضيف للدخول |
| /api/v1/auth/me | 401 | لا وصول بلا Bearer صالح |
| /shop | 503 | متوقع: لا إعداد متجر أو نشر بيانات معاينة تلقائيًا |

لا Nginx/FPM/HTTPS فعليًا هنا. الإعداد cached داخل verification-offline خاص بالتجربة ويحتوي مفاتيح/اتصالها؛ لا تنسخه للسيرفر أو ZIP. مجلد المرشح له ACL خاصة،ولا يشمل ZIP مجلدات verification.
الأدلة: final-release-production-install.txt (فشل الشبكة)،final-release-production-offline-install.txt،final-release-cache-build.txt،final-release-production-http.txt،final-release-production-smoke.json،final-release-package.json.

## 18. فحص الأصل عند التسليم
القراءة الأخيرة local/mysql/127.0.0.1/app_store:34 migration فقط،ولا جداول mobile/store الجديدة أو reserved_quantity.
بصمات جميع جداول الأعمال والمستخدمين والأدوار والصلاحيات والأرصدة وحالة الإعداد بقيت كما كانت. اختلف cache وsessions خلال مدة العمل؛ لا ننسب مصدر التغير دون دليل ولا ندعي تطابق القاعدة كلها عند التسليم. المقارنة الأولى عند انتهاء تجربة الاستعادة كانت متطابقة بالكامل؛ هذه قراءة أحدث.
فحص Cache نسخة Production أكد أن DB المستهدفة فعليًا هي appstore_release_rehearsal_20261101_a68eaee5؛ لا app_store. لا تعكس أو تحذف جلسات أو cache الأصل لتجميل المقارنة.
النسخة المثبتة صالحة لاستعادة لقطة وقتها؛ يجب نسخة حديثة عند أي موافقة وتنفيذ لاحق.
بعد Pint أعيد السيناريوهان النهائيان أيضًا:2 اختبار و32 assertions ناجحة. أدلة النهاية:final-release-appstore-final.json وfinal-release-final-scenarios.txt.
