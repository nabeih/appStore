<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.manage_catalog') ?? false;
    }

    public function rules(): array
    {
        $productId = $this->route('id');

        return [
            'category_id' => ['sometimes', 'required', 'integer', 'exists:categories,id'],

            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('products', 'code')->ignore($productId),
            ],

            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'image' => ['sometimes', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'image_color_id' => ['required_with:image', 'integer', 'min:1'],
            'description' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],

            'variants' => ['sometimes', 'array'],

            'variants.*.id' => [
                'sometimes',
                'integer',
                Rule::exists('product_variants', 'id')
                    ->where('product_id', $productId),
            ],

            'variants.*.color_id' => [
                'required',
                'integer',
                'exists:colors,id',
            ],

            'variants.*.size_id' => [
                'required',
                'integer',
                'exists:sizes,id',
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

            'variants.*.is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $variants = $this->input('variants', []);
                $pairs = [];
                $ids = [];

                if (! is_array($variants)) {
                    return;
                }

                foreach ($variants as $index => $variant) {
                    if (! is_array($variant)) {
                        continue;
                    }

                    if (isset($variant['id'])) {
                        if (in_array($variant['id'], $ids)) {
                            $validator->errors()->add(
                                "variants.$index.id",
                                'لا يمكن تكرار نفس الـVariant في الطلب.'
                            );
                        }

                        $ids[] = $variant['id'];
                    }

                    if (! isset($variant['color_id'], $variant['size_id'])) {
                        continue;
                    }

                    $key = $variant['color_id'].'-'.$variant['size_id'];

                    if (in_array($key, $pairs)) {
                        $validator->errors()->add(
                            "variants.$index",
                            'لا يمكن تكرار نفس اللون والمقاس للمنتج.'
                        );
                    }

                    $pairs[] = $key;
                }
            },
        ];
    }
}
