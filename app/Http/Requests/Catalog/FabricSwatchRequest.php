<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class FabricSwatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.manage_catalog') ?? false;
    }

    public function rules(): array
    {
        if ($this->isMethod('DELETE')) {
            return ['revision' => ['required', 'string', 'size:64']];
        }

        return [
            'revision' => ['present', 'nullable', 'string', 'size:64'],
            'mode' => ['required', 'in:upload,crop'],
            'image' => ['required_if:mode,upload', 'prohibited_unless:mode,upload', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'source_image_id' => ['required_if:mode,crop', 'prohibited_unless:mode,crop', 'integer', 'min:1'],
            'source_revision' => ['required_if:mode,crop', 'prohibited_unless:mode,crop', 'string', 'size:64'],
            'x' => ['required', 'integer', 'min:0', 'max:16000000'],
            'y' => ['required', 'integer', 'min:0', 'max:16000000'],
            'size' => ['required', 'integer', 'min:1', 'max:16000000'],
            'image_path' => ['prohibited'], 'product_id' => ['prohibited'], 'color_id' => ['prohibited'], 'size_id' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'image.required_if' => 'اختر صورة القماش أولًا.', 'image.image' => 'الملف ليس صورة صالحة.',
            'image.mimes' => 'الصيغ المسموحة JPG وPNG وWebP.', 'image.max' => 'الصورة أكبر من 4 ميجابايت.',
            'source_image_id.required_if' => 'اختر صورة المنتج واللون نفسها للقص.',
            'revision.present' => 'حدّث عينة اللون قبل الحفظ.',
            'size.min' => 'اختر مساحة مربعة صالحة للقص.',
            'mode.required' => 'اختر رفع صورة أو القص من صورة المنتج.', 'mode.in' => 'طريقة إنشاء العينة غير صالحة.',
            'revision.size' => 'حدّث المعاينة؛ نسخة العينة غير صالحة.',
            'source_image_id.integer' => 'معرّف الصورة المصدر غير صالح.',
            'source_revision.required_if' => 'أعد فتح الصورة المصدر قبل القص.',
            'source_revision.size' => 'نسخة الصورة المصدر غير صالحة؛ حدّث المعاينة.',
            'x.required' => 'حدد موضع مربع القص.', 'y.required' => 'حدد موضع مربع القص.',
            'x.integer' => 'إحداثيات القص يجب أن تكون أعدادًا صحيحة.', 'y.integer' => 'إحداثيات القص يجب أن تكون أعدادًا صحيحة.',
            'x.min' => 'مربع القص خارج الصورة.', 'y.min' => 'مربع القص خارج الصورة.',
            'x.max' => 'إحداثيات القص تتجاوز الحدود المسموحة.', 'y.max' => 'إحداثيات القص تتجاوز الحدود المسموحة.',
            'size.required' => 'حدد حجم مربع القص.', 'size.integer' => 'حجم القص يجب أن يكون عددًا صحيحًا.',
            'size.max' => 'حجم القص يتجاوز الحدود المسموحة.',
            'image_path.prohibited' => 'لا يقبل النظام مسار ملف من المتصفح.',
        ];
    }
}
