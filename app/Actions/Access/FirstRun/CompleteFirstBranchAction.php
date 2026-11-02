<?php

namespace App\Actions\Access\FirstRun;

use App\Actions\Branches\CreateBranchAction;
use App\Models\ApplicationInstallation;
use App\Models\Branch;
use App\Models\User;
use App\Support\Access\AccessPermissions;
use App\Support\Sales\OperationContext;
use Illuminate\Support\Facades\DB;

class CompleteFirstBranchAction
{
    public function __construct(private CreateBranchAction $branches) {}

    /** @param array{name: string, address?: ?string, phone?: ?string} $data */
    public function execute(User $actor, array $data): Branch
    {
        return DB::transaction(function () use ($actor, $data): Branch {
            $state = ApplicationInstallation::whereKey(1)->lockForUpdate()->firstOrFail();
            $owner = User::with('role')->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless(AccessPermissions::isOwner($owner) && (int) $state->owner_id === (int) $owner->id, 403);
            abort_unless($state->completed_at !== null && $state->first_branch_required && $state->first_branch_completed_at === null, 409, 'إعداد الفرع غير متاح أو اكتمل بالفعل.');
            DB::table('product_code_counters')->where('id', 1)->lockForUpdate()->first();
            abort_if(Branch::exists(), 409, 'يوجد فرع بالفعل؛ راجع مسؤول النظام دون إعادة إعداد التثبيت.');
            $branch = $this->branches->execute($data);
            $owner->forceFill(['branch_id' => $branch->id])->save();
            $state->update(['first_branch_required' => false, 'first_branch_completed_at' => now(), 'initial_branch_id' => $branch->id]);
            OperationContext::audit($branch, $owner, 'installation.first_branch_completed', null, ['installation_id' => $state->id]);
            $actor->setRelation('branch', $branch);
            $actor->branch_id = $branch->id;

            return $branch;
        }, 3);
    }
}
