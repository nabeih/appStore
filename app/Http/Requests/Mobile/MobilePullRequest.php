<?php

namespace App\Http\Requests\Mobile;

use Illuminate\Foundation\Http\FormRequest;

class MobilePullRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['schema_version' => ['sometimes', 'integer', 'in:1'], 'cursor' => ['nullable', 'string', 'max:4096'], 'branch_id' => ['required_without:cursor', 'integer', 'min:1'],
            'sync_uuid' => ['required_without:cursor', 'uuid'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:200']];
    }
}
