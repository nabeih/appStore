<?php

namespace App\Actions\Account;

use App\Models\User;
use App\Notifications\Account\VerifyAccountEmail;
use Illuminate\Notifications\Notification;
use RuntimeException;
use Throwable;

class AccountMail
{
    public function assertConfigured(): void
    {
        $mailer = config('mail.default');
        $transport = config('mail.mailers.'.$mailer.'.transport');
        if (! in_array($transport, ['smtp', 'sendmail', 'ses', 'ses-v2', 'postmark', 'resend', 'mailgun'], true)) {
            throw new RuntimeException('Account security mail requires a real non-logging transport.');
        }
        if (app()->environment('production') && parse_url(config('app.url'), PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('Account mail requires HTTPS in production.');
        }
    }

    public function send(User $user, Notification $notification): bool
    {
        try {
            $this->assertConfigured();
            $user->notify($notification);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function verification(User $user): bool
    {
        return filled($user->email) && ! $user->hasVerifiedEmail()
            && $this->send($user, new VerifyAccountEmail);
    }
}
