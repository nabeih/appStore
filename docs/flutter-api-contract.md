# Flutter API Contract — appStore v1

تاريخ العقد: 2026-10-31. Laravel13.35، PHP8.5، Sanctum4.3.3. هذا عقد Backend منفذ محليًا، وليس تطبيق Flutter أو إثبات تشغيل على هاتف. ترحيلا الميزة طُبقا على MySQL الاختباري فقط. لا تبدأ التجربة على app_store قبل الموافقة على تطبيقهما.

## العنوان والمصادقة

Base URL المحلي النموذجي: http://127.0.0.1:8000/api/v1
Base URL عند النشر بعد الموافقة: https://api.your-store.example/api/v1

العنوان المحلي من محاكي أو هاتف يعتمد على شبكة الجهاز؛ لم يُجهز اتصال LAN أو HTTPS للهاتف في هذه المهمة. لا تستخدم HTTP خارج تجربة محلية معزولة.

كل طلب خاص يحمل Authorization: Bearer <issued-token> وAccept: application/json. لا تستخدم Cookies أو CSRF أو واجهات /api القديمة لتطبيق Flutter. لا تضع الرمز في URL. طلب JSON حده2MiB، وملف الصورة4MiB، والطلب multipart كله6MiB كحد تطبيق؛ اضبط حدود PHP/Nginx المتوافقة عند النشر.

POST /auth/login:
~~~json
{
  "username": "owner_demo",
  "password": "<chosen-by-user>",
  "device_id": "ad63c818-a2b5-433d-aea6-df46479571b1",
  "device_name": "هاتف المتجر"
}
~~~

device_id هو UUID تسجيل منطقي يولده التطبيق ويحفظه لكل حساب؛ عند تبديل الحساب استخدم تسجيل جهاز جديدًا وافصل Cache وOutbox، ولا تعاود تبني UUID مستخدم آخر.  لا يُعتبر إثبات هوية الجهاز المادي. الارتباط آمن بالتوكن وحساب المستخدم، دون Hardware Attestation.
الاستجابة200:
~~~json
{
  "token": "<issued-once>",
  "expires_at": "2026-11-30T12:00:00.000000Z",
  "device_id": "ad63c818-a2b5-433d-aea6-df46479571b1",
  "user": {
    "id": 1,
    "name": "مالك المتجر",
    "username": "owner_demo",
    "role": "sales_owner",
    "permissions": ["catalog.search", "inventory.view"],
    "branches": [{"id": 1, "name": "الرئيسي", "is_active": true}],
    "schema_version": 1
  }
}
~~~
قائمة الصلاحيات أعلاه مثال مختصر؛ الفعلية من الدور الموجود. لا توجد حسابات أو كلمات مرور افتراضية. يُقبل اسم المستخدم العربي بالقواعد الحالية. مدة التوكن30 يومًا. تُحفظ بصمته لدى Sanctum، ويعاد التحقق من الدور والتفعيل والفرع عند الطلب. إعادة الدخول بالجهاز نفسه تدوّر التوكن وتبقي mappings والعمليات السابقة. جهاز مسجل لحساب آخر أو مبطل نهائيًا لا يعاد تبنيه بهذا UUID.

GET /auth/me يعيد بيانات المستخدم نفسها تحت data. GET /devices يعيد الأجهزة الخاصة بالمستخدم فقط: id/name/revoked_at/created_at. POST /auth/logout يبطل التوكن الحالي فقط. DELETE /devices/{uuid} يبطل جهاز المستخدم نهائيًا؛ يرفض جهاز مستخدم آخر. تغيير كلمة المرور أو remember_token، وتعطيل الحساب أو الدور، وانتهاء التوكن تمنع الوصول في الطلب التالي. لا تنشئ API أدوارًا أو صلاحيات أو فرعًا أولًا؛ إكمال الفرع الأول من الويب يظل إلزاميًا.

لا تستطيع API محو بيانات سبق تنزيلها إلى هاتف غير متصل. على مطورة Flutter حماية التخزين بمفتاح من Keychain/Keystore، وتحديد مدة استخدام Offline، وقفل التطبيق عند انتهاء مهلة الدخول المحلية، ومسح Cache القراءة عند الخروج/تبديل المستخدم مع عدم حذف Outbox غير المعالج بلا قرار مستخدم. لا تخزن كلمة المرور، ولا توكنًا في SQLite أو سجلات الأخطاء.

## جميع Endpoints المنفذة

| Method | Path تحت /api/v1 | الاستخدام |
|---|---|---|
| POST | /auth/login | دخول وتسجيل جهاز/تدوير توكن |
| GET | /auth/me | هوية وصلاحيات وفروع مسموحة |
| POST | /auth/logout | إبطال التوكن الحالي |
| GET | /devices | أجهزة المستخدم |
| DELETE | /devices/{device} | إبطال جهاز مملوك للمستخدم |
| GET | /data/{entity} | بيانات مرجعية/تشغيلية بصفحات keyset |
| GET | /data/{entity}/{id} | سجل مسموح بعينه |
| POST | /sync/bootstrap | بدء جلسة تنزيل أولي |
| POST | /sync/pull | متابعة التنزيل الأولي/التغييرات اللاحقة |
| POST | /sync/push | رفع دفعة عمليات مستقلة ذريًا لكل عملية |
| GET | /sync/operations/{operation_uuid} | استعادة نتيجة عملية لهذا الجهاز |
| POST | /product-images | رفع صورة لون بعد مزامنة المنتج |

لا توجد Endpoints كتابة مستقلة تكرر منطق Sync؛ إنشاء/تعديل المنتج والمستندات عبر /sync/push. لا توجد كتابة مرتجعات أو استبدالات أو سداد مورد لاحق أو مصروفات أو ورديات في هذا الإصدار المحمول.

## القراءة والصلاحيات

كل طلب /data يحتاج branch_id مسموحًا، حتى مع الكيانات المشتركة، ويعيد فقط حقول DTO المعلنة. تغيير الفرع في Query لا يوسع الوصول.

| entity | القدرة المطلوبة | الحقول الرئيسية والسياسة |
|---|---|---|
| branches | مستخدم فعال وفرع مسموح | الفرع المختار فقط؛ id/name/address/phone/type/is_active/opening_stock_closed_at |
| categories/colors/sizes | catalog.search | مرجع مشترك؛ اسم/رمز وحالة، وhex/display fallback للألوان |
| products/variants/images/swatches | catalog.search + inventory.view | منتجات لها مخزون محلي؛ المتغير نفسه والصورة حسب لون محلي، لا متغير حصري لفرع آخر |
| stocks | inventory.view | branch_id/product_variant_id/quantity/quarantined_quantity/damaged_quantity/revision |
| customers | sales.customers | دليل مشترك حسب سياسة الويب الحالية؛ id/name/phone فقط |
| customer_balances | sales.customers | id=customer_id وdebt_minor وcredit_minor من دفتر الفرع المختار فقط |
| sales | sales.view | الفاتورة وحالتها وإجمالياتها ومستحقها وبنودها التاريخية؛ الفرع المختار |
| receipts | sales.customers + sales.collect | سندات الفرع فقط؛ رقم/نوع/حالة/تاريخ/قيمة |
| accounts | sales.sell أو sales.collect أو sales.manage_accounts | id/branch/name/type/is_active لحسابات القبض الفعالة في الفرع؛ لا أرصدة دفتر أو مراجع بنكية |
| suppliers/purchases | purchases.view + sales.view_cost | الموردون مشتركون؛ المشتريات وبنودها من الفرع المختار |
| openings | inventory.correct_setup + sales.view_cost | مستندات أول المدة وبنودها للفرع المختار |

inventory.manage_catalog يسمح بقراءة المنتجات/المتغيرات غير المخزنة بعد، كي يمكن إعداد منتج ثم إدخاله. الكتالوج والهوية العامة مشتركان؛ تعديل اسم منتج مشترك أو تفعيله يؤثر في هويته العامة كما في الويب. تعديل متغير موجود أو رفع لون في API الجديدة يتحقق من نطاق المتغير أيضًا. دليل العملاء المشترك قرار قائم موثق في search-authorization-audit.md، وليس ضمان عزل هوية العملاء بين الفروع.

sales.view_cost وحدها تسمح بإضافة average_cost/value_minor/quarantined_value_minor إلى stocks وتكلفة الفاتورة والبنود إلى sales. تحديث 2026-11-01: minimum_selling_price معلومة تسعير مسموحة للكاشير في variants وليست تكلفة شراء؛ تعديلها يقتصر على المالك. الحقول غير المسموحة غائبة من JSON أصلًا؛ لا COGS أو ربح أو هامش للكاشير الافتراضي. لا تُرسل paths داخلية للصور؛ images تعيد thumbnail_url وoptimized_url، وswatches تعيد url.

GET /data/variants?branch_id=1&q=P-00000001-BLK-L&match=exact&limit=50&after_id=0
~~~json
{
  "schema_version": 1,
  "data": [{
    "id": 12, "product_id": 5, "color_id": 2, "size_id": 3,
    "sku": "P-00000001-BLK-L", "selling_price": "35.00",
    "is_active": true, "revision": "<opaque-64-char-hmac>"
  }],
  "next_after_id": null,
  "server_timestamp": "2026-10-31T12:00:00.000000Z"
}
~~~

limit=1–100، والافتراضي50. للصفحة التالية استخدم next_after_id مع نفس الفرع ونوع البيانات ومرشح البحث. لا يوجد total يكشف سجلات خارج النطاق. قراءة /data ليست لقطة ثابتة متعددة الطلبات؛ استخدم Bootstrap/Pull للمزامنة القابلة للاستئناف، و/data للاطلاع والبحث وتحديث تعارض بعينه.
q متاح للمنتجات والمتغيرات والعملاء والموردين والمبيعات؛ match: contains/exact/prefix للبحث المدعوم. المنتجات تطابق الاسم/الكود وSKU لمتغير مسموح، والمتغيرات تدعم منطق البحث الحالي. اسم/هاتف العميل والمورد، ورقم/تفاصيل فاتورة المبيعات مدعومة بالقواعد القائمة. لا تعيد API الحالية الموظفين أو الأدوار كأنواع بيانات قابلة للبحث.

barcode_supported في المتغير يوضح صلاحية SKU لباركود Code128؛ لا تغيّر SKU قديمًا غير مدعوم، واسمح بالإدخال اليدوي.

مبالغ *_minor أعداد صحيحة بأصغر وحدة نقدية؛ لا تستخدم double في المحاسبة. أسعار الإدخال وselling_price وaverage_cost سلاسل عشرية. لا تقم بتقدير ربح أو اعتماد تكلفة محليًا. revision قيمة HMAC معتمة، وليست تكلفة أو timestamp أو رقمًا يمكن اشتقاقه محليًا.

## Bootstrap وIncremental Pull

ابدأ POST /sync/bootstrap:
~~~json
{"schema_version":1,"sync_uuid":"7f7fe8b0-7de0-40c1-a392-37e3f393c861","branch_id":1,"limit":100}
~~~
sync_uuid جديد لكل إعادة بناء Cache؛ عند فقد الاستجابة الأولى أعد نفس الطلب بنفس UUID. الجلسة مربوطة بالجهاز والفرع وبصمة الصلاحيات، ومدتها7 أيام. الاستجابة:
~~~json
{
  "schema_version":1,
  "server_timestamp":"2026-10-31T12:00:00.000000Z",
  "branch_id":1,
  "changes":[
    {"entity":"products","id":5,"action":"upsert","data":{"id":5,"name":"قميص","code":"P-00000001","category_id":1,"description":null,"is_active":true,"revision":"<opaque>"}},
    {"entity":"variants","id":12,"action":"upsert","data":{"id":12,"product_id":5,"sku":"P-00000001-BLK-L","color_id":2,"size_id":3,"selling_price":"35.00","is_active":true,"revision":"<opaque>"}}
  ],
  "has_more":true,
  "cursor":"<encrypted-opaque-cursor>"
}
~~~
تابع POST /sync/pull بـcursor وlimit حتى has_more=false، ثم استعمل cursor الأخير عند الاتصال التالي. لا تفك cursor ولا تعدله ولا تحوله إلى تاريخ. لا تحتاج sync_uuid أو branch_id مع cursor؛ إن أرسلت branch_id مختلفًا يرفض.

السجل يلتقط تغييرات الويب والـSQL المباشر في الجداول المتعقبة عبر MySQL Triggers داخل المعاملة نفسها. تخصيص إصدار التغيير يمسك قفلًا معاملاتيًا حتى commit، لذلك لا يقفز Cursor فوق معاملة لم تكتمل بعد. الصفحات keyset ومحدودة بحد علوي ثابت لكل دورة، وتتابع الدورة الجديدة من آخر حد علوي. تصفية الصلاحيات والفرع تسبق العد والحد، فلا تظهر Tombstones لمعرفات أجنبية لم يسبق تسليمها للجلسة.

هذه مزامنة **الحالة الحالية** وليست أرشيف snapshots زمنية: قد تتضمن قراءة سجل حالته الأحدث من دورة الصفحة، ثم يصل تحديثه مجددًا في دورة لاحقة. لا فقد بسبب هذا التكرار؛ طبّق upsert idempotent حسب entity+id. لا تستخدم server_timestamp لإثبات رصيد تاريخي أو كبديل عن تقرير السيرفر.
action=delete مع data=null عند حذف منطقي أو فقد ظهور سجل كان مسموحًا سابقًا. is_active=false تحديث حالة، وليس حذف سجل تاريخي. حذف منتج من Cache يستلزم إزالة/إبطال متغيراته وصور ألوانه ومخزونه المرجعي حسب علاقات SQLite، مع الحفاظ على Outbox ومستندات العرض التاريخية.

يمكن أن تتكرر هوية السجل داخل دورات مختلفة. طبّق الصفحة كلها داخل SQLite Transaction: المراجع/المنتجات أولًا ثم المتغيرات والصور والمخزون ثم المستندات، والحذف بالعكس. استخدم Foreign Keys مؤجلة أو staging إذا احتاجت العلاقات عدة صفحات. لا تُظهر Cache أول تنزيل كبيانات مكتملة حتى انتهاء الدورة. مراجع المنتج/العميل في المستند التاريخي تُحفظ كمعرّفات وSnapshot مستقل، ولا تجعل حذف Cache مرجعي يحذف فاتورة أو يفشل بسبب FK إلزامي إلى سجل مؤرشف غير منزل.
**احفظ cursor والصفحة داخل المعاملة المحلية نفسها**؛ إذا فشل الحفظ، أعد طلب cursor القديم. المؤشرات لا تتقدم إلى بيانات خارج النطاق.
إذا كانت الصلاحيات/إسناد الفرع قد تغيرت أو انتهت الجلسة، يعاد409 ويجب إعادة Bootstrap مع مسح بيانات القراءة القديمة؛ لا تمسح Outbox. APP_KEY المتغير أو cursor تالف يعيد422؛ ابدأ جلسة جديدة بعد التحقق. عند401 يلزم إعادة الدخول أولًا؛ لا تعاود العمليات باسم مستخدم آخر.

لا توجد مهمة حذف تلقائي لسجل التغييرات أو إيصالات العمليات في هذه المرحلة. راقب الحجم؛ أي سياسة احتفاظ/تنظيف لاحقة يجب أن تنتهي بإبطال المؤشرات المتأثرة وإلزام Bootstrap جديد، وتحافظ على Idempotency المالي. لا تحذف صفوف mobile_* يدويًا.

## Batched Push ومعرّفات Offline

POST /sync/push:
~~~json
{
  "schema_version":1,
  "operations":[{
    "operation_uuid":"2aec8f74-fd8c-47ef-9b81-885c42434810",
    "operation_type":"product.create",
    "client_created_at":"2026-10-31T11:00:00.000000Z",
    "branch_id":1,
    "dependencies":[],
    "payload":{
      "client_uuid":"9a41ae50-c54b-4080-a81c-d08667eb6fc4",
      "name":"قميص الهاتف","category_id":1,
      "description":"قطن","is_active":true,
      "variants":[{
        "client_uuid":"af78feef-e25b-432d-a40b-7f7366524a7b",
        "color_id":2,"size_id":3,"selling_price":"35.00","minimum_selling_price":"22.00"
      }]
    }
  }]
}
~~~
الحد20 عملية في Batch، و100 متغير للمنتج، و300 سطر للمستند؛ يفضل دفعات أصغر للمستندات الكبيرة. لا ترسل user_id/cashier_id/role_id/status/cost_minor أو SKU نهائيًا من الهاتف. الجداول المرجعية تستخدم IDs تنزيلها من السيرفر؛ لا توجد كتابة تصنيفات وألوان ومقاسات Offline في v1.

device_id يؤخذ من التوكن المسجل ولا يقبل كهوية بديلة من payload. Client UUID Mapping معزول بالجهاز ونوع الكيان. operation_uuid فريد عالميًا؛ إعادة استخدام UUID يخص جهازًا آخر لا تكشف نتيجته. لا تعتمد على IDs SQLite الرقمية.
يمكن حذف code من إنشاء المنتج: يُخصص السيرفر P-... بواسطة مولّد أكواد الافتتاح الموجود بعد استخراجه كـAction مشترك دون تغيير طريقته. إذا أرسلت code صريحًا يخضع لتفرد الويب؛ التعارض لا يُعالج بتغيير الكود خفية. SKU النهائي يولده CreateProductAction من كود المنتج واللون والمقاس. احتفظ برمز محلي مؤقت حتى وصول mapping؛ لا تطبع باركودًا نهائيًا له قبل ذلك.

الاستجابة200 للدفعة:
~~~json
{
  "schema_version":1,
  "results":[{
    "operation_uuid":"2aec8f74-fd8c-47ef-9b81-885c42434810",
    "status":"accepted",
    "result":{
      "product_id":5,"code":"P-00000001","revision":"<opaque>",
      "variants":[{"id":12,"sku":"P-00000001-BLK-L"}],
      "mappings":[
        {"entity":"products","client_uuid":"9a41ae50-c54b-4080-a81c-d08667eb6fc4","server_id":5},
        {"entity":"variants","client_uuid":"af78feef-e25b-432d-a40b-7f7366524a7b","server_id":12}
      ]
    }
  }]
}
~~~
لا تفترض أن ترتيب results يطابق ترتيب الإدخال؛ يُرتب التنفيذ وفق dependencies. طابق بالـoperation_uuid. كل عملية تجارية وحفظ نتيجتها في Transaction واحدة، أما الدفعة فليست معاملة واحدة: قد تنجح عمليات مستقلة وتفشل أخرى، ولا تُلغى النجاحات بسبب فشل عملية لاحقة.

### أنواع العمليات والقدرات

- product.create وproduct.update: inventory.manage_catalog + catalog.search + inventory.view. إرسال minimum_selling_price يتطلب المالك الفعّال مع صلاحيات إدارة الكتالوج القائمة؛ يشمل ذلك محاولة مسح القيمة بإرسال null.
- opening_stock.create: inventory.correct_setup + sales.view_cost.
- purchase.create: purchases.view + purchases.manage + sales.view_cost + finance.purchase_post. الدفعة الأولية تخضع أيضًا لضوابط السداد القائمة.
- sale.create: sales.view + sales.sell؛ الخصومات والسعر الاستثنائي والبيع دون التكلفة تخضع لقدراتها القائمة.

كل Branch يفحص على السيرفر. Batch يحتوي فرعًا غير مسموح يُرفض403 قبل تنفيذ عملياته. أخطاء الغلاف أو schema_version ترفض422 قبل استلام العمليات. تبقى هذه الطلبات في Outbox العميل؛ لا يعني عدم وجود Receipt أنها نُفذت.

### Product Update
payload يحتوي product_id أو product_uuid من Mapping الجهاز، وexpected_revision من أحدث Product DTO، والحقول المراد تعديلها فقط. يمكن تعديل الاسم والوصف والتصنيف والتفعيل والكود والمتغيرات وفق قيود UpdateProductAction؛ المنتج المرتبط بمستندات لا يغيّر كوده/هوية متغيره التاريخية. لتعديل متغير أرسل id؛ لكل متغير جديد أرسل client_uuid. لا يُقبل متغير حصري لفرع آخر.
revision تشمل هوية المنتج ومتغيراته، ويُرفض التعديل المتقادم409 ضمن conflict_requires_review. لا يوجد last-write-wins صامت. لا ترسل صورًا في هذه العملية.

### Opening Stock
~~~json
{
  "operation_uuid":"ab3096f2-afce-476c-a1ae-44f4df9c3c10",
  "operation_type":"opening_stock.create",
  "client_created_at":"2026-10-31T11:01:00.000000Z",
  "branch_id":1,
  "dependencies":["2aec8f74-fd8c-47ef-9b81-885c42434810"],
  "payload":{
    "opening_date":"2026-10-31",
    "notes":"إدخال أول المدة",
    "rows":[{"variant_uuid":"af78feef-e25b-432d-a40b-7f7366524a7b","qty":3,"cost":"20.00","price":"35.00"}]
  }
}
~~~
بديل variant_uuid هو product_variant_id، لا الاثنان. أسماء المنتج والكود واللون والمقاس والتصنيف تؤخذ من المتغير المسموح، لا من هوية يرسلها الهاتف. يستدعى PostOpeningStockAction نفسه؛ ليست فاتورة شراء. تعتمد العملية المستند كما يفعل حفظ الافتتاح في الويب، ولا تغلق الفترة الافتتاحية.

### Purchase
payload:
~~~json
{
  "supplier_id":4,"invoice_date":"2026-10-31","supplier_invoice_number":"SUP-47",
  "initial_payment":"20.00","payment_method":"cash",
  "payment_details":{"account_id":2,"confirmed":true,"reference":"سداد جزئي"},
  "rows":[{"variant_uuid":"af78feef-e25b-432d-a40b-7f7366524a7b","quantity":4,"unit_cost":"20.00"}]
}
~~~
payment_method: cash/bank/wallet/other حسب النموذج الحالي. SavePurchaseInvoiceAction ثم ApprovePurchaseInvoiceAction داخل Transaction العملية نفسها؛ القيود والسداد عبر Actions الموجودة. هذه العملية **حفظ واعتماد** وليست مسودة شراء. تواريخ المستندات والتحصيل تخضع لضوابط الويب، ولا يتجاوز client_created_at هذه القواعد.

### Sale
payload:
~~~json
{
  "customer_id":9,"payment_mode":"partial",
  "payments":[{"account_id":2,"amount":"10.00","confirmed":true}],
  "rows":[{
    "variant_uuid":"af78feef-e25b-432d-a40b-7f7366524a7b",
    "quantity":1,
    "stock_revision_from":"<accepted-purchase-operation-uuid>"
  }]
}
~~~
payment_mode: paid/partial/credit. الآجل/الجزئي يحتاج العميل وفق Rules الحالية. يدعم payload أيضًا invoice_date/notes/reason/discount؛ ويدعم السطر unit_price/price_reason/discount وفق صلاحيات الأسعار القائمة. لا يقبل تكاليف من الهاتف.

يجب لكل سطر إرسال expected_revision الحالية للمخزون، أو stock_revision_from لعملية ناجحة على الجهاز نفسه ومذكورة في dependencies. الثاني يأخذ stock_revisions[server_variant_id] من نتيجة العملية الأم. استخدمه لبيع بضاعة اشتريتها في الدفعة نفسها، أو ربط بيع Offline ثانٍ ببيع أول غيّر الرصيد. لا تختلق revision بعد خصم محلي؛ ليست قابلة للحساب خارج السيرفر.
أي اختلاف مراجعة مخزون يرفض البيع conflict_requires_review، ولا يُنقص المخزون جزئيًا. SaveSaleAction ثم PostSaleAction ينفذان القواعد والمحاسبة القائمة.

نجاح المستند:
~~~json
{"id":17,"number":"SL-000017","status":"approved","stock_revisions":{"12":"<new-opaque-revision>"}}
~~~
صيغة number من النموذج الفعلي؛ المثال لا يفرض عدد أصفار جديدًا. لا ترسل client_uuid للمستند؛ السيرفر يربطه بـoperation_uuid. UUID مرتبط بمستند ويب سابق لا يُتبنى خفية كعملية هاتف جديدة.

## حالات النتائج والاستعادة

| status | التصرف |
|---|---|
| accepted | ثبّت mapping/Receipt محليًا؛ العملية طُبقت مرة واحدة |
| already_applied | نفس العملية والمحتوى؛ طبّق النتيجة السابقة دون خصم محلي إضافي |
| retryable | احتفظ بالعملية كما هي وأعد UUID والمحتوى نفسيهما؛ retry_after_seconds عادة5 |
| conflict_requires_review | لا تعدّل العملية الأصلية؛ اطلب مراجعة وأرسل تصحيحًا بعملية UUID جديدة |
| validation_failed | اعرض errors أو message؛ احتفظ بالأصل وأنشئ عملية مصححة جديدة بعد قرار المستخدم |
| dependency_blocked | لا تنفذ التابع؛ أعده كما هو بعد نجاح جميع آبائه؛ إن تغيّرت dependencies استخدم UUID جديدًا |

الـUUID والمحتوى وتاريخ الإنشاء والتبعيات تُحفظ ثابتة في Outbox. ترتيب مفاتيح JSON لا يغير البصمة، لكن تغيير قيمة أو نوع قيمة أو ترتيب قائمة قد يغيّرها. UUID نفسه بمحتوى مختلف لا ينفذ ثانية. نتائج المرفوضات ذات الغلاف الصحيح تبقى في mobile_operations. لا توجد مهمة حذف تلقائي لها.

إذا فقدت الشبكة الاستجابة بعد commit: GET /sync/operations/{uuid} على الجهاز نفسه. accepted يعني نجاحًا سابقًا، و404 يعني أنه لا يوجد Receipt لهذا الجهاز؛ أعد إرسال UUID نفسه، ولا تنشئ UUID جديدًا بسبب timeout وحده. عملية أعيدت للمراجعة لا تُعد نجاحًا حتى لو HTTP200 للدفعة.
429 يحمل Retry-After. 401 يعيدك للدخول، و403 لا يعالج بالتحايل على الفرع، و503 يحافظ على Outbox. لا تُظهر SQL أو stacktrace أو Tokens في UI/Crash Reports.

مثال تحقق:
~~~json
{"message":"راجع بيانات الطلب.","errors":{"schema_version":["The selected schema version is invalid."]}}
~~~
قد تكون بعض رسائل Laravel الافتراضية إنجليزية؛ code/status هي العقدة المستقرة، وليس نص الرسالة. داخل نتيجة العملية:
~~~json
{"operation_uuid":"<uuid>","status":"validation_failed","result":{"code":"validation_failed","message":"راجع البيانات وقواعد العملية.","errors":{"rows":["..."]}}}
~~~

## الصور

بعد قبول product.create والحصول على product_id:
POST /product-images باستخدام multipart/form-data، لا JSON base64.
الحقول: operation_uuid UUID مستقل، schema_version=1، branch_id، product_id، color_id، image.
لا ترسل disk أو image_path أو اسم مجلد أو مسار مصدر. لا ترسل token داخل عنوان الصورة.
تُعاد status=accepted أو already_applied وresult يحوي image_id/product_id/color_id/thumbnail_url/optimized_url.
الرفع يعيد استخدام معالجة WebP القائمة: نسختان فقط، Thumbnail أقصاها160×160 وOptimized أقصاها1200×1200. كل المقاسات للمنتج واللون تستخدم الصورة نفسها.
إعادة رفع الملف نفسه بنفس UUID تعيد النتيجة دون ملفات جديدة. تغيير محتوى ملف مقبول بنفس UUID يعيد409. يوجد قيد صورة واحدة لكل لون؛ v1 لا يستبدل صورة محفوظة خفية. استبدال الصور واجهته القائمة في الويب، وليس Endpoint محمولًا منفذًا هنا. ملف مرفوض قبل قبول الرفع لا يعتبر أثرًا تجاريًا ناجحًا؛ احتفظ بمحاولة الرفع محليًا.
فشل المعاملة بعد كتابة نسخ الصورة ينظف الملفات الجديدة. لا تُرفع صور كاميرا OCR/Barcode إلى هذا المسار تلقائيًا؛ صور المنتجات يختارها المستخدم صراحة.

## SQLite وOutbox المقترحان

- userscope: user_id/device_id/branch_id/schema_version، مع فصل Cache لكل نطاق.
- cache_entities: entity/server_id/payload_json/revision، فريد entity+server_id داخل النطاق.
- client_entities: client_uuid/entity/local_key/server_id/sku النهائي.
- outbox: operation_uuid/operation_type/client_created_at/branch_id/payload_json/dependencies_json/status/result_json/attempts/next_attempt_at/last_error_code.
- sync_state: sync_uuid/cursor/initial_completed، ويحفظ مع الصفحة في Transaction واحدة.
- image_outbox: UUID/مسار محلي خاص/بصمة الملف/client_product_uuid/color_id/state. الملف محلي وليس مسارًا يرسل للسيرفر.

تسلسل SQLite: سجّل المنتج ومتغيراته وOutbox معًا؛ استعمل client UUID في بنود الشراء/الافتتاح/البيع إلى حين Mapping. لا تستبدل النص داخل JSON الأصلي بعد إرساله؛ أرسله بنفس المحتوى حتى تأكيده، أو أنشئ عملية تصحيح جديدة. احفظ نتائج جميع operations في دفعة حتى لو فشل بعضها.
Retry: انتظر max(Retry-After, random jitter + min(300, 2^attempts)) ثانية، مع حد لمحاولات الخلفية وتنبيه بعد استمرار الفشل. لا تكرر validation_failed/conflict تلقائيًا. أوقف التابع عند فشل الأصل. لا تحذف المرفوضات تلقائيًا.

البيانات المحلية أسعار وكميات تقريبية حتى قبول السيرفر. البيع Offline لا يحجز آخر قطعة فعليًا؛ قد يرفض السيرفر عند مزامنة جهاز آخر. لا تطبع فاتورة تعتبر معتمدة مركزيًا قبل قبولها. سياسة تسليم بضاعة فعلية أثناء انقطاع الإنترنت تحتاج قرار صاحب المحل؛ لم نضف حجز مخزون أو ترحيلًا محاسبيًا محليًا.

## سيناريوهات كاملة

1. مخزون افتتاحي: product.create ← opening_stock.create عبر variant_uuid وتبعية UUID. ينشئ السيرفر المنتج وأثر الافتتاح مرة واحدة. sale.create يبقى مرفوضًا أثناء فترة الإعداد المفتوحة. المالك يراجع الأرصدة ويغلق الإعداد من المسار القائم، ثم تبدأ المبيعات؛ لا يوجد إغلاق تلقائي.
2. متجر بدأ تشغيله: product.create ← purchase.create ← sale.create. يستخدم البيع stock_revision_from للشراء؛ يُرتب السيرفر Batch غير المرتب، ويقبل المراحل المتاحة بصلاحياتها. العميل يحتفظ بالمرفوضات إن لم يُقبل الأصل.
3. مشتريات وبيع جزئي:4 قطع ×20، دفعة شراء20، بيع قطعة35 وقبض10. نتائج الاختبار: مخزون3 بقيمة60، دين العميل25، مستحق الشراء60، وCOGS20، دون تكرار القيد/السداد عند إعادة الطلب.
4. بيعان لآخر قطعة على جهازين: يقبل السيرفر واحدًا ويرد الآخر conflict_requires_review؛ لا كمية سالبة ولا قيود جزئية.
5. حذف منتج من الويب: Pull يعيد Tombstone فقط لجلسة سبق أن استلمته؛ يمسح العميل Cache المرتبط لا Outbox. لا تُرسل هويات منتجات أجنبية كـTombstones.

## تشغيل آمن لاحقًا على aaPanel — إرشادات فقط

لم يُنفذ نشر أو اتصال بـVPS. النطاق الفرعي يجب أن يخدم public من نسخة Laravel المعتمدة، مع PHP8.5 CLI/FPM وHTTPS. أضف ربط النطاق وRunning Directory وSSL من إعدادات PHP Project كما في [وثائق aaPanel الرسمية](https://www.aapanel.com/docs/Function/php.html).
وجّه Nginx إلى public/index.php عبر try_files واضبط APP_DEBUG=false وصلاحيات storage وbootstrap/cache وفق [وثائق نشر Laravel](https://laravel.com/framework/docs/deployment). لا تعرض جذر المشروع أو .env.

قبل النشر المعتمد:
1. نسخة احتياطية متسقة للقاعدة والملفات، تشمل Triggers، وتجربة استعادتها على قاعدة معزولة؛ لا تُنقل قواعد الاختبار أو بيانات الأجهزة والتوكنات منها.
2. مراجعة pending migrations ونسخة MySQL وصلاحية TRIGGER للمستخدم المخصص؛ لا تسمح باتصالات عامة إلى3306. آلية v1 الحالية تتطلب اتصال MySQL أساسيًا واحدًا، ولا تفترض Replica.
3. إيقاف كتابات الويب والهاتف أثناء الترحيل وBackfill. يضيف الترحيل سجل تغييرات لكل بيانات الأعمال الموجودة، دون إعادة إدخال بضاعة أو إنشاء قيود مالية.
4. بعد موافقة صاحب البيانات، تطبيق create_mobile_sync_infrastructure أولًا ثم install_mobile_sync_change_tracking. لا migrate:fresh أو db:wipe.
5. إذا فشل إنشاء Trigger بسبب الصلاحيات، لا تفعّل المزامنة. يدعم install إعادة المحاولة إذا كانت التعريفات الموجودة مطابقة؛ التعريف المتعارض يوقفه ولا يُحذف تلقائيًا. MySQL DDL ليس Transaction كاملة؛ اعتمد خطة استرداد.
6. تحقق من الستين Trigger وتغطيتها، ومن رفض API إن كانت ناقصة، ومن اختبار ويب وSync على نسخة معزولة مماثلة قبل فتح الكتابة.
7. Cache إعدادات Laravel بالأوامر المعتمدة بعد ضبط البيئة، وتهيئة رابط storage العام إذا لم يكن موجودًا. لا تضع التوكنات في logs أو CI أو أمثلة OpenAPI.
8. احتفظ بـAPP_KEY ثابتًا وآمنًا؛ تغييره يبطل المؤشرات الحالية. شارك نفس القاعدة وStorage والـKey مع نسخة الويب عندما يكون subdomain للمشروع نفسه. لا تُجهز تثبيتًا ثانيًا بمعالج /setup فوق قاعدة قائمة.
9. حد طلبات Nginx/PHP ووقت المعالجة، ومراقبة سعة سجل التغييرات والأقفال و429/503. قفل الإصدار العام يزيد كلفة الكتابة ويحتاج قياس حمل السيرفر الحقيقي؛ اختبارات التزامن ليست Benchmark إنتاج.
10. الرجوع عن نشر فاشل ليس migrate:rollback آليًا؛ down متعمد الرفض لحماية إيصالات العمليات والمؤشرات. أوقف الهواتف ثم راجع استرداد النسخة المعتمدة أو إصلاح الترحيل للأمام؛ لا تحذف Metadata قد تمنع تكرار فاتورة.

لا خدمات مدفوعة ولا مكتبات جديدة؛ Sanctum موجود مسبقًا. لا نموذج AI أو CDN أو اعتماد جديد للمزامنة. يحتاج تنفيذ Cache مشفر وOutbox وواجهات تعارضات Flutter إلى مطورة Flutter؛ هذه المهمة Backend وعقد فقط.

## تحديث 2026-11-01 — الحد الأدنى وحجوزات الموقع
- minimum_selling_price يعاد للكاشير في variants، ويكون null عند عدم تحديده؛ هذا ليس average_cost. تغييره أو مسحه عبر API يقتصر على المالك.
- sale.create.payload يقبل below_minimum_confirmed، افتراضيًا false. Laravel تحسب السعر الفعلي بعد خصم السطر وتوزيع خصم الفاتورة بالأعداد الصحيحة؛ التجاوز دون تأكيد يرفض. تعرض Flutter تحذيرًا وخيار الرجوع أو التأكيد الصريح.
- التأكيد لا يتجاوز sales.below_cost أو حدود الخصومات أو صلاحية تعديل الأسعار. الاستثناء والإشعار يثبتان فقط عند قبول البيع على السيرفر، بما فيه Offline.
- إعادة عملية مقبولة تستعمل UUID والمحتوى نفسيهما. فقدان الاستجابة يستدعي /sync/operations/{uuid} أو إعادة المحتوى نفسه. بعد عملية تحقق نهائية مرفوضة، لا تغيري محتواها تحت UUID نفسه؛ بعد مراجعة المستخدم أنشئي عملية مصححة جديدة مرتبطة بنتيجة سابقتها في Outbox، مع منع إرسال نسختين مقبولتين للبيعة نفسها.
- stocks.quantity إجمالي المخزون المملوك في الفرع؛ reserved_quantity يشمل الحجز داخل المحل والبضاعة الخارجة للتوصيل التي لم تُبع أو تعد فعليًا؛ available_quantity = quantity - reserved_quantity. اعرضي المتاح للكاشير.
- تأكيد طلب الموقع وتحرير الحجز يغيران stock revision ويظهران في Pull عبر Triggers القائمة. بيع بمراجعة قديمة يرفض بدل تجاوز الحجز. التقييم لا يتحرك بالحجز؛ COGS تثبت عند التسليم والبيع الفعلي.
- عند تحديث Cache الهاتف من نسخة أقدم طبقي ترحيلها المحلي ثم Bootstrap جديدًا عند الحاجة؛ لا تمسحي Outbox المعلقة. null لا يعني صفرًا معتمدًا.
- لم تضف هذه المرحلة مزامنة طلبات الموقع أو إشعارات الهاتف أو Push إلى /api/v1؛ إدارة الطلبات والإشعارات الحالية في الويب فقط.

اختبار مشترك مطلوب من مطورة Flutter:
1. Online: عرض الحد الأدنى والمتاح، رفض التجاوز دون تأكيد، ثم قبول عملية مصححة بعد التأكيد، مع غياب التكلفة.
2. Offline: حفظ البيع محليًا دون إشعار مركزي، ثم عودة الشبكة وإعادة UUID المقبول دون تكرار بيع أو إشعار.
3. جهازان: حجز آخر قطعة من الويب أثناء وجودها في Cache الهاتف؛ Pull يخفض المتاح ويغير revision، والبيع القديم يرفض ويحتاج مراجعة.
4. اقطعي الشبكة بعد Commit وقبل وصول رد Push؛ استعيدي إيصال العملية بدل إنشاء UUID جديد تلقائيًا.
5. سحب صلاحية أو تغيير الفرع أثناء الانقطاع: رفض توسيع النطاق على السيرفر، وإبقاء البيانات غير المرسلة للمراجعة.
اختُبرت مقابلات السيرفر على MySQL، ولم يُشغّل تطبيق Flutter فعليًا في هذه المرحلة.
