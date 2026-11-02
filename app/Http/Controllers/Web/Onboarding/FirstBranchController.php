<?php

namespace App\Http\Controllers\Web\Onboarding;

use App\Actions\Access\FirstRun\CompleteFirstBranchAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\FirstBranchRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class FirstBranchController extends Controller
{
    public function create(): View
    {
        return view('onboarding.branch');
    }

    public function store(FirstBranchRequest $request, CompleteFirstBranchAction $complete): RedirectResponse
    {
        $complete->execute($request->user(), $request->validated());

        return redirect()->route('dashboard.index')->with('status', 'تم إعداد الفرع الأول. يمكنك تجهيز المنتجات والأرصدة؛ البيع يبقى خاضعًا لاعتماد الإعداد الافتتاحي.');
    }
}
