# استكمال التثبيت وإعداد الفرع والحساب والاسترداد
تاريخ التنفيذ: 2026-10-31. Laravel 13.35.0، PHP 8.5.12، MySQL 8.4.3.

## النتيجة وحدود التنفيذ
نُفذت الميزة محليًا على الكود، واختُبرت على MySQL معزول. لم يُطبّق الترحيل الجديد على app_store، ولم نُنشئ أو نُعدّل المالك أو الفروع أو المنتجات أو الحسابات المالية فيها. لم نُرسل بريدًا حقيقيًا، ولم نتصل بإنتاج أو منصة اشتراكات، ولم نُضف مكتبات أو حسابات افتراضية.

حالة app_store التي فُحصت للقراءة فقط:
- الاتصال mysql، البيئة local، المضيف127.0.0.1.
- تثبيت completed، مالك موجود، مستخدم واحد،4 أدوار،35 صلاحية، دون فروع أو منتجات أو مخزون.
- جدول التثبيت موجود من المرحلة السابقة؛ الترحيل الجديد غير منفذ.
- MAIL_MAILER الحالي log. روابط الحماية لا ترسل عبره.
- مقارنة البصمات قبل وبعد: جميع الجداول تطابقت عدا sessions وcache. بقيت أعداد المستخدمين والأدوار والصلاحيات وبيانات الأعمال وحالة التثبيت كما هي، دون جداول جديدة. عدد sessions بقي3 وتغير محتواها، وعدد cache زاد من7 إلى9. هذه تغيّرات رُصدت خلال فترة العمل؛ لا ننسب سببها دون دليل. الاختبارات وDDL استهدفت Sandbox فقط.

## ما فُحص قبل البناء
- app/Models/{User,Branch,Role,Permission,ApplicationInstallation}.php.
- migrations الفروع والمستخدمين والمبيعات والمالية والتثبيت الأول.
- app/Support/Sales/{OperationContext,SalesAccess,SalesReports}.php.
- app/Support/Access/AccessPermissions.php.
- app/Actions/Access/{InitializeAccessAction,ManageAccessAction}.php.
- app/Actions/Access/FirstRun/{InstallationReadiness,BootstrapOwnerAction,PrepareSetupTokenAction}.php.
- app/Actions/Inventory/{ReviewInventoryAction,SetupReadinessReport}.php.
- مسارات web وapi، Middleware المصادقة والجلسة والمستخدم الفعّال والتثبيت.
- صفحات الدخول والتثبيت والقالب العام وtopbar.
- config/auth.php وconfig/mail.php وconfig/session.php وسلوك Password Broker وVerifyEmail وEmailVerificationRequest من Laravel المثبّت.
- tests/TestCase.php وtests/run-go-live.php واختبارات Auth وAccess السابقة.

لا توجد .ai/rules في هذه النسخة، ولم تُستخدم خدمة خارجية أو واجهة GitHub Pages.

## بنية الفروع وإعادة الاستخدام
Branch يدعم name وaddress وphone وtype وis_active. اسم الفرع فريد. علاقة User::branch تستخدم branch_id.
نطاق الفروع يعتمد على SalesAccess وSalesReports وOperationContext؛ sales.all_branches تمنح المالك نطاق جميع الفروع حتى بعد ربطه بالفرع الأول.

لم نجد Action أو شاشة إدارة لإنشاء الفروع حاليًا يمكن استدعاؤها؛ الموجود نموذج وSchema واستخدامات للفروع في المستندات والاختبارات. لذلك أضيف CreateBranchAction كمكان مركزي لإنشاء الفرع وفق الحقول الحالية، دون إنشاء كيان متجر أو حقول لا تُستخدم. يستدعيه CompleteFirstBranchAction مع التفويض والمعاملة والتدقيق.

## تسلسل العميل الجديد
1. تنفذ جميع migrations في نشر جديد معتمد، ويجهّز مسؤول النشر رمز التثبيت بالطريقة القائمة.
2. /setup يطلب البريد إلزاميًا، مع النص الإرشادي المطلوب ظاهرًا، ويقبل كلمة مرور من4 أحرف أو أكثر وفق سياسة المشروع.
3. Bootstrap القائم ينشئ الأدوار والمالك وحالة الإكمال كما سبق، ويضيف first_branch_required=true في المعاملة نفسها. لم تُنسخ تعريفات الصلاحيات أو تُخفف حماية sales_owner.
4. يسجل المالك دخوله. تحاول المنظومة إرسال التحقق بعد اكتمال Transaction؛ فشل البريد لا يلغي حسابًا مكتملًا ولا يعيد فتح /setup.
5. التوجيه إلى /onboarding/branch بعنوان «إعداد متجرك لأول مرة».
6. تُقبل بيانات اسم الفرع والعنوان والهاتف الاختياريين. يثبت type=shop وis_active=true من السيرفر.
7. يقفل CompleteFirstBranchAction سجل التثبيت والمالك ثم العداد المشترك، ويعيد التحقق من الملكية والحالة وعدم وجود فرع، وينشئ الفرع ويربط branch_id بالمالك ويثبت first_branch_completed_at وinitial_branch_id ويطفئ required، ويسجل Audit Event ضمن Transaction واحدة.
8. ينتقل إلى Dashboard. إعداد البضائع والأرصدة يستكمل من الوحدات الحالية.

RequireFirstBranch يمنع تجاوز الخطوة عبر صفحات التشغيل أو API؛ GET العادي يوجه للمعالج، والطلبات التشغيلية/JSON ترفض409. الإعداد نفسه للمالك الفعّال الأصلي فقط، والموظف يُرفض403.
يسمح أثناء الخطوة بالملف الشخصي والتحقق من البريد وتسجيل الخروج. لا توجد حلقة توجيه.

حالة اكتمال الفرع دائمة ولا تعتمد على عدد الفروع. حذف الفرع لاحقًا لا يعيد المعالج، حتى لو أُفرغ initial_branch_id بسبب FK. النموذج يمنع إعادة فتح الحالة المكتملة.
الترحيل يعلّم التثبيت completed صاحب المالك ودون أي فرع بأنه يحتاج الخطوة. التثبيت القديم المحتوي فروعًا لا يُفرض عليه المعالج؛ وحالات blocked ليست مسار onboarding جديد.

## المخزون والمحاسبة
لم نعدّل OperationContext::salesStarted ولا ReviewInventoryAction::close ولا SetupReadinessReport.
لا إنشاء لمخزون أو حسابات أو قيود أو أرصدة تلقائية، ولا تعديل opening_stock_closed_at أو sales_controls.activated_at.
إنشاء الفرع لا يفعّل البيع ولا ينهي الإعداد الافتتاحي. اختُبر استمرار المنع وعدم وجود أثر مالي/مخزني.

## سياسة البريد والتحقق
صحة الصيغة لا تعني الملكية. بريد المالك إلزامي وفريد، ويبدأ email_verified_at=null.
User يطبق MustVerifyEmail، ورسالة عربية تستخدم رابط Laravel الموقّع المؤقت، افتراضيًا60 دقيقة.
الرابط يتطلب تسجيل دخول صاحب الحساب، وتطابق معرفه وبصمة بريده والتوقيع ووقت الانتهاء. يعاد فحص البريد تحت قفل السجل لمنع توثيق عنوان قديم بالتزامن مع تغييره.
إعادة الإرسال POST مع CSRF و6 محاولات بالدقيقة.

السياسة المختارة: التأكيد لا يحجب الدخول أو إعداد الفرع أو وحدات العمل ضمن الصلاحيات. تظل حالة «غير مؤكد» ظاهرة. استرداد كلمة المرور يرسل فقط إلى بريد الحساب الحالي المؤهل؛ استهلاك رابط الاسترداد لا يزوّر تأكيد البريد. هذا يحمي تجربة أول تشغيل من توقف البريد مع إبقاء إثبات الملكية صريحًا.
عند تغيير البريد تلغى حالة التحقق وروابط الاسترداد القديمة، وتحتاج العملية كلمة المرور الحالية، ثم يُرسل رابط جديد.
لا تعديل للهوية الإدارية أو الدور أو الفرع بواسطة رابط التحقق.

AccountMail يقبل transport فعليًا معروفًا مثل SMTP، ويرفض log وarray وfailover/roundrobin غير المراجعين لتجنب تسجيل روابط الحماية. الاختبارات تضبط Mailer على SMTP وتستخدم Notification Fake لمنع أي إرسال فعلي، دون استثناء اختباري في كود الإنتاج. أخطاء النقل لا تُعرض أو تُسجل بتفاصيل قد تحتوي رابطًا أو بيانات اتصال.
فشل التحقق يظهر رسالة مفهومة للمستخدم مع إمكانية إعادة المحاولة. فشل إرسال الاسترداد يبقى وراء رسالة عامة تحمي وجود الحساب.

## الملف الشخصي
/profile متاح للمستخدم المسجل الفعّال، ويعرض الاسم واسم المستخدم والبريد وحالة التحقق والدور والفرع.
يحدّث الاسم واسم المستخدم والبريد فقط، مع قواعد Unicode والتفرد القائمة. يتطلب البريد عنوانًا صالحًا، ويمكن للموظف القديم الذي لا يملك بريدًا إضافته هنا.
role_id وbranch_id وis_active والصلاحيات وemail_verified_at لا يمكن تعديلها من النموذج.
رابط «ملفي الشخصي» موجود في topbar على الهاتف والكمبيوتر. الصفحة مستقلة RTL بنفس هوية شاشة الحساب، وتوفر الرجوع للإدارة والخروج.
تغيير كلمة المرور يطلب الحالية والجديدة وتأكيدها، ويعيد استخدام قواعد ResetEmployeePasswordRequest. يحفظ Hash ويروّت remember_token ويلغي رموز الاسترداد وينهي الجلسات الأخرى. تتجدد الجلسة الحالية مع تحديث بصمة auth.session، ويبقى المستخدم داخل حسابه.
حفظ الملف محدود10 مرات بالدقيقة وتغيير كلمة المرور5.

## استرداد كلمة المرور
- /login يحتوي «نسيت كلمة المرور؟».
- GET/POST /forgot-password لطلب الاسترداد، وGET /reset-password/{token} للنموذج، وPOST /reset-password لحفظ الجديدة.
- Laravel Password Broker بجدول password_reset_tokens، وبصمة الرمز الرسمية، وصلاحية60 دقيقة، ومهلة إعادة إرسال60 ثانية.
- Provider منفصل للاسترداد يستبعد المحذوف منطقيًا وغير الفعّال والحساب المرتبط بدور معطل؛ لا يغيّر Provider تسجيل الدخول الحالي.
- الرد العام نفسه للبريد المعروف وغير المعروف وغير المؤهل. Rate Limiting:5 بالدقيقة/IP و10 بالساعة لكل بصمة بريد، للمسارين.
- الاستهلاك تحت Transaction وقفل صف الرمز ثم المستخدم، مع إعادة فحص أهلية الحساب وبريده، وتغيير Hash وremember_token وإبطال الجلسات، ثم حذف رمز الاسترداد عبر Broker وتسجيل التدقيق.
- لا إعادة تفعيل لحساب معطل، ولا تغيير للدور/الفرع، ولا تسجيل دخول تلقائي بعد الاسترداد.
- رمز مستهلك/خاطئ/منتهٍ يُرفض. لم تُرسل كلمات مرور بالبريد.
- token وcurrent_password وحقول كلمة المرور وsetup_token مستثناة من old input. Debugbar مستثنى من صفحات الحساب والحماية، وReferrer-Policy=no-referrer وno-store على ردودها.
- في الإنتاج يجب HTTPS وضبط reverse proxy وAPP_URL، وتعطيل/تنقيح access logs وAnalytics عن مسارات وروابط reset وverification حتى لا تُسجل رموز URL عند الخادم أو أدوات خارجية.

## تشغيل البريد الفعلي
الإعدادات الموجودة في config/mail.php قابلة للضبط عبر البيئة. مثال بأسماء متغيرات فقط، دون أسرار:
```dotenv
APP_URL=https://your-store.example
APP_DEBUG=false
MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=<smtp-host>
MAIL_PORT=465
MAIL_USERNAME=<smtp-user>
MAIL_PASSWORD=<smtp-secret>
MAIL_FROM_ADDRESS=<verified-sender>
MAIL_FROM_NAME="إدارة المتجر"
SESSION_SECURE_COOKIE=true
```
استخدم scheme/port المناسبين لمزوّدك، واحفظ الأسرار خارج Git. نموذج465 أعلاه لا يستبدل تعليمات مزود البريد.
في تجربة محلية يمكن توجيه SMTP إلى صندوق اختبار محلي مثل Mailpit، أو حساب SMTP اختبار مملوك لك، دون اشتراك مضاف من قبلنا. لم نثبت أو نجهّز خدمة بريد أو نعدل .env.
الرسائل متزامنة؛ لا تحتاج Queue جديدة. زمن SMTP قد يؤخر الرد، لذلك اختبر زمن الإرسال والانقطاعات عند التسليم.
نجاح Notification Fake لا يعني وصول البريد. التسليم النهائي يحتاج اختبار وصول تأكيد واسترداد إلى صندوق حقيقي، ومراجعة الرسائل غير المرغوبة وإعدادات المرسل وSPF/DKIM/DMARC حسب المزوّد.

## الترحيل والموافقة المطلوبة
الجديد:
database/migrations/2026_10_31_135148_add_account_security_and_first_branch_onboarding.php

يضيف:
- users.email_verified_at nullable، دون توثيق أي بريد قديم تلقائيًا.
- جدول password_reset_tokens.
- application_installations.first_branch_required وfirst_branch_completed_at وinitial_branch_id.

نُفذ هذا الترحيل بآلية Laravel المعتادة على appstore_golive_20261031_tests فقط، بعد حارس اتصال صريح. لم يُنفذ على app_store. لا migrate:fresh ولا db:wipe ولا حذف بيانات أعمال قائمة.
down يرفض التراجع التلقائي الذي يحذف حالة الأمان/الإعداد، ويلزم مسار استرداد معتمد.

بعد موافقتك فقط، يمكن تطبيق الترحيل المحدد على app_store المحلية:
```text
php artisan migrate --path=database/migrations/2026_10_31_135148_add_account_security_and_first_branch_onboarding.php --no-interaction
```
هذا أمر توثيقي للتطبيق اللاحق؛ لم ينفذ على app_store. استخدم PHP8.5 المثبت عند التنفيذ؛ php الافتراضي في PATH بهذه البيئة يشير إلى8.3.

## الاختبارات والأدلة
- الحزمة الكاملة:108 اختبارات ناجحة،1003 assertions، على MySQL المعزول.
- تشمل18 اختبارًا جديدًا في AccountOnboardingTest، واختبارات التثبيت السابق والمصادقة وأمر إنشاء المستخدم وحماية الأدوار والموظفين.
- بعد تجهيز لقطات الشاشات، أعيد مسار العميل الكامل واختبار الترحيب:2 اختبار،38 assertions، ناجحان. وبعد تنظيف خدمة البريد من أي اعتماد على Fakes، أعيدت الاختبارات الجديدة الثمانية عشر:18 اختبارًا و188 assertions، ناجحة.
- اختبار التزامن الحقيقي الموسع: إنشاء المالك201/404، وإنشاء الفرع201/409 بعمليتي PHP مستقلتين. مالك واحد وفرع واحد وAudit واحد لكل عملية، دون أموال أو مخزون.
- اختُبر فشل Audit للفرع والتراجع عن الفرع والربط وحالة الاكتمال.
- التحقق من صلاحية روابط البريد/الحساب/الانتهاء، وإعادة الإرسال/الاسترداد المحدودة، والتغيير إلى بريد جديد، واسترداد مرة واحدة، ومنع المعطل والمحذوف، وإلغاء الجلسات وحماية الصلاحيات.
- قوالب البريد العربية رُندرت فعليًا، واستخدم Notification Fake لتأكيد الإرسال.
- Chrome بملفات HTML مولّدة من اختبارات Sandbox: جميع شاشات setup والفرع والملف والتحقق والاسترداد على390px و1280px، دون تجاوز أفقي أو أخطاء JavaScript أو تخزين متصفح أو طلبات كتابة فعلية لقاعدة التطوير.
- Pint وgit diff --check وnode --check ناجحة.

الاختبار البصري استخدم صفحات ثابتة؛ تدفق HTTP والحفظ والتحقق فُحص في Feature Tests على MySQL. لم يُختبر هاتف فعلي أو Safari، ولم يُختبر وصول SMTP حقيقي أو إعداد Production.
اختبار التزامن ينشئ قواعد appstore_first_run_race_* معزولة وينسخ تعريفات الجداول فقط، ويبقيها للمراجعة دون حذف. اختبارات التوافق القديمة تستخدم Seeders داخل Sandbox ومعاملاتها الراجعة.

الأدلة:
- storage/app/reports/first-run-concurrency.json
- storage/app/reports/first-run-browser.json
- storage/app/reports/account-branch.png وaccount-profile.png وaccount-verify.png وaccount-forgot.png وaccount-reset.png
- storage/app/reports/account-onboarding-verification.json

## الملفات المضافة لهذه المرحلة
- app/Actions/Branches/CreateBranchAction.php
- app/Actions/Access/FirstRun/CompleteFirstBranchAction.php
- app/Actions/Account/{AccountMail,UpdateProfileAction,ChangePasswordAction,RecoverPasswordAction}.php
- app/Support/Access/ActivePasswordUserProvider.php
- app/Http/Middleware/Auth/RequireFirstBranch.php
- app/Http/Controllers/Web/Onboarding/FirstBranchController.php
- app/Http/Controllers/Web/Account/ProfileController.php
- app/Http/Controllers/Web/Auth/{EmailVerificationController,PasswordRecoveryController}.php
- app/Http/Requests/Onboarding/FirstBranchRequest.php
- app/Http/Requests/Account/{ProfileRequest,ChangePasswordRequest}.php
- app/Http/Requests/Auth/{ForgotPasswordRequest,ResetPasswordRequest}.php
- app/Notifications/Account/{VerifyAccountEmail,ResetAccountPassword}.php
- resources/views/layouts/account.blade.php
- resources/views/onboarding/branch.blade.php
- resources/views/account/profile.blade.php
- resources/views/auth/{verify-email,forgot-password,reset-password}.blade.php
- resources/views/emails/account.blade.php
- public/assets/admin/css/pages/auth/account.css
- الترحيل المذكور وtests/Feature/Auth/AccountOnboardingTest.php وهذا التقرير وملفات الأدلة.

المعدلة لهذه المرحلة:
- User وApplicationInstallation.
- BootstrapOwnerAction وFirstRunSetupController وFirstRunSetupRequest.
- FirstRunSetupGate وAppServiceProvider وbootstrap/app.php وconfig/auth.php.
- routes/web.php وroutes/api.php.
- auth/setup وauth/login وpartials/topbar.
- FirstRunSetupTest وFirstRunSetupConcurrencyTest وtests/Browser/FirstRunSetup.cjs.
- first-run-setup.md: تنبيه أن تقريره يخص المرحلة السابقة وأن البريد أصبح إلزاميًا.

لم يتغير ManageAccessAction أو AccessPermissions أو SalesAccess أو منطق المخزون والدفتر والبيع، ولم يتغير config/mail.php أو .env أو dependencies.

## تجربة المتصفح بعد الموافقة
التثبيت المحلي الحالي مكتمل بالفعل وله مالك، لذلك لا تعِد فتح /setup أو تجهيز رمز له. لا تنشئ مالكًا ثانيًا.
1. بعد اعتماد الترحيل وتطبيقه، سجّل الدخول بالمالك الحالي على http://127.0.0.1:8000/login.
2. سيطلب /dashboard إعداد الفرع الأول على /onboarding/branch لأن هذا التثبيت لا يملك فروعًا بعد.
3. إذا لم يملك المالك بريدًا، أضف عنوانًا حقيقيًا من /profile مع كلمة المرور الحالية. لا توجد معالجة تلقائية لبريده.
4. بعد تجهيز SMTP محليًا، أعد إرسال التحقق من /email/verify أو /profile، وافتح الرسالة بالحساب نفسه.
5. أنشئ الفرع ببياناتك؛ راجع Dashboard ثم وحدات تجهيز المخزون والأرصدة. يبقى البيع ممنوعًا وفق شروط الإعداد القائمة.
6. جرّب /profile لتغيير البيانات وكلمة المرور، ثم سجّل الخروج واستخدم /forgot-password وافتح رابط الرسالة.
7. تجربة من الصفر بما فيها /setup مرة أخرى تحتاج قاعدة محلية جديدة معزولة معتمدة؛ لا حذف مستخدم أو تعديل حالة completed.

توقف التنفيذ هنا قبل تطبيق التغييرات على app_store أو ضبط SMTP. المطلوب للموافقة التالية: تطبيق الترحيل المحدد محليًا، ثم تحديد وجهة SMTP آمنة لتجربة وصول الرسائل.
