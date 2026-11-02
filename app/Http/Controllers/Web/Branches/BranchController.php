<?php

namespace App\Http\Controllers\Web\Branches;

use App\Actions\Branches\BranchDependencies;
use App\Actions\Branches\ManageBranchAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branches\ChangeBranchStatusRequest;
use App\Http\Requests\Branches\SaveBranchRequest;
use App\Models\Branch;
use App\Support\Sales\OperationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BranchController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $query = Branch::query()->select(['id', 'name', 'address', 'phone', 'type', 'is_active', 'created_at'])->when(! $request->user()->can('sales.all_branches'), fn ($q) => $q->whereKey($request->user()->branch_id ?? 0));
        $branches = $query->when(filled($filters['q'] ?? null), fn ($q) => $q->where('name', 'like', '%'.addcslashes($filters['q'], '%_\\\\').'%'))->orderBy('name')->paginate(20)->withQueryString();

        return view('branches.index', compact('branches', 'filters'));
    }

    public function create(): View
    {
        return view('branches.form', ['branch' => null]);
    }

    public function store(SaveBranchRequest $request, ManageBranchAction $manage): RedirectResponse
    {
        $manage->save($request->user(), $request->validated());

        return redirect()->route('branches.index')->with('status', 'تم إنشاء الفرع بنجاح');
    }

    public function edit(Request $request, Branch $branch, BranchDependencies $dependencies): View
    {
        OperationContext::branch($request->user(), $branch->id, 'branches.update', false);
        $blockers = $request->user()->can('branches.manage_status') && $branch->is_active ? $dependencies->blockers($branch) : [];

        return view('branches.form', compact('branch', 'blockers'));
    }

    public function update(SaveBranchRequest $request, Branch $branch, ManageBranchAction $manage): RedirectResponse
    {
        $manage->save($request->user(), $request->validated(), $branch);

        return redirect()->route('branches.index')->with('status', 'تم تحديث بيانات الفرع.');
    }

    public function status(ChangeBranchStatusRequest $request, Branch $branch, ManageBranchAction $manage): RedirectResponse
    {
        $manage->status($request->user(), $branch, $request->boolean('is_active'), $request->validated('reason'));

        return redirect()->route('branches.index')->with('status', 'تم حفظ حالة الفرع.');
    }
}
