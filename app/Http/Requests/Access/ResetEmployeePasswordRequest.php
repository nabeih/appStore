<?php

namespace App\Http\Requests\Access;

use App\Actions\Access\ManageAccessAction;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class ResetEmployeePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! ($this->user()?->can('access.users.manage') ?? false)) {
            return false;
        }
        app(ManageAccessAction::class)->authorizeEmployee($this->user(), $this->route('user'));

        return true;
    }

    /** @return list<string|Closure> */
    public static function passwordRules(): array
    {
        return ['required', 'string', 'confirmed', 'min:4', 'max:72',
            function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && (strlen($value) > 72 || str_contains($value, "\0"))) {
                    $fail('كلمة المرور يجب ألا تتجاوز 72 بايت وألا تحتوي على محارف فارغة.');
                }
            }];
    }

    public function rules(): array
    {
        return ['password' => self::passwordRules(), 'password_confirmation' => ['required', 'string'],
            'role_id' => ['prohibited'], 'branch_id' => ['prohibited'], 'is_active' => ['prohibited'], 'permissions' => ['prohibited']];
    }

    public function messages(): array
    {
        return ['password.required' => 'أدخل كلمة المرور الجديدة.', 'password.string' => 'كلمة المرور غير صالحة.',
            'password.min' => 'كلمة المرور يجب أن تكون 4 أحرف على الأقل.', 'password.max' => 'كلمة المرور تتجاوز الحد المسموح.',
            'password.confirmed' => 'تأكيد كلمة المرور غير مطابق.', 'password_confirmation.required' => 'أكد كلمة المرور الجديدة.',
            'role_id.prohibited' => 'إعادة تعيين كلمة المرور لا تغير الدور.', 'branch_id.prohibited' => 'إعادة تعيين كلمة المرور لا تغير الفرع.'];
    }
}
