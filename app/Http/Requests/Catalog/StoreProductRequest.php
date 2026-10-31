<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'category_id' => [
                'required',
                'integer',
                Rule::exists('categories', 'id'),
            ],

            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('products', 'code'),
            ],

            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'is_active' => [
                'sometimes',
                'boolean',
            ],

            // يجب أن يحتوي المنتج على Variant واحد على الأقل.
            'variants' => [
                'required',
                'array',
                'min:1',
            ],

            'variants.*' => [
                'required',
                'array:color_id,size_id,selling_price,minimum_selling_price',
            ],

            'variants.*.color_id' => [
                'required',
                'integer',
                Rule::exists('colors', 'id'),
            ],

            'variants.*.size_id' => [
                'required',
                'integer',
                Rule::exists('sizes', 'id'),
            ],

            'variants.*.selling_price' => [
                'required',
                'numeric',
                'min:0',
                'decimal:0,2',
            ],

            'variants.*.minimum_selling_price' => [
                'nullable',
                'numeric',
                'min:0',
                'decimal:0,2',
            ],

            // صورة واحدة كحد أقصى لكل لون.
            'images' => [
                'sometimes',
                'array',
            ],

            'images.*' => [
                'required',
                'array:color_id,image',
            ],

            'images.*.color_id' => [
                'required',
                'integer',
                Rule::exists('colors', 'id'),
                'distinct',
            ],

            'images.*.image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'image_path' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $variants = $this->input('variants', []);
                $images = $this->input('images', []);

                // منع تكرار اللون والمقاس داخل المنتج.
                $combinations = [];
                $usedColors = [];

                foreach ($variants as $index => $variant) {
                    $colorId = (int) $variant['color_id'];
                    $sizeId = (int) $variant['size_id'];

                    $key = $colorId.':'.$sizeId;

                    if (isset($combinations[$key])) {
                        $validator->errors()->add(
                            "variants.$index",
                            'لا يمكن تكرار نفس اللون والمقاس للمنتج.'
                        );
                    }

                    $combinations[$key] = true;
                    $usedColors[$colorId] = true;
                }

                // لا نسمح بصورة للون غير موجود في Variants المنتج.
                foreach ($images as $index => $image) {
                    $colorId = (int) $image['color_id'];

                    if (! isset($usedColors[$colorId])) {
                        $validator->errors()->add(
                            "images.$index.color_id",
                            'لون الصورة غير موجود ضمن ألوان المنتج.'
                        );
                    }
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'كود المنتج يجب أن يحتوي على أحرف إنجليزية أو أرقام أو - أو _ فقط.',

            'code.unique' => 'كود المنتج مستخدم مسبقًا.',

            'variants.required' => 'يجب إضافة لون ومقاس واحد على الأقل.',

            'variants.min' => 'يجب إضافة Variant واحد على الأقل.',

            'images.*.color_id.distinct' => 'لا يمكن إضافة أكثر من صورة للون نفسه.',
        ];
    }
}
