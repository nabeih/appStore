# توحيد أخطاء إدخال البيانات — نطاق التنفيذ والتغطية

تاريخ التنفيذ: 2026-11-22. العمل محلي، دون اتصال ببيانات app_store أو Testing/Production، ودون Migration أو Seeder أو نشر.

## ما نُفّذ

- مكوّنا Blade: `validation-feedback` لربط النماذج التقليدية بأخطاء Laravel، و`field-error` لرسائل الحقول المعروضة من الخادم. نموذج المديونية يعرض الأخطاء بجانب الحقول حتى دون JavaScript. بقية النماذج تعتمد على الآلية المشتركة في المتصفح لإضافة الرسائل بجانب الحقول.
- `public/assets/admin/js/validation.js`: جميع رسائل الحقل، إطار أحمر ونص، `aria-invalid` و`aria-describedby`، الحفاظ على تعليمات الحقل وقيمه، ملخص بروابط للحقول، والتركيز داخل details/dialog والتبويبات القابلة للتعرف. تخزين هوية النموذج المرسل فقط في sessionStorage، دون قيم المستخدم، يساعد عند تعدد النماذج في الصفحة.
- معالجة 422 بطريق قراءة نسخة من الاستجابة، دون استهلاك الاستجابة الأصلية أو تغيير شكل API. ربط صريح للنماذج التي تستخدم معاينة تلقائية وأسطر متكررة: المشتريات والكاشير والافتتاح والمرتجعات والعملاء وCheckout. الأخطاء غير المرتبطة بحقل تبقى في الملخص.
- النجاح في إعادة التحقق لنفس المسار يزيل الأخطاء السابقة؛ الكتابة وحدها لا تعني قبول الخادم. الاستجابات الأقدم لا تستبدل نتيجة أحدث لنفس النموذج والمسار. فشل الشبكة برسالة عربية، مع الحفاظ على AbortError لإلغاء المعاينات.
- توسيع `ValidationMessages` وإعادة استخدامه؛ `ArabicValidator` يمنع الرجوع إلى اسم الحقل البرمجي عند غياب ترجمة. يُستخدم اسم عربي عام للحقل غير المعرّب، ويستفيد المتصفح من عنوان الحقل الظاهر. لم يُنشأ نظام صلاحيات أو محاسبة جديد.
- أخطاء الخادم غير المتوقعة لا تعرض SQL أو Classes أو Stack Trace؛ تبقى آلية تسجيل الاستثناءات الحالية. بقيت حالات المصادقة والصلاحيات والاستجابات الصريحة على حالها وتغطيها اختبارات.

## مديونية المورد

شرط نشوء الدين قبل بداية تتبع الموردين لم يتغير. يجوز تسجيل القيد لاحقًا، بعد بدء التشغيل، بشرط الفترة المفتوحة وبقية ضوابط الاعتماد. توضّح الرسائل التاريخين الفعليين، وتُنسب أخطاء نشوء الدين وبداية التتبع وتاريخ القيد إلى الحقل المسؤول. لا إعادة تصنيف أو معالجة تلقائية للتاريخ.

## الجرد الفعلي للنماذج

الجرد شمل 74 ملف Blade يحتوي على form، بإجمالي 120 عنصر form. يشمل ذلك المرشحات ونماذج الأوامر، وليس عدد شاشات مستقلة. شملت القراءة أيضًا الأجزاء التي تحتوي حقولًا دون form مستقل، مثل أسطر المرتجع وإدخال الافتتاح ومكوّنات الألوان.

الحالة «فحص DOM» تعني فحص الحقول الثابتة المعروضة في HTML ناتج من اختبارات HTTP على MySQL معزول، باستخدام Chrome. لا تعني تنفيذ كل عملية تجارية أو كل حالة ديناميكية في تلك الشاشة.

| الملف | عدد النماذج | التغطية وحدودها |
|---|---:|---|
| [access/employee-form](../resources/views/access/employee-form.blade.php) | 2 | فحص DOM بالمكوّن المشترك؛ القبول الكامل لكل عملية غير مشمول |
| [access/roles](../resources/views/access/roles.blade.php) | 1 | فحص DOM بالمكوّن المشترك؛ القبول الكامل لكل عملية غير مشمول |
| [access/users](../resources/views/access/users.blade.php) | 1 | فحص DOM بالمكوّن المشترك؛ القبول الكامل لكل عملية غير مشمول |
| [account/profile](../resources/views/account/profile.blade.php) | 3 | فحص DOM بالمكوّن المشترك؛ القبول الكامل لكل عملية غير مشمول |
| [auth/forgot-password](../resources/views/auth/forgot-password.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [auth/login](../resources/views/auth/login.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [auth/reset-password](../resources/views/auth/reset-password.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [auth/setup](../resources/views/auth/setup.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [auth/verify-email](../resources/views/auth/verify-email.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [branches/form](../resources/views/branches/form.blade.php) | 1 | فحص DOM بالمكوّن المشترك؛ القبول الكامل لكل عملية غير مشمول |
| [branches/index](../resources/views/branches/index.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [branches/partials/status](../resources/views/branches/partials/status.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [catalog/attributes/index](../resources/views/catalog/attributes/index.blade.php) | 3 | فحص DOM بالمكوّن المشترك؛ القبول الكامل لكل عملية غير مشمول |
| [catalog/attributes/partials/category-form](../resources/views/catalog/attributes/partials/category-form.blade.php) | 1 | فحص DOM بالمكوّن المشترك؛ القبول الكامل لكل عملية غير مشمول |
| [catalog/attributes/partials/color-form](../resources/views/catalog/attributes/partials/color-form.blade.php) | 1 | فحص DOM بالمكوّن المشترك؛ القبول الكامل لكل عملية غير مشمول |
| [catalog/attributes/partials/manage-dialogs](../resources/views/catalog/attributes/partials/manage-dialogs.blade.php) | 2 | فحص DOM بالمكوّن المشترك؛ القبول الكامل لكل عملية غير مشمول |
| [catalog/attributes/partials/size-form](../resources/views/catalog/attributes/partials/size-form.blade.php) | 1 | فحص DOM بالمكوّن المشترك؛ القبول الكامل لكل عملية غير مشمول |
| [customers/index](../resources/views/customers/index.blade.php) | 2 | فحص DOM بالمكوّن المشترك؛ القبول الكامل لكل عملية غير مشمول |
| [customers/show](../resources/views/customers/show.blade.php) | 3 | فحص DOM بالمكوّن المشترك؛ القبول الكامل لكل عملية غير مشمول |
| [dashboard/inventory-valuation](../resources/views/dashboard/inventory-valuation.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [finance/accounts/index](../resources/views/finance/accounts/index.blade.php) | 3 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [finance/chart/form](../resources/views/finance/chart/form.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [finance/chart/index](../resources/views/finance/chart/index.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [finance/chart/show](../resources/views/finance/chart/show.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [finance/expenses/index](../resources/views/finance/expenses/index.blade.php) | 2 | فحص DOM بالمكوّن المشترك؛ القبول الكامل لكل عملية غير مشمول |
| [finance/expenses/report](../resources/views/finance/expenses/report.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [finance/expenses/schedule](../resources/views/finance/expenses/schedule.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [finance/expenses/show](../resources/views/finance/expenses/show.blade.php) | 4 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [finance/review/opening-transitions](../resources/views/finance/review/opening-transitions.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [finance/review/supplier](../resources/views/finance/review/supplier.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [finance/review/transition-form](../resources/views/finance/review/transition-form.blade.php) | 2 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [finance/treasury/index](../resources/views/finance/treasury/index.blade.php) | 5 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [guidance/widget](../resources/views/guidance/widget.blade.php) | 1 | فحص DOM بالمكوّن المشترك؛ القبول الكامل لكل عملية غير مشمول |
| [inventory/opening-stock/import](../resources/views/inventory/opening-stock/import.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [inventory/opening-stock/index](../resources/views/inventory/opening-stock/index.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [inventory/opening-stock/partials/draft-edit](../resources/views/inventory/opening-stock/partials/draft-edit.blade.php) | 3 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [inventory/setup/document-correction](../resources/views/inventory/setup/document-correction.blade.php) | 2 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [inventory/setup/partials/completion](../resources/views/inventory/setup/partials/completion.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [inventory/setup/partials/dialog](../resources/views/inventory/setup/partials/dialog.blade.php) | 1 | فحص DOM بالمكوّن المشترك؛ القبول الكامل لكل عملية غير مشمول |
| [inventory/setup/partials/product-details](../resources/views/inventory/setup/partials/product-details.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [inventory/setup/progressive](../resources/views/inventory/setup/progressive.blade.php) | 5 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [inventory/setup/review](../resources/views/inventory/setup/review.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [layouts/account](../resources/views/layouts/account.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [onboarding/branch](../resources/views/onboarding/branch.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [partials/sidebar](../resources/views/partials/sidebar.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [partials/topbar](../resources/views/partials/topbar.blade.php) | 2 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [purchases/invoices/form](../resources/views/purchases/invoices/form.blade.php) | 4 | فحص DOM بالمكوّن المشترك؛ القبول الكامل لكل عملية غير مشمول |
| [purchases/invoices/index](../resources/views/purchases/invoices/index.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [purchases/invoices/show](../resources/views/purchases/invoices/show.blade.php) | 5 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [sales/cashier/index](../resources/views/sales/cashier/index.blade.php) | 2 | فحص DOM بالمكوّن المشترك؛ القبول الكامل لكل عملية غير مشمول |
| [sales/exchanges/form](../resources/views/sales/exchanges/form.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [sales/invoices/index](../resources/views/sales/invoices/index.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [sales/invoices/show](../resources/views/sales/invoices/show.blade.php) | 2 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [sales/partials/reverse-form](../resources/views/sales/partials/reverse-form.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [sales/reports/index](../resources/views/sales/reports/index.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [sales/returns/form](../resources/views/sales/returns/form.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [search/index](../resources/views/search/index.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [search/partials/filters](../resources/views/search/partials/filters.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [search/partials/header](../resources/views/search/partials/header.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [search/partials/product-search](../resources/views/search/partials/product-search.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [search/visual/index](../resources/views/search/visual/index.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [store/admin/content/form](../resources/views/store/admin/content/form.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [store/admin/content/index](../resources/views/store/admin/content/index.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [store/admin/minimum-prices](../resources/views/store/admin/minimum-prices.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [store/admin/orders/index](../resources/views/store/admin/orders/index.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [store/admin/orders/show](../resources/views/store/admin/orders/show.blade.php) | 2 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [store/public/checkout](../resources/views/store/public/checkout.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [store/public/index](../resources/views/store/public/index.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [store/public/layout](../resources/views/store/public/layout.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [store/public/product](../resources/views/store/public/product.blade.php) | 1 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [suppliers/debt-create](../resources/views/suppliers/debt-create.blade.php) | 1 | فحص DOM بالمكوّن المشترك؛ القبول الكامل لكل عملية غير مشمول |
| [suppliers/debt-show](../resources/views/suppliers/debt-show.blade.php) | 4 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |
| [suppliers/index](../resources/views/suppliers/index.blade.php) | 2 | فحص DOM بالمكوّن المشترك؛ القبول الكامل لكل عملية غير مشمول |
| [suppliers/show](../resources/views/suppliers/show.blade.php) | 5 | جرد المصدر وربط عبر Layout/النموذج الأب؛ لم يُنفذ قبول تفاعلي مستقل |

## الاختبارات والأدلة

- MySQL 8.4.3، Laravel 13.35، PHP 8.5.12. استخدم `tests/run-go-live.php` حواجز الهوية وقواعد معزولة موجودة؛ لم تُشغل Migrations.
- حزمة التوافق: **122 اختبارًا و1674 assertions ناجحة** عبر مجموعتين: 87/1372 على `appstore_account_status_20261118_tests`، و35/302 على `appstore_golive_20261031_tests`.
- بعد تفصيل أخطاء التواريخ داخل Action، إعادة نهائية: **29 اختبارًا و403 assertions ناجحة**، تشمل مديونية المورد والتحقق العام. لا تجمع هذه الأرقام بالحزمة السابقة لأنها تتداخل معها.
- Chrome: 11 صفحة أساسية، 46 نموذجًا و176 حقلًا، إضافة إلى HTML رفض المديونية الحقيقي؛ 768 فحصًا، صفر أخطاء JavaScript. جرى اختبار كود محرر المشتريات الفعلي بأسطر متعددة واستجابات وهمية، وفهرسة الحقول بعد حذف سطر. اختُبرت أيضًا الحقول المتداخلة وفتح التفاصيل والنافذة، والحفاظ على القيم وتعليمات الوصول، و422 والنجاح اللاحق والتحقق الأصلي للمتصفح.
- تجربة Chrome استخدمت HTML من استجابات اختبارات MySQL المعزولة وطلبات Fetch وهمية، ولم تتصل بخادم التطبيق أو تنفذ عملية مالية عبر المتصفح. ليست تجربة End-to-End حية لكل شاشة.
- الجولة الموسعة الأولى رفضت 35 حالة بسبب حارس اسم قاعدة الاختبار. أُعيدت على القاعدة التي تشترطها دون إضعاف الحارس. فحص Excel تعثر أولًا لغياب ZIP في CLI؛ نجح بعد تحميل الامتداد المثبت باستخدام `-d extension=zip` لهذه العملية فقط، دون تعديل php.ini.
- الأدلة: `storage/app/reports/validation-regression-final.txt`، `validation-expense-purchase-final.txt`، `validation-debt-final.txt`، `validation-browser.json`. ملفات HTML الاختبارية تحت `storage/framework/testing/validation-pages` و`validation-debt.html`.

## ما لم يكتمل كقبول شامل

- لا يُعد جرد المصادر أو إضافة المكوّن إثباتًا لاكتمال مراجعة كل رسالة مخصصة في جميع Actions أو جميع حالات المستندات. الرسائل السياقية لمديونية المورد تمت مراجعتها تفصيليًا؛ بقية الوحدات تستفيد من القاموس والآلية المشتركة مع الحدود المسجلة في الجدول.
- تحتاج نماذج التعديل والمستندات ذات الحالات المتقدمة غير المدرجة ضمن فحص DOM إلى قبول تفاعلي مستقل: القيود والحسابات والتحويلات، تصحيح المستندات، الاعتمادات والعكس، إعدادات المتجر والطلبات، ورفع الملفات والصور الفعلي. لا يوجد ادعاء بفحص هذه الدورات كاملة في المتصفح.
- روابط الحقول ذات مفاتيح name أو data-validation-field مثبتة. أي حقل ديناميكي جديد أو واجهة خارج هذه Layouts يحتاج ربطًا صريحًا واختبارًا. واجهات Flutter الأصلية ليست ضمن هذا المستودع؛ لم تُختبر استجابتها المرئية.
- لم تُشغّل الحزمة الكاملة للمشروع، ولم يُختبر هاتف فعلي أو قارئ شاشة فعلي أو Safari. اختبارات الوصول الحالية تتحقق من DOM والتركيز في Chrome.

## الملفات الخاصة بهذه المهمة

- `app/Support/Guidance/ArabicValidator.php` و`ValidationMessages.php` و`app/Providers/AppServiceProvider.php` و`bootstrap/app.php`.
- `StoreSupplierDebtSettlementRequest.php` و`ManageSupplierDebtSettlement.php` و`resources/views/suppliers/debt-create.blade.php`.
- `resources/views/components/validation-feedback.blade.php` و`field-error.blade.php`، وLayouts الإدارة والحساب والمتجر، وصفحتا login/setup.
- ربط الحقول التي تُنشئ payload عبر id في نماذج المشتريات والكاشير والمرتجعات والعملاء والمخزون ومساعد المتجر والبحث بالصورة. هذه إضافات وصفية للحقول لا تغيّر البيانات المرسلة.
- JavaScript: `validation.js` و`sales/core.js` و`cashier.js` و`adjustment.js` و`purchases/editor.js` و`customers/receipts.js` و`inventory/opening-stock.js` و`opening-draft.js` و`assets/store/js/live-store.js`، مع `validation.css`.
- الاختبارات: `tests/Feature/ValidationExperienceTest.php` وتوسعة `SupplierDebtSettlementTest.php` و`tests/Browser/ValidationExperience.cjs`.

بقية التعديلات المحلية السابقة محفوظة، ولا تُنسب إلى هذه المهمة. لا Migration مطلوب لهذه التغييرات، ولا Commit أو Push أو Deploy.

## مراجعة الاستكمال — 2026-11-23

النتائج أعلاه محفوظة ولم تُعد الحزم الناجحة. أضيفت إلى اختبار عرض الشاشات حالات الحسابات والصندوق وشجرة الحسابات والجاهزية، ونماذج إعدادات المتجر الستة. محاولة تشغيل هذا الاختبار الإضافي توقفت قبل أي assertion أو تجهيز بيانات لأن MySQL على 127.0.0.1:3306 رفض الاتصال (SQLSTATE 2002). لذلك هذه الإضافات الاختبارية غير متحققة حاليًا، ولا تتغير أرقام النجاح السابقة. الدليل: `storage/app/reports/validation-expanded-pages.txt`.

الحكم: الآلية المشتركة ورسائل المديونية منفّذة ومختبرة، لكن القبول الشامل لكل النماذج غير مكتمل. الخطوة التالية تشغيل MySQL المحلي ثم إعادة الاختبار المستهدف فقط؛ لا حاجة لإعادة التنفيذ من البداية. لم يتغير كود التطبيق أو إعداد اتصال أو قاعدة بيانات في مراجعة الاستكمال هذه.
