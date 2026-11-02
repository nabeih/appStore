<?php

namespace App\Notifications\Account;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetAccountPassword extends ResetPassword
{
    public function toMail(mixed $notifiable): MailMessage
    {
        $url = route('password.reset', ['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()]);

        return (new MailMessage)->subject('إعادة تعيين كلمة المرور — إدارة المتجر')
            ->view('emails.account', ['heading' => 'إعادة تعيين كلمة المرور', 'messageText' => 'وصلنا طلب استرداد حسابك. استخدم الرابط لإنشاء كلمة مرور جديدة. لن نرسل كلمة مرورك بالبريد.',
                'actionLabel' => 'إنشاء كلمة مرور جديدة', 'actionUrl' => $url, 'minutes' => config('auth.passwords.users.expire', 60)]);
    }
}
