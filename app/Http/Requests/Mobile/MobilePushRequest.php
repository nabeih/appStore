<?php

namespace App\Http\Requests\Mobile;

use Illuminate\Foundation\Http\FormRequest;

class MobilePushRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['schema_version' => ['required', 'integer', 'in:1'], 'operations' => ['required', 'array', 'list', 'min:1', 'max:20'],
            'operations.*' => ['required', 'array:operation_uuid,operation_type,client_created_at,payload,dependencies,branch_id'],
            'operations.*.operation_uuid' => ['required', 'uuid', 'distinct'],
            'operations.*.operation_type' => ['required', 'in:product.create,product.update,opening_stock.create,purchase.create,sale.create'],
            'operations.*.client_created_at' => ['required', 'date', 'before_or_equal:now'],
            'operations.*.branch_id' => ['required', 'integer', 'min:1'],
            'operations.*.payload' => ['required', 'array'],
            'operations.*.dependencies' => ['present', 'array', 'list', 'max:20'],
            'operations.*.dependencies.*' => ['required', 'uuid', 'distinct']];
    }
}
