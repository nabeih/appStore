<?php

namespace App\Http\Controllers\Web\Auth;

use App\Actions\Account\RecoverPasswordAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PasswordRecoveryController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function send(ForgotPasswordRequest $request, RecoverPasswordAction $recovery): RedirectResponse
    {
        $recovery->send($request->validated('email'));

        return back()->with('status', 'إذا كان البريد مرتبطًا بحساب مؤهل، فستصلك رسالة برابط استرداد مؤقت. راجع البريد غير الهام أيضًا.');
    }

    public function edit(Request $request, string $token): View
    {
        abort_unless(strlen($token) <= 255, 404);

        return view('auth.reset-password', ['token' => $token, 'email' => is_string($request->query('email')) ? $request->query('email') : '']);
    }

    public function update(ResetPasswordRequest $request, RecoverPasswordAction $recovery): RedirectResponse
    {
        if (! $recovery->reset($request->safe()->only(['email', 'token', 'password', 'password_confirmation']))) {
            return back()->withErrors(['email' => 'رابط الاسترداد غير صالح أو انتهت صلاحيته. اطلب رابطًا جديدًا.']);
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'تم تغيير كلمة المرور. سجّل الدخول بكلمة المرور الجديدة.');
    }
}
