<?php

namespace App\Http\Requests\Purchases;

use App\Actions\Catalog\VisibleProductsQuery;
use App\Support\Access\AccessPermissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePurchaseProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchases.manage') && $this->user()->can('inventory.manage_catalog') && $this->user()->can('sales.view_cost');
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $validator->errors()->has('product_id') && filled($this->input('product_id'))
                && ! app(VisibleProductsQuery::class)->execute($this->user())->whereKey($this->input('product_id'))->exists()) {
                $validator->errors()->add('product_id', 'المنتج غير متاح ضمن صلاحياتك؛ اختر منتجًا مسموحًا من البحث.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    public function rules(): array
    {
        return ['product_id' => ['nullable', 'integer', 'min:1'], 'name' => ['required_without:product_id', 'nullable', 'string', 'max:200'],
            'code' => ['nullable', 'string', 'max:50', 'regex:/\A[A-Z0-9_-]+\z/'],
            'category_id' => ['required_without:product_id', 'nullable', 'integer', 'min:1'],
            'colors' => ['required', 'array', 'list', 'min:1', 'max:20'], 'colors.*' => ['required', 'integer', 'min:1', 'distinct:strict'],
            'sizes' => ['required', 'array', 'list', 'min:1', 'max:15'], 'sizes.*' => ['required', 'integer', 'min:1', 'distinct:strict'],
            'images' => ['sometimes', 'array', 'list', 'max:20'],
            'images.*' => ['required', 'array:color_id,image'], 'images.*.color_id' => ['required', 'integer', 'min:1', 'distinct'],
            'images.*.image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'minimum_selling_price' => [Rule::prohibitedIf(fn (): bool => ! AccessPermissions::canManageMinimumPrices($this->user())), 'nullable', 'regex:/\A\d{1,7}(?:\.\d{1,2})?\z/', 'lte:selling_price'],
            'selling_price' => ['required', 'numeric', 'min:0', 'regex:/\A\d{1,7}(\.\d{1,2})?\z/']];
    }

    public function messages(): array
    {
        return ['images.*.color_id.distinct' => 'أرفق صورة واحدة فقط لكل لون.', 'images.*.image.required' => 'لم تصل صورة اللون. أعد إرفاقها.', 'images.*.image.max' => 'حجم الصورة يجب ألا يتجاوز 4 ميجابايت.', 'images.*.image.image' => 'الملف يجب أن يكون صورة صحيحة.', 'images.*.image.mimes' => 'الصيغ المسموحة: JPG وPNG وWebP.', 'required' => 'حقل :attribute مطلوب.', 'required_without' => 'حقل :attribute مطلوب للمنتج الجديد.', 'code.regex' => 'الكود يقبل الحروف الإنجليزية والأرقام والشرطة فقط.',
            'selling_price.regex' => 'أدخل سعر بيع صالحًا بمنزلتين عشريتين كحد أقصى.'];
    }
}
