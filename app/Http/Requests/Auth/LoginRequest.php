<?php

namespace App\Http\Requests\Auth;

use Closure;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('username'))) {
            $this->merge(['username' => mb_strtolower(trim($this->input('username')))]);
        }
    }

    /** @return array<string, array<int, string|Closure>> */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'max:72', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && (strlen($value) > 72 || str_contains($value, "\0"))) {
                    $fail('كلمة المرور غير صالحة.');
                }
            }],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'username.required' => 'أدخل اسم المستخدم.',
            'username.string' => 'اسم المستخدم غير صالح.',
            'username.max' => 'اسم المستخدم أطول من الحد المسموح.',
            'password.required' => 'أدخل كلمة المرور.',
            'password.string' => 'كلمة المرور غير صالحة.',
            'password.max' => 'كلمة المرور أطول من الحد المسموح.',
        ];
    }

    public function authenticate(): void
    {
        $accountKey = 'login:account:'.hash('sha256', $this->string('username').'|'.$this->ip());
        $addressKey = 'login:address:'.hash('sha256', (string) $this->ip());

        foreach ([$accountKey => 5, $addressKey => 30] as $key => $limit) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                event(new Lockout($this));
                $seconds = RateLimiter::availableIn($key);

                throw ValidationException::withMessages([
                    'username' => "محاولات دخول كثيرة. حاول مجددًا بعد {$seconds} ثانية.",
                ])->status(429);
            }
        }

        if (! Auth::guard('web')->attempt([
            'username' => $this->string('username')->toString(),
            'password' => $this->input('password'),
            'is_active' => true,
        ])) {
            RateLimiter::hit($accountKey, 60);
            RateLimiter::hit($addressKey, 60);

            throw ValidationException::withMessages([
                'username' => 'بيانات الدخول غير صحيحة أو الحساب غير متاح.',
            ]);
        }

        RateLimiter::clear($accountKey);
    }
}
