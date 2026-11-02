<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function messages(): array
    {
        return ['required' => 'أكمل الحقل المطلوب: :attribute.', 'accepted' => 'راجع سياسة الطلب ورسوم رفض الاستلام ووافق عليها قبل إرسال الطلب.',
            'rows.min' => 'أضف قطعة واحدة على الأقل إلى الحقيبة.', 'distinct' => 'الصنف مكرر؛ عدّل كميته في الحقيبة.', 'regex' => 'راجع صيغة الحقل :attribute.'];
    }

    public function rules(): array
    {
        $base = ['delivery_zone_id' => ['required', 'integer', 'min:1'], 'rows' => ['required', 'array', 'list', 'min:1', 'max:50'],
            'rows.*' => ['required', 'array:product_variant_id,quantity'],
            'rows.*.product_variant_id' => ['required', 'integer', 'min:1', 'distinct'],
            'rows.*.quantity' => ['required', 'integer', 'min:1', 'max:100']];
        if ($this->routeIs('store.quote')) {
            return $base;
        }

        return $base + ['client_uuid' => ['required', 'uuid'], 'customer_name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'min:7', 'max:40', 'regex:/\\A[+0-9 ()-]+\\z/'],
            'address' => ['required', 'string', 'min:5', 'max:2000'], 'notes' => ['nullable', 'string', 'max:1000'],
            'accepted' => ['accepted'], 'policy_version' => ['required', 'string', 'size:64'], 'quote_revision' => ['required', 'string', 'size:64']];
    }
}
