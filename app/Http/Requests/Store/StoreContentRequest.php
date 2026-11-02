<?php

namespace App\Http\Requests\Store;

use App\Actions\Store\ManageStoreContent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('store.manage') ?? false) && $this->user()->can('sales.all_branches');
    }

    public function rules(): array
    {
        $type = (string) $this->route('type');
        abort_unless(isset(ManageStoreContent::TYPES[$type]), 404);
        $id = $this->route('record');
        $money = ['nullable', 'regex:/\\A\\d{1,10}(?:\\.\\d{1,2})?\\z/'];
        $name = ['required', 'string', 'max:255'];
        $seo = ['seo_title' => ['nullable', 'string', 'max:255'], 'seo_description' => ['nullable', 'string', 'max:1000']];
        $base = ['description' => ['nullable', 'string', 'max:10000'], 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'], 'is_active' => ['sometimes', 'boolean']];
        $table = (new (ManageStoreContent::TYPES[$type]))->getTable();
        $slug = ['required', 'string', 'max:150', 'regex:/\\A[\\pL\\pN_-]+\\z/u', Rule::unique($table, 'slug')->ignore($id)];

        return match ($type) {
            'settings' => $seo + ['name' => $name, 'phone' => ['nullable', 'string', 'max:40'], 'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('is_active', true)],
                'policy' => ['required', 'string', 'min:20', 'max:15000'], 'is_open' => ['sometimes', 'boolean']],
            'products' => $seo + ['product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at'), Rule::unique($table, 'product_id')->ignore($id)],
                'store_section_id' => ['nullable', 'integer', 'exists:store_sections,id'], 'slug' => $slug, 'badge' => ['nullable', 'string', 'max:80'],
                'description' => $base['description'], 'sort_order' => $base['sort_order'], 'is_published' => ['sometimes', 'boolean']],
            'sections' => $seo + $base + ['name' => $name, 'slug' => $slug, 'category_id' => ['nullable', 'integer', 'exists:categories,id']],
            'banners' => $base + ['title' => $name, 'store_section_id' => ['nullable', 'integer', 'exists:store_sections,id'], 'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:max_width=6000,max_height=6000']],
            'zones' => ['name' => [...$name, Rule::unique($table, 'name')->ignore($id)], 'fee' => ['required', ...array_slice($money, 1)],
                'courier_delivery' => $money, 'courier_refusal' => $money, 'is_active' => ['sometimes', 'boolean']],
            'offers' => ['name' => $name, 'type' => ['required', Rule::in(['product', 'delivery'])], 'discount_percent' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
                'minimum' => ['required', ...array_slice($money, 1)], 'minimum_inclusive' => ['sometimes', 'boolean'],
                'zone_ids' => ['nullable', 'array', 'max:200'], 'zone_ids.*' => ['integer', 'distinct', 'exists:delivery_zones,id'],
                'product_ids' => ['nullable', 'array', 'max:300'], 'product_ids.*' => ['integer', 'distinct', 'exists:products,id'],
                'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'], 'is_active' => ['sometimes', 'boolean']],
        };
    }
}
