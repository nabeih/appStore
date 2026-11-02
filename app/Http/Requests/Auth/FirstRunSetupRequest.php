<?php

namespace App\Http\Requests\Auth;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class FirstRunSetupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() === null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'username' => is_string($this->input('username')) ? Str::lower(trim($this->input('username'))) : $this->input('username'),
            'email' => is_string($this->input('email')) ? (trim($this->input('email')) ?: null) : $this->input('email'),
        ]);
    }

    public function rules(): array
    {
        return [
            'setup_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/\A[\p{L}\p{N}][\p{L}\p{N}\p{M}._-]*\z/u', Rule::unique('users', 'username')],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:4', 'confirmed', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && (strlen($value) > 72 || str_contains($value, "\0"))) {
                    $fail('كلمة المرور يجب ألا تتجاوز 72 بايت وألا تحتوي محارف فارغة.');
                }
            }],
            'role_id' => ['prohibited'], 'branch_id' => ['prohibited'], 'is_active' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'setup_token.required' => 'أدخل رمز الإعداد الذي سلّمه لك مسؤول النشر.',
            'setup_token.regex' => 'صيغة رمز الإعداد غير صحيحة.',
            'name.required' => 'أدخل اسمك.', 'name.min' => 'الاسم من حرفين على الأقل.',
            'username.required' => 'أدخل اسم المستخدم.', 'username.regex' => 'استخدم حروفًا عربية أو إنجليزية وأرقامًا ونقطة أو شرطة.',
            'username.min' => 'اسم المستخدم من 3 أحرف على الأقل.', 'username.unique' => 'اسم المستخدم مستخدم بالفعل.',
            'email.required' => 'البريد الإلكتروني ضروري لحماية حسابك واستعادته.', 'email.email' => 'أدخل بريدًا إلكترونيًا صحيحًا.', 'email.unique' => 'البريد مستخدم بالفعل.',
            'password.required' => 'أدخل كلمة المرور.', 'password.min' => 'كلمة المرور من 4 أحرف على الأقل.',
            'password.confirmed' => 'تأكيد كلمة المرور غير مطابق.',
        ];
    }
}
