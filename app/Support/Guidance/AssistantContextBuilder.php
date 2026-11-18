<?php

namespace App\Support\Guidance;

use App\Actions\Inventory\ProgressiveOperation;
use App\Models\Branch;
use App\Models\OpeningStockEntry;
use App\Models\PurchaseInvoice;
use App\Models\SaleInvoice;
use App\Models\User;
use App\Support\Access\AccessPermissions;
use App\Support\Sales\SalesAccess;

class AssistantContextBuilder
{
    public function build(User $actor, array $input, ?string $topic): array
    {
        $branchId = $input['branch_id'] ?? $actor->branch_id;
        if ($branchId !== null) {
            abort_unless(SalesAccess::allows($actor, 'sales.all_branches') || (int) $actor->branch_id === (int) $branchId, 403);
        }
        $branch = $branchId ? Branch::findOrFail($branchId) : null;
        $context = ['page' => $topic, 'branch' => $branch ? ['id' => $branch->id, 'name' => $branch->name] : null];
        if (! empty($input['document_kind'])) {
            [$class, $permissions] = match ($input['document_kind']) {
                'opening' => [OpeningStockEntry::class, ['inventory.correct_setup', 'sales.view_cost']],
                'purchase' => [PurchaseInvoice::class, ['purchases.view', 'sales.view_cost']],
                'sale' => [SaleInvoice::class, ['sales.view']],
            };
            foreach ($permissions as $permission) {
                abort_unless($actor->can($permission), 403);
            }
            abort_unless($branch, 422, 'اختر الفرع قبل طلب مساعدة مستند.');
            $document = $class::where('branch_id', $branch->id)->findOrFail($input['document_id']);
            $context['document'] = ['kind' => $input['document_kind'], 'status' => $document->status];
        }
        if ($topic === 'readiness' && $branch && AccessPermissions::canViewOperation($actor)) {
            $report = app(ProgressiveOperation::class)->report($branch);
            $context['readiness'] = ['state' => $report['state'], 'ready_count' => $report['ready_count'],
                'not_ready_count' => $report['not_ready_count'], 'blockers' => $report['blockers']];
        }

        return $context;
    }
}
