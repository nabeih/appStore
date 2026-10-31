<?php

namespace App\Http\Requests\Access;

use App\Actions\Access\ManageAccessAction;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SaveEmployeeRequest extends AssignUserRoleRequest
{
    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }
        $employee = $this->route('user');
        if ($employee instanceof User) {
            app(ManageAccessAction::class)->authorizeEmployee($this->user(), $employee);
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'username', 'email'] as $field) {
            if (is_string($this->input($field))) {
                $value = trim($this->input($field));
                $this->merge([$field => $field === 'name' ? $value : ($field === 'email' && $value === '' ? null : Str::lower($value))]);
            }
        }
    }

    public function rules(): array
    {
        $employee = $this->route('user');
        $id = $employee instanceof User ? $employee->id : null;

        return array_replace(parent::rules(), [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/\\A[\\p{L}\\p{N}][\\p{L}\\p{N}\\p{M}._-]*\\z/u', Rule::unique('users', 'username')->ignore($id)],
            'email' => ['nullable', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($id)],
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('is_active', true)],
            'password' => $this->isMethod('POST') ? ResetEmployeePasswordRequest::passwordRules() : ['prohibited'],
            'password_confirmation' => $this->isMethod('POST') ? ['required', 'string'] : ['prohibited'],
            'id' => ['prohibited'], 'remember_token' => ['prohibited'], 'deleted_at' => ['prohibited'],
        ]);
    }

    public function messages(): array
    {
        return array_replace(parent::messages(), [
            'name.required' => 'أدخل اسم الموظف.', 'name.max' => 'الاسم يجب ألا يتجاوز 255 حرفًا.',
            'username.required' => 'أدخل اسم المستخدم.', 'username.min' => 'اسم المستخدم يجب أن يكون 3 أحرف على الأقل.',
            'username.max' => 'اسم المستخدم يجب ألا يتجاوز 50 حرفًا.',
            'username.regex' => 'استخدم حروفًا عربية أو إنجليزية وأرقامًا ونقطة أو شرطة لاسم المستخدم.',
            'username.unique' => 'اسم المستخدم مستخدم بالفعل.', 'email.unique' => 'البريد الإلكتروني مستخدم بالفعل.',
            'email.email' => 'أدخل بريدًا إلكترونيًا صالحًا أو اتركه فارغًا.', 'branch_id.required' => 'اختر فرع الموظف.',
            'password.required' => 'أدخل كلمة المرور الأولية.', 'password.min' => 'كلمة المرور يجب أن تكون 4 أحرف على الأقل.',
            'password.max' => 'كلمة المرور تتجاوز الحد المسموح.', 'password.confirmed' => 'تأكيد كلمة المرور غير مطابق.',
            'password_confirmation.required' => 'أكد كلمة المرور الأولية.',
            'password.prohibited' => 'غيّر كلمة المرور من نموذج إعادة التعيين المنفصل.',
        ]);
    }
}
