<?php

namespace App\Http\Requests\Suppliers;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreSupplierDebtSettlementRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('finance.transition_review') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'client_uuid' => ['required', 'uuid'], 'branch_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'string', 'regex:/\A\d{1,12}(?:\.\d{1,2})?\z/'],
            'original_date' => ['required', 'date_format:Y-m-d', 'before:tracking_started_on'],
            'tracking_started_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'posting_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:tracking_started_on', 'before_or_equal:today'],
            'reference' => ['required', 'string', 'min:3', 'max:150'], 'notes' => ['required', 'string', 'min:10', 'max:2000'],
            'evidence' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $original = preg_match('/\A\d{4}-\d{2}-\d{2}\z/', (is_string($this->input('original_date')) ? $this->input('original_date') : '')) ? $this->input('original_date') : 'غير صحيح';
        $tracking = preg_match('/\A\d{4}-\d{2}-\d{2}\z/', (is_string($this->input('tracking_started_on')) ? $this->input('tracking_started_on') : '')) ? $this->input('tracking_started_on') : 'غير صحيح';

        return [
            'original_date.before' => "تاريخ نشوء الدين ({$original}) يجب أن يسبق بداية تتبع التزامات الموردين ({$tracking}). صحّح التاريخين وفق المستندات الفعلية؛ تاريخ تسجيل القيد يمكن أن يكون لاحقًا.",
            'posting_date.after_or_equal' => 'تاريخ تسجيل القيد المحاسبي يجب أن يوافق بداية تتبع التزامات الموردين أو يأتي بعدها. أبقِ تاريخ نشوء الدين الحقيقي في حقله المستقل.',
            'tracking_started_on.before_or_equal' => 'بداية تتبع التزامات الموردين لا يمكن أن تكون في المستقبل. اختر تاريخ البداية الفعلي.',
        ];
    }
}
