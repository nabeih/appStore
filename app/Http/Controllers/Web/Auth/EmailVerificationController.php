<?php

namespace App\Http\Controllers\Web\Auth;

use App\Actions\Account\AccountMail;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class EmailVerificationController extends Controller
{
    public function notice(): View
    {
        return view('auth.verify-email');
    }

    public function send(Request $request, AccountMail $mail): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return back()->with('status', 'بريدك الإلكتروني مؤكد بالفعل.');
        }

        return back()->with('status', $mail->verification($request->user())
            ? 'أرسلنا رابط التحقق إلى بريدك الإلكتروني.'
            : 'تعذر إرسال رسالة التحقق حاليًا. تحقق من إعداد البريد ثم حاول مجددًا.');
    }

    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless(filled($user->email) && hash_equals(sha1($user->email), (string) $request->route('hash')), 403);
            $request->setUserResolver(fn (): User => $user);
            $request->fulfill();
        }, 3);

        return redirect()->route('dashboard.index')->with('status', 'تم تأكيد بريدك الإلكتروني بنجاح.');
    }
}
