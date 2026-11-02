<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Access\ResetEmployeePasswordRequest;

class ResetPasswordRequest extends ForgotPasswordRequest
{
    public function rules(): array
    {
        return parent::rules() + ['token' => ['required', 'string', 'max:255'],
            'password' => ResetEmployeePasswordRequest::passwordRules(),
            'role_id' => ['prohibited'], 'branch_id' => ['prohibited'], 'is_active' => ['prohibited']];
    }
}
