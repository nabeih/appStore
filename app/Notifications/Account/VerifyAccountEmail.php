<?php

namespace App\Notifications\Account;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

class VerifyAccountEmail extends VerifyEmail
{
    protected function buildMailMessage(mixed $url): MailMessage
    {
        return (new MailMessage)->subject('تأكيد بريدك الإلكتروني — إدارة المتجر')
            ->view('emails.account', ['heading' => 'تأكيد بريدك الإلكتروني', 'messageText' => 'أكد ملكيتك لهذا البريد لحماية حسابك واستعادته عند الحاجة.',
                'actionLabel' => 'تأكيد البريد الإلكتروني', 'actionUrl' => $url, 'minutes' => config('auth.verification.expire', 60)]);
    }
}
