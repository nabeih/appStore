<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductColorImagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['images' => ['required', 'array', 'list', 'min:1', 'max:20'],
            'images.*' => ['required', 'array:color_id,image'], 'images.*.color_id' => ['required', 'integer', 'min:1', 'distinct'],
            'images.*.image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096']];
    }

    public function messages(): array
    {
        return ['images.*.color_id.distinct' => 'أرفق صورة واحدة فقط لكل لون.', 'images.*.image.required' => 'لم تصل صورة اللون. أعد اختيارها ورفعها.',
            'images.*.image.image' => 'يجب إرفاق صورة صحيحة.', 'images.*.image.mimes' => 'الصيغ المسموحة: JPG وPNG وWebP.',
            'images.*.image.max' => 'حجم الصورة يجب ألا يتجاوز 4 ميجابايت.', 'images.*.image.uploaded' => 'تعذر رفع الصورة. تحقق من حجمها ثم أعد المحاولة.'];
    }
}
