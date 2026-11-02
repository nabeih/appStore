<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'username', 'email'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $this->merge([$field => $field === 'name' ? trim($value) : Str::lower(trim($value))]);
            }
        }
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'min:2', 'max:255'],
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/\A[\p{L}\p{N}][\p{L}\p{N}\p{M}._-]*\z/u', Rule::unique('users')->ignore($this->user()->id)],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($this->user()->id)],
            'current_password' => ['nullable', 'string', 'max:255'],
            'role_id' => ['prohibited'], 'branch_id' => ['prohibited'], 'is_active' => ['prohibited'],
            'permissions' => ['prohibited'], 'email_verified_at' => ['prohibited'], 'status' => ['prohibited']];
    }

    public function attributes(): array
    {
        return ['name' => 'الاسم', 'username' => 'اسم المستخدم', 'email' => 'البريد الإلكتروني'];
    }
}
