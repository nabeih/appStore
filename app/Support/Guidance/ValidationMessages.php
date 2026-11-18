<?php

namespace App\Support\Guidance;

class ValidationMessages
{
    /** @return array<string, string> */
    public static function lines(): array
    {
        $messages = [
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
