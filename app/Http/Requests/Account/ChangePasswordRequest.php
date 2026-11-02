<?php

namespace App\Http\Requests\Account;

use App\Http\Requests\Access\ResetEmployeePasswordRequest;
use Illuminate\Foundation\Http\FormRequest;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['current_password' => ['required', 'string', 'max:255'], 'password' => ResetEmployeePasswordRequest::passwordRules(),
            'role_id' => ['prohibited'], 'branch_id' => ['prohibited'], 'is_active' => ['prohibited'], 'permissions' => ['prohibited']];
    }
}
