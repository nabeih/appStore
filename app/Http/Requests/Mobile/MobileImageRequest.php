<?php

namespace App\Http\Requests\Mobile;

use Illuminate\Foundation\Http\FormRequest;

class MobileImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.manage_catalog') ?? false;
    }

    public function rules(): array
    {
        return ['operation_uuid' => ['required', 'uuid'], 'branch_id' => ['required', 'integer', 'min:1'],
            'product_id' => ['required', 'integer', 'min:1'], 'color_id' => ['required', 'integer', 'min:1'],
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:max_width=6000,max_height=6000'],
            'schema_version' => ['required', 'integer', 'in:1']];
    }
}
