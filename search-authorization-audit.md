# تدقيق صلاحيات البحث في appStore
تحديث 2026-11-01: أُصلحت SA-01 وSA-02 بموافقة المستخدم، وأصبحت اختبارات إعادة الإنتاج اختبارات لمنع الكشف. تفاصيل المعالجة والاختبارات في [تقرير الإصلاح والتكامل](docs/search-remediation-flutter-integration.md). بقية هذا الملف يحتفظ بنتيجة التدقيق الأصلية وأدلتها التاريخية؛ لا يصف حالة الملاحظتين بعد الإصلاح. قرار دليل العملاء المشترك D-01 لم يتغير.

التاريخ: 2026-10-31. مراجعة كود واختبارات HTTP Feature فعلية على MySQL المعزول appstore_golive_20261031_tests. لا تعديل لإصلاح منطق البحث القديم أو صلاحياته، ولا استخدام لحسابات app_store في الاختبارات.

## النتيجة
لا يمكن اعتماد عبارة «جميع نقاط البحث تمنع تجاوز الفروع بالكامل». ثبت كشف بيانات تعريف متغير من فرع آخر ضمن منتج مشترك، وثبت خطأ مرشح يمكنه كشف تفاصيل تقنية عند APP_DEBUG=true. لم تكشف الاختبارات المحددة تكلفة أو COGS أو ربحًا للكاشير الافتراضي. الفواتير والأرصدة المخزنية الفعلية مقيدة بالفرع في المسارات المختبرة.

العملاء والموردون والكتالوج المرجعي كيانات مشتركة في التصميم الحالي؛ Customer لا يحتوي branch_id. ظهور اسم وهاتف عميل لا توجد له إلا فاتورة بفرع آخر قابل لإعادة الإنتاج، بينما كشفه المالي بالفرع الآخر يرفض. حسم هل يجب عزل دليل العملاء نفسه قرار مطلوب، ولا يصح وصف عزل العملاء بأنه مكتمل.

## نتائج مثبتة تنتظر الموافقة على الإصلاح

### SA-01 — متغير من فرع آخر داخل منتج مشترك — متوسطة
المسارات:
- GET /api/products?q=<foreign-only-SKU>
- GET /api/products/{sharedProduct}
- GET /sales/catalog?branch_id=<ownBranch>&q=<foreign-only-SKU>

VisibleProductsQuery يثبت وجود المنتج في الفرع المسموح، لكن ProductController يجلب variants دون ربط كل متغير بمخزون الفرع. وفي CashierController شرط وجود مخزون محلي للمنتج مستقل عن شرط مطابقة المتغير، والتحميل اللاحق يقيد المخزون نفسه فقط. يمكن أن تعود هوية المتغير وSKU والمقاس وسعر البيع لمتغير مخزونه حصري بفرع آخر. لم تكشف إعادة الإنتاج كمية الفرع الآخر أو تكلفته؛ كتالوج الكاشير يعرض available=0 لهذا المتغير.

الدليل: SearchAuthorizationAuditTest::test_reproduces_foreign_only_variant_metadata_in_shared_product_without_exposing_its_quantity_or_cost وstorage/app/reports/search-authorization-findings.json.
اختبار إعادة الإنتاج يؤكد وجود المشكلة عمدًا؛ نجاحه ليس نجاح حماية هذه المسارات. الإصلاح المقترح بعد الموافقة: ربط مطابقة المتغير وتحميله وصوره بنطاق المخزون المسموح، مع حسم سياسة الكتالوج المشترك والأصناف غير المخزنة، ثم قلب التأكيد إلى منع الكشف.

### SA-02 — مرشح valuation في Global Search — منخفضة، وترتفع أهمية الكشف التقني مع Debug
GET /search/suggestions?type=products&q=AUDIT&valuation=mismatch يعيد500. SearchRequest يقبل valuation ثم ModuleSearch::inventory يشير إلى history.history_quantity دون انضمام history داخل ProductSearchAction. مع APP_DEBUG=true يظهر QueryException وتفاصيل SQL داخل JSON. لم يظهر سجل محظور في إعادة الإنتاج، لكن تفاصيل قاعدة البيانات ومسارات التطبيق لا ينبغي إرسالها.
الدليل: test_reproduces_global_product_valuation_filter_failure_and_debug_metadata_exposure.
الإصلاح المقترح بعد الموافقة: قبول مرشح التقييم في شاشة الجاهزية فقط أو بناء Query مناسب له، وضمان رد عام للخطأ وتعطيل Debug عند النشر. لم نغير الاستعلام القديم.

### D-01 — دليل العملاء المشترك — قرار نطاق مطلوب
GET /customers?q=... وGlobal Search يعيدان اسم العميل المشترك ولو كانت فواتيره كلها في فرع آخر. Autocomplete يعيد id/name/phone فقط. كشوف الذمم والفواتير والسندات مقيدة بالفرع. إذا كانت السياسة المطلوبة هي سرية هوية العملاء بين الفروع، فالحماية الحالية غير كافية وتحتاج تعريف ارتباط العميل بالفروع، لا مجرد إضافة where على عمود غير موجود.

## حصر نقاط البحث الفعلية

كل المسارات الخاصة أدناه تتطلب مصادقة ومستخدمًا فعالًا وإتمام الفرع الأول وفق بوابات الويب الحالية. الرمز «اختبار» يعني طلبًا فعليًا في الحزمة أو اختبارًا قائمًا محددًا، و«مراجعة» يعني تحقق الكود فقط لذلك التفصيل؛ لا يُستبدل أحدهما بالآخر.

| الوظيفة | Endpoint | الصلاحية الإضافية | نطاق الفرع/البيانات | ما يسمح للكاشير وما يحظر | نتيجة التدقيق |
|---|---|---|---|---|---|
| Global Search | GET /search | GlobalSearchAction::allowed حسب type | المستندات والمخزون whereIn للفروع؛ الاتصالات مشتركة | منتجات/عملاء/مبيعات/مرتجعات/استبدالات/سندات؛ لا موردين/شراء/حسابات/إدارة | اختبار رفض الأنواع المحظورة وتصفية الفواتير؛ D-01 |
| اقتراحات عامة | GET /search/suggestions | نفس allowed | نفس Queries قبل limit6 ثم5 نتائج | DTO محدد، بلا تكلفة أو سعر ضمن اقتراح المنتج | اختبار الحدود والأنواع؛ SA-02 عند valuation |
| بحث SKU/منتج | GET /search/products | catalog.search + inventory.view | Inventory scoped قبل pagination | SKU/سعر بيع/كمية محلية؛ لا average_cost دون sales.view_cost | اختبار مرور ومنع branch_id الأجنبي وحجب التكلفة |
| باركود ونتيجة OCR | POST /search/products/codes | catalog.search + inventory.view | نفس ProductSearchAction ثم whereIn SKU | معرّفات وأسعار بيع محلية فقط | اختبار كودين من فرعين يعيد المحلي فقط |
| بحث بصري | POST /search/products/visual | catalog.search + inventory.view | فحص branch قبل embedding؛ حصر allowed image IDs وإعادة فلترة النتائج | بيانات المتغير المسموح؛ لا تكلفة للكاشير | VisualProductSearchTest بمزود محقون، لا نموذج فعلي أو تنزيل |
| ملصقات باركود/طباعة | GET /search/products/labels | catalog.search + inventory.view | جميع IDs يجب وجودها ضمن Query المسموح | ملصق SKU محلي وسعر البيع | اختبار المحلي200 والأجنبي404 بلا تفاصيله |
| إدارة الفهرس | GET /search/visual-index | السابقة + inventory.manage_catalog | allowedImages من Query scoped | محجوب عن الكاشير | اختبار403، ومراجعة Query |
| شاشة الكاشير | GET /sales/cashier | sales.view + sales.sell | branches مسموحة، branch_id الأجنبي403 | إعدادات بيع ومدفوعات محلية؛ لا تكلفة | اختبارات قائمة ومراجعة JSON المضمن |
| كتالوج الكاشير | GET /sales/catalog | sales.view + sales.sell + catalog.search + inventory.view | مخزون وreadiness بالفرع المطلوب | سعر البيع والكميات المحلية، لا unit_cost | اختبار؛ SA-01 في المتغيرات المشتركة |
| معاينة السلة المرتبطة | POST /sales/preview | sales.view + sales.sell داخل Request/Action | OperationContext + مخزون الفرع | الإجماليات والمقبوض؛ cost/gross_profit فقط للمخول | مراجعة ومجموعة FinancialReportSecurityTest؛ ليست محرك بحث مستقلًا |
| المنتجات API وقائمة التفاصيل | GET /api/products و/{id} | catalog.search | VisibleProductsQuery على المنتج | بيانات كتالوج دون minimum_selling_price | اختبار ID أجنبي404 وعدد1؛ SA-01 للمتغير المشترك |
| المخزون API | GET /api/inventories | inventory.view | whereIn ثم branch_id تقاطع؛ valuation مستبعد | كمية الفرع، بلا average_cost/value_minor/quarantined_value_minor/minimum_selling_price | اختبار JSON والعدد |
| قائمة المخزون | GET /inventory | inventory.view | ModuleSearch::inventory scoped | الاسم/صور اللون/المقاس/الكمية/سعر البيع | اختبار HTML وحجب التكلفة وعدم تضمين حوار تعديل التكلفة للكاشير |
| تفاصيل المنتج | GET /inventory/products/{product} | inventory.view | ShowInventoryProductAction يقيد المتغيرات والمخزون والصور | تكلفة لا تُجلب دون الصلاحية | اختبار المنتج الأجنبي404؛ مراجعة select المشروط |
| مرجع التصنيفات | GET /api/categories | catalog.search | مشترك | مرجع كتالوج وليس إدارة تصنيفات | مراجعة؛ ليس التفافًا على صلاحية صفحة الإدارة |
| مرجع الألوان | GET /api/colors | catalog.search | مشترك | اسم/لون/رمز مرجعي | مراجعة |
| مرجع المقاسات | GET /api/sizes | catalog.search | مشترك | مقاسات مرجعية | مراجعة |
| قائمة/Autocomplete العملاء | GET /customers | sales.customers | دليل مشترك، أرصدة HTML مجمعة بفروع مسموحة | id/name/phone في JSON؛ لا أرصدة فرع آخر | اختبار؛ D-01 |
| بحث كشف عميل | GET /customers/{id}?entry_q=... | sales.customers | branch membership ثم query مالي مقيد | كشف الفرع المسموح؛ لا كشف أجنبي | اختبار branch_id الأجنبي403 ومراجعة SalesReports |
| المبيعات | GET /sales?q=... | sales.view | whereIn branch قبل البحث والعد | فواتير الفرع، بلا أعمدة التكلفة | اختبار الرقم واسم الموظف/العميل الأجنبي لا نتائج |
| روابط الفاتورة | GET /sales/{id} و/print و/edit | sales.view، ثم OperationContext بالقدرة اللازمة | الفرع يفحص على السيرفر | الفاتورة المسموحة؛ لا IDOR | اختبار روابط فرع آخر403 بلا علاماته |
| سند العميل | GET /customers/receipts/{id} | sales.customers + sales.collect داخل Controller | OperationContext branch | السند المحلي | مراجعة وربط نتائج Global Search |
| الموردون وكشفهم | GET /suppliers و/{id} | purchases.view + sales.view_cost | الدليل مشترك؛ الفواتير والدفعات scoped | محجوب عن الكاشير | اختبار403؛ ModuleSearchTest يراجع بحث المالك |
| Autocomplete المورد | GET /purchases/suppliers | purchases.view + sales.view_cost | دليل مشترك | محجوب عن الكاشير | اختبار403 |
| بحث أصناف الشراء | GET /purchases/products | purchases.view + sales.view_cost | كتالوج مشترك؛ تكلفة branch تختبر عضويته | محجوب عن الكاشير | اختبار403؛ سياسة ظهور كتالوج عابر للفروع للمشتري تحتاج مراجعة إذا أُريد عزله |
| فواتير الشراء | GET /purchases | purchases.view + sales.view_cost | فروع مسموحة قبل بحث المستند | محجوب عن الكاشير | اختبار403 + ModuleSearchTest للمالك |
| أول المدة والكتالوج | GET /inventory/opening-stock و/products و/options | inventory.correct_setup + sales.view_cost | المستندات scoped، مرجع كتالوج للإدخال | محجوب عن الكاشير | اختبار403 ومراجعة Actions |
| جاهزية المخزون والتصدير | GET /inventory/readiness و/export | inventory.valuation_review + sales.view_cost | Query نفسه scoped والتصدير يعيد فحص الفرع | محجوب عن الكاشير | اختبار403 للتصدير أيضًا |
| المصروفات | GET /finance/expenses | finance.view | فروع مسموحة قبل البحث/العد | محجوب عن الكاشير | اختبار403، مراجعة Controller |
| الصناديق/تصدير الفترة | GET /finance/treasury?export=1 | finance.view، cost للحقول الحساسة | اختيار الفرع من القائمة المسموحة | محجوب عن الكاشير؛ المالي دون تكلفة مصفى | اختبار403 + FinancialReportSecurityTest |
| حسابات القبض | GET /finance/accounts | sales.manage_accounts | ModuleSearch::accounts scoped | محجوب عن الكاشير | اختبار403 + ModuleSearchTest |
| الانتقال المالي/التصدير | GET /finance/transition?export=1 | finance.transition_review + sales.view_cost | فروع الممثل فقط | محجوب عن الكاشير | اختبار403 ومراجعة |
| تقارير المبيعات | GET /sales/reports | sales.view + sales.reports + sales.view_cost | نطاق الفروع ومجاميع الفترة | محجوب عن الكاشير | اختبار403 |
| إدارة التصنيفات/الألوان/المقاسات | GET /catalog/attributes?q=... | inventory.manage_catalog | مرجع مشترك | محجوب عن الكاشير | مراجعة route، وليس Global Search |
| الفروع | GET /branches?q=... | branches.view | own id ما لم sales.all_branches | محجوب عن الكاشير | اختبار403 واختبارات الفروع القائمة |
| المستخدمون والأدوار | GET /access/users و/roles | access.users.manage / access.roles.manage | قائمة المستخدمين scoped لغير المالك؛ لا بحث q مستقل في Controller | محجوبان عن الكاشير؛ لا نوع employees في Global Search | اختبار403 وtype غير معروف422 |

## الأدلة والحدود
- أضيف tests/Feature/Search/SearchAuthorizationAuditTest.php: **6 اختبارات و127 assertions ناجحة**، تشمل حالتي إعادة إنتاج تؤكدان وجود الملاحظات، وليستا إثباتًا على إصلاحهما.
- السجل: storage/app/reports/search-authorization-audit-tests.txt.
- الأدلة الخاصة بالمتغير المشترك: storage/app/reports/search-authorization-findings.json.
- راجعنا Blade وJSON المضمن وواجهات JS: كاشير المنتجات والعملاء، Global Suggestions، أدوات المسح/OCR والبحث البصري. JS يستهلك المسارات المحمية ولا يحل محل تفويض السيرفر. أسماء Routes والمعرّفات داخل JS ليست صلاحية بحد ذاتها.
- Counts وPagination للفواتير والمخزون في سيناريوهات الفرعين اقتصرت على السجلات المحلية. دليل العملاء له إجمالي مشترك حسب D-01. طلب موظف/مورد لا يُنشئ نوع بحث إداري غير مسموح.
- لا ملف Export عام مستقل للبحث الشامل؛ تصدير الجاهزية والانتقال والفترة والملصقات هو الموجود فعليًا.
- لم نختبر نموذج AI حقيقي أو هاتفًا فعليًا أو إعداد Production، ولم نتصل بالإنتاج.
- لم نُصلح SA-01 أو SA-02. المطلوب قبل تسليم حماية بحث كاملة: الموافقة على إصلاحهما، وحسم D-01 وسياسة الكتالوج المشترك للمستخدمين ذوي صلاحيات الشراء. هذا التقرير يغطي البحث الموجود قبل API Flutter الجديد؛ توثيق API الجديد منفصل.
