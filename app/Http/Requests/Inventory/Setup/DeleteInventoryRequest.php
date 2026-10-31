<?php

namespace App\Http\Requests\Inventory\Setup;

use Illuminate\Foundation\Http\FormRequest;

class DeleteInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) config('inventory.allow_setup_changes') && ($this->user()?->can('inventory.correct_setup') ?? false) && $this->user()->can('sales.view_cost');
    }

    public function rules(): array
    {
        return [
            'revision' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'revision.required' => 'حدّث جدول المخزون ثم أعد المحاولة.',
            'revision.size' => 'بيانات السطر غير صالحة. حدّث الصفحة.',
            'revision.regex' => 'بيانات السطر غير صالحة. حدّث الصفحة.',
            'reason.max' => 'الملاحظة يجب ألا تزيد عن 500 حرف.',
        ];
    }
}
