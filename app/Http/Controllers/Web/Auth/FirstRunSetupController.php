<?php

namespace App\Http\Controllers\Web\Auth;

use App\Actions\Access\FirstRun\BootstrapOwnerAction;
use App\Actions\Access\FirstRun\InstallationReadiness;
use App\Actions\Account\AccountMail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\FirstRunSetupRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class FirstRunSetupController extends Controller
{
    public function home(InstallationReadiness $readiness): RedirectResponse
    {
        $state = $readiness->state();
        if ($state?->status === 'completed' && $state->completed_at !== null) {
            return redirect()->route('store.home');
        }
        if ($readiness->canBootstrap($state)) {
            return redirect()->route('setup.show');
        }

        abort(503);
    }

    public function create(InstallationReadiness $readiness): View
    {
        $state = $readiness->state();
        $prepared = $state?->status === 'pending' && $state->token_hash !== null && $state->token_expires_at?->isFuture();

        return view('auth.setup', ['prepared' => $prepared, 'expiresAt' => $state?->token_expires_at]);
    }

    public function store(FirstRunSetupRequest $request, BootstrapOwnerAction $bootstrap, AccountMail $mail): RedirectResponse
    {
        $owner = $bootstrap->execute($request->validated());
        Auth::guard('web')->login($owner);
        $request->session()->regenerate();
        $request->session()->forget('url.intended');

        $sent = $mail->verification($owner);

        return redirect()->route('onboarding.branch')->with('status', $sent
            ? 'اكتمل حساب المالك وأرسلنا رابط تأكيد بريدك. أكمل إعداد متجرك.'
            : 'اكتمل حساب المالك. تعذر إرسال رسالة التحقق حاليًا؛ يمكنك إعداد الفرع وإعادة الإرسال لاحقًا.');
    }
}
