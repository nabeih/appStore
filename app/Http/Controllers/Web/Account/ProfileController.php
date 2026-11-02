<?php

namespace App\Http\Controllers\Web\Account;

use App\Actions\Account\AccountMail;
use App\Actions\Account\ChangePasswordAction;
use App\Actions\Account\UpdateProfileAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ChangePasswordRequest;
use App\Http\Requests\Account\ProfileRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function show(Request $request): View
    {
        return view('account.profile', ['user' => $request->user()->loadMissing('role', 'branch')]);
    }

    public function update(ProfileRequest $request, UpdateProfileAction $update, AccountMail $mail): RedirectResponse
    {
        $emailChanged = $request->user()->email !== $request->validated('email');
        $user = $update->execute($request->user(), $request->validated());
        $status = 'تم تحديث بيانات حسابك.';
        if ($emailChanged) {
            $status .= $mail->verification($user) ? ' أرسلنا رابط تأكيد البريد الجديد.' : ' البريد الجديد غير مؤكد. تعذر إرسال رسالة التحقق؛ يمكنك إعادة المحاولة بعد تجهيز البريد.';
        }

        return redirect()->route('profile.show')->with('status', $status);
    }

    public function password(ChangePasswordRequest $request, ChangePasswordAction $change): RedirectResponse
    {
        $user = $change->execute($request->user(), $request->validated('current_password'), $request->validated('password'), $request->session()->getId());
        Auth::guard('web')->setUser($user);
        $request->session()->put('password_hash_web', $user->getAuthPassword());
        $request->session()->regenerate();

        return redirect()->route('profile.show')->with('status', 'تم تغيير كلمة المرور وإنهاء الجلسات الأخرى.');
    }
}
