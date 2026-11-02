# تقرير تنفيذ Laravel REST API وFlutter Offline Sync

التاريخ: 2026-10-31، واستكمال التحقق بعد الانقطاع في 2026-11-01. التنفيذ محلي باستخدام PHP8.5.12، Laravel13.35.0، MySQL8.4.3، وSanctum4.3.3 المثبت مسبقًا.
لم يحدث Commit أو Push أو Deploy أو اتصال بالإنتاج أو VPS أو aaPanel. لم تتغير dependencies أو إعدادات البريد أو ملفات البيئة.

## النتيجة

نُفذت المراحل السبع للـBackend ضمن النطاق المحدد: مصادقة Bearer مرتبطة بجهاز، قراءة مفوضة، إنشاء/تعديل منتجات مع Client UUID Mapping، صور لون WebP، افتتاح ومشتريات ومبيعات عبر Actions القائمة، وBootstrap/Pull/Push مع Idempotency والتعارضات والاعتماديات. لا يوجد تطبيق Flutter أو SQLite client ضمن المستودع ولم ننشئ واجهاته؛ العقد يحدد ما على مطورة Flutter تنفيذه.

API تحت /api/v1، ولها12 نمط Route موثقًا في OpenAPI. لا يمكن تشغيلها على app_store الحالية قبل الموافقة على تطبيق ترحيليها؛ عدم وجود Schema/سجل ترحيل المزامنة يعيد503. لا تنشئ الميزة حساب مالك أو فرعًا أو صلاحيات أو أرصدة تلقائيًا.

## 1. مراجعة المعمارية وإعادة الاستخدام

رُوجعت Models والعلاقات والجداول وقيودها، مصادقة الويب وRBAC والفروع والإعداد الأول، وActions إنشاء/تعديل المنتجات والصور وتوليد الأكواد، وإعداد/اعتماد الافتتاح والمشتريات والمبيعات، وStockLedger وPostJournalAction وOperationContext والاختبارات ذات الصلة. لم ننسخ قواعد الترحيل المالي إلى Controllers أو Flutter.

| عملية المحمول | التنفيذ الفعلي المعاد استخدامه |
|---|---|
| product.create | CreateProductAction، مع توليد كود السيرفر عند غيابه |
| product.update | UpdateProductAction وقيود هوية الصنف التاريخية |
| صور المنتج واللون | StoreProductColorImagesAction وProcessProductImageAction والمراقب الحالي للفهرسة |
| opening_stock.create | StoreOpeningStockRequest وPostOpeningStockAction |
| purchase.create | SavePurchaseInvoiceRequest ثم SavePurchaseInvoiceAction وApprovePurchaseInvoiceAction؛ مالية الشراء والسداد عبر Actions القائمة |
| sale.create | SaveSaleRequest ثم SaveSaleAction وPostSaleAction؛ السداد والتحصيل عبر منطق البيع القائم |
| التفويض والفروع | SalesAccess وGates القائمة وOperationContext، دون صلاحيات أو أدوار موازية |
| البحث | ModuleSearch وSearchTerm داخل Query مفوضة للـAPI الجديدة |

استخرجنا generateCodes من PostOpeningStockAction إلى AllocateProductCodesAction بنفس الكود والخطوات، واستدعاه مسارا الافتتاح والمحمول. لا تغيير لطريقة توليد SKU أو تسلسل المرشحين أو فحص المنتجات المؤرشفة، ولا أعمدة UUID جديدة في جداول المنتجات.

السيرفر ينشئ الهوية النهائية للمنتج والمتغير؛ UUID العميل مرجع مزامنة وليس ID قاعدة البيانات ولا SKU نهائيًا. لا يعتمد الهاتف كودًا مطبوعًا مؤقتًا على أنه نهائي.

## 2. البنية الأمنية والمزامنة

- Sanctum tokens تحفظ ببصمتها، وقدرة التوكن mobile:v1 لا تحل محل RBAC. لا Cookies للمصادقة على API المحمول.
- كل جهاز مربوط بالمستخدم وبالتوكن الحالي، ويتحقق من التفعيل والدور وكلمة المرور/remember_token الحالية. يوجد تدوير للتوكن وخروج وإبطال جهاز وقائمة أجهزة المستخدم.
- دخول الجهاز وخروجه وإبطاله مدققة دون كلمات مرور أو Tokens في البيانات المسجلة.
- صلاحية الفرع وحقول DTO تُفحص على السيرفر. تكلفة المخزون والحد الأدنى للسعر وCOGS غير مرسلة للكاشير.
- الاستجابات والأخطاء في المسارات الجديدة لا تتضمن stacktrace أو SQL حتى في وضع Debug، مع Rate Limiting وRetry-After وحدود للدفعات والملفات.
- operation_uuid فريد عالميًا. حفظ العملية ونتيجتها والمستند وحركاته وقيوده داخل معاملة واحدة لكل عملية. المحتوى المختلف لنفس UUID يرفض بتعارض، وإعادة الطلب المقبول تعيد النتيجة السابقة.
- Batch بحد20 عملية، وتسلسل Topological للتبعيات؛ الفشل يمنع التابع، ولا يُرجع نجاحًا وهميًا. الرفض والتعارض قابلان للمراجعة ولا يحذفان تلقائيًا.
- تعديل المنتج يحتاج expected_revision. البيع يحتاج مراجعة مخزون معتمة أو stock_revision_from لعملية أم ناجحة على الجهاز؛ اختلاف الرصيد يرفض البيع دون أثر جزئي.
- سجل تغييرات MySQL يلتقط التعديلات في20 جدولًا، بما فيها SQL/كتابات الويب في الجداول المتعقبة. إصداره مقفل حتى commit لمنع فقد معاملة متأخرة خلف Cursor.
- التصفية تسبق حدود صفحات Pull، وTombstones تقتصر على سجلات سبق تسليمها للجلسة. المؤشر مشفر ومربوط بالجهاز والفرع وبصمة الصلاحيات، ولا يعتمد updated_at.
- يجمع Pull الحالة الحالية، وليس Snapshot محاسبيًا تاريخيًا عبر عدة طلبات. التكرارات متوقعة وموثقة، ويطبقها العميل idempotent.
- لا محرك تعارضات last-write-wins، ولا اعتماد مخزون أو تعديل تكلفة مالية خفي، ولا إغلاق تلقائي لفترة الإعداد.

## 3. Endpoints

| Method | Path |
|---|---|
| POST | /api/v1/auth/login |
| GET | /api/v1/auth/me |
| POST | /api/v1/auth/logout |
| GET | /api/v1/devices |
| DELETE | /api/v1/devices/{device} |
| GET | /api/v1/data/{entity} |
| GET | /api/v1/data/{entity}/{id} |
| POST | /api/v1/sync/bootstrap |
| POST | /api/v1/sync/pull |
| POST | /api/v1/sync/push |
| GET | /api/v1/sync/operations/{operation} |
| POST | /api/v1/product-images |

أنواع القراءة17، محددة في ReadMobileData::allowed وليست أسماء Models يختارها المستخدم: مراجع وكتالوج وصور ومخزون وعملاء وذمم ومبيعات وسندات وحسابات قبض وموردون ومشتريات وافتتاح وفق القدرات. إنشاء المستندات عبر /sync/push؛ الدفعة ذات عملية واحدة تعمل أيضًا للعميل المتصل.

## 4. Migrations

طُبقا بآلية Laravel المعتادة على appstore_golive_20261031_tests فقط، دون migrate:fresh أو db:wipe:

1. database/migrations/2026_10_31_204108_create_mobile_sync_infrastructure.php
   ينشئ7 جداول:
   - mobile_devices: ربط جهاز المستخدم بالتوكن وحالة الإبطال.
   - mobile_operations: UUID فريد، بصمة الطلب، التبعيات، الحالة والنتيجة الدائمة.
   - mobile_entity_mappings: UUID المنتج/المتغير المحلي إلى ID السيرفر، بنطاق الجهاز.
   - mobile_sync_clock: الإصدار المعاملاتي المرتب بالاعتماد.
   - mobile_sync_changes: معرفات تغييرات مفهرسة، بلا نسخ Payload مالية.
   - mobile_sync_sessions: جلسة المؤشر ونطاقها ومدة صلاحيتها.
   - mobile_sync_records: ما سبق تسليمه للجلسة، لمنع كشف Tombstones أجنبية.
2. database/migrations/2026_10_31_204109_install_mobile_sync_change_tracking.php
   يضيف60 Trigger على20 جدولًا مع Backfill للهوية الحالية للبيانات الموجودة. لا يعيد إدخال البضاعة أو إنشاء قيود أو دفعات.
   يمكن إعادة المحاولة بعد انقطاع تركيب Triggers إذا كانت التعريفات الموجودة مطابقة؛ التعريف المتعارض يوقف العمل ولا يُحذف آليًا.

لا تغييرات على أعمدة الأعمال القائمة أو أسعار المخزون أو UUID للمستندات القديمة. الترحيل الثاني يعمل على MySQL؛ API نفسها ترفض SQLite على السيرفر. ملفات SQLite لا تزال تستخدم في بعض اختبارات الويب القديمة فقط.

down يرفض الحذف التلقائي حفاظًا على Idempotency والمؤشرات. MySQL DDL ليست معاملة واحدة؛ ترحيل جزئي يحتاج مراجعة أو استرداد نسخة معتمدة. لا تجرّب rollback حاذفًا لإيصالات عمليات مقبولة.

## 5. الاختبارات والأدلة

- حزمة التوافق العامة: 529 حالة،510 ناجحة و19 متجاوزة اختياريًا، و4535 assertions، دون فشل أو أخطاء. سجل mobile-api-full-compatibility.txt. هذه الجولة سبقت إضافة اختباري مطابقة العقد والمقارنة HTTP التفصيلية وبعض تأكيدات DTO الأخيرة؛ أُعيدت حزمة API الحالية بعد ذلك.
- مجموعة API النهائية قبل الانقطاع: 25 اختبارًا ناجحًا و717 assertions خلال 2:09 دقائق، وفق mobile-api-final-verification.txt. تشمل حماية نتيجة تعديل المنتج وإعادة الطلب من كشف متغيرات فرع آخر لموظف كتالوج محدود الفروع.
- إعادة التحقق بعد الانقطاع في 2026-11-01: نجح المرشح `Mobile|SearchAuthorizationAuditTest` على MySQL المعزول: 32 اختبارًا و854 assertions خلال 3:08 دقائق، دون أخطاء أو فشل. تشمل النتيجة اختبارات إعادة إنتاج ملاحظات البحث القديم؛ نجاحها يثبت استمرار الملاحظات ولا يعني إصلاحها. لم نُعد حزمة التوافق العامة كاملة بعد الاستئناف؛ نتيجتها السابقة محفوظة أعلاه.
- OpenAPI3.1:12 مسارًا و53 Schema. تحققنا من قراءة YAML ومرجعياته المحلية، ومن مطابقة DTO الفعلية لـ17 نوع قراءة مع الحقول والأنواع والتواريخ. هذا ليس تشغيل مولّد Flutter أو مدقق OpenAPI خارجي كامل.
- اختبارات المصادقة تستعمل تسجيل دخول وتوكنات Sanctum حقيقية على MySQL، لا Sanctum::actingAs بدل اختبار Bearer.
- اختبارات التزامن تستخدم عمليتي PHP مستقلتين وقواعد appstore_mobile_race_* معزولة، وتعريفات جداول فقط من Sandbox. احتُفظ بها للمراجعة ولم تُحذف قواعد قائمة.
- تشمل الحالات: Cash بلا عميل، آجل، جزئي، منتج Offline ثم افتتاح، ومنتج ثم شراء ثم بيع في دفعة غير مرتبة، UUID مكرر ومحتوى مختلف، استعادة الحالة بعد فقد الاستجابة، تراجع بعد فشل Audit، فشل التبعيات، تعارض الرصيد، تعديل منتج متقادم، إبطال التوكن/الجهاز/الدور، حجب التكلفة، IDOR وفروع أخرى، المؤشرات المشوهة وتغير الصلاحيات، تغير SQL مباشر وTombstone، رفع الصورة وتكرارها والتراجع وتنظيف ملفاتها.
- مقارنة HTTP بين الويب وAPI أكدت تطابق كمية وقيمة ومتوسط المخزون، وإجماليات البيع والمشتريات والمستحق، وحركات المخزون، ومتجهات المدين والدائن حسب حساب القيد.
- استعلامات SELECT في تنزيل المزامنة:41 لمنتج واحد و41 لـ16 منتجًا. إدراج سجلات التسليم مجمع لكل صفحة. هذا اختبار لثبات عدد الاستعلامات، وليس Benchmark حمل إنتاج أو زمن هاتف.
- معالجة الصور تنشئ WebP160 و1200 بالحدود النسبية القائمة، وصورة واحدة للمنتج واللون يشترك فيها كل المقاسات، مع رفض إعادة UUID بملف مختلف وعدم بقاء الملفات الجديدة عند فشل المعاملة.

الأدلة داخل storage/app/reports:
- mobile-api-full-compatibility.txt
- mobile-api-final-verification.txt
- mobile-api-resume-verification.txt
- mobile-api-delivery-verified.txt
- mobile-api-delivery-final.txt
- mobile-api-contract-tests.txt
- mobile-api-concurrency-tests.txt وmobile-race-databases.jsonl
- mobile-sync-query-count.json
- mobile-api-appstore-preservation.json
- search-authorization-audit-tests.txt وsearch-authorization-findings.json

الـ19 المتجاوزة اختياريًا في الحزمة العامة:12 من مجموعة تزامن MySQL الأقدم،6 تكاملات نموذج البحث البصري الحقيقي، وفحص Snapshot واحد. شُغلت مجموعة تدقيق التزامن الـ15 الموجودة، إضافة لاختبارات الهاتف الجديدة. لا تنزيل AI أو اتصال خدمة صور خارجية.

لم يُختبر تطبيق Flutter فعلي أو هاتف/محاكي أو سيرفر aaPanel أو Replica أو حمل إنتاج. لا ادعاء اختبار SMTP أو Queue إنتاج جديدة؛ آلية فهرسة الصور القائمة لم تتغير.

## 6. الملفات

المضافة لهذه المهمة:
- app/Actions/Mobile/{MobileAccess,MobileAuthentication,ReadMobileData,ApplyMobileOperation,PushMobileOperations,PullMobileChanges,UploadMobileImage}.php
- app/Support/Mobile/{MobileChangeTracking,MobileRevision}.php
- app/Actions/Catalog/AllocateProductCodesAction.php
- app/Models/{MobileDevice,MobileOperation,MobileEntityMapping}.php
- database/factories/{MobileDeviceFactory,MobileOperationFactory,MobileEntityMappingFactory}.php
- app/Http/Controllers/Api/V1/{MobileAuthController,MobileSyncController,MobileDataController,MobileImageController}.php
- app/Http/Middleware/Mobile/{MobileApiContext,AuthenticateMobileDevice}.php
- app/Http/Requests/Mobile/{MobileLoginRequest,MobilePushRequest,MobilePullRequest,MobileImageRequest}.php
- app/Http/Resources/Mobile/MobileUserResource.php
- routes/mobile.php والترحيلان أعلاه.
- tests/Feature/Mobile/{MobileTestCase,MobileApiTest,MobileSynchronizationTest,MobileScopeTest,MobileConcurrencyTest,MobileContractTest}.php
- docs/flutter-api-contract.md وdocs/openapi-flutter.yaml وهذا التقرير.
- tests/Feature/Search/SearchAuthorizationAuditTest.php وsearch-authorization-audit.md؛ تدقيق منفصل للبحث القديم، لا إصلاحه.

المعدلة لهذه المهمة:
- app/Models/User.php: HasApiTokens فقط ضمن تغييرات هذه المرحلة.
- app/Providers/AppServiceProvider.php: Limiter دخول المحمول واستثناء Debugbar لمساراته.
- bootstrap/app.php: أخطاء وتقارير منقحة تخص api/v1 فقط.
- routes/api.php: تضمين routes/mobile.php خارج مجموعة مصادقة الويب القائمة.
- app/Actions/Inventory/OpeningStock/PostOpeningStockAction.php: استدعاء مولّد الأكواد المستخرج، دون تغيير خوارزميته.

المشروع يحتوي تعديلات محلية سابقة محفوظة؛ لا تُنسب جميع فروق Git الحالية إلى هذه المهمة.

## 7. حماية app_store

لم نشغل عليها ترحيلًا أو Seeder أو أمر تهيئة أو تصحيح أرصدة، ولم ننشئ حسابًا أو Token أو جهازًا عليها. جدول mobile_devices غير موجود فيها بعد، والجداول الأصلية بقيت53، والمستخدمون2 والأدوار4 والصلاحيات39 في فحص المقارنة.

**البصمات الكاملة ليست متطابقة خلال زمن المهمة.** رصدت المقارنة شراءً وموردًا وحركات مخزون ودفتر وحسابات، وتغييرات تشغيلية في Audit/Cache/Sessions/Jobs. سُئل المستخدم وأكد أنه كان يجرّب إدخال مشتريات من واجهة التطبيق أثناء العمل. سجل التدقيق نسب عمليات الشراء والحسابات إلى المستخدم2. لا ندعي أن جميع تغييرات التشغيل مفصلة المصدر، ولا أن القاعدة بقيت جامدة رغم التجربة اليدوية.

لا حذف أو عكس أو «استعادة» لتلك التجارب. جميع اختباراتنا ومهاجراتنا استهدفت Sandbox، مع حارس اتصال في runner، وقواعد التزامن المستقلة احتفظت ببياناتها للمراجعة. ملف البصمات يسجل الرصد الفعلي بدل ادعاء تطابق لم يحدث.

## 8. المخاطر والقرارات المتبقية

### فحص الاستئناف بعد الانقطاع

فُحصت حالة Git وفرق الملفات قبل التعديل، بما يشمل الملفات الجديدة غير المتتبعة. كانت مصادر API واختبار حجب متغيرات الفروع محفوظة، وسجل آخر اختبار مكتملًا وناجحًا؛ بقي سطر نتيجة مؤقت في التقرير واستُكمل. لم يُعد تنفيذ ترحيل أو تهيئة أو تصحيح بيانات. تحقق قراءة فقط من اتصال `local/mysql/127.0.0.1/app_store` أثبت أن ترحيلي المحمول غير مسجلين وعدد جداول `mobile_*` صفر. بقيت تعديلات المستخدم والمراحل السابقة محفوظة دون حذف أو تراجع. نجح Pint وgit diff --check؛ قُرئ OpenAPI 3.1 بنجاح مع 12 مسارًا و53 Schema و147 مرجعًا محليًا صالحًا. اقتصر تعديل الاستئناف على استكمال هذا التقرير، مع ملفات أدلة الاختبارات.

1. قبل تفعيل API على app_store: موافقة منفصلة على الترحيلين، ومراجعة نسخة احتياطية وتجربة استردادها، وامتياز TRIGGER، ونافذة توقف الكتابة أثناء التركيب وBackfill. لا تشغيل من تلقاء المهمة.
2. فترة الإعداد المفتوحة لا تُغلق بالهاتف. الافتتاح ثم البيع يحتاج مراجعة المالك من المسار القائم. العميل لا يستطيع تجاوز فترة مالية مغلقة أو قواعد التأريخ.
3. client_created_at ليس تاريخ ترحيل. تواريخ المستندات يحكمها منطق الويب. إذا حُذف invoice_date من البيع يُستخدم تاريخ السيرفر وفق القاعدة الحالية؛ على Flutter إرسال التاريخ المقصود صراحة، وترك العمليات المتأخرة المرفوضة للمراجعة. لا سياسة جديدة لتأريخ مبيعات أيام سابقة.
4. قرار تسليم بضاعة فعليًا وقت انقطاع الإنترنت وقبل قبول المخزون المركزي، ومدة فتح Cache Offline، ومسار مراجعة الرفض مع صاحب المحل. السيرفر يرفض التعارض ولا يحجز آخر قطعة من سلة محلية.
5. دليل العملاء والهوية العامة للكتالوج مشتركان حسب المشروع الحالي. عزل هوية العملاء بالفرع يحتاج قرارًا ونموذج ارتباط واضحًا.
6. قفل إصدار سجل التغييرات يضيف قفل كتابة مشتركًا. اختبر حمل السيرفر قبل الإطلاق، وراقب حجم السجل والأقفال. لا تنظيف تلقائي له أو لإيصالات العمليات في الإصدار الحالي.
7. التطبيق يجب أن يطبق الحفظ الذري للصفحة وCursor، وتخزين Token آمنًا، وCache مفصولًا/محميًا، وOutbox لا تُحذف مرفوضاته تلقائيًا. هذه أعمال Flutter وليست Backend منجزًا داخل مستودع عميل غير متاح.
8. لا استبدال صور تلقائي من API، ولا مرتجعات/استبدالات كتابة، ولا تحصيل لاحق مستقل أو دفعات مورد لاحقة أو مصروفات أو ورديات ضمن v1 المحمول. وظائف الويب القائمة بقيت كما هي.
9. تحديث 2026-11-01 بعد الموافقة: عولجت SA-01 وSA-02 في البحث القديم، وجُهزت قاعدة تكامل منفصلة ونُفذ ترحيلا المزامنة عليها فقط. انظر [تقرير الإصلاح والتكامل](search-remediation-flutter-integration.md) للملفات والاختبارات وحدود ما اختُبر. الفقرات السابقة عن استمرار الملاحظتين تصف جولة التدقيق السابقة قبل هذه الموافقة.

## 9. التسليم والنشر اللاحق

أرسل لمطورة Flutter:
- docs/flutter-api-contract.md
- docs/openapi-flutter.yaml
- Base URL معتمد وبيئة Staging وحساب محدود وتجريبي يُسلّمان منفصلين بطريقة آمنة.
- نتائج الاختبارات وحدود السياسة المذكورة، دون أسرار .env أو Tokens أو ملفات installation-delivery أو نسخة قاعدة حقيقية.

خطوات Subdomain/aaPanel والنسخ الاحتياطي والترحيل والرجوع موثقة في آخر flutter-api-contract.md بروابط الوثائق الرسمية. هذه تعليمات مراجعة فقط، ولم تُنفذ على أي سيرفر. الميزة جاهزة للمراجعة المحلية وربط عميل تجريبي بعد اعتماد Schema، وليست إعلانًا بأن الإنتاج أو Flutter أصبح جاهزًا للتشغيل.


## تحديث المتجر — 2026-11-01
أضيف دعم عرض minimum_selling_price وتأكيد below_minimum_confirmed وreserved_quantity/available_quantity وتحديث مراجعات المخزون عند حجز طلب موقع. التفاصيل الحالية في flutter-api-contract.md وstore-commerce-implementation.md. أرقام الاختبارات السابقة تخص مرحلتها، وليست دليل تشغيل Flutter فعلي أو نشر على app_store.
