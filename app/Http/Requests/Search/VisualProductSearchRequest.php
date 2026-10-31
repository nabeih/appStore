<?php

namespace App\Http\Requests\Search;

class VisualProductSearchRequest extends ProductSearchRequest
{
    public function rules(): array
    {
        return ['branch_id' => ['required', 'integer', 'min:1'],
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:max_width=4096,max_height=4096']];
    }

    public function messages(): array
    {
        return ['image.required' => 'اختر صورة ثابتة بعد تأكيدك.', 'image.image' => 'الملف ليس صورة صالحة.',
            'image.mimes' => 'الصور المقبولة JPG وPNG وWebP.', 'image.max' => 'الحد الأقصى لصورة البحث 4 ميجابايت.',
            'image.dimensions' => 'أبعاد الصورة أكبر من حدود البحث.'];
    }
}
