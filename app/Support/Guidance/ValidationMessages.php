<?php

namespace App\Support\Guidance;

class ValidationMessages
{
    /** @return array<string, string> */
    public static function lines(): array
    {
        $messages = [
            'present' => 'بيانات :attribute ناقصة؛ أكملها من النموذج ثم حاول مجددًا.',
            'prohibited_unless' => 'لا يُسمح بإدخال :attribute مع الاختيار الحالي؛ راجع نوع العملية.',
            'prohibits' => 'لا تجمع :attribute مع البيانات المتعارضة في هذه العملية.',
            'current_password' => 'كلمة المرور الحالية غير صحيحة؛ أعد إدخالها.',
            'same' => ':attribute غير مطابق للحقل المرتبط به؛ راجع القيمتين.',
            'different' => 'يجب أن يختلف :attribute عن الحقل المرتبط به.',
            'gt.numeric' => 'يجب أن تكون قيمة :attribute أكبر من :value.',
            'gte.numeric' => 'يجب ألا تقل قيمة :attribute عن :value.',
            'lt.numeric' => 'يجب أن تكون قيمة :attribute أقل من :value.',
            'lte.numeric' => 'يجب ألا تتجاوز قيمة :attribute القيمة :value.',
            'url' => 'أدخل رابطًا صحيحًا وكاملًا في :attribute.',
            'required' => 'أكمل حقل :attribute ثم حاول مرة أخرى.',
            'required_if' => 'أكمل حقل :attribute لهذا الاختيار قبل المتابعة.',
            'required_unless' => 'أكمل حقل :attribute لهذا الاختيار قبل المتابعة.',
            'required_with' => 'أكمل حقل :attribute مع البيانات المرتبطة به.',
            'required_without' => 'أكمل حقل :attribute أو البديل المطلوب.',
            'accepted' => 'راجع التأكيد المتعلق بـ:attribute وضع علامة الموافقة إن كان صحيحًا.',
            'accepted_if' => 'هذا الاختيار يحتاج تأكيد :attribute قبل المتابعة.',
            'confirmed' => 'تأكيد :attribute غير مطابق؛ أعد إدخاله.',
            'string' => 'اكتب :attribute كنص واضح.', 'integer' => 'أدخل :attribute كعدد صحيح دون كسور.',
            'numeric' => 'أدخل مبلغًا أو رقمًا صحيحًا في :attribute، دون رموز أو كلمات.',
            'decimal' => 'راجع عدد المنازل العشرية في :attribute؛ المسموح :decimal منازل.',
            'array' => 'بيانات :attribute غير مكتملة؛ أعد الاختيار من النموذج.',
            'list' => 'أعد ترتيب بيانات :attribute من النموذج ثم حاول مجددًا.',
            'boolean' => 'اختر نعم أو لا في :attribute.', 'email' => 'أدخل بريدًا إلكترونيًا صحيحًا في :attribute.',
            'exists' => 'اختيار :attribute غير متاح. حدّث القائمة واختر منها بدل كتابة المعرّف يدويًا.',
            'unique' => ':attribute مستخدم بالفعل. ابحث عن السجل الموجود قبل إنشاء سجل جديد.',
            'in' => 'اختر قيمة متاحة من قائمة :attribute.', 'not_in' => 'هذا الاختيار غير مسموح في :attribute؛ راجع القائمة.',
            'distinct' => ':attribute مكرر في نفس الإدخال؛ أبقِ كل اختيار مرة واحدة.',
            'date' => 'اختر تاريخًا صحيحًا في :attribute.', 'date_format' => 'أدخل :attribute بصيغة :format.',
            'before_or_equal' => 'يجب أن يكون :attribute في :date أو قبله.',
            'after_or_equal' => 'يجب أن يكون :attribute في :date أو بعده.',
            'before' => 'يجب أن يكون :attribute قبل :date.', 'after' => 'يجب أن يكون :attribute بعد :date.',
            'regex' => 'صيغة :attribute غير صحيحة؛ راجع المثال أو التعليمات بجانب الحقل.',
            'uuid' => 'معرّف :attribute غير صحيح؛ أعد فتح العملية من الشاشة الأصلية.',
            'image' => 'اختر ملف صورة صالحًا في :attribute.',
            'file' => 'اختر ملفًا صالحًا في :attribute.', 'uploaded' => 'لم يكتمل رفع :attribute. تحقق من الاتصال وحجم الملف ثم أعد المحاولة.',
            'mimes' => 'صيغة :attribute غير مدعومة؛ استخدم :values.',
            'mimetypes' => 'نوع ملف :attribute غير مدعوم؛ اختر ملفًا من الأنواع الموضحة في الشاشة.',
            'dimensions' => 'أبعاد :attribute غير مناسبة؛ اختر صورة ضمن الأبعاد الموضحة بجانب الرفع.',
            'max.string' => 'اختصر :attribute إلى :max حرفًا كحد أقصى.',
            'max.numeric' => 'يجب ألا تتجاوز قيمة :attribute الرقم :max.',
            'max.array' => 'يُسمح بحد أقصى :max عناصر في :attribute؛ قسّم الإدخال عند الحاجة.',
            'max.file' => 'حجم :attribute أكبر من المسموح (:max كيلوبايت)؛ اختر ملفًا أصغر.',
            'min.string' => 'اكتب :attribute في :min أحرف على الأقل لتوضيح المطلوب.',
            'min.numeric' => 'يجب ألا تقل قيمة :attribute عن :min.',
            'min.array' => 'أضف :min عناصر على الأقل في :attribute.',
            'between.numeric' => 'أدخل :attribute بين :min و:max.',
            'between.string' => 'اكتب :attribute بين :min و:max حرفًا.',
            'size.string' => 'يجب أن يكون :attribute من :size حرفًا.',
            'prohibited' => 'لا ترسل :attribute في هذه العملية؛ استخدم الحقول المتاحة في الشاشة.',
        ];
        $attributes = ['name' => 'الاسم', 'username' => 'اسم الدخول', 'password' => 'كلمة المرور', 'email' => 'البريد الإلكتروني',
            'branch_id' => 'الفرع', 'account_id' => 'الحساب', 'supplier_id' => 'المورد', 'customer_id' => 'العميل',
            'rows' => 'الأصناف', 'items' => 'البنود', 'payments' => 'الدفعات', 'amount' => 'المبلغ',
            'reason' => 'السبب', 'notes' => 'الملاحظات', 'confirmed' => 'الموافقة', 'version' => 'نسخة المستند',
            'revision' => 'معاينة المستند', 'invoice_date' => 'تاريخ الفاتورة', 'opening_date' => 'تاريخ بداية البضاعة',
            'opening_balance' => 'رصيد البداية', 'quantity' => 'عدد القطع', 'qty' => 'عدد القطع', 'unit_cost' => 'تكلفة القطعة',
            'selling_price' => 'سعر البيع', 'image' => 'الصورة', 'phone' => 'رقم الهاتف', 'question' => 'السؤال',
            'original_date' => 'تاريخ نشوء الدين الحقيقي', 'tracking_started_on' => 'بداية تتبع التزامات الموردين',
            'posting_date' => 'تاريخ تسجيل القيد المحاسبي', 'evidence' => 'المستند الداعم', 'reference' => 'المرجع',
            'client_uuid' => 'طلب العملية', 'operation_uuid' => 'طلب العملية', 'average_cost' => 'متوسط تكلفة القطعة',
            'phone1' => 'رقم الهاتف الأول', 'phone2' => 'رقم الهاتف الآخر', 'display_name' => 'اسم العرض',
            'type' => 'النوع', 'kind' => 'نوع العملية', 'category' => 'تصنيف المصروف', 'status' => 'الحالة',
            'opening_confirmed' => 'تأكيد طبيعة الدين الافتتاحية', 'unrecorded_confirmed' => 'تأكيد عدم تسجيل الدين سابقًا',
            'refund_confirmed' => 'تأكيد صرف الرصيد', 'below_minimum_confirmed' => 'تأكيد البيع تحت الحد الأدنى',
            'approved_value' => 'القيمة المعتمدة', 'from_account_id' => 'حساب الصرف', 'to_account_id' => 'حساب الاستلام',
            'normal_balance' => 'طبيعة الرصيد', 'account_group' => 'مجموعة الحساب', 'is_posting' => 'السماح بالترحيل',
            'start_date' => 'تاريخ بدء التشغيل', 'actual' => 'المبلغ المعدود فعليًا', 'basis' => 'أساس الإثبات',
            'progressive_opening.counted_at' => 'وقت الجرد', 'progressive_opening.evidence' => 'مرجع إثبات الجرد',
            'progressive_opening.confirmed' => 'تأكيد الرصيد المراجع', 'return_rows.*.quantity' => 'كمية البضاعة المرجعة',
            'return_rows.*.condition' => 'حالة البضاعة المرجعة', 'sale_rows.*.quantity' => 'عدد قطع البضاعة البديلة',
            'sale_rows.*.unit_price' => 'سعر البضاعة البديلة', 'credits.*.amount' => 'مبلغ الرصيد المستخدم',
            'allocations.*.amount' => 'المبلغ المخصص للفاتورة', 'customer_credits.*.amount' => 'الرصيد المقدم المستخدم',
            'slug' => 'عنوان الصفحة', 'seo_title' => 'عنوان ظهور المتجر', 'seo_description' => 'وصف ظهور المتجر',
            'announcement' => 'الإعلان', 'starts_at' => 'بداية العرض', 'ends_at' => 'نهاية العرض',
            'store_section_id' => 'قسم المتجر', 'product_id' => 'المنتج', 'discount_percent' => 'نسبة الخصم',
            'title' => 'العنوان', 'code' => 'الكود', 'color_id' => 'اللون', 'size_id' => 'المقاس', 'category_id' => 'التصنيف',
            'description' => 'الوصف', 'parent_id' => 'القسم الرئيسي', 'sort_order' => 'ترتيب العرض',
            'is_active' => 'حالة التفعيل', 'role_id' => 'الدور الوظيفي', 'permissions' => 'الصلاحيات',
            'address' => 'العنوان', 'password_confirmation' => 'تأكيد كلمة المرور', 'current_password' => 'كلمة المرور الحالية',
            'setup_token' => 'رمز التفعيل', 'minimum_selling_price' => 'الحد الأدنى لسعر البيع',
            'period_start' => 'بداية فترة الاستفادة', 'period_end' => 'نهاية فترة الاستفادة', 'recognition_method' => 'طريقة توزيع المصروف',
            'transaction_date' => 'تاريخ العملية', 'payment_date' => 'تاريخ السداد', 'payee' => 'المستفيد',
            'initial_payment' => 'الدفعة الأولى', 'payment_details.account_id' => 'حساب السداد',
            'payment_details.confirmed' => 'تأكيد التحويل', 'supplier_invoice_number' => 'رقم فاتورة المورد',
            'customer_name' => 'اسم العميل', 'delivery_zone_id' => 'منطقة التوصيل', 'accepted' => 'الموافقة على الشروط',
            'from' => 'بداية الفترة', 'to' => 'نهاية الفترة', 'q' => 'عبارة البحث', 'batch_names' => 'أسماء المقاسات',
            'variants.*.color_id' => 'لون التركيبة', 'variants.*.size_id' => 'مقاس التركيبة',
            'variants.*.selling_price' => 'سعر بيع التركيبة', 'variants.*.minimum' => 'الحد الأدنى للتركيبة',
            'rows.*.cost' => 'تكلفة قطعة الصنف', 'rows.*.price' => 'سعر بيع قطعة الصنف', 'rows.*.name' => 'اسم الصنف',
            'payments.*.account_id' => 'حساب الدفعة', 'payments.*.amount' => 'مبلغ الدفعة',
            'payments.*.confirmed' => 'تأكيد التحويل', 'payments.*.reference' => 'مرجع التحويل',
            'discount.value' => 'قيمة الخصم', 'discount.type' => 'نوع الخصم',
            'rows.*.unit_price' => 'سعر بيع قطعة الصنف',
            'rows.*.quantity' => 'عدد قطع الصنف', 'rows.*.qty' => 'عدد قطع الصنف', 'rows.*.unit_cost' => 'تكلفة قطعة الصنف',
            'rows.*.product_variant_id' => 'لون الصنف ومقاسه', 'rows.*.color_id' => 'اللون', 'rows.*.size_id' => 'المقاس'];
        $lines = [];
        foreach ($messages as $rule => $message) {
            $lines['validation.'.$rule] = $message;
        }
        foreach ($attributes as $field => $label) {
            $lines['validation.attributes.'.$field] = $label;
        }

        return $lines;
    }
}
