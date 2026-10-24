<?php

namespace App\Console\Commands\Auth;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CreateUserCommand extends Command
{
    protected $signature = 'app:create-user';

    protected $description = 'Create an active user using a hidden password prompt';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('شغّل الأمر تفاعليًا لإدخال كلمة المرور بشكل مخفي.');

            return self::FAILURE;
        }

        $data = [
            'name' => trim((string) $this->ask('الاسم')),
            'username' => Str::lower(trim((string) $this->ask('اسم المستخدم (حروف عربية أو إنجليزية وأرقام)'))),
            'email' => trim((string) $this->ask('البريد الإلكتروني (اختياري)')) ?: null,
            'password' => $this->secret('كلمة المرور (4 أحرف على الأقل)', false),
            'password_confirmation' => $this->secret('تأكيد كلمة المرور', false),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/\A[\p{L}\p{N}][\p{L}\p{N}\p{M}._-]*\z/u', Rule::unique('users', 'username')],
            'email' => ['nullable', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'confirmed', 'min:4',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && (strlen($value) > 72 || str_contains($value, "\0"))) {
                        $fail('كلمة المرور يجب ألا تتجاوز 72 بايت وألا تحتوي على محارف فارغة.');
                    }
                }],
        ], [
            'username.unique' => 'اسم المستخدم مستخدم بالفعل، ولم يتم تعديل الحساب الموجود.',
            'username.regex' => 'استخدم حروفًا عربية أو إنجليزية وأرقامًا ونقطة أو شرطة لاسم المستخدم.',
            'email.unique' => 'البريد الإلكتروني مستخدم بالفعل.',
            'password.min' => 'كلمة المرور يجب أن تكون 4 أحرف على الأقل.',
            'password.confirmed' => 'تأكيد كلمة المرور غير مطابق.',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        try {
            User::create(collect($validator->validated())->except('password_confirmation')->all());
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                $this->error('اسم المستخدم أو البريد مستخدم بالفعل. لم يتم تعديل أي حساب.');

                return self::FAILURE;
            }

            throw $exception;
        }

        $this->info('تم إنشاء الحساب. يمكنك تسجيل الدخول الآن باسم المستخدم الذي أدخلته.');

        return self::SUCCESS;
    }
}
